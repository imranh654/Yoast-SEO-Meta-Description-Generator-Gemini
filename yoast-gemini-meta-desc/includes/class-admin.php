<?php
/**
 * Admin Interface & Menu Handler.
 *
 * Renders the dashboard page, controls settings, and enqueues admin scripts/styles.
 *
 * @package Yoast_Gemini_Meta_Desc
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Yoast_Gemini_Admin
 */
class Yoast_Gemini_Admin {

	/**
	 * Admin page slug.
	 */
	const PAGE_SLUG = 'yoast-gemini-meta-desc';

	/**
	 * Initialize hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register_admin_menu' ], 99 );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	/**
	 * Register admin menu under Yoast SEO or top-level fallback.
	 */
	public function register_admin_menu() {
		$page_title = __( 'Yoast SEO Meta Description Generator (Gemini)', 'yoast-gemini-meta-desc' );
		$menu_title = __( 'Gemini Meta Generator', 'yoast-gemini-meta-desc' );

		if ( Yoast_Gemini_Yoast_Bridge::is_yoast_active() ) {
			add_submenu_page(
				'wpseo_dashboard',
				$page_title,
				$menu_title,
				'manage_options',
				self::PAGE_SLUG,
				[ $this, 'render_admin_page' ]
			);
		} else {
			add_menu_page(
				$page_title,
				$menu_title,
				'manage_options',
				self::PAGE_SLUG,
				[ $this, 'render_admin_page' ],
				'dashicons-superhero',
				99
			);
		}
	}

	/**
	 * Enqueue admin scripts and stylesheets on the plugin admin page.
	 *
	 * @param string $hook The current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		// Only enqueue on our specific addon page.
		if ( false === strpos( $hook, self::PAGE_SLUG ) ) {
			return;
		}

		wp_enqueue_style(
			'yoast-gemini-admin-css',
			YOAST_GEMINI_META_URL . 'assets/css/admin.css',
			[],
			YOAST_GEMINI_META_VERSION
		);

		wp_enqueue_script(
			'yoast-gemini-admin-js',
			YOAST_GEMINI_META_URL . 'assets/js/admin.js',
			[ 'jquery' ],
			YOAST_GEMINI_META_VERSION,
			true
		);

		$options = Yoast_Gemini_Client::get_options();

		wp_localize_script(
			'yoast-gemini-admin-js',
			'yoastGeminiData',
			[
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( Yoast_Gemini_Batch_Processor::NONCE_ACTION ),
				'maxBatchSize' => Yoast_Gemini_Batch_Processor::MAX_BATCH_SIZE,
				'hasApiKey'    => ! empty( $options['api_key'] ),
				'strings'      => [
					'confirmStart'    => __( 'Start generating meta descriptions for the next batch (up to 20 posts)?', 'yoast-gemini-meta-desc' ),
					'batchStarting'   => __( 'Preparing batch queue (up to 20 posts)...', 'yoast-gemini-meta-desc' ),
					'processingItem'  => __( 'Processing %1$d of %2$d: "%3$s"...', 'yoast-gemini-meta-desc' ),
					'batchComplete'   => __( 'Batch complete! %1$d of %2$d processed successfully.', 'yoast-gemini-meta-desc' ),
					'allDone'         => __( 'All eligible posts in this post type now have Yoast meta descriptions!', 'yoast-gemini-meta-desc' ),
					'noEligible'      => __( 'No eligible posts found. All published posts in this post type already have meta descriptions.', 'yoast-gemini-meta-desc' ),
					'savingSettings'  => __( 'Saving settings...', 'yoast-gemini-meta-desc' ),
					'testingApi'      => __( 'Connecting to Google Gemini API...', 'yoast-gemini-meta-desc' ),
					'chars'           => __( 'chars', 'yoast-gemini-meta-desc' ),
					'success'         => __( 'Success', 'yoast-gemini-meta-desc' ),
					'failed'          => __( 'Failed', 'yoast-gemini-meta-desc' ),
					'skipped'         => __( 'Skipped', 'yoast-gemini-meta-desc' ),
					'keyMissingError' => __( 'Please enter and save your Gemini API key first.', 'yoast-gemini-meta-desc' ),
				],
			]
		);
	}

	/**
	 * Retrieve eligible public post types for selection.
	 *
	 * @return array Array of post type slug => WP_Post_Type object.
	 */
	public static function get_eligible_post_types() {
		$post_types = get_post_types( [ 'public' => true ], 'objects' );

		// Strictly exclude non-content/internal types.
		unset( $post_types['attachment'] );
		unset( $post_types['revision'] );
		unset( $post_types['nav_menu_item'] );
		unset( $post_types['custom_css'] );
		unset( $post_types['customize_changeset'] );

		return $post_types;
	}

