# Crawl Cove Connector

WordPress companion plugin for the [Crawl Cove](https://crawlcove.com) desktop SEO crawler: push approved title and meta description fixes straight into **Yoast SEO**, **Rank Math** or **SEOPress**, with a full change log and one-click revert.

Crawl → review → push live. No CSV exports, no copy-pasting into the post editor.

## How it works

The plugin registers a small REST API under `crawlcove/v1` (auth: WordPress core Application Passwords):

| Route | Method | Purpose |
|---|---|---|
| `/status` | GET | Plugin + detected SEO plugin info |
| `/resolve` | POST | Map crawled URLs to posts, return current title/description |
| `/apply` | POST | Apply a batch of reviewed fixes (`dry_run` supported) |
| `/changes` | GET | The change log |
| `/revert` | POST | Restore a change's previous value |

Safety model:

- Nothing is written that wasn't sent by an authenticated user with `edit_post` capability for that specific post.
- Values are sanitized and length-capped; batches are capped at 50 changes.
- Every write records the previous value; revert from the app or from **Tools → Crawl Cove**.
- Empty string means "remove the override, fall back to the SEO plugin's template".
- No external requests, no tracking, no data leaves the site.

## Development

```
composer install
composer test     # PHPUnit against lightweight WP stubs (tests/bootstrap.php)
composer lint     # php -l over all plugin files
```

The core logic (`CCC_Service`, `CCC_Adapter`, `CCC_Change_Log`) is deliberately free of `WP_REST_*` types so it unit-tests without a WordPress install. Integration smoke-testing against a real WordPress runs before each release.

## Releasing

Versions are tagged `vX.Y.Z` on `main`. Releases (and the WordPress.org submission) go through the CrawlCove approvals queue — see the ops repo.

License: GPLv2 or later.
