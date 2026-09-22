#!/usr/bin/env bash
#
# REST route assertions run by run.sh against a live WordPress. Reads its
# fixture data (URL, users, post ids) from environment variables run.sh sets.
# Every check increments PASS/FAIL and prints one line; a nonzero exit means
# at least one assertion failed.

set -uo pipefail

: "${CCC_URL:?}" "${CCC_EDITOR_PW:?}" "${CCC_AUTHOR_PW:?}" "${CCC_SUBSCRIBER_PW:?}"
: "${CCC_POST_EDITOR:?}" "${CCC_POST_AUTHOR:?}" "${CCC_ADAPTER:?}"

BASE="$CCC_URL/index.php?rest_route=/crawlcove/v1"
EDITOR="ccc_editor:$CCC_EDITOR_PW"
AUTHOR="ccc_author:$CCC_AUTHOR_PW"
SUBSCRIBER="ccc_subscriber:$CCC_SUBSCRIBER_PW"

PASS=0
FAIL=0

# check <label> <expected_http> <actual_http> <jq_filter> <expected_value> <body>
check() {
  local label="$1" expect_http="$2" got_http="$3" jq_filter="$4" expect_val="$5" body="$6"
  local got_val
  got_val="$(echo "$body" | jq -rc "$jq_filter" 2>/dev/null)"
  if [[ "$got_http" == "$expect_http" && "$got_val" == "$expect_val" ]]; then
    PASS=$((PASS+1))
    echo "  ok   $label"
  else
    FAIL=$((FAIL+1))
    echo "  FAIL $label — want http=$expect_http $jq_filter=$expect_val, got http=$got_http $jq_filter=$got_val"
    echo "       body: $body"
  fi
}

req() { # req METHOD PATH AUTH JSON_BODY -> sets RESP_BODY RESP_HTTP
  local method="$1" path="$2" auth="$3" data="${4:-}"
  local out
  if [[ -n "$auth" ]]; then
    if [[ -n "$data" ]]; then
      out="$(curl -s -u "$auth" -X "$method" "$BASE$path" -H 'Content-Type: application/json' -d "$data" -w '\n%{http_code}')"
    else
      out="$(curl -s -u "$auth" -X "$method" "$BASE$path" -w '\n%{http_code}')"
    fi
  else
    if [[ -n "$data" ]]; then
      out="$(curl -s -X "$method" "$BASE$path" -H 'Content-Type: application/json' -d "$data" -w '\n%{http_code}')"
    else
      out="$(curl -s -X "$method" "$BASE$path" -w '\n%{http_code}')"
    fi
  fi
  RESP_HTTP="$(echo "$out" | tail -1)"
  RESP_BODY="$(echo "$out" | sed '$d')"
}

echo "-- status --"
req GET /status ""
check "status: unauthenticated is 401" 401 "$RESP_HTTP" '.code' 'rest_forbidden' "$RESP_BODY"

req GET /status "$EDITOR"
check "status: authenticated reports adapter" 200 "$RESP_HTTP" '.seo_plugin' "$CCC_ADAPTER" "$RESP_BODY"
check "status: can_apply true" 200 "$RESP_HTTP" '.can_apply' 'true' "$RESP_BODY"

echo "-- resolve --"
EDITOR_URL="$CCC_URL/?p=$CCC_POST_EDITOR"
req POST /resolve "$EDITOR" "{\"urls\":[\"$EDITOR_URL\"]}"
check "resolve: valid url ok" 200 "$RESP_HTTP" '.[0].ok' 'true' "$RESP_BODY"
check "resolve: valid url post_id" 200 "$RESP_HTTP" '.[0].post_id' "$CCC_POST_EDITOR" "$RESP_BODY"

req POST /resolve "$EDITOR" '{"urls":["https://evil.example.com/?p=1"]}'
check "resolve: wrong site rejected" 200 "$RESP_HTTP" '.[0].error' 'ccc_wrong_site' "$RESP_BODY"

req POST /resolve "$EDITOR" "{\"urls\":[\"$CCC_URL/?p=999999\"]}"
check "resolve: phantom numeric id (url_to_postid quirk) is unresolvable, not a fake ok" 200 "$RESP_HTTP" '.[0].error' 'ccc_unresolvable' "$RESP_BODY"

req POST /resolve "$SUBSCRIBER" "{\"urls\":[\"$EDITOR_URL\"]}"
check "resolve: subscriber (no edit_posts) forbidden" 403 "$RESP_HTTP" '.code' 'rest_forbidden' "$RESP_BODY"

