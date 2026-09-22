#!/usr/bin/env bash
#
# AIOSEO-only regression check: title/description live in a custom DB table
# (wp_aioseo_posts, 40+ columns) written via AIOSEO's own
# \AIOSEO\Plugin\Common\Models\Post::savePost(), not simple postmeta like the
# other three adapters — so unlike those, a bug here could silently reset a
# site owner's OTHER AIOSEO settings on a post (social titles, robots flags,
# etc.) while "successfully" writing the title/description CCC was asked to
# change. Proves that doesn't happen: seeds three unrelated fields directly
# via AIOSEO's own API (simulating a post already configured before CCC ever
# touched it), applies a title/description change through the real REST
# route, then reads the row back and asserts the seeded fields survived.
#
# Run by run.sh only when --adapter=aioseo. Requires CCC_SITE/CCC_CACHE (the
# WP install + wp-cli.phar paths) in addition to the usual CCC_* vars.

set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$HERE/lib.sh"

: "${CCC_SITE:?}" "${CCC_CACHE:?}"
WPCLI=(php "$CCC_CACHE/wp-cli.phar")

SEED_FILE="$(mktemp)"
trap 'rm -f "$SEED_FILE"' EXIT

cat > "$SEED_FILE" <<PHP
<?php
\AIOSEO\Plugin\Common\Models\Post::savePost( $CCC_POST_EDITOR, array(
	'title'           => 'Pre-existing AIOSEO Title',
	'description'     => 'Pre-existing AIOSEO Description',
	'og_title'        => 'Pre-existing OG Title',
	'og_description'  => 'Pre-existing OG Description',
	'twitter_title'   => 'Pre-existing Twitter Title',
) );
PHP
"${WPCLI[@]}" eval-file "$SEED_FILE" --path="$CCC_SITE" --quiet

echo "-- aioseo: writing title/description must not touch unrelated fields --"
req POST /apply "$EDITOR" "{\"changes\":[{\"post_id\":$CCC_POST_EDITOR,\"title\":\"New Title From Crawl Cove\",\"description\":\"New Description From Crawl Cove\"}]}"
check "apply: aioseo write ok" 200 "$RESP_HTTP" '.[0].ok' 'true' "$RESP_BODY"

READBACK="$("${WPCLI[@]}" eval "
\$p = \AIOSEO\Plugin\Common\Models\Post::getPost( $CCC_POST_EDITOR );
echo json_encode( array(
	'title'          => \$p->title,
	'description'    => \$p->description,
	'og_title'       => \$p->og_title,
	'og_description' => \$p->og_description,
	'twitter_title'  => \$p->twitter_title,
) );
" --path="$CCC_SITE")"

check "title updated" 200 200 '.title' 'New Title From Crawl Cove' "$READBACK"
check "description updated" 200 200 '.description' 'New Description From Crawl Cove' "$READBACK"
check "og_title untouched" 200 200 '.og_title' 'Pre-existing OG Title' "$READBACK"
check "og_description untouched" 200 200 '.og_description' 'Pre-existing OG Description' "$READBACK"
check "twitter_title untouched" 200 200 '.twitter_title' 'Pre-existing Twitter Title' "$READBACK"

summary
