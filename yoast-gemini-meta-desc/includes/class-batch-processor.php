<?php
/**
 * AJAX Batch Processor.
 *
 * Handles eligibility queries, asynchronous processing of up to 20 posts per batch,
 * and reporting real-time statistics.
 *
 * @package Yoast_Gemini_Meta_Desc
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Yoast_Gemini_Batch_Processor
 */
class Yoast_Gemini_Batch_Processor {

	/**
	 * Maximum posts permitted in a single batch click.
	 */
	const MAX_BATCH_SIZE = 20;

	/**
	 * Nonce action string.
	 */
	const NONCE_ACTION = 'yoast_gemini_meta_nonce';

	/**
	 * Initialize AJAX hooks.
	 */
	public function __construct() {
		add_action( 'wp_ajax_yoast_gemini_get_stats', [ $this, 'ajax_get_stats' ] );
		add_action( 'wp_ajax_yoast_gemini_get_batch_queue', [ $this, 'ajax_get_batch_queue' ] );
		add_action( 'wp_ajax_yoast_gemini_process_single_post', [ $this, 'ajax_process_single_post' ] );
		add_action( 'wp_ajax_yoast_gemini_save_settings', [ $this, 'ajax_save_settings' ] );
		add_action( 'wp_ajax_yoast_gemini_test_api', [ $this, 'ajax_test_api' ] );
	}

