#!/usr/bin/env bash
#
# Shared HTTP request + assertion helpers for checks.sh and security-checks.sh.
# Sourced, not executed. Expects CCC_* env vars from run.sh and defines
# BASE/EDITOR/AUTHOR/SUBSCRIBER plus PASS/FAIL counters.

: "${CCC_URL:?}" "${CCC_EDITOR_PW:?}" "${CCC_AUTHOR_PW:?}" "${CCC_SUBSCRIBER_PW:?}"
: "${CCC_POST_EDITOR:?}" "${CCC_POST_AUTHOR:?}" "${CCC_POST_HOME:?}" "${CCC_ADAPTER:?}"

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
    echo "       body: $(echo "$body" | head -c 400)"
  fi
}

# check_http <label> <expected_http> <actual_http> [body]
check_http() {
  local label="$1" expect_http="$2" got_http="$3" body="${4:-}"
  if [[ "$got_http" == "$expect_http" ]]; then
    PASS=$((PASS+1))
    echo "  ok   $label"
  else
    FAIL=$((FAIL+1))
    echo "  FAIL $label — want http=$expect_http, got http=$got_http"
    [[ -n "$body" ]] && echo "       body: $(echo "$body" | head -c 400)"
  fi
}

# req METHOD PATH AUTH JSON_BODY [EXTRA_CURL_ARGS...] -> sets RESP_BODY RESP_HTTP
req() {
  local method="$1" path="$2" auth="$3" data="${4:-}"
  shift 4 2>/dev/null || shift $#
  local out
  local -a curlargs=(-s -X "$method" "$BASE$path" -w '\n%{http_code}' --max-time 10)
  [[ -n "$auth" ]] && curlargs+=(-u "$auth")
  if [[ -n "$data" ]]; then
    curlargs+=(-H 'Content-Type: application/json' --data-binary "$data")
  fi
  out="$(curl "${curlargs[@]}" "$@")"
  RESP_HTTP="$(echo "$out" | tail -1)"
  RESP_BODY="$(echo "$out" | sed '$d')"
}

summary() {
  echo
  echo "== $PASS passed, $FAIL failed =="
  [[ $FAIL -eq 0 ]]
}
