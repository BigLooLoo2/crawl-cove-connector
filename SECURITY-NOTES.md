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

## Addendum — 26 Sept 2026 (WooCommerce + Multisite capability verification)

Two new standalone harnesses (`tests/integration/woocommerce-checks.sh`,
`tests/integration/multisite-checks.sh`), same "prove it against a real
install, don't trust the code read" discipline as the addenda above. No
vulnerabilities found; one capability-model behavior worth recording here
because it's exactly the kind of thing a site owner could mistake for a
CCC bug (or a security hole in the other direction — an Editor who thinks
they can't touch products, when actually they simply lack the capability
core already withholds):

7. **Not a CCC issue, confirmed on the merits**: WooCommerce grants
   `edit_products`/`edit_product_terms` (and related capabilities) ONLY to
   the Shop Manager and Administrator roles — verified against
   `WC_Install::create_roles()` and `WC_Post_Types::register_taxonomies()`
   source, not assumed. CCC's `edit_post`/`edit_term` capability checks defer
   entirely to WordPress core's meta-capability system, so an Editor who can
   push fixes to ordinary posts/pages correctly gets `ccc_forbidden` on a
   WooCommerce product or product_cat term. This is WooCommerce's own
   authorization model working as intended, not a gap in CCC. Documented in
   README.md.
8. **Multisite scoping confirmed, not assumed**: activating CCC + Rank Math
   on one subsite of a network and hitting the network root's REST API
   returns a genuine `rest_no_route` 404 (route doesn't exist there at all),
   not a `ccc_forbidden`/`rest_forbidden` — confirming `register_rest_route()`
   is correctly per-site (fires on each site's own `rest_api_init`) with no
   network-wide leakage, and that a write via the subsite's REST endpoint
   lands in that subsite's own postmeta table, never the network's shared
   tables.

## Addendum — 26 Sept 2026 (caching-plugin interaction — a real bug, now fixed)

Investigated on the hypothesis that a caching plugin's own per-page purge
hook might not fire for every CCC write path — not a security issue, but a
correctness one with the same "silent, invisible failure" shape worth this
file's discipline. Confirmed real, not assumed: downloaded WP Super Cache's
actual current-stable source from wordpress.org and read its cache-purge
functions directly (`wp-cache-phase2.php`) rather than guessing at its
behavior.

9. **Real bug, now fixed**: `wp_cache_post_edit()`/`wp_cache_post_change()`
   — WP Super Cache's own functions, hooked to core's `clean_post_cache`
   action — both `return` immediately when `$post_id === 0`, and neither is
   registered on any term-edit action at all. CCC's homepage (`post_id 0`)
   and taxonomy-archive (negative term-id sentinel) writes went through
   `update_option()`/term meta directly, with no `wp_update_post()` call to
   fire `clean_post_cache` in the first place — so a site running WP Super
   Cache (or any similarly-built caching plugin) kept serving a stale
   cached homepage or category/tag archive page after a desktop-app push,
   for as long as that page's cache lived. `CCC_Service::invalidate_caches_for()`
   now runs a best-effort full-site purge (WP Super Cache, W3 Total Cache,
   WP Rocket, WP Fastest Cache via `function_exists()`-guarded calls to
   their own public functions, plus LiteSpeed Cache's documented
   `litespeed_purge_all` action) for these two target types, and always
   fires a new `ccc_after_uncached_write` action for anything not listed.
10. **Second real bug found investigating the first**: `CCC_Change_Log::
    revert()` never called `wp_update_post()` at all, for ANY target —
    including an ordinary post. A reverted post's Yoast indexable went
    stale and no caching plugin learned the page changed, the exact defect
    class `apply()` already had a fix for (re-saving the post) that
    `revert()` simply never inherited. Both fixes share one code path now
    (`CCC_Service::invalidate_caches_for()`, called from both `apply()` and
    `revert()`), so they can't drift apart again silently.

Verified against a real WordPress + real Rank Math install, not just unit
stubs: `tests/integration/caching-checks.sh` uses an mu-plugin probe that
logs real firings of `clean_post_cache` and a stand-in for WP Super Cache's
own `wp_cache_clear_cache()` (name and signature taken from its real
source, not guessed). Confirmed the harness genuinely catches the bug, not
just exercises the happy path: reverted the fix locally and re-ran it first
— 10 of 12 checks failed exactly as predicted, then re-ran with the fix
restored for a clean 12/12.

## Not covered here (separate backlog items)

- PHPCS / WordPress-Coding-Standards pass.
- CSRF: not applicable — the REST API here is authenticated by Application
  Passwords (Basic Auth), which WordPress core exempts from the cookie-nonce
  CSRF check by design (same model core itself uses for the REST API).
- Rate limiting / brute-force protection on Application Password auth: this
  plugin doesn't implement its own; it relies on whatever the host/core
  provides. Worth a line in `readme.txt` if it ever comes up in a
  wordpress.org review.
