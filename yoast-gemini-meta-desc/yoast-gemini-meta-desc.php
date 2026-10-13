<?php
/**
 * Plugin Name:       Yoast SEO Meta Description Generator (Gemini)
 * Plugin URI:        https://github.com/imranh654/yoast-seo-gemini-meta-description
 * Description:       Standalone addon for Yoast SEO that automatically generates SEO-optimized meta descriptions (120–158 characters) for WordPress posts and pages using the Google Gemini API.
 * Version:           1.0.0
 * Author:            Imran H
 * Author URI:        https://github.com/imranh654
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       yoast-gemini-meta-desc
 * Domain Path:       /languages
 * Requires at least: 5.8
 * Requires PHP:      7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants.
define( 'YOAST_GEMINI_META_VERSION', '1.0.0' );
define( 'YOAST_GEMINI_META_FILE', __FILE__ );
define( 'YOAST_GEMINI_META_PATH', plugin_dir_path( __FILE__ ) );
define( 'YOAST_GEMINI_META_URL', plugin_dir_url( __FILE__ ) );
define( 'YOAST_GEMINI_META_BASENAME', plugin_basename( __FILE__ ) );

// Load plugin classes.
require_once YOAST_GEMINI_META_PATH . 'includes/class-yoast-bridge.php';
require_once YOAST_GEMINI_META_PATH . 'includes/class-gemini-client.php';
require_once YOAST_GEMINI_META_PATH . 'includes/class-batch-processor.php';
require_once YOAST_GEMINI_META_PATH . 'includes/class-admin.php';
require_once YOAST_GEMINI_META_PATH . 'includes/class-plugin.php';

/**
 * Main plugin bootstrap function.
 *
 * @return Yoast_Gemini_Plugin
 */
function yoast_gemini_meta_desc() {
	return Yoast_Gemini_Plugin::get_instance();
}

// Initialize plugin after all plugins are loaded.
add_action( 'plugins_loaded', 'yoast_gemini_meta_desc' );