	/**
	 * Verify admin capability and security nonce.
	 */
	protected function verify_request() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied. Administrator access required.', 'yoast-gemini-meta-desc' ) ], 403 );
		}

		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( [ 'message' => __( 'Security verification failed (invalid nonce). Please refresh the page.', 'yoast-gemini-meta-desc' ) ], 403 );
		}
	}

	/**
	 * AJAX Handler: Save plugin settings.
	 */
	public function ajax_save_settings() {
		$this->verify_request();

		$api_key       = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';
		$model         = isset( $_POST['model'] ) ? sanitize_text_field( wp_unslash( $_POST['model'] ) ) : Yoast_Gemini_Client::DEFAULT_MODEL;
		$clear_api_key = ! empty( $_POST['clear_api_key'] );

		Yoast_Gemini_Client::update_options(
			[
				'api_key'       => $api_key,
				'model'         => $model,
				'clear_api_key' => $clear_api_key,
			]
		);

		$options = Yoast_Gemini_Client::get_options();
		$has_key = ! empty( $options['api_key'] );

		$message = __( 'Settings saved successfully.', 'yoast-gemini-meta-desc' );
		if ( $clear_api_key ) {
			$message = __( 'Gemini API key has been removed.', 'yoast-gemini-meta-desc' );
		} elseif ( $has_key ) {
			$message = __( 'Settings saved successfully. Gemini API key is configured and active.', 'yoast-gemini-meta-desc' );
		}

		wp_send_json_success(
			[
				'message'    => $message,
				'has_key'    => $has_key,
				'masked_key' => Yoast_Gemini_Client::mask_api_key( $options['api_key'] ),
				'model'      => $options['model'],
			]
		);
	}

	/**
	 * AJAX Handler: Test Google Gemini API connection.
	 */
	public function ajax_test_api() {
		$this->verify_request();

		$api_key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';
		$model   = isset( $_POST['model'] ) ? sanitize_text_field( wp_unslash( $_POST['model'] ) ) : null;

		// If input is empty or has mask symbols, use saved key.
		if ( empty( $api_key ) || false !== strpos( $api_key, '•' ) ) {
			$api_key = null;
		}

		$result = Yoast_Gemini_Client::test_connection( $api_key, $model );

		if ( $result['success'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
		}
	}

	/**
	 * AJAX Handler: Get stats for the selected post type.
	 */
	public function ajax_get_stats() {
		$this->verify_request();

		$post_type = isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : 'post';
		$stats     = self::get_post_type_stats( $post_type );

		wp_send_json_success( $stats );
	}

	/**
	 * Compute post type statistics (total, completed, eligible/remaining).
	 *
	 * @param string $post_type The post type.
	 * @return array Array with total, completed, eligible, and remaining counts.
	 */
	public static function get_post_type_stats( $post_type ) {
		global $wpdb;

		$post_type = sanitize_key( $post_type );

		// 1. Total published posts of this post_type.
		$total_query = $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'",
			$post_type
		);
		$total = (int) $wpdb->get_var( $total_query );

		// 2. Posts that already have a non-empty _yoast_wpseo_metadesc.
		$completed_query = $wpdb->prepare(
			"SELECT COUNT(DISTINCT p.ID) 
			 FROM {$wpdb->posts} p 
			 INNER JOIN {$wpdb->postmeta} pm ON (p.ID = pm.post_id AND pm.meta_key = %s)
			 WHERE p.post_type = %s 
			   AND p.post_status = 'publish' 
			   AND TRIM(pm.meta_value) != ''",
			Yoast_Gemini_Yoast_Bridge::META_DESC_KEY,
			$post_type
		);
		$completed = (int) $wpdb->get_var( $completed_query );

		$eligible = max( 0, $total - $completed );

		return [
			'post_type'  => $post_type,
			'total'      => $total,
			'completed'  => $completed,
			'eligible'   => $eligible,
			'remaining'  => $eligible,
		];
	}

	/**
	 * AJAX Handler: Retrieve the next batch queue of eligible posts (max 20).
	 */
	public function ajax_get_batch_queue() {
		$this->verify_request();

		$post_type = isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : 'post';

		// Verify API key is configured before queuing.
		$options = Yoast_Gemini_Client::get_options();
		if ( empty( $options['api_key'] ) ) {
			wp_send_json_error(
				[
					'message' => __( 'Please enter and save your Google Gemini API key before generating meta descriptions.', 'yoast-gemini-meta-desc' ),
				]
			);
		}

		$queue = self::get_eligible_post_ids( $post_type, self::MAX_BATCH_SIZE );

		wp_send_json_success(
			[
				'post_type'  => $post_type,
				'count'      => count( $queue ),
				'queue'      => $queue,
				'batch_size' => self::MAX_BATCH_SIZE,
			]
		);
	}

	/**
	 * Retrieve up to $limit eligible post IDs lacking a Yoast meta description.
	 *
	 * @param string $post_type Post type slug.
	 * @param int    $limit     Maximum posts to fetch (hard-capped at 20).
	 * @return array Array of associative arrays: [ ['id' => 123, 'title' => 'Sample'], ... ].
	 */
	public static function get_eligible_post_ids( $post_type, $limit = 20 ) {
		global $wpdb;

		$post_type  = sanitize_key( $post_type );
		$safe_limit = min( self::MAX_BATCH_SIZE, max( 1, absint( $limit ) ) );

		$query = $wpdb->prepare(
			"SELECT p.ID, p.post_title 
			 FROM {$wpdb->posts} p 
			 LEFT JOIN {$wpdb->postmeta} pm ON (p.ID = pm.post_id AND pm.meta_key = %s)
			 WHERE p.post_type = %s 
			   AND p.post_status = 'publish' 
			   AND (pm.meta_value IS NULL OR TRIM(pm.meta_value) = '')
			 ORDER BY p.ID DESC 
			 LIMIT %d",
			Yoast_Gemini_Yoast_Bridge::META_DESC_KEY,
			$post_type,
			$safe_limit
		);

		$rows = $wpdb->get_results( $query, ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return [];
		}

		$queue = [];
		foreach ( $rows as $row ) {
			$title = ! empty( $row['post_title'] ) ? $row['post_title'] : sprintf( __( '(Untitled #%d)', 'yoast-gemini-meta-desc' ), (int) $row['ID'] );
			$queue[] = [
				'id'    => (int) $row['ID'],
				'title' => esc_html( $title ),
			];
		}

		return $queue;
	}

	/**
	 * AJAX Handler: Process a single post from the batch.
	 */
	public function ajax_process_single_post() {
		$this->verify_request();

		$post_id   = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$post_type = isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : '';

		if ( ! $post_id ) {
			wp_send_json_error( [ 'message' => __( 'Missing or invalid Post ID.', 'yoast-gemini-meta-desc' ) ] );
		}

		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			wp_send_json_error(
				[
					'post_id' => $post_id,
					'title'   => $post ? $post->post_title : "#{$post_id}",
					'message' => __( 'Post not found or is not published.', 'yoast-gemini-meta-desc' ),
				]
			);
		}

		if ( ! empty( $post_type ) && $post->post_type !== $post_type ) {
			wp_send_json_error(
				[
					'post_id' => $post_id,
					'title'   => $post->post_title,
					'message' => __( 'Post type does not match requested batch.', 'yoast-gemini-meta-desc' ),
				]
			);
		}

		// Safety check: Do not overwrite existing meta descriptions.
		if ( Yoast_Gemini_Yoast_Bridge::has_meta_description( $post_id ) ) {
			wp_send_json_success(
				[
					'post_id'     => $post_id,
					'title'       => $post->post_title,
					'skipped'     => true,
					'description' => Yoast_Gemini_Yoast_Bridge::get_meta_description( $post_id ),
					'length'      => mb_strlen( Yoast_Gemini_Yoast_Bridge::get_meta_description( $post_id ), 'UTF-8' ),
					'message'     => __( 'Skipped: already has an existing Yoast meta description.', 'yoast-gemini-meta-desc' ),
				]
			);
		}

		// Generate meta description with Gemini (includes length validation and retries).
		$generation = Yoast_Gemini_Client::generate_meta_description( $post );

		if ( ! $generation['success'] ) {
			wp_send_json_error(
				[
					'post_id' => $post_id,
					'title'   => $post->post_title,
					'message' => $generation['error'],
				]
			);
		}

		// Save generated description to Yoast SEO.
		$saved = Yoast_Gemini_Yoast_Bridge::save_meta_description( $post_id, $generation['description'] );

		if ( ! $saved ) {
			wp_send_json_error(
				[
					'post_id' => $post_id,
					'title'   => $post->post_title,
					'message' => __( 'Failed to save meta description to Yoast SEO metadata.', 'yoast-gemini-meta-desc' ),
				]
			);
		}

		wp_send_json_success(
			[
				'post_id'     => $post_id,
				'title'       => $post->post_title,
				'description' => $generation['description'],
				'length'      => $generation['length'],
				'message'     => __( 'Description generated and saved successfully.', 'yoast-gemini-meta-desc' ),
			]
		);
	}
}
