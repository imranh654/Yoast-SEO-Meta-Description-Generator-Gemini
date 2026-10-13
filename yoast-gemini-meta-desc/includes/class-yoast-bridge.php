<?php
/**
 * Yoast SEO Integration Bridge.
 *
 * Handles reading and writing Yoast SEO metadata and ensures compatibility with Yoast indexables.
 *
 * @package Yoast_Gemini_Meta_Desc
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Yoast_Gemini_Yoast_Bridge
 */
class Yoast_Gemini_Yoast_Bridge {

	/**
	 * Yoast meta description postmeta key.
	 */
	const META_DESC_KEY = '_yoast_wpseo_metadesc';

	/**
	 * Yoast primary focus keyword postmeta key.
	 */
	const FOCUS_KW_KEY = '_yoast_wpseo_focuskw';

	/**
	 * Yoast custom SEO title postmeta key.
	 */
	const TITLE_KEY = '_yoast_wpseo_title';

	/**
	 * Check if Yoast SEO is currently active.
	 *
	 * @return bool True if Yoast SEO is loaded and active.
	 */
	public static function is_yoast_active() {
		return defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Meta' );
	}

	/**
	 * Check if a post already has an explicit non-empty Yoast meta description.
	 *
	 * @param int $post_id The post ID.
	 * @return bool True if meta description already exists and is not empty.
	 */
	public static function has_meta_description( $post_id ) {
		$desc = get_post_meta( $post_id, self::META_DESC_KEY, true );

		if ( is_string( $desc ) && trim( $desc ) !== '' ) {
			return true;
		}

		return false;
	}

	/**
	 * Retrieve the Yoast meta description for a post.
	 *
	 * @param int $post_id The post ID.
	 * @return string The meta description or empty string.
	 */
	public static function get_meta_description( $post_id ) {
		$desc = get_post_meta( $post_id, self::META_DESC_KEY, true );
		return is_string( $desc ) ? trim( $desc ) : '';
	}

	/**
	 * Retrieve the Yoast focus keyword for a post.
	 *
	 * @param int $post_id The post ID.
	 * @return string The focus keyword or empty string.
	 */
	public static function get_focus_keyword( $post_id ) {
		$keyword = get_post_meta( $post_id, self::FOCUS_KW_KEY, true );
		return is_string( $keyword ) ? trim( $keyword ) : '';
	}

	/**
	 * Retrieve the Yoast SEO title for a post (or fallback to standard post title).
	 *
	 * @param int $post_id The post ID.
	 * @return string The title.
	 */
	public static function get_seo_title( $post_id ) {
		$title = get_post_meta( $post_id, self::TITLE_KEY, true );
		if ( is_string( $title ) && trim( $title ) !== '' ) {
			return trim( $title );
		}

		return get_the_title( $post_id );
	}

	/**
	 * Save the generated meta description to Yoast SEO.
	 *
	 * Updates the WordPress postmeta and triggers Yoast's native indexable synchronization.
	 *
	 * @param int    $post_id     The post ID.
	 * @param string $description The sanitized meta description.
	 * @return bool True on success, false on failure.
	 */
	public static function save_meta_description( $post_id, $description ) {
		$post_id = absint( $post_id );
		if ( ! $post_id || empty( $description ) ) {
			return false;
		}

		$clean_desc = sanitize_text_field( $description );

		// 1. If WPSEO_Meta class exists, use it to adhere to Yoast's internal pipeline.
		if ( class_exists( 'WPSEO_Meta' ) && method_exists( 'WPSEO_Meta', 'set_value' ) ) {
			$saved = WPSEO_Meta::set_value( 'metadesc', $clean_desc, $post_id );
		} else {
			// Direct postmeta update (compatible even if Yoast is temporarily initializing).
			$saved = update_post_meta( $post_id, self::META_DESC_KEY, wp_slash( $clean_desc ) );
		}

		// 2. Ensure postmeta is explicitly set if set_value returned false (e.g. if identical).
		if ( false === $saved ) {
			$current = get_post_meta( $post_id, self::META_DESC_KEY, true );
			if ( $current === $clean_desc ) {
				$saved = true;
			}
		}

		// 3. Optional: Trigger indexable builder directly if Yoast dependency container is loaded.
		self::sync_yoast_indexable( $post_id );

		return (bool) $saved;
	}

	/**
	 * Explicitly request Yoast's Indexable builder for the post if available.
	 * Note: Yoast's Indexable_Post_Meta_Watcher automatically catches update_post_meta,
	 * but this ensures immediate sync for AJAX responses without waiting for PHP shutdown.
	 *
	 * @param int $post_id The post ID.
	 * @return void
	 */
	protected static function sync_yoast_indexable( $post_id ) {
		if ( function_exists( 'YoastSEO' ) ) {
			try {
				$yoast = YoastSEO();
				if ( isset( $yoast->classes ) && method_exists( $yoast->classes, 'get' ) ) {
					$builder = $yoast->classes->get( 'Yoast\WP\SEO\Builders\Indexable_Builder' );
					$repo    = $yoast->classes->get( 'Yoast\WP\SEO\Repositories\Indexable_Repository' );
					if ( $builder && $repo && method_exists( $repo, 'find_by_id_and_type' ) && method_exists( $builder, 'build_for_id_and_type' ) ) {
						$indexable = $repo->find_by_id_and_type( $post_id, 'post' );
						if ( $indexable ) {
							$builder->build_for_id_and_type( $post_id, 'post', $indexable );
						}
					}
				}
			} catch ( Exception $e ) {
				// Silently allow shutdown hook to handle indexable sync if direct build fails.
			}
		}
	}
}
