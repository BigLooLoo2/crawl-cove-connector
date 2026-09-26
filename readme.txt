=== Crawl Cove Connector ===
Contributors: crawlcove
Tags: seo, yoast, rank math, seopress, aioseo
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.8.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Push approved title/description fixes from Crawl Cove into Yoast, Rank Math, SEOPress or AIOSEO — with a change log and one-click revert.

== Description ==

Crawl Cove Connector closes the loop between an SEO crawl and your CMS. Instead of exporting a spreadsheet of title and meta description problems and fixing each post by hand, the [Crawl Cove](https://crawlcove.com) desktop crawler sends the fixes you approved straight to your site, and this plugin applies them to whichever SEO plugin you already use.

* Works with **Yoast SEO**, **Rank Math**, **SEOPress** and **AIOSEO** (writes their native fields — nothing is duplicated or overridden at render time).
* **You stay in control**: nothing is applied unless you approved it in the crawler, every change is logged with its previous value, and any change can be reverted with one click from Tools → Crawl Cove (or from the app).
* **Dry-run mode** shows exactly what would change before anything is written.
* Uses WordPress core **Application Passwords** for authentication — no extra accounts, no API keys stored by the plugin, revoke access any time from your profile.
* Per-post (and per-term) capability checks: a connected user can only change posts, pages or taxonomy terms they are allowed to edit.

The plugin is a small, auditable bridge (a few hundred lines, no external requests, no tracking, GPL). It exposes five REST routes under `crawlcove/v1`: status, resolve, apply, changes, revert.

== Installation ==

1. Install and activate the plugin.
2. Create an Application Password: Users → Profile → Application Passwords → "Crawl Cove".
3. In the Crawl Cove desktop app, open your site profile → WordPress and enter the site URL, username and application password.
4. Crawl, review the suggested fixes, push the approved ones. Review or revert them any time under Tools → Crawl Cove.

== Frequently Asked Questions ==

= Does it work without Yoast, Rank Math, SEOPress or AIOSEO? =

Not yet. The plugin writes the SEO title and meta description fields those plugins own. Support for further SEO plugins is planned; the /status endpoint reports what was detected.

= Can it change my content? =

No. It writes only the SEO title and meta description fields, only for changes you approved. Ordinary posts/pages are re-saved so your SEO plugin and any caching plugin pick up the new values immediately; the homepage and taxonomy archives use a best-effort cache purge instead, since neither has a post to re-save.

= Is this safe on a live site? =

Every write is capability-checked, validated, length-capped and logged with its previous value; reverting is one click. Authentication is core WordPress Application Passwords over HTTPS — this plugin relies on WordPress core and your host for login rate-limiting/brute-force protection rather than implementing its own; a login-throttling plugin is recommended if you don't already run one.

== Screenshots ==

1. Tools → Crawl Cove: connection status, the detected SEO plugin, setup steps, and the change log with one-click revert.

== Changelog ==

= 0.8.1 =
* Security/correctness fix: a taxonomy archive URL under a language-prefixed permalink (e.g. a multilingual plugin's `/fr/category/news/`, or in the worst case a bare `/fr/` itself) could resolve to the WRONG term — specifically, a rewrite rule belonging to an internal, non-public taxonomy that happens to share the same URL shape. Found and verified against a real Polylang install: its own internal "language" taxonomy (used for its language switcher, not real content) matched a French "your latest posts" homepage URL, silently writing an SEO fix to that internal term instead of the homepage — reported as a successful apply while the real page never changed. Both URL resolution and direct `post_id`-based apply/revert now require the matched taxonomy to be public, matching WordPress's own definition of "a real, browsable archive."

= 0.8.0 =
* Fix: an apply or revert to the homepage ("your latest posts" mode) or a taxonomy archive (category/tag/custom term) never told a page-caching plugin the page had changed — those targets write through an options/term-meta update with no post row for a caching plugin's usual save hook to fire against, so a pushed fix could sit behind a stale cached page for as long as the cache's lifetime. Now triggers a best-effort full-site purge for common caching plugins (WP Super Cache, W3 Total Cache, WP Rocket, WP Fastest Cache, LiteSpeed Cache) plus a plugin-agnostic action hook for anything else.
* Fix: reverting a change to an ORDINARY post never re-saved it, so a revert didn't rebuild the SEO plugin's indexable or fire the cache-purge hook a normal apply already does — reverts now go through the exact same invalidation path applies do.

= 0.7.0 =
* SEOPress taxonomy term title/description support, extending 0.6.0's per-term target to a third adapter. Its term storage turned out to be the simplest of the three: plain term meta under the exact same key names as its post-level fields, no shared-array read-modify-write risk. AIOSEO stays unsupported, and not just unresearched — its own source confirms per-term SEO fields are a Pro-only feature, absent entirely from the free plugin this connector supports.

= 0.6.0 =
* Taxonomy term title/description support: fix one category, tag or custom-taxonomy archive's title/description without affecting every other term in that taxonomy. Yoast and Rank Math only for now — SEOPress and AIOSEO report the change as unsupported rather than guessing at their storage.
* Fix: writing a Yoast term title/description could silently reset that term's OTHER Yoast fields (focus keyword, Open Graph/Twitter overrides, cornerstone flag) back to default — Yoast's own `WPSEO_Taxonomy_Meta::set_value()` helper isn't a true single-field patch for most fields. Now reads the term's full current settings first and writes them all back with only the intended field changed.

= 0.5.0 =
* SEOPress homepage title/description support, extending the "your latest posts" homepage target from 0.4.0 to a third adapter. AIOSEO stays unsupported — its "homepage" title/description turned out to read the same site-wide template used on every other page, so writing to it would change titles across the whole site, not just the homepage.

= 0.4.0 =
* Homepage title/description support for sites with no static front page set (Settings → Reading → "Your latest posts") — Yoast and Rank Math only for now; SEOPress and AIOSEO report the change as unsupported rather than silently doing nothing. A site with a static front page needs no change; that page's own title/description already worked exactly like any other page.
* Rank Math's homepage title cannot be cleared to an empty value (verified against real Rank Math source: unlike every other field this plugin writes, it has no template fallback at render time, so clearing it would leave a genuinely blank browser-tab title) — set a new title instead, or clear it from Rank Math's own settings.

= 0.3.0 =
* AIOSEO adapter: title/description live in a custom DB table for this plugin (not postmeta like the other three), written through AIOSEO's own `Post::savePost()` model method — verified against AIOSEO 4.9 source that this only touches the columns given, and confirmed against a real install that it doesn't reset a post's other AIOSEO settings (social titles, etc).

= 0.2.0 =
* SEOPress adapter: writes `_seopress_titles_title` / `_seopress_titles_desc`, the same postmeta SEOPress's own admin metabox saves to and deletes on empty (verified against SEOPress 10.2 source).

= 0.1.1 =
* Fix: `/resolve` could report a nonexistent post as successfully resolved for a numeric URL like `?p=999` — WordPress core's `url_to_postid()` returns that id even with no matching post; now verified before returning.
* Real-WordPress integration test harness (Rank Math and Yoast, SQLite, all five REST routes) and a security pass (auth, capability matrix, payload fuzzing) — no vulnerabilities found.
* PHPCS clean against WordPress-Extra + WordPress-Docs; POT file for translators.

= 0.1.0 =
* First release: Yoast SEO and Rank Math adapters, REST API (status/resolve/apply/changes/revert), dry-run, change log with revert, admin page under Tools.
