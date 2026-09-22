#!/usr/bin/env bash
#
# Runs the official wordpress.org "Plugin Check" tool (wp-cli command,
# plugin-check plugin) against exactly the files that would ship in the
# wordpress.org SVN package — NOT this git checkout as-is.
#
# TRAP, confirmed the hard way (two runs that never finished in 40+ minutes
# each): symlinking the WHOLE repo root into wp-content/plugins/ (which is
# what run.sh does for the integration harness) also symlinks in vendor/
# (4000+ files of dev-only PHPCS/PHPUnit tooling), tests/, wordpress-org/
# etc. Plugin Check's docs say vendor/ is excluded from file-based scans by
# default, but in practice checking it against the full repo hung well past
# the point any real submission-pack check should take; checking a clean,
# production-only copy (this script) finishes in under a minute. Always
# check the same file set that would actually be zipped for SVN.
#
# Usage: tests/integration/plugin-check.sh

set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_ROOT="$(cd "$HERE/../.." && pwd)"
CACHE="$HERE/.cache"
SITE="$HERE/.site-check"
DIST="$HERE/.dist"
WPCLI=(php "$CACHE/wp-cli.phar")
PORT=8988

log() { echo "[plugin-check] $*"; }

for f in wordpress.zip sqlite.zip wp-cli.phar; do
  if [[ ! -f "$CACHE/$f" ]]; then
    echo "missing $CACHE/$f — run tests/integration/run.sh once first to populate the cache" >&2
    exit 1
  fi
done

log "staging a production-only copy of the plugin (no vendor/, tests/, dev tooling)"
rm -rf "$DIST"
mkdir -p "$DIST"
rsync -a \
  --exclude=vendor --exclude=tests --exclude=wordpress-org --exclude=.git \
  --exclude=composer.json --exclude=composer.lock --exclude=phpcs.xml.dist \
  --exclude=phpunit.xml --exclude=.phpunit.result.cache --exclude=.gitignore \
  --exclude=SECURITY-NOTES.md --exclude=README.md --exclude=CHANGELOG.md \
  "$PLUGIN_ROOT/" "$DIST/"

log "rebuilding throwaway WP site"
rm -rf "$SITE"
mkdir -p "$SITE"
unzip -q "$CACHE/wordpress.zip" -d "$SITE/_core"
cp -r "$SITE"/_core/wordpress/. "$SITE/"
rm -rf "$SITE/_core"

mkdir -p "$SITE/wp-content/plugins"
unzip -q "$CACHE/sqlite.zip" -d "$SITE/wp-content/plugins"
cp "$SITE/wp-content/plugins/sqlite-database-integration/db.copy" "$SITE/wp-content/db.php"
ln -s "$DIST" "$SITE/wp-content/plugins/crawl-cove-connector"

log "wp core config + install"
"${WPCLI[@]}" config create --path="$SITE" --dbname=irrelevant --dbuser=irrelevant --dbpass=irrelevant --skip-check --quiet
"${WPCLI[@]}" core install --path="$SITE" --url="http://127.0.0.1:$PORT" --title="CCC Plugin Check" \
  --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email --quiet
"${WPCLI[@]}" plugin activate sqlite-database-integration crawl-cove-connector --path="$SITE" --quiet

log "installing plugin-check (downloads from wordpress.org)"
"${WPCLI[@]}" plugin install plugin-check --activate --path="$SITE" --quiet

log "running wp plugin check crawl-cove-connector"
OUT="$("${WPCLI[@]}" plugin check crawl-cove-connector --path="$SITE" --allow-root 2>&1)"
echo "$OUT"

if echo "$OUT" | grep -qE "^Success: Checks complete\. No errors found\.$"; then
  log "PASS — no errors or warnings"
  exit 0
fi
log "FAIL — plugin check reported findings, see output above"
exit 1
