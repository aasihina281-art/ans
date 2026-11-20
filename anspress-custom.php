<?php
/**
 * AnsPress customizations (Task 1 + Task 2).
 *
 * How to use: copy this file and the anspress-custom/ directory into
 * wp-content/plugins/ and activate "AnsPress Customizations" from the
 * Plugins screen, OR simply add at the top of your theme functions.php:
 *
 *     require_once __DIR__ . '/anspress-custom.php'; // adjust the path
 *
 * Task 1 — Hierarchical category dropdown in the ask form:
 *   - The original flat <select id="form_question-category"> is replaced by a
 *     parent select + a child select rendered INSIDE the same form field
 *     wrapper (.ap-field-form_question-category), so the existing validation
 *     ("required,not_zero") keeps working unchanged.
 *   - Child options are loaded on demand via the plugin's own AJAX dispatcher
 *     (action "ap_ajax", hook "ap_ajax_<name>", see includes/process-form.php
 *     lines 48-49 and 81-110) — no new admin-ajax endpoint is registered.
 *
 * Task 2 — Style isolation from the theme:
 *   - Loads an extra stylesheet AFTER anspress-main that re-declares the key
 *     element rules scoped under #anspress/.anspress (the wrapper the plugin
 *     already outputs — see includes/shortcode-basepage.php line 88) with
 *     higher specificity and WITHOUT !important.
 *   - Bumps the wp_enqueue_scripts priority of AnsPress assets from 1 to 50
 *     so they print after most theme stylesheets (see templates/functions.php
 *     line 51: `add_action( 'wp_enqueue_scripts', 'ap_scripts_front', 1 );`).
 *
 * @package AnsPressCustom
 */

defined( 'ABSPATH' ) || exit;

define( 'AP_CUSTOM_URL', plugin_dir_url( __FILE__ ) );
define( 'AP_CUSTOM_DIR', plugin_dir_path( __FILE__ ) );

require_once AP_CUSTOM_DIR . 'anspress-custom/class-ap-custom-hierarchical-category.php';
require_once AP_CUSTOM_DIR . 'anspress-custom/class-ap-custom-style-isolation.php';

// Hook everything only once AnsPress itself is loaded and WP is initialized.
add_action( 'plugins_loaded', 'ap_custom_init' );

/**
 * Bootstrap the customizations.
 *
 * @return void
 */
function ap_custom_init() {
	if ( ! class_exists( 'AnsPress' ) ) {
		return; // AnsPress is not active — bail out gracefully.
	}

	// Task 2 first: its plugins_loaded(99) re-hook of ap_scripts_front must be
	// registered before anything else touches wp_enqueue_scripts.
	AP_Custom_Style_Isolation::instance();
	AP_Custom_Hierarchical_Category::instance();
}

/**
 * Plugin header for installation as a standalone mu-plugin/plugin.
 * Plugin Name: AnsPress Customizations
 * Description: Hierarchical category dropdown in ask form + theme-proof AnsPress styles.
 * Version:     1.0.0
 * Requires PHP: 7.2
 */
