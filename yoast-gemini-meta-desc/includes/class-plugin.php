<?php
/**
 * Main Plugin Orchestrator.
 *
 * Handles plugin lifecycle, dependency checks, and module instantiation.
 *
 * @package Yoast_Gemini_Meta_Desc
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Yoast_Gemini_Plugin
 */
class Yoast_Gemini_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Yoast_Gemini_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Admin interface instance.
	 *
	 * @var Yoast_Gemini_Admin
	 */
	public $admin;

	/**
	 * Batch processor instance.
	 *
	 * @var Yoast_Gemini_Batch_Processor
	 */
	public $batch_processor;

	/**
	 * Retrieve singleton instance.
	 *
	 * @return Yoast_Gemini_Plugin
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->init_modules();
		$this->register_hooks();
	}

	/**
	 * Instantiate modules.
	 */
	private function init_modules() {
		$this->admin           = new Yoast_Gemini_Admin();
		$this->batch_processor = new Yoast_Gemini_Batch_Processor();
	}

	/**
	 * Register global hooks.
	 */
	private function register_hooks() {
		register_activation_hook( YOAST_GEMINI_META_FILE, [ $this, 'activate' ] );
		register_deactivation_hook( YOAST_GEMINI_META_FILE, [ $this, 'deactivate' ] );

		add_action( 'admin_notices', [ $this, 'check_yoast_dependency_notice' ] );
		add_filter( 'plugin_action_links_' . YOAST_GEMINI_META_BASENAME, [ $this, 'add_action_links' ] );
	}

	/**
	 * Activation callback.
	 */
	public function activate() {
		// Initialize default options if not present.
		$current = get_option( Yoast_Gemini_Client::OPTION_KEY, false );
		if ( false === $current ) {
			Yoast_Gemini_Client::update_options(
				[
					'api_key' => '',
					'model'   => Yoast_Gemini_Client::DEFAULT_MODEL,
				]
			);
		}
	}

	/**
	 * Deactivation callback.
	 */
	public function deactivate() {
		// Clean up any transient processing states if set.
	}

	/**
	 * Display admin notice if Yoast SEO is inactive.
	 */
	public function check_yoast_dependency_notice() {
		if ( Yoast_Gemini_Yoast_Bridge::is_yoast_active() ) {
			return;
		}

		// Only show to users who can manage plugins.
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$screen = get_current_screen();
		// Show on plugins page or addon dashboard.
		if ( ! $screen || ! in_array( $screen->id, [ 'plugins', 'yoast-gemini-meta-desc', 'toplevel_page_yoast-gemini-meta-desc' ], true ) ) {
			return;
		}
		?>
		<div class="notice notice-warning is-dismissible">
			<p>
				<strong><?php esc_html_e( 'Yoast SEO Meta Description Generator:', 'yoast-gemini-meta-desc' ); ?></strong>
				<?php
				printf(
					/* translators: %s: Plugin name */
					esc_html__( '%s requires the Yoast SEO plugin to be installed and active to save meta descriptions to Yoast SEO metadata fields.', 'yoast-gemini-meta-desc' ),
					'<strong>' . esc_html__( 'Yoast SEO Meta Description Generator', 'yoast-gemini-meta-desc' ) . '</strong>'
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Add "Settings" shortcut link to Plugins page entry.
	 *
	 * @param array $links Array of plugin action links.
	 * @return array Modified links.
	 */
	public function add_action_links( $links ) {
		$settings_url  = admin_url( 'admin.php?page=' . Yoast_Gemini_Admin::PAGE_SLUG );
		$settings_link = '<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Generator & Settings', 'yoast-gemini-meta-desc' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}
}
