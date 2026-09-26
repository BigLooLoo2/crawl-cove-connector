# Changelog

## 0.8.0 — 2026-09-26

- Fix: a homepage ("your latest posts" mode) or taxonomy-archive apply/
  revert never signalled a page-caching plugin that the page had changed.
  Both targets write through `update_option()`/term meta directly — no
  post row exists for `wp_update_post()` to re-save, so `clean_post_cache`
  (the action a real post's apply already fires, and the one caching
  plugins hook) never ran. Verified against WP Super Cache's real source
  (`wp-cache-phase2.php`): `wp_cache_post_edit()`/`wp_cache_post_change()`
  both bail immediately on `$post_id === 0` and are registered only on
  `clean_post_cache`, never on any term-edit hook — so a WP Super Cache
  site kept serving the old cached homepage/archive HTML indefinitely
  after a desktop-app push. `CCC_Service::invalidate_caches_for()` now
  runs a best-effort full-site purge for WP Super Cache, W3 Total Cache and
  WP Rocket (via their own public functions, guarded by `function_exists()`)
  plus WP Fastest Cache's and LiteSpeed Cache's own documented
  `wpfc_clear_all_cache`/`litespeed_purge_all` action hooks, and always
  fires a new plugin-agnostic `ccc_after_uncached_write` action for
  anything else.
- Fix: `CCC_Change_Log::revert()` never called `wp_update_post()` at all,
  for ANY target — not even an ordinary post. A reverted post's Yoast
  indexable went stale and no caching plugin's `clean_post_cache` hook
  fired, the exact gap `apply()` already closed for a fresh write. Revert
  now runs through the same `CCC_Service::invalidate_caches_for()` path
  apply() uses, for every target type.
- New `tests/integration/caching-checks.sh`: a real WordPress + real Rank
  Math harness with an mu-plugin probe that logs `clean_post_cache`
  firings and WP Super Cache's own `wp_cache_clear_cache()` (confirmed
  against its real source rather than guessed), plus LiteSpeed's
  `litespeed_purge_all` and the new `ccc_after_uncached_write` action.
  12/12 checks green with the fix; confirmed the harness actually catches
  the bug by reverting the fix locally first — 10/12 failed as predicted
  (only the pre-existing "apply on a real post" path passed).
- 85 unit tests (+1), full gate green (phpcs, phpcompat, 4-adapter
  integration matrix, Plugin Check) — see SECURITY-NOTES.md for the full
  writeup.

## 0.7.0 — 2026-09-24

- SEOPress taxonomy term title/description support, extending 0.6.0's
  per-term target (Yoast, Rank Math) to a third adapter. Verified against
  real SEOPress source: unlike Yoast's shared-option storage, per-term SEO
  data is plain term meta under the *exact same key names* as its
  post-level fields (`_seopress_titles_title`/`_seopress_titles_desc`),
  confirmed at `src/Services/Metas/Title/Specifications/
  TaxonomySpecification.php` (read) and `inc/admin/metaboxes/
  admin-term-metaboxes.php` (write, plain `update_term_meta()`/
  `delete_term_meta()`) — no shared-array read-modify-write risk, same
  simple pattern as Rank Math's term storage, so both now share one code
  path in `CCC_Adapter`.
