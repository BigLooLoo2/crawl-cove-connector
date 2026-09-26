#!/usr/bin/env bash
#
# Compares the SEO plugin zips cached under tests/integration/.cache/ (used
# by run.sh's real-WordPress integration harness) against the CURRENT stable
# version each plugin has on wordpress.org right now, and flags drift.
#
# WHY THIS EXISTS: run.sh refuses to auto-download — it errors if a cache
# file is missing, and silently reuses whatever's already there otherwise.
# That's correct for repeatable, offline-friendly test runs, but it means
# the cache can go stale with nothing ever saying so: on 26 Sept 2026 the
# cached AIOSEO build was still 5.0.1.1 four days after wordpress.org shipped
# 5.0.2, and every integration run in between was quietly testing against a
# build nobody would actually be running in production anymore. Found by
# accident that session, not by any check — this script makes it a check.
#
# This is a manual pre-release / periodic sanity script, like plugin-check.sh
# — NOT wired into composer test or CI, because it needs network access and
# an offline/CI run should stay deterministic. Run it yourself every so often
# (e.g. at the top of a wp session) and re-run tests/integration/run.sh for
# any adapter it flags as stale.
#
# Usage: tests/integration/check-versions.sh

set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CACHE="$HERE/.cache"

log() { echo "[check-versions] $*"; }

current_version() {
  # $1 = wordpress.org plugin slug
  curl -s "https://api.wordpress.org/plugins/info/1.0/$1.json" \
    | python3 -c "import json,sys; print(json.load(sys.stdin).get('version','?'))"
}

cached_version() {
  # $1 = zip file, $2 = path inside zip to the main plugin file
  unzip -p "$CACHE/$1" "$2" 2>/dev/null | grep -m1 -oP '(?<=Version:)\s*\K[0-9][0-9A-Za-z.\-]*'
}

STALE=0

check() {
  local label="$1" slug="$2" zip="$3" mainfile="$4"
  if [[ ! -f "$CACHE/$zip" ]]; then
    log "$label: no cache file $zip — nothing to compare (run.sh will error first anyway)"
    return
  fi
  local have want
  have="$(cached_version "$zip" "$mainfile")"
  want="$(current_version "$slug")"
  if [[ -z "$have" ]]; then
    log "$label: could not read a Version: header from $zip/$mainfile — check by hand"
    return
  fi
  if [[ "$have" == "$want"* || "$want" == "$have"* ]]; then
    log "$label: cache matches current stable ($have)"
    return
  fi
  # Strip pre-release suffixes (e.g. "28.6-RC4" -> "28.6") so `sort -V` compares
  # release lines, not RC tags — a cached RC for the NEXT stable is not "stale".
  local have_base="${have%%-*}" want_base="${want%%-*}"
  local lower
  lower="$(printf '%s\n%s\n' "$have_base" "$want_base" | sort -V | head -1)"
  if [[ "$have_base" == "$want_base" ]]; then
    log "$label: cache ($have) and current stable ($want) are the same release line — fine"
  elif [[ "$lower" == "$have_base" ]]; then
    log "$label: STALE — cache has $have, wordpress.org stable is $want (cache is BEHIND)"
    STALE=1
  else
    log "$label: cache has $have, wordpress.org stable is $want — cache is AHEAD (pre-release build), not stale"
  fi
}

check "Rank Math" "seo-by-rank-math"        "rankmath.zip"  "seo-by-rank-math/rank-math.php"
check "Yoast SEO"  "wordpress-seo"          "yoast.zip"     "wordpress-seo/wp-seo.php"
check "SEOPress"   "wp-seopress"            "seopress.zip"  "wp-seopress/seopress.php"
check "AIOSEO"     "all-in-one-seo-pack"    "aioseo.zip"    "all-in-one-seo-pack/all_in_one_seo_pack.php"

if [[ "$STALE" -eq 1 ]]; then
  log "one or more caches are stale. To refresh, e.g.:"
  log "  curl -sL https://downloads.wordpress.org/plugin/<slug>.<version>.zip -o tests/integration/.cache/<name>.zip"
  log "then re-run tests/integration/run.sh --adapter=<name> and check for regressions."
  exit 1
fi
log "all cached SEO plugin builds match current wordpress.org stable"
exit 0
