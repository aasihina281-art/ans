<?php
/**
 * Task 1 — Hierarchical (parent -> child) category dropdown in the AnsPress
 * ask-question form.
 *
 * Architecture notes (verified against the plugin source):
 *
 * 1. The category field is registered by the "categories" addon:
 *    addons/categories/categories.php, method ap_question_form_fields()
 *    (lines ~426-455), attached to the filter/action "ap_question_form_fields"
 *    (line 58). The field is:
 *        type => 'select', options => 'terms'
 *            -> rendered by AnsPress\Form\Field\Select
 *               (lib/form/class-select.php, field_markup(), lines ~86-101)
 *            -> options come from get_terms( terms_args ) — a FLAT list.
 *    Field HTML id is produced by AnsPress\Form\Field::id()
 *    (lib/form/class-field.php lines ~436-449): "form_question-category",
 *    name is "question[category]" (class-field.php line 161).
 *
 * 2. Validation/sanitization: the Select field with options === 'terms'
 *    gets sanitize_cb 'absint' (class-select.php lines 56-60) and the addon
 *    requires "required,not_zero" on question[category]. Saved later via
 *    after_new_question(): $values['category']['value'] (categories.php
 *    lines 466-471). So the FINAL submitted value MUST stay in an input
 *    named exactly "question[category]".
 *
 * 3. AJAX: every AnsPress front-end request goes through the single
 *    dispatcher action "ap_ajax" (includes/process-form.php lines 48-49),
 *    which fires "ap_ajax_{ap_ajax_action}" (line 105). JS helper
 *    AnsPress.ajax() (assets/js/common.js line 118) posts to it. We reuse
 *    this dispatcher — no new endpoint, works for logged-in and guests
 *    (wp_ajax_nopriv_ap_ajax is registered by the plugin itself).
 *
 * Approach: keep ONE real <select> named question[category] (so validation
 * and saving are untouched), but render only ROOT categories in it. When a
 * root category that HAS children is selected, a second <select> with the
 * children (loaded via AJAX) appears; while it is visible the first select
 * becomes a plain visual "section" (disabled + no name) and the REAL value
 * is moved to the children select, which carries the name question[category].
 * If a root category has NO children, or the user picks "All/none", the name
 * returns to the parent select. Because only one element ever carries
 * question[category], the server-side "required,not_zero" + absint
 * sanitization keeps working without any change.
 *
 * @package AnsPressCustom
 */

defined( 'ABSPATH' ) || exit;

class AP_Custom_Hierarchical_Category {

	/**
	 * Singleton.
	 *
	 * @return AP_Custom_Hierarchical_Category
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
		// Modify the ask-form category field AFTER the categories addon added it
		// (addon registers it at default priority 10, see categories.php line 58).
		add_filter( 'ap_question_form_fields', array( $this, 'hierarchy_field' ), 20 );

		// Render parent/child selects inside the existing field wrapper.
		add_action( 'ap_after_field_markup', array( $this, 'render_child_select' ) );

		// AJAX endpoint via the plugin's own dispatcher: ap_ajax_load_child_categories.
		add_action( 'ap_ajax_load_child_categories', array( $this, 'ajax_load_child_categories' ) );

		// Front-end JS + pass ajaxurl/nonce (plugin already prints these globals,
		// see includes/theme.php lines ~717-723, so we only add our small script).
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ), 100 );
	}

	/**
	 * Turn the flat category select into a parents-only select.
	 *
	 * @param array $form Ask form args.
	 * @return array
	 */
	public function hierarchy_field( $form ) {
		if ( empty( $form['fields']['category'] ) ) {
			return $form;
		}

		// Only show ROOT (parent) categories in the main select.
		$form['fields']['category']['terms_args'] = array_merge(
			isset( $form['fields']['category']['terms_args'] ) ? $form['fields']['category']['terms_args'] : array(),
			array(
				'taxonomy'   => 'question_category',
				'hide_empty' => false,
				'parent'     => 0,          // <-- key change: roots only.
				'fields'     => 'id=>name',
				'orderby'    => 'name',
			)
		);

		// Keep a copy of the currently selected term id so we can restore the
		// right select state when editing a question / re-rendering after errors.
		$value = isset( $form['fields']['category']['value'] ) ? (int) $form['fields']['category']['value'] : 0;

		if ( $value > 0 && taxonomy_exists( 'question_category' ) ) {
			$term = get_term( $value, 'question_category' );

			if ( $term && ! is_wp_error( $term ) ) {
				if ( 0 === (int) $term->parent ) {
					// Selected term is a root — parent select stays as-is.
					$form['fields']['category']['ap_custom_root_value'] = $value;
				} else {
					// Selected term is a child — prefill: show parent select with its
					// parent and mark the child select to be auto-loaded & selected.
					$form['fields']['category']['ap_custom_root_value']      = (int) $term->parent;
					$form['fields']['category']['ap_custom_child_value']     = $value;
					$form['fields']['category']['value']                    = ''; // Parent select must not preselect the child id.

					// Make sure the root option exists in the parents list.
					add_filter(
						'ap_question_form_fields',
						function ( $f ) use ( $term ) {
							// No-op hook kept for symmetry; root exists because it is a parent term.
							return $f;
						}
					);
				}
			}
		}

		return $form;
	}