	/**
	 * Render the complete addon dashboard page.
	 */
	public function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'yoast-gemini-meta-desc' ) );
		}

		// Handle standard (non-AJAX) form POST fallback.
		if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['yoast_gemini_settings_nonce'] ) ) {
			if ( wp_verify_nonce( sanitize_key( $_POST['yoast_gemini_settings_nonce'] ), 'yoast_gemini_save_settings_action' ) ) {
				$posted_key   = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';
				$posted_model = isset( $_POST['model'] ) ? sanitize_text_field( wp_unslash( $_POST['model'] ) ) : Yoast_Gemini_Client::DEFAULT_MODEL;
				$clear_key    = ! empty( $_POST['clear_api_key'] );

				Yoast_Gemini_Client::update_options(
					[
						'api_key'       => $posted_key,
						'model'         => $posted_model,
						'clear_api_key' => $clear_key,
					]
				);

				echo '<div class="notice notice-success is-dismissible yoast-gemini-notice"><p><strong>' . esc_html__( 'Settings saved successfully.', 'yoast-gemini-meta-desc' ) . '</strong></p></div>';
			}
		}

		$options          = Yoast_Gemini_Client::get_options();
		$masked_key       = Yoast_Gemini_Client::mask_api_key( $options['api_key'] );
		$available_models = Yoast_Gemini_Client::get_available_models();
		$post_types       = self::get_eligible_post_types();
		$is_yoast_active  = Yoast_Gemini_Yoast_Bridge::is_yoast_active();
		?>
		<div class="wrap yoast-gemini-wrap">
			<div class="yoast-gemini-header">
				<div class="yoast-gemini-header-inner">
					<h1>
						<span class="dashicons dashicons-superhero"></span>
						<?php esc_html_e( 'Yoast SEO Meta Description Generator', 'yoast-gemini-meta-desc' ); ?>
						<span class="badge badge-gemini"><?php esc_html_e( 'Powered by Gemini AI', 'yoast-gemini-meta-desc' ); ?></span>
					</h1>
					<p class="yoast-gemini-tagline">
						<?php esc_html_e( 'Automatically generate accurate, high-CTR meta descriptions (120–158 characters) for your WordPress content and sync directly into Yoast SEO.', 'yoast-gemini-meta-desc' ); ?>
					</p>
				</div>
			</div>

			<?php if ( ! $is_yoast_active ) : ?>
				<div class="notice notice-warning inline yoast-gemini-notice">
					<p>
						<strong><?php esc_html_e( 'Yoast SEO is currently inactive or not installed.', 'yoast-gemini-meta-desc' ); ?></strong>
						<?php esc_html_e( 'This addon requires Yoast SEO to store metadata into the _yoast_wpseo_metadesc field and keep Yoast Indexables synchronized.', 'yoast-gemini-meta-desc' ); ?>
					</p>
				</div>
			<?php endif; ?>

			<div class="yoast-gemini-grid">
				<!-- Left / Top Column: Configuration Card -->
				<div class="yoast-gemini-card yoast-gemini-card-config">
					<div class="card-header">
						<h2>
							<span class="dashicons dashicons-admin-generic"></span>
							<?php esc_html_e( '1. Google Gemini API Configuration', 'yoast-gemini-meta-desc' ); ?>
						</h2>
					</div>
					<div class="card-body">
						<form id="yoast-gemini-settings-form" method="post" action="">
							<?php wp_nonce_field( 'yoast_gemini_save_settings_action', 'yoast_gemini_settings_nonce' ); ?>
							<div class="form-group">
								<label for="gemini-api-key">
									<?php esc_html_e( 'Google Gemini API Key', 'yoast-gemini-meta-desc' ); ?>
									<span class="required">*</span>
								</label>
								<div class="api-key-input-wrapper">
									<input 
										type="password" 
										id="gemini-api-key" 
										name="api_key" 
										class="regular-text" 
										placeholder="<?php echo esc_attr( ! empty( $masked_key ) ? $masked_key : __( 'Enter your Gemini API key (AIzaSy...)', 'yoast-gemini-meta-desc' ) ); ?>" 
										autocomplete="off"
									/>
									<button type="button" class="button button-secondary" id="toggle-key-visibility" title="<?php esc_attr_e( 'Toggle Key Visibility', 'yoast-gemini-meta-desc' ); ?>">
										<span class="dashicons dashicons-visibility"></span>
									</button>
								</div>
								<p class="description">
									<?php
									printf(
										/* translators: %s: Link to Google AI Studio */
										esc_html__( 'Get your free Gemini API key from %s.', 'yoast-gemini-meta-desc' ),
										'<a href="https://aistudio.google.com/app/apikey" target="_blank" rel="noopener noreferrer">Google AI Studio <span class="dashicons dashicons-external"></span></a>'
									);
									?>
									<span class="key-status-container">
										<?php if ( ! empty( $masked_key ) ) : ?>
											<span class="key-saved-notice">
												<span class="dashicons dashicons-yes-alt"></span>
												<?php printf( esc_html__( 'Key configured: %s', 'yoast-gemini-meta-desc' ), esc_html( $masked_key ) ); ?>
												<button type="button" class="button-link" id="btn-clear-key" style="margin-left: 8px; color: #d63638; text-decoration: underline; font-size: 12px; cursor: pointer;">
													<?php esc_html_e( '(Remove Key)', 'yoast-gemini-meta-desc' ); ?>
												</button>
											</span>
										<?php endif; ?>
									</span>
								</p>
							</div>

							<div class="form-group">
								<label for="gemini-model">
									<?php esc_html_e( 'Gemini Model', 'yoast-gemini-meta-desc' ); ?>
								</label>
								<select id="gemini-model" name="model" class="regular-text">
									<?php foreach ( $available_models as $model_slug => $model_label ) : ?>
										<option value="<?php echo esc_attr( $model_slug ); ?>" <?php selected( $options['model'], $model_slug ); ?>>
											<?php echo esc_html( $model_label ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description">
									<?php esc_html_e( 'Gemini 1.5 Flash provides optimal speed and high adherence to strict character length constraints.', 'yoast-gemini-meta-desc' ); ?>
								</p>
							</div>

							<div class="form-actions">
								<button type="submit" class="button button-primary" id="btn-save-settings">
									<span class="dashicons dashicons-saved"></span>
									<?php esc_html_e( 'Save Settings', 'yoast-gemini-meta-desc' ); ?>
								</button>
								<button type="button" class="button button-secondary" id="btn-test-api">
									<span class="dashicons dashicons-admin-plugins"></span>
									<?php esc_html_e( 'Test API Connection', 'yoast-gemini-meta-desc' ); ?>
								</button>
								<span class="spinner" id="settings-spinner"></span>
							</div>

							<div id="settings-feedback" class="yoast-gemini-feedback" style="display: none;"></div>
						</form>
					</div>
				</div>

				<!-- Right / Bottom Column: Batch Generator Card -->
				<div class="yoast-gemini-card yoast-gemini-card-generator">
					<div class="card-header">
						<h2>
							<span class="dashicons dashicons-update"></span>
							<?php esc_html_e( '2. Batch Meta Description Generator', 'yoast-gemini-meta-desc' ); ?>
						</h2>
					</div>
					<div class="card-body">
						<div class="generator-controls">
							<div class="post-type-selector-wrap">
								<label for="select-post-type">
									<strong><?php esc_html_e( 'Select Post Type:', 'yoast-gemini-meta-desc' ); ?></strong>
								</label>
								<select id="select-post-type">
									<?php foreach ( $post_types as $pt_slug => $pt_obj ) : ?>
										<option value="<?php echo esc_attr( $pt_slug ); ?>">
											<?php echo esc_html( $pt_obj->labels->name ); ?> (<?php echo esc_html( $pt_slug ); ?>)
										</option>
									<?php endforeach; ?>
								</select>
								<span class="spinner" id="stats-spinner"></span>
							</div>

							<!-- Stats Counters Grid -->
							<div class="stats-grid">
								<div class="stat-card stat-total">
									<span class="stat-value" id="stat-total-count">-</span>
									<span class="stat-label"><?php esc_html_e( 'Total Published', 'yoast-gemini-meta-desc' ); ?></span>
								</div>
								<div class="stat-card stat-completed">
									<span class="stat-value" id="stat-completed-count">-</span>
									<span class="stat-label"><?php esc_html_e( 'Have Description', 'yoast-gemini-meta-desc' ); ?></span>
								</div>
								<div class="stat-card stat-eligible">
									<span class="stat-value" id="stat-eligible-count">-</span>
									<span class="stat-label"><?php esc_html_e( 'Missing Description (Eligible)', 'yoast-gemini-meta-desc' ); ?></span>
								</div>
								<div class="stat-card stat-remaining">
									<span class="stat-value" id="stat-remaining-count">-</span>
									<span class="stat-label"><?php esc_html_e( 'Remaining to Process', 'yoast-gemini-meta-desc' ); ?></span>
								</div>
							</div>

							<div class="batch-action-bar">
								<button type="button" class="button button-primary button-hero" id="btn-start-batch">
									<span class="dashicons dashicons-controls-play"></span>
									<?php esc_html_e( 'Generate Meta Descriptions', 'yoast-gemini-meta-desc' ); ?>
								</button>
								<p class="batch-notice-text">
									<span class="dashicons dashicons-info"></span>
									<?php esc_html_e( 'Processes a maximum of 20 posts per click. Existing descriptions are never overwritten. Repeatable until all posts are complete.', 'yoast-gemini-meta-desc' ); ?>
								</p>
							</div>
						</div>

						<!-- Live Progress Box (Hidden until batch runs) -->
						<div id="batch-progress-section" class="batch-progress-box" style="display: none;">
							<div class="progress-header">
								<div class="progress-title">
									<span class="spinner is-active" id="batch-active-spinner"></span>
									<strong id="batch-status-message"><?php esc_html_e( 'Preparing batch...', 'yoast-gemini-meta-desc' ); ?></strong>
								</div>
								<div class="progress-counter">
									<span id="batch-current-count">0</span> / <span id="batch-total-count">20</span> <?php esc_html_e( 'in current batch', 'yoast-gemini-meta-desc' ); ?>
								</div>
							</div>

							<div class="progress-bar-container">
								<div class="progress-bar-fill" id="batch-progress-fill" style="width: 0%;"></div>
							</div>

							<div class="batch-stats-summary">
								<span class="summary-item summary-success">
									<span class="dashicons dashicons-yes-alt"></span>
									<span id="batch-success-count">0</span> <?php esc_html_e( 'Successful', 'yoast-gemini-meta-desc' ); ?>
								</span>
								<span class="summary-item summary-failed">
									<span class="dashicons dashicons-warning"></span>
									<span id="batch-failed-count">0</span> <?php esc_html_e( 'Failed', 'yoast-gemini-meta-desc' ); ?>
								</span>
								<span class="summary-item summary-skipped">
									<span class="dashicons dashicons-controls-skipforward"></span>
									<span id="batch-skipped-count">0</span> <?php esc_html_e( 'Skipped', 'yoast-gemini-meta-desc' ); ?>
								</span>
							</div>
						</div>

						<!-- All Posts Completed Notice Banner -->
						<div id="all-completed-banner" class="all-completed-banner" style="display: none;">
							<div class="banner-inner">
								<span class="dashicons dashicons-awards"></span>
								<div>
									<h3><?php esc_html_e( 'All Eligible Content Optimized!', 'yoast-gemini-meta-desc' ); ?></h3>
									<p><?php esc_html_e( 'All published posts in this post type now have Yoast SEO meta descriptions.', 'yoast-gemini-meta-desc' ); ?></p>
								</div>
							</div>
						</div>

						<!-- Results Live Table -->
						<div class="results-table-section">
							<h3>
								<span class="dashicons dashicons-list-view"></span>
								<?php esc_html_e( 'Batch Generation Activity Log', 'yoast-gemini-meta-desc' ); ?>
							</h3>
							<div class="table-responsive">
								<table class="widefat striped yoast-gemini-results-table">
									<thead>
										<tr>
											<th class="col-id"><?php esc_html_e( 'ID', 'yoast-gemini-meta-desc' ); ?></th>
											<th class="col-title"><?php esc_html_e( 'Post Title', 'yoast-gemini-meta-desc' ); ?></th>
											<th class="col-desc"><?php esc_html_e( 'Generated Meta Description', 'yoast-gemini-meta-desc' ); ?></th>
											<th class="col-length"><?php esc_html_e( 'Length', 'yoast-gemini-meta-desc' ); ?></th>
											<th class="col-status"><?php esc_html_e( 'Status', 'yoast-gemini-meta-desc' ); ?></th>
										</tr>
									</thead>
									<tbody id="batch-results-tbody">
										<tr class="empty-row">
											<td colspan="5" class="text-center text-muted">
												<?php esc_html_e( 'Click "Generate Meta Descriptions" above to begin processing eligible posts.', 'yoast-gemini-meta-desc' ); ?>
											</td>
										</tr>
									</tbody>
								</table>
							</div>
						</div>

					</div>
				</div>
			</div>
		</div>
		<?php
	}
}
