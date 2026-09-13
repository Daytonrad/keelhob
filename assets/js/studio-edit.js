/**
 * The snippet editor screen.
 *
 * WHY THIS IS A FILE AND NOT AN INLINE <script>.
 *
 * It used to be inline, printed by Claudpress_Studio_Edit::render() right after
 * the form. Everything enqueued from a screen render callback prints in the
 * FOOTER, so the inline block ran as script 9 of 85 and code-editor.min.js
 * loaded as script 74. `wp.codeEditor` did not exist yet, this guard swallowed
 * the whole thing —
 *
 *     var editor = wp.codeEditor && wp.codeEditor.initialize ? ... : null;
 *
 * — and every install shipped a plain <textarea> where the source clearly said
 * there was a syntax-highlighting editor. No console error. No warning. Nothing
 * a grep or a PHP test could ever have seen.
 *
 * A real file with a declared `code-editor` dependency cannot have that bug.
 * WordPress orders the graph; nobody has to remember to.
 *
 * It also fails LOUDLY now. The silent guard is gone: if the editor cannot
 * start, the page says so. A feature that degrades silently degrades silently
 * forever.
 */
(function () {
	'use strict';

	var cfg = window.cpStudioEdit || {};
	var settings = window.cpStudioEditorSettings || null;
	var textarea = document.getElementById('cp-code');
	var editor = null;

	if (!textarea) {
		return;
	}

	/* ---------------------------------------------------------------- */
	/* The code editor                                                   */
	/* ---------------------------------------------------------------- */

	function warn(message) {
		var box = document.getElementById('cp-editor-warning');
		if (!box) {
			return;
		}
		box.innerHTML = '<div class="notice notice-warning inline"><p>' + message + '</p></div>';
		box.hidden = false;
	}

	if (settings && window.wp && window.wp.codeEditor && window.wp.codeEditor.initialize) {
		try {
			editor = window.wp.codeEditor.initialize(textarea, settings);
		} catch (e) {
			editor = null;
		}
		if (!editor || !editor.codemirror) {
			warn(cfg.i18n.editorFailed);
		}
	} else if (settings) {
		// Settings exist, so the person has NOT turned highlighting off; the
		// library genuinely failed to arrive. Worth saying out loud.
		warn(cfg.i18n.editorFailed);
	}
	// settings === false means the user switched syntax highlighting off in
	// their own profile. That is a choice, not a fault, and it gets no notice.

	/** The code, wherever it currently lives. */
	function codeValue() {
		return editor && editor.codemirror ? editor.codemirror.getValue() : textarea.value;
	}

	/**
	 * Switching the type must re-highlight.
	 *
	 * Without it, changing a snippet from PHP to CSS leaves you typing CSS into
	 * a PHP highlighter, which reads as the editor being broken. This branch has
	 * existed for as long as the bug above and has never once executed.
	 */
	var typeEl = document.getElementById('cp-type');
	if (typeEl && editor && editor.codemirror) {
		typeEl.addEventListener('change', function () {
			editor.codemirror.setOption('mode', cfg.modes[this.value] || 'text/plain');
		});
	}

	/* ---------------------------------------------------------------- */
	/* Ask AI what this does                                             */
	/* ---------------------------------------------------------------- */

	var btn = document.getElementById('cp-ask-ai');
	if (!btn) {
		return;
	}

	btn.addEventListener('click', function () {
		var code = codeValue();
		if (!code.trim()) {
			window.alert(cfg.i18n.writeSomethingFirst);
			return;
		}

		btn.disabled = true;
		btn.textContent = cfg.i18n.asking;

		var body = new FormData();
		body.append('action', 'claudpress_studio_review');
		body.append('nonce', cfg.nonce);
		body.append('code', code);
		body.append('snippet_type', typeEl ? typeEl.value : 'php');

		window
			.fetch(cfg.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
			.then(function (r) {
				return r.json();
			})
			.then(function (json) {
				document.getElementById('cp-ai-report').innerHTML =
					json && json.data && json.data.html
						? json.data.html
						: '<div class="notice notice-warning inline"><p>' +
							((json && json.data && json.data.message) || cfg.i18n.unreachable) +
							'</p></div>';
			})
			.catch(function () {
				document.getElementById('cp-ai-report').innerHTML =
					'<div class="notice notice-warning inline"><p>' + cfg.i18n.unreachableSafe + '</p></div>';
			})
			.finally(function () {
				btn.disabled = false;
				btn.textContent = cfg.i18n.askAi;
			});
	});
})();
