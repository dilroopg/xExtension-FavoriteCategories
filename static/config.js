/**
 * Favorite Categories — settings page behaviour.
 *
 * Registered with appendScript from handleConfigureAction(), never inlined in
 * configure.phtml: the configure view is injected into the AJAX slider and an
 * inline <script> in an injected fragment does not execute.
 *
 * The page works with this file absent. Nothing the server needs (act, cat,
 * _csrf) is filled in here. What this adds:
 *   - the live "After saving" preview and the unsaved-changes line for the
 *     sidebar row form
 *   - an unsaved-changes guard on the label field only, so a typed label is
 *     not lost by clicking a star button elsewhere on the page
 */
(function () {
	'use strict';

	function ready(fn) {
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', fn);
		} else {
			fn();
		}
	}

	function bind() {
		var root = document.getElementById('favorite-categories-config');
		if (!root) { return; }
		if (root.getAttribute('data-fc-bound') === '1') { return; }
		root.setAttribute('data-fc-bound', '1');

		bindRowPreview(root);
	}

	/**
	 * Live preview + dirty marker for the sidebar row form.
	 *
	 * The saved state is drawn by the server in the "Now reads" row. This
	 * handles the draft: picking a state or typing re-renders "After saving"
	 * instantly and says whether the draft differs from what is stored, so
	 * both controls give the same feedback and neither saves without the
	 * button.
	 */
	function bindRowPreview(root) {
		var form = document.getElementById('favorite-categories-display-form');
		if (!form) { return; }
		var input = form.querySelector('[data-fc-label]');
		var live = root.querySelector('[data-fc-live]');
		var msg = root.querySelector('[data-fc-live-msg]');
		var dirty = root.querySelector('[data-fc-dirty]');
		if (!input || !live || !msg) { return; }

		// the real favorites count, taken from the saved row so it cannot drift
		var num = root.querySelector('.fc-preview .fc-row-num');
		var count = num ? num.textContent : '';

		// the saved state, captured once from the server-rendered form
		var savedMode = (form.querySelector('[data-fc-rowmode]:checked') || {}).value || 'caret';
		var savedLabel = input.value.replace(/\s+/g, ' ').replace(/^\s+|\s+$/g, '');

		function esc(s) {
			return String(s).replace(/[&<>"']/g, function (c) {
				return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
			});
		}

		function currentMode() {
			var picked = form.querySelector('[data-fc-rowmode]:checked');
			return picked ? picked.value : 'caret';
		}

		function render() {
			var label = input.value.replace(/\s+/g, ' ').replace(/^\s+|\s+$/g, '');
			var mode = currentMode();
			var caret = '<span class="fc-caret" aria-hidden="true">&#9662;</span>';
			var changed = (label !== savedLabel) || (mode !== savedMode);

			if (mode === 'caret') {
				live.innerHTML = caret;
				msg.textContent = 'the row will show the caret only';
			} else if (mode === 'number' || label === '') {
				// "Label and number" with an empty label degrades to the number,
				// which is what the placeholder promises
				live.innerHTML = caret + '<span class="fc-row-num">' + esc(count) + '</span>';
				msg.textContent = (mode === 'both')
					? 'label is empty — will show the number on its own'
					: (changed ? 'preview of your unsaved changes' : 'unchanged');
			} else {
				live.innerHTML = caret
					+ '<span class="fc-row-label">' + esc(label) + '</span>'
					+ '<span class="fc-row-num">' + esc(count) + '</span>';
				msg.textContent = changed ? 'preview of your unsaved changes' : 'unchanged';
			}

			if (dirty) {
				dirty.textContent = changed ? 'Unsaved changes — press Save' : '';
			}
		}

		input.addEventListener('input', render);
		var radios = form.querySelectorAll('[data-fc-rowmode]');
		for (var i = 0; i < radios.length; i++) {
			radios[i].addEventListener('change', render);
		}
		render();
	}

	ready(bind);
	// The configure view can be injected after DOMContentLoaded; poll briefly.
	var tries = 0;
	var timer = window.setInterval(function () {
		tries++;
		if (document.getElementById('favorite-categories-config') || tries > 40) {
			window.clearInterval(timer);
			bind();
		}
	}, 250);
}());