	/**
	 * After the (parents-only) select is rendered, append the hidden child
	 * select markup inside the same .ap-form-group wrapper.
	 *
	 * Hook: ap_after_field_markup (fires at the end of
	 * AnsPress\Form\Field::field_markup() overrides, e.g. class-select.php
	 * line ~99 and class-input.php).
	 *
	 * @param AnsPress\Form\Field $field Field object (by reference).
	 * @return void
	 */
	public function render_child_select( $field ) {
		// Target exactly the category select of the question form.
		if ( ! ( $field instanceof \AnsPress\Form\Field\Select ) ) {
			return;
		}

		if ( 'question' !== $field->form_name || 'category' !== $field->original_name ) {
			return;
		}

		$root_value  = (int) $field->get( 'ap_custom_root_value', 0 );
		$child_value = (int) $field->get( 'ap_custom_child_value', 0 );

		// Data attributes consumed by anspress-custom.js.
		$field->add_html(
			'<div class="ap-child-categories"' .
				' data-parent-field="' . esc_attr( $field->id() ) . '"' .
				' data-root-value="' . esc_attr( $root_value ) . '"' .
				' data-child-value="' . esc_attr( $child_value ) . '"' .
				' style="display:none;">' .
				'<label class="ap-form-label" for="ap-child-category">' .
				esc_html__( 'Subcategory', 'anspress-question-answer' ) . '</label>' .
				'<select id="ap-child-category" class="ap-form-control"></select>' .
			'</div>'
		);
	}

	/**
	 * AJAX handler: return child categories of a parent term as JSON.
	 *
	 * Called through the plugin dispatcher: do_action( 'ap_ajax_' . $action )
	 * in includes/process-form.php line 105. Response format matches what
	 * AnsPress.ajax()/ap_ajax_json() expect (we echo json directly and die,
	 * like other handlers using ap_send_json()).
	 *
	 * @return void
	 */
	public function ajax_load_child_categories() {
		$parent = (int) ap_sanitize_unslash( 'parent', 'r' );

		// Basic capability check: reading public taxonomy terms requires only
		// read access; still verify the shared AnsPress ajax nonce if present.
		$nonce = ap_sanitize_unslash( 'ap_nonce', 'r' );
		if ( $nonce && ! wp_verify_nonce( $nonce, 'ap_ajax_nonce' ) ) {
			wp_send_json_error( array( 'msg' => 'invalid_nonce' ), 403 );
		}

		$children = array();

		if ( $parent > 0 && taxonomy_exists( 'question_category' ) ) {
			$terms = get_terms(
				array(
					'taxonomy'   => 'question_category',
					'parent'     => $parent,
					'hide_empty' => false,
					'orderby'    => 'name',
				)
			);

			if ( $terms && ! is_wp_error( $terms ) ) {
				foreach ( $terms as $t ) {
					$children[] = array(
						'id'   => (int) $t->term_id,
						'name' => $t->name,
					);
				}
			}
		}

		header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
		echo wp_json_encode( array( 'children' => $children ) );
		exit;
	}

	/**
	 * Enqueue the small jQuery script on AnsPress pages only.
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		if ( ! function_exists( 'is_anspress' ) || ! ( is_anspress() || ap_current_page() !== '' ) ) {
			return;
		}

		wp_enqueue_script(
			'ap-custom-hierarchical-category',
			AP_CUSTOM_URL . 'anspress-custom/js/hierarchical-category.js',
			array( 'jquery', 'anspress-common' ),
			'1.0.0',
			true
		);

		wp_localize_script(
			'ap-custom-hierarchical-category',
			'ApCustomCat',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'ap_ajax_nonce' ),
				'i18n'    => array(
					'selectChild' => __( 'Select a subcategory', 'anspress-question-answer' ),
					'loading'     => __( 'Loading…', 'anspress-question-answer' ),
				),
			)
		);
	}
}
