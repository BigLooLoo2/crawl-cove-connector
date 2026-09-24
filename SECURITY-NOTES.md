# Security pass — 22 Sept 2026

Run against a real WordPress install (SQLite drop-in, no MySQL), both Rank
Math and Yoast SEO, over live HTTP with Application Password auth. Reproduce
with `tests/integration/run.sh --adapter=rankmath|yoast` — it runs
`checks.sh` (route behaviour, 22 checks) then `security-checks.sh` (this
pass, 42 checks). Both adapters: 64/64 green.

## Scope

- Every route (`status`, `resolve`, `apply`, `changes`, `revert`) confirmed
  to require authentication — no route reachable without valid Application
  Password credentials, and a wrong password is rejected too.
- Full capability matrix: subscriber (no `edit_posts`) blocked at all five
  routes with 403; author (`edit_posts`, not `edit_others_posts`) can act on
  their own posts but is `ccc_forbidden` on another user's post; editor
  (`edit_others_posts`) can act on any post — all matching WordPress core's
  own capability model, nothing bespoke in the plugin to get wrong.
- `/apply` and `/resolve` fuzzed: wrong types (string/null/object/array where
  a string or array is expected), nested objects, non-numeric `post_id`,
  10k-character strings, exact length-cap boundaries (512/1024/50/100),
  `<script>` tags, a SQL-injection-shaped string, an embedded null byte, and
  deliberately truncated/malformed JSON. Every case returns a clean 4xx or a
  per-item `ok:false` — no PHP warning, stack trace, or file path ever
  appears in a response body.
- `/revert`: missing/non-numeric/negative/nonexistent `change_id` all handled
  without a crash (400 or 404, never 500).

## Findings

1. **Fixed in code** (see `includes/class-ccc-service.php`): WordPress
   core's `url_to_postid()` pattern-matches `?p=N` straight out of a URL's
   query string and returns `N` even when no such post exists. `resolve_url()`
   trusted that blindly, so a stale or guessed numeric URL was reported as a
   successfully resolved (but blank, uneditable) post instead of "not found".
   Now verifies `get_post()` before returning; covered by a new unit test in
   `tests/ServiceTest.php` plus the integration check
   "resolve: phantom numeric id (url_to_postid quirk) is unresolvable".

2. **Observed, no code change needed**: WordPress's REST argument layer does
   not 400 a scalar against a route arg declared `type => 'array'` — it
   silently `(array)`-casts it to a one-element array instead of rejecting
   the request. Both `/resolve`'s `urls` and `/apply`'s `changes` rely on
   this only as a first line of defense; the plugin's own `is_array()` /
   per-item validation in `CCC_Service` is what actually catches the
   malformed shape, and it does so safely in every case tested. Worth
   knowing if either route's args are ever refactored: don't assume the REST
   layer enforces the declared `type` for you.

3. **Confirmed safe, not a finding**: `sanitize_text_field()` (used on every
   title/description value) strips `<script>` and other tags before
   storage, and the admin page (`admin/class-ccc-admin.php`) escapes every
   logged value with `esc_html()` on output — defense in depth on both ends,
   no stored-XSS path found.

4. **Confirmed safe, not a finding**: capability checks (`current_user_can`)
   correctly deny `edit_post` for a non-existent post id, so even the
   url_to_postid quirk in (1) could never have led to an unauthorized write
   — `/apply` with a phantom post id returned `ccc_forbidden`, not a crash or
   a write. (1) was a read-side correctness bug in `/resolve`'s response,
   not a write-side authorization gap.

## Addendum — 24 Sept 2026 (taxonomy term support, v0.6.0)

Same harness, extended with `tests/integration/taxonomy-checks.sh` (16
checks) covering the new negative-`post_id` term target across all four
adapters, plain and pretty permalinks, and the `edit_term`/`manage_categories`
capability boundary (author blocked, editor allowed — WordPress core's own
model again, nothing bespoke). Two more real-WordPress-only findings, same
class as finding 1 above (a core quirk / a plugin helper's own behaviour
trusted too literally, not a vulnerability in this plugin's own code):

5. **Fixed in code**: on a site with a static front page, `url_to_postid()`
   collapses *any* query string at the site root (not just the homepage's
   own) to the front page's post id — `?cat=2` was resolving to the
   homepage, not the category. `resolve_url()` now resolves query-string
   term targets (`CCC_Term_Resolver::resolve_plain_query_vars()`) *before*
   calling `url_to_postid()`, not after.
6. **Fixed in code**: Yoast's own `WPSEO_Taxonomy_Meta::set_value()` is not
   a true single-field patch — writing just a term's title (or just its
   description) was silently resetting the other back to `''`, and would do
   the same to any other Yoast term setting a site owner had set by hand
   (focus keyword, Open Graph/Twitter overrides, cornerstone flag). Not an
   auth/injection issue, but a real silent-data-loss bug a wordpress.org
   reviewer or a site owner could reasonably treat as a security-adjacent
   correctness defect. Fixed by reading the term's full current settings
   first (`get_term_meta()` with no `$meta` arg) and writing them all back
   with only the intended field changed.

## Not covered here (separate backlog items)

- PHPCS / WordPress-Coding-Standards pass.
- CSRF: not applicable — the REST API here is authenticated by Application
  Passwords (Basic Auth), which WordPress core exempts from the cookie-nonce
  CSRF check by design (same model core itself uses for the REST API).
- Rate limiting / brute-force protection on Application Password auth: this
  plugin doesn't implement its own; it relies on whatever the host/core
  provides. Worth a line in `readme.txt` if it ever comes up in a
  wordpress.org review.