- AIOSEO's term unsupported status is now confirmed on the merits, not
  left unresearched: its own source (`app/Common/Main/BulkActions.php`)
  states outright that per-term SEO analysis columns "live on the Pro
  aioseo_terms table" and the free-tier REST term controller's meta-field
  registration is a deliberate no-op ("Term SEO meta requires the Pro Term
  model"). The free plugin this connector supports has no term SEO storage
  to write to at all.
- 84 unit tests (+2), taxonomy integration checks extended to all
  supporting adapters, full gate green, Plugin Check clean.

## 0.6.0 — 2026-09-24

- Taxonomy term title/description support: fix a single category, tag or
  custom-taxonomy archive's title/description without touching any other
  term in that taxonomy (the taxonomy-wide default TEMPLATE was explicitly
  scoped out — see BACKLOG.md — a template fix would let one crawled-URL
  fix silently rewrite every other term's title). New negative post_id
  sentinel (`post_id = -$term_id`) across `/resolve`, `/apply`, `/revert`,
  the same "reuse the existing int slot" trick `HOME_ID` (0) already uses
  for the homepage — no new REST fields, no change-log schema change.
  `CCC_Term_Resolver` (new) resolves a term archive URL two ways: query-var
  matching for "Plain" permalinks (`?cat=N`, `?category_name=slug`,
  `?tag=slug`, and any other public taxonomy's own registered query_var),
  and rewrite-rule matching for pretty permalinks, adapted from WordPress
  core's own `url_to_postid()` (checks `is_tax`/`is_category`/`is_tag`
  instead of `is_singular`). Capability is `edit_term` (WordPress core's own
  meta capability, maps to `manage_categories` for category/post_tag).
  Yoast via `WPSEO_Taxonomy_Meta::get_term_meta()`/`set_values()`; Rank Math
  via real term meta (`rank_math_title`/`rank_math_description`, same key
  names as posts, via core's `get_term_meta()`/`update_term_meta()`).
  SEOPress and AIOSEO report `ccc_term_unsupported` per-item rather than
  guessing at their storage.
- Fix, found by the real-WordPress integration harness rather than unit
  stubs: `url_to_postid()` has its own quirk on a site with a static front
  page — *any* query string at the site root (not just the homepage's own)
  collapses to the front page's post id, because its "is what's left the
  home URL?" check runs before rewrite-rule matching. `?cat=2` was
  resolving to the homepage instead of the category. Fixed by resolving
  query-string term targets BEFORE calling `url_to_postid()`, not after — a
  named taxonomy query var is a far more precise signal than that coarse
  check.
- Fix, also caught by the integration harness: Yoast's own
  `WPSEO_Taxonomy_Meta::set_value()` is not a true single-field patch for
  most fields — writing just a term's title (or just its description) was
  silently resetting the OTHER of the two back to '', and would do the same
  to any other Yoast term setting a site owner had set by hand (focus
  keyword, Open Graph/Twitter overrides, cornerstone flag). Fixed by
  reading the term's full current Yoast settings first and writing them all
  back with only the intended field changed.

## 0.5.0 — 2026-09-23

- SEOPress homepage title/description support, extending 0.4.0's
  `post_id: 0` homepage target to a third adapter. Storage:
  `seopress_titles_option_name` option's `seopress_titles_home_site_title` /
  `seopress_titles_home_site_desc` keys, written through the same
  read-whole-array-then-`update_option()` pattern SEOPress's own setup
  wizard uses (`inc/admin/wizard/admin-wizard.php`). Confirmed safe to clear
  to '': `LatestPostsSpecification::isSatisfyBy()` in SEOPress's own title/
  description generator explicitly stops applying when the value is empty,
  falling through to the next specification rather than rendering blank —
  same safety class as Yoast, no `can_clear_home_title()` exception needed.
- AIOSEO investigated properly this session and confirmed genuinely
  unsupported, not just unresearched: `Meta\Title::getHomePageTitle()` and
  `Meta\Description::getHomePageDescription()` (app/Common/Meta/) fall back,
  for a "your latest posts" site, to
  `searchAppearance.global.siteTitle`/`.metaDescription` — the *same*
  site-wide template that fills the `#site_title`/`#tagline` variables used
  in every other page's title/description template. AIOSEO has no dedicated
  per-homepage field to write to; doing so would silently change title
  generation across the whole site, not just `/`. `ccc_home_unsupported`
  stays correct for AIOSEO, now for a verified reason.
- 61 unit tests (+2), full 4-adapter integration suite (including
  `tests/integration/homepage-checks.sh`) green, Plugin Check clean.

## 0.4.0 — 2026-09-23

- Homepage title/description support for sites with **no static front page**
  (Settings → Reading → "Your latest posts"). Previously only a static front
  page worked, because it's just a normal page and CCC already resolved/wrote
  it like any other post; a "latest posts" homepage has no post to hold an
  override, so Yoast and Rank Math each keep it in their own settings —
  `wpseo_titles` (`title-home-wpseo` / `metadesc-home-wpseo`) and
  `rank-math-options-titles` (`homepage_title` / `homepage_description`)
  respectively, both confirmed against real plugin source and both written
  through the plugin's own safe read-modify-write helper (`WPSEO_Options::
  save_option()` for Yoast; read-whole-array-then-`update_option()` for Rank
  Math), never a bare option overwrite — either would silently wipe every
  other setting sharing that option array.
- New REST target: `/resolve`, `/apply` and `/revert` now accept `post_id: 0`
  meaning "the homepage" (`CCC_Service::HOME_ID`). `/resolve` reports it
  automatically for the site root when there's no static front page.
  Permission is `manage_options`, not `edit_post` (there's no post to check
  `edit_post` against). SEOPress and AIOSEO report `ccc_home_unsupported`
  rather than silently dropping the change; sending `post_id: 0` on a site
  that *does* have a static front page reports `ccc_no_homepage_target`.
- Found and fixed while implementing, before it ever wrote anything wrong:
  (1) a homepage write must never trigger the post-apply `wp_update_post()`
  "touch" — `wp_update_post( [ 'ID' => 0 ] )` is WordPress core's signal to
  **insert a new post**, not a no-op, which would have created a stray empty
  post on every homepage change; (2) the initial homepage-URL matcher ignored
  query strings, so a "Plain" permalink post at the site root
  (`/?p=5`) was misidentified as the homepage — fixed by requiring an empty
  query string; (3) Rank Math's homepage title has no template fallback when
  cleared (unlike Yoast's homepage fields and Rank Math's own homepage
  description, which do fall back safely) — verified against real Rank Math
  source, confirmed on a live install, and now refused with
  `ccc_home_title_clear_unsupported` instead of shipping a blank
  browser-tab title to a stranger's site.
- `tests/integration/homepage-checks.sh` (new, run by `run.sh` for every
  adapter): switches a real WordPress site to "your latest posts" mode,
  proves the resolve/apply/revert round-trip against the SEO plugin's own
  stored option (not just CCC's own read-back), the `manage_options`
  capability gate, the Rank Math clear-title guard, and the
  `ccc_home_unsupported` path for SEOPress/AIOSEO. 59 unit tests (was 37,
  +22), full 4-adapter integration suite still green.

## 0.3.0 — 2026-09-22

- AIOSEO adapter. Structurally different from the other three: AIOSEO 4.x
  stores title/description in a custom `wp_aioseo_posts` table (40+
  columns, several JSON-encoded), not postmeta. Verified against real
  AIOSEO 4.9 source before writing anything: the REST-controller wrapper
  (`PostSeoService`) is explicitly `@internal Not a public extension
  surface`, but `\AIOSEO\Plugin\Common\Models\Post::savePost()` (their own
  internal callers' write path, `@since 4.0.3`, not `@internal`) is
  patch-style — it only touches the keys you pass, filling in every other
  column's default when the row doesn't exist yet. Confirmed empty-string
  correctly falls back to AIOSEO's default title template (their renderer
  uses PHP's `empty()`, true for both `''` and `null`). Added a permanent
  integration-harness regression check
  (`tests/integration/aioseo-checks.sh`) that seeds a post with unrelated
  AIOSEO fields (social titles) via AIOSEO's own API, applies a title/
  description change through CCC's real REST route, and asserts the
  unrelated fields survive untouched — this is the one claim from reading
  the source that needed proving against a real write, not just reading.
- `tests/integration/run.sh` now builds an AIOSEO site too
  (`--adapter=aioseo`); 68/68 checks green (26 route + 42 security), no
  regressions on rankmath/yoast/seopress.

## 0.2.0 — 2026-09-22

- SEOPress adapter (`_seopress_titles_title` / `_seopress_titles_desc`),
  verified against SEOPress 10.2 source: same postmeta keys its own admin
  metabox saves to, empty value deletes the meta key exactly like Yoast and
  Rank Math. Detection via `SEOPRESS_VERSION`.
- `CCC_Adapter::label()` for the admin page's plugin-name display (was an
  inline Yoast/Rank Math ternary, now scales to any adapter).
- wordpress.org submission pack verified: `wp plugin check` via a new
  `tests/integration/plugin-check.sh` (production file set only — the
  integration harness's whole-repo symlink makes Plugin Check hang on
  vendor/'s dev tooling) — 0 errors, 0 warnings.
- Investigated AIOSEO (All in One SEO) for a third adapter: its title/
  description live in a custom `wp_aioseo_posts` table with 40+ JSON-encoded
  columns, and the only in-plugin write path is explicitly marked
  `@internal Not a public extension surface`. Needs its own integration
  harness before shipping, not a quick postmeta-style addition — left for a
  dedicated session (see BACKLOG.md).

## 0.1.1 — 2026-09-22

- Fix: `resolve_url()` now verifies `get_post()` before returning a resolved
  id — WordPress core's `url_to_postid()` pattern-matches `?p=N` out of the
  query string and returns `N` even when no such post exists, so `/resolve`
  was reporting a phantom post as successfully resolved.
- Real-WordPress integration test harness (`tests/integration/`): SQLite
  drop-in, Rank Math and Yoast, all five REST routes end-to-end.
- Security pass: auth sweep, subscriber/author/editor capability matrix,
  `/apply` + `/resolve` payload fuzzing. No vulnerabilities found
  (`SECURITY-NOTES.md`).
- PHPCS clean against WordPress-Extra + WordPress-Docs (`phpcs.xml.dist`).
- POT file for translators (`languages/crawl-cove-connector.pot`).
- wordpress.org submission pack: icon/banner/screenshot assets
  (`wordpress-org/`).

## 0.1.0 — 2026-09-21

First version.

- Yoast SEO and Rank Math adapters (writes their native title/description post meta; empty string removes the override).
- REST API under `crawlcove/v1`: `/status`, `/resolve`, `/apply` (with `dry_run`), `/changes`, `/revert`.
- Authentication via WordPress core Application Passwords; `edit_posts` required on every route, `edit_post` re-checked per post.
- Change log (capped at 200 entries) with previous values; revert from the API or from Tools → Crawl Cove.
- Touched posts are re-saved after apply so Yoast's indexables pick the change up immediately (filter `ccc_touch_post_after_apply` to disable).
- Unit tests for the adapter, change log and service layers.
