/**
 * Task 1 — Hierarchical (parent -> child) category dropdown for the
 * AnsPress ask form.
 *
 * Companion script of anspress-custom/class-ap-custom-hierarchical-category.php.
 *
 * Behaviour:
 *  - The main select (#form_question-category) contains ONLY root categories
 *    (terms_args parent=0 on the server side).
 *  - On change, child categories are fetched through the plugin's own AJAX
 *    dispatcher (action "ap_ajax", ap_ajax_action=load_child_categories —
 *    see includes/process-form.php lines 48-49 and 96-105).
 *  - If the chosen root has children, a second select (#ap-child-category)
 *    appears and CARRIES the real name="question[category]" (the parent
 *    select is disabled and loses its name), so server-side validation
 *    ("required,not_zero") and absint sanitization keep working unchanged.
 *  - If the root has no children, the name returns to the parent select.
 *  - Uses event delegation ($document.on(...)) so it also works when the
 *    ask form itself is re-rendered via AJAX by AnsPress.
 *
 * Globals provided by wp_localize_script (class name: ApCustomCat):
 *  ajaxUrl, nonce, i18n.selectChild, i18n.loading
 */
/* global jQuery, ajaxurl, ApCustomCat */
(function ($) {
	'use strict';

	var settings = window.ApCustomCat || {};

	function ajaxUrl() {
		return settings.ajaxUrl || window.ajaxurl;
	}

	function nonce() {
		return settings.nonce || (window.ap_nonce ? window.ap_nonce : '');
	}

	function t(key, fallback) {
		return (settings.i18n && settings.i18n[key]) || fallback;
	}

	/**
	 * Move the submitted field name from the parent select to the child
	 * select (or back), so that exactly ONE element posts question[category].
	 */
	function assignName($parent, $child, useChild) {
		var fieldName = 'question[category]';

		if (useChild) {
			$child.attr('name', fieldName);
			$parent.removeAttr('name').attr('disabled', 'disabled');
		} else {
			$parent.removeAttr('disabled').attr('name', fieldName);
			$child.removeAttr('name');
		}
	}

	function fillChildSelect($child, children, selectedId) {
		var html = '<option value="0">' + $('<i>').text(t('selectChild', 'Select a subcategory')).html() + '</option>';

		$.each(children, function (i, term) {
			html += '<option value="' + parseInt(term.id, 10) + '"' +
				(parseInt(selectedId, 10) === parseInt(term.id, 10) ? ' selected="selected"' : '') + '>' +
				$('<div>').text(term.name).html() + // escape term name
				'</option>';
		});

		$child.html(html);
	}

	/**
	 * Load children of `parentId` and update the UI accordingly.
	 */
	function loadChildren($parent, $wrap, parentId, preselectChild, callback) {
		var $child = $wrap.find('#ap-child-category, select.ap-child-category-select').first();

		if (!parentId || parseInt(parentId, 10) <= 0) {
			$wrap.hide(200);
			fillChildSelect($child, [], 0);
			assignName($parent, $child, false);
			if (callback) { callback(); }
			return;
		}

		// Show a "loading" state while the request runs.
		$wrap.show();
		fillChildSelect($child, [{ id: 0, name: t('loading', 'Loading…') }], 0);
		$child.prop('disabled', true);

		$.post(ajaxUrl(), {
			action: 'ap_ajax',
			ap_ajax_action: 'load_child_categories',
			parent: parseInt(parentId, 10),
			ap_nonce: nonce()
		}).done(function (response) {
			var children = (response && response.children) ? response.children : [];

			$child.prop('disabled', false);

			if (children.length > 0) {
				fillChildSelect($child, children, preselectChild || 0);
				assignName($parent, $child, true);
				$wrap.show();
			} else {
				// Root category without children — submit the root itself.
				fillChildSelect($child, [], 0);
				$wrap.hide(200);
				assignName($parent, $child, false);
			}
		}).fail(function () {
			// Network / server error: fail safe — keep the parent select active
			// so the form remains valid and submittable with the root category.
			fillChildSelect($child, [], 0);
			$wrap.hide(200);
			assignName($parent, $child, false);
		}).always(function () {
			if (callback) { callback(); }
		});
	}

	$(document).ready(function () {
		var $parentSel = $('#form_question-category');

		if (!$parentSel.length) {
			return; // Not an AnsPress ask page — nothing to do.
		}

		var $wrap = $('.ap-child-categories[data-parent-field="form_question-category"]').first();

		if (!$wrap.length) {
			return; // PHP part not rendered (e.g. addon inactive) — bail out.
		}

		// Ensure the parent select always starts out carrying the name
		// (server renders it with name by default; this is just a safety net).
		if (!$parentSel.attr('name')) {
			$parentSel.attr('name', 'question[category]');
		}

		// --- Restore state when editing a question / after validation errors. ---
		var rootValue = parseInt($wrap.data('root-value'), 10) || 0;
		var childValue = parseInt($wrap.data('child-value'), 10) || 0;

		if (rootValue > 0) {
			$parentSel.val(String(rootValue));
		}

		if (rootValue > 0 && childValue > 0) {
			loadChildren($parentSel, $wrap, rootValue, childValue);
		} else if (rootValue > 0) {
			// Root selected but no child forced yet — still fetch children so the
			// cascade reflects reality (user may then pick one).
			loadChildren($parentSel, $wrap, rootValue, 0);
		}

		// --- Live cascade via delegation (works after AJAX re-render too). ---
		$(document.body)
			.on('change', '#form_question-category', function () {
				loadChildren($(this), $wrap, $(this).val(), 0);
			})
			.on('change', '#ap-child-category', function () {
				// If user resets the child select to "none", fall back to root value.
				var v = parseInt($(this).val(), 10) || 0;
				if (v === 0) {
					assignName($parentSel, $(this), false);
				} else {
					assignName($parentSel, $(this), true);
				}
			});

		// Re-bind after the whole form is replaced via AJAX (AnsPress re-renders
		// form fields on some actions). Delegation covers events; this observer
		// restores initial state if the wrapper markup was recreated.
		var mo = window.MutationObserver;
		if (mo) {
			var target = document.querySelector('.ap-field-form_question-category') || document.body;
			new mo(function (mutations) {
				mutations.forEach(function (m) {
					$(m.addedNodes).find('#form_question-category').addBack('#form_question-category').each(function () {
						var $p = $(this);
						if (!$p.attr('name')) { $p.attr('name', 'question[category]'); }
					});
				});
			}).observe(target, { childList: true, subtree: true });
		}
	});
})(jQuery);
