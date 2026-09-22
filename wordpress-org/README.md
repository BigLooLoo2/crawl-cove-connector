Submission-pack graphics for the wordpress.org plugin directory listing —
not shipped with the plugin itself, not loaded by any PHP here. These exist
because the actual upload target (SVN `assets/`) is separate from this git
repo and gated behind a human-only account (see BACKLOG.md).

- `icon-128x128.png`, `icon-256x256.png` — plugin icon.
- `banner-772x250.png`, `banner-1544x500.png` (retina) — directory header banner.
- `screenshot-1.png` — Tools → Crawl Cove admin page with sample data,
  captured against the real-WP integration harness (see readme.txt's
  `== Screenshots ==` section for the caption that must stay in step).
- `make-assets.php` — regenerates the icon/banner PNGs (PHP GD, run directly:
  `php wordpress-org/make-assets.php`, writes back into this folder).

At submission time these get copied into the SVN `assets/` directory
(trunk/tags stay separate) — that step is part of the wp-release approval,
not this repo.
