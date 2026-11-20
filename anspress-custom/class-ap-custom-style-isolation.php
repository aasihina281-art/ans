<?php
/**
 * Task 2 — Isolate AnsPress styles from the active theme.
 *
 * Verified plugin facts:
 *  - templates/functions.php line 43:
 *        add_action( 'wp_enqueue_scripts', 'ap_scripts_front', 1 );
 *    Priority 1 makes anspress-main/anspress-rtl/ap-overrides print BEFORE
 *    most theme stylesheets, so the theme wins on equal specificity.
 *  - includes/theme.php lines 660-662 registers 'anspress-main' (and
 *    'anspress-rtl' depending on it).
 *  - The plugin already wraps its front-end output in <div id="anspress"
 *    class="anspress"> (includes/shortcode-basepage.php line 88), so we use
 *    that existing wrapper for scoping instead of inventing ".anspress-wrap".
 *
 * Strategy (no core file is modified):
 *  1. Re-hook ap_scripts_front() from priority 1 to priority 50 so AnsPress
 *     CSS is printed after the theme's styles (theme enqueues usually run at
 *     default priority 10-20). Done at plugins_loaded via a late hook so the
 *     original add_action() has definitely happened.
 *  2. Enqueue isolation.css AFTER 'anspress-main' / 'ap-overrides' using an
 *     explicit dependency, scoped under #anspress/.anspress with plain
 *     specificity (ID + class) — no !important anywhere.
 *
 * @package AnsPressCustom
 */

defined( 'ABSPATH' ) || exit;

class AP_Custom_Style_Isolation {

	/**
	 * Singleton.
	 *
	 * @return AP_Custom_Style_Isolation
	 */
	public static function instance() {
		static $instance = false;

		if ( ! $instance ) {
			$instance = new self();
			$instance->init();
		}

		return $instance;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	private function init() {
		// Step 1: re-hook AnsPress assets BEFORE wp_enqueue_scripts fires at all.
		// The original registration lives in templates/functions.php line 43
		// (`add_action( 'wp_enqueue_scripts', 'ap_scripts_front', 1 );`), which
		// has definitely run by the time plugins_loaded completes — so we can
		// safely remove/re-add it here. Doing this inside wp_enqueue_scripts
		// itself would be unreliable: WP does not re-read the hook queue for
		// callbacks added while the same hook is running.
		add_action( 'plugins_loaded', array( $this, 'reorder_anspress_assets' ), 99 );

		// Step 2: our stylesheet, printed last (after anspress-main & overrides).
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ), 101 );
	}

	/**
	 * Re-hook ap_scripts_front() from priority 1 to priority 50 so AnsPress
	 * CSS prints after most theme stylesheets (themes typically enqueue at
	 * default priority 10–20).
	 *
	 * @return void
	 */
	public function reorder_anspress_assets() {
		// Original registration: templates/functions.php line 43.
		remove_action( 'wp_enqueue_scripts', 'ap_scripts_front', 1 );
		add_action( 'wp_enqueue_scripts', 'ap_scripts_front', 50 );
	}

	/**
	 * Enqueue the isolation stylesheet on AnsPress pages only, AFTER
	 * anspress-main (explicit dependency) and after ap-overrides if present.
	 *
	 * @return void
	 */
	public function enqueue() {
		if ( ! wp_style_is( 'anspress-main', 'enqueued' ) && ! wp_style_is( 'anspress-main', 'registered' ) ) {
			return; // AnsPress assets not in play on this page.
		}

		$deps = array( 'anspress-main' );

		if ( wp_style_is( 'ap-overrides', 'enqueued' ) || wp_style_is( 'ap-overrides', 'registered' ) ) {
			$deps[] = 'ap-overrides'; // templates/functions.php line 41.
		}

		wp_enqueue_style(
			'ap-custom-isolation',
			AP_CUSTOM_URL . 'anspress-custom/css/isolation.css',
			$deps,
			'1.0.0'
		);
	}
}
