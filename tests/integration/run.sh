#!/usr/bin/env bash
#
# Real-WordPress integration smoke for crawl-cove-connector: builds a throwaway
# WP install backed by the SQLite drop-in (no MySQL needed on this box),
# installs the real Rank Math, Yoast SEO or SEOPress plugin, symlinks in this
# repo's plugin code, and exercises all five REST routes end to end (auth,
# capability checks, validation, dry-run, apply, revert) against a live
# php -S server. Unit stubs can't see this: url_to_postid()'s "?p=N with no
# such post" quirk and Yoast's indexable auto-rebuild were both proven/caught
# here, not in tests/*Test.php.
#
# Usage: tests/integration/run.sh [--adapter=rankmath|yoast|seopress]
#
# Downloads are cached under tests/integration/.cache/ (gitignored) so repeat
# runs don't hit wordpress.org again. The site itself is rebuilt from scratch
# every run under tests/integration/.site/ (also gitignored).

set -uo pipefail

ADAPTER="rankmath"
for arg in "$@"; do
  case "$arg" in
    --adapter=*) ADAPTER="${arg#*=}" ;;
    *) echo "unknown arg: $arg" >&2; exit 2 ;;
  esac
done
if [[ "$ADAPTER" != "rankmath" && "$ADAPTER" != "yoast" && "$ADAPTER" != "seopress" ]]; then
  echo "adapter must be rankmath, yoast or seopress, got: $ADAPTER" >&2
  exit 2
fi

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_ROOT="$(cd "$HERE/../.." && pwd)"
CACHE="$HERE/.cache"
SITE="$HERE/.site"
WPCLI=(php "$CACHE/wp-cli.phar")
PORT=8987
URL="http://127.0.0.1:$PORT"
SERVER_PID=""

log() { echo "[integration] $*"; }

cleanup() {
  if [[ -n "$SERVER_PID" ]] && kill -0 "$SERVER_PID" 2>/dev/null; then
    kill "$SERVER_PID" 2>/dev/null || true
    wait "$SERVER_PID" 2>/dev/null || true
  fi
}
trap cleanup EXIT

for f in wordpress.zip sqlite.zip rankmath.zip yoast.zip seopress.zip wp-cli.phar; do
  if [[ ! -f "$CACHE/$f" ]]; then
    echo "missing $CACHE/$f — see NEXT.md for the download commands" >&2
    exit 1
  fi
done

log "rebuilding site dir"
rm -rf "$SITE"
mkdir -p "$SITE"
unzip -q "$CACHE/wordpress.zip" -d "$SITE/_core"
cp -r "$SITE"/_core/wordpress/. "$SITE/"
rm -rf "$SITE/_core"

mkdir -p "$SITE/wp-content/plugins" "$SITE/wp-content/mu-plugins"
unzip -q "$CACHE/sqlite.zip" -d "$SITE/wp-content/plugins"
unzip -q "$CACHE/rankmath.zip" -d "$SITE/wp-content/plugins"
unzip -q "$CACHE/yoast.zip" -d "$SITE/wp-content/plugins"
unzip -q "$CACHE/seopress.zip" -d "$SITE/wp-content/plugins"
cp "$SITE/wp-content/plugins/sqlite-database-integration/db.copy" "$SITE/wp-content/db.php"
ln -s "$PLUGIN_ROOT" "$SITE/wp-content/plugins/crawl-cove-connector"

# The harness serves plain HTTP on 127.0.0.1, so core's is_ssl() gate on
# Application Passwords would block every request. Force it available WITHOUT
# setting WP_ENVIRONMENT_TYPE=local: Yoast's `wp yoast index` (and its
# save_post indexable rebuild) refuses to run on any non-"production" type,
# which cost real debugging time to find (see NEXT.md / LESSONS candidate).
cat > "$SITE/wp-content/mu-plugins/ccc-test-harness.php" <<'PHP'
<?php
add_filter( 'wp_is_application_passwords_available', '__return_true' );
PHP

