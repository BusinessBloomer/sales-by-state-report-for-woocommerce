<?php
// Run via: wp eval-file sync-docs.php --path=<remote WP path>
// Expects docs/*.md and docs/screenshots/*.jpg to already be copied to the
// same directory as this script.
//
// Matches existing posts by slug (derived from the filename) and updates
// them in place, so re-running this never creates duplicates. Posts are
// always written as drafts - publishing is a manual decision made in
// WordPress, not something this script does.

require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

$docs_dir = __DIR__ . '/docs';
$shots_dir = $docs_dir . '/screenshots';

$term = get_category_by_slug( 'documentation' );
if ( ! $term ) {
	WP_CLI::error( 'documentation category not found - create it first.' );
}
$cat_id = $term->term_id;

function sbsdocs_slug_from_filename( $filename ) {
	return preg_replace( '/^\d+-/', '', preg_replace( '/\.md$/', '', $filename ) );
}

function sbsdocs_md_to_blocks( $md ) {
	$lines = explode( "\n", $md );
	$title = '';
	$blocks = [];
	$para = [];
	$screenshot_file = null;

	$flush = function () use ( &$para, &$blocks ) {
		if ( ! empty( $para ) ) {
			$text = trim( implode( ' ', $para ) );
			if ( $text !== '' ) {
				$blocks[] = "<!-- wp:paragraph -->\n<p>" . $text . "</p>\n<!-- /wp:paragraph -->";
			}
			$para = [];
		}
	};

	foreach ( $lines as $line ) {
		$line = rtrim( $line );
		if ( preg_match( '/^# (.+)/', $line, $m ) ) {
			$title = trim( $m[1] );
		} elseif ( preg_match( '/^## (.+)/', $line, $m ) ) {
			$flush();
			$blocks[] = "<!-- wp:heading -->\n<h2>" . trim( $m[1] ) . "</h2>\n<!-- /wp:heading -->";
		} elseif ( preg_match( '/^!\[screenshot\]\(([^)]+)\)/', $line, $m ) ) {
			$flush();
			$screenshot_file = trim( $m[1] );
		} elseif ( trim( $line ) === '' ) {
			$flush();
		} else {
			$para[] = trim( $line );
		}
	}
	$flush();

	return [ $title, implode( "\n\n", $blocks ), $screenshot_file ];
}

function sbsdocs_upload_image( $path, $alt ) {
	$filetype = wp_check_filetype( basename( $path ), null );
	$upload_dir = wp_upload_dir();
	$filename = wp_unique_filename( $upload_dir['path'], basename( $path ) );
	$dest = $upload_dir['path'] . '/' . $filename;
	copy( $path, $dest );
	$attach_id = wp_insert_attachment( [
		'post_mime_type' => $filetype['type'],
		'post_title'      => $alt,
		'post_content'    => '',
		'post_status'     => 'inherit',
	], $dest );
	$attach_data = wp_generate_attachment_metadata( $attach_id, $dest );
	wp_update_attachment_metadata( $attach_id, $attach_data );
	update_post_meta( $attach_id, '_wp_attachment_image_alt', $alt );
	return $attach_id;
}

$files = array_values( array_filter( scandir( $docs_dir ), function ( $f ) {
	return preg_match( '/\.md$/', $f );
} ) );
sort( $files );

$results = [];

foreach ( $files as $file ) {
	$slug = sbsdocs_slug_from_filename( $file );
	$md = file_get_contents( $docs_dir . '/' . $file );
	list( $title, $content, $screenshot_file ) = sbsdocs_md_to_blocks( $md );

	$attach_id = null;
	if ( $screenshot_file ) {
		$image_path = $shots_dir . '/' . $screenshot_file;
		if ( file_exists( $image_path ) ) {
			$attach_id = sbsdocs_upload_image( $image_path, $title . ' screenshot' );
		}
	}

	// get_page_by_path() doesn't reliably match drafts, and post_status =>
	// 'any' silently excludes drafts too ('draft' is registered with
	// exclude_from_search, which is what 'any' actually filters on) - an
	// explicit status list is the only way to really match everything.
	$matches = get_posts( [
		'name'           => $slug,
		'post_type'      => 'post',
		'post_status'    => [ 'publish', 'draft', 'pending', 'future', 'private' ],
		'posts_per_page' => 1,
	] );
	$existing = $matches ? $matches[0] : null;

	$postarr = [
		'post_title'    => $title,
		'post_content'  => $content,
		'post_type'     => 'post',
		'post_category' => [ $cat_id ],
	];

	if ( $existing ) {
		$postarr['ID'] = $existing->ID;
		$post_id = wp_update_post( $postarr );
		$results[] = "updated: $slug (post $post_id)";
	} else {
		$postarr['post_name'] = $slug;
		$postarr['post_status'] = 'draft';
		$post_id = wp_insert_post( $postarr );
		$results[] = "created: $slug (post $post_id)";
	}

	if ( $attach_id && ! is_wp_error( $post_id ) ) {
		set_post_thumbnail( $post_id, $attach_id );
	}
}

WP_CLI::success( implode( ' | ', $results ) );
