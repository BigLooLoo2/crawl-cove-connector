# Changelog

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