log "wp core config + install (SQLite drop-in, no MySQL)"
"${WPCLI[@]}" config create --path="$SITE" --dbname=irrelevant --dbuser=irrelevant --dbpass=irrelevant --skip-check --quiet
"${WPCLI[@]}" core install --path="$SITE" --url="$URL" --title="CCC Integration" \
  --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email --quiet

case "$ADAPTER" in
  rankmath) SEO_PLUGIN="seo-by-rank-math" ;;
  yoast)    SEO_PLUGIN="wordpress-seo" ;;
  seopress) SEO_PLUGIN="wp-seopress" ;;
esac
"${WPCLI[@]}" plugin activate sqlite-database-integration crawl-cove-connector "$SEO_PLUGIN" --path="$SITE" --quiet

log "users: editor (can edit any post), author (own posts only), subscriber (none)"
"${WPCLI[@]}" user create ccc_editor editor@example.com --role=editor --user_pass=editor-pass --path="$SITE" --quiet
"${WPCLI[@]}" user create ccc_author author@example.com --role=author --user_pass=author-pass --path="$SITE" --quiet
"${WPCLI[@]}" user create ccc_subscriber sub@example.com --role=subscriber --user_pass=sub-pass --path="$SITE" --quiet
EDITOR_ID="$("${WPCLI[@]}" user get ccc_editor --field=ID --path="$SITE")"
AUTHOR_ID="$("${WPCLI[@]}" user get ccc_author --field=ID --path="$SITE")"

pw_for() { "${WPCLI[@]}" user application-password create "$1" "ccc-test" --porcelain --path="$SITE"; }
EDITOR_PW="$(pw_for ccc_editor)"
AUTHOR_PW="$(pw_for ccc_author)"
SUBSCRIBER_PW="$(pw_for ccc_subscriber)"

log "posts: one owned by editor, one owned by author"
POST_EDITOR="$("${WPCLI[@]}" post create --post_title="Editor Post" --post_status=publish --post_author="$EDITOR_ID" --porcelain --path="$SITE")"
POST_AUTHOR="$("${WPCLI[@]}" post create --post_title="Author Post" --post_status=publish --post_author="$AUTHOR_ID" --porcelain --path="$SITE")"

log "starting php -S on $URL"
( cd "$SITE" && exec php -S "127.0.0.1:$PORT" -t "$SITE" >"$HERE/.server.log" 2>&1 ) &
SERVER_PID=$!
UP=0
for i in $(seq 1 30); do
  if curl -s -o /dev/null "$URL/index.php?rest_route=/"; then UP=1; break; fi
  sleep 0.3
done
if [[ "$UP" -ne 1 ]]; then
  echo "php -S never came up — see $HERE/.server.log" >&2
  exit 1
fi

export CCC_URL="$URL"
export CCC_EDITOR_PW="$EDITOR_PW"
export CCC_AUTHOR_PW="$AUTHOR_PW"
export CCC_SUBSCRIBER_PW="$SUBSCRIBER_PW"
export CCC_POST_EDITOR="$POST_EDITOR"
export CCC_POST_AUTHOR="$POST_AUTHOR"
export CCC_ADAPTER="$ADAPTER"

log "running REST route checks (adapter=$ADAPTER)"
bash "$HERE/checks.sh"
CHECKS_STATUS=$?

log "running security pass (auth sweep, capability matrix, fuzzing)"
bash "$HERE/security-checks.sh"
SECURITY_STATUS=$?

STATUS=0
[[ $CHECKS_STATUS -ne 0 || $SECURITY_STATUS -ne 0 ]] && STATUS=1

if [[ $STATUS -eq 0 ]]; then
  log "ALL CHECKS PASSED (route checks + security pass)"
else
  log "CHECKS FAILED — see output above; server log: $HERE/.server.log"
fi
exit $STATUS