echo "-- apply --"
req POST /apply "$EDITOR" "{\"dry_run\":true,\"changes\":[{\"post_id\":$CCC_POST_EDITOR,\"title\":\"Dry Run Title\"}]}"
check "apply: dry_run reports changed, no change_id" 200 "$RESP_HTTP" '.[0].applied.title | .changed and (has("change_id")|not)' 'true' "$RESP_BODY"

req GET /changes "$EDITOR"
DRY_LOGGED="$(echo "$RESP_BODY" | jq -r '[.[] | select(.to == "Dry Run Title")] | length')"
if [[ "$DRY_LOGGED" == "0" ]]; then
  PASS=$((PASS+1)); echo "  ok   apply: dry_run wrote nothing to the change log"
else
  FAIL=$((FAIL+1)); echo "  FAIL apply: dry_run leaked into the change log ($DRY_LOGGED entries)"
fi

req POST /apply "$EDITOR" "{\"changes\":[{\"post_id\":$CCC_POST_EDITOR,\"title\":\"Real Title\",\"description\":\"Real Desc\"}]}"
check "apply: real write ok" 200 "$RESP_HTTP" '.[0].ok' 'true' "$RESP_BODY"
CHANGE_ID_TITLE="$(echo "$RESP_BODY" | jq -r '.[0].applied.title.change_id')"

req POST /apply "$AUTHOR" "{\"changes\":[{\"post_id\":$CCC_POST_EDITOR,\"title\":\"Steal\"}]}"
check "apply: author cannot edit editor's post" 200 "$RESP_HTTP" '.[0].error' 'ccc_forbidden' "$RESP_BODY"

req POST /apply "$EDITOR" "{\"changes\":[{\"post_id\":$CCC_POST_AUTHOR,\"title\":\"Editor can edit others (WP core: edit_others_posts)\"}]}"
check "apply: editor CAN edit author's post (WP core capability)" 200 "$RESP_HTTP" '.[0].ok' 'true' "$RESP_BODY"

BIG_ITEMS="$(python3 -c "import json; print(json.dumps([{'post_id':$CCC_POST_EDITOR,'title':'x'} for _ in range(51)]))")"
req POST /apply "$EDITOR" "{\"changes\":$BIG_ITEMS}"
check "apply: batch >50 rejected" 400 "$RESP_HTTP" '.code' 'ccc_batch_too_big' "$RESP_BODY"

LONG_TITLE="$(python3 -c "print('x'*513)")"
req POST /apply "$EDITOR" "{\"changes\":[{\"post_id\":$CCC_POST_EDITOR,\"title\":\"$LONG_TITLE\"}]}"
check "apply: title over 512 chars rejected" 200 "$RESP_HTTP" '.[0].error' 'ccc_too_long' "$RESP_BODY"

req POST /apply "$EDITOR" '{"changes":[]}'
check "apply: empty batch rejected" 400 "$RESP_HTTP" '.code' 'ccc_empty_batch' "$RESP_BODY"

req POST /apply "$EDITOR" "{\"changes\":[{\"post_id\":$CCC_POST_EDITOR,\"title\":\"日本語 — émoji 🚀\"}]}"
check "apply: unicode written ok" 200 "$RESP_HTTP" '.[0].ok' 'true' "$RESP_BODY"
req POST /resolve "$EDITOR" "{\"urls\":[\"$EDITOR_URL\"]}"
check "apply: unicode round-trips exactly" 200 "$RESP_HTTP" '.[0].current.title' '日本語 — émoji 🚀' "$RESP_BODY"

echo "-- changes / revert --"
req GET /changes "$EDITOR"
check "changes: newest first" 200 "$RESP_HTTP" '.[0].to' '日本語 — émoji 🚀' "$RESP_BODY"

req POST /revert "$EDITOR" "{\"change_id\":$CHANGE_ID_TITLE}"
check "revert: valid change ok" 200 "$RESP_HTTP" '.ok' 'true' "$RESP_BODY"

req POST /revert "$EDITOR" "{\"change_id\":$CHANGE_ID_TITLE}"
check "revert: repeat is 409 already-reverted" 409 "$RESP_HTTP" '.code' 'ccc_already_reverted' "$RESP_BODY"

req POST /revert "" "{\"change_id\":$CHANGE_ID_TITLE}"
check "revert: unauthenticated is 401" 401 "$RESP_HTTP" '.code' 'rest_forbidden' "$RESP_BODY"

echo
echo "== $PASS passed, $FAIL failed =="
[[ $FAIL -eq 0 ]]
