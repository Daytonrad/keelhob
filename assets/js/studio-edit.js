/**
 * The snippet editor screen.
 *
 * WHY THIS IS A FILE AND NOT AN INLINE <script>.
 *
 * It used to be inline, printed by Keelhob_Studio_Edit::render() right after
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
	var textarea = document.getElementById('kh-code');
	var editor = null;

	if (!textarea) {
		return;
	}

	var i18n = cfg.i18n || {};

	/* ================================================================== */
	/* The code editor                                                     */
	/* ================================================================== */

	function warn(message) {
		var box = document.getElementById('kh-editor-warning');
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
			warn(i18n.editorFailed);
		}
	} else if (settings) {
		// Settings exist, so the person has NOT turned highlighting off; the
		// library genuinely failed to arrive. Worth saying out loud.
		warn(i18n.editorFailed);
	}
	// settings === false means the user switched syntax highlighting off in
	// their own profile. That is a choice, not a fault, and it gets no notice.

	/** The code, wherever it currently lives. */
	function codeValue() {
		return editor && editor.codemirror ? editor.codemirror.getValue() : textarea.value;
	}

	/* ================================================================== */
	/* The editor chrome follows the type                                  */
	/* ================================================================== */

	var typeEl = document.getElementById('kh-type');
	var barEl = document.getElementById('kh-editor-bar');
	var nameEl = document.getElementById('kh-file-name');
	var pillEl = document.getElementById('kh-lang-pill');

	/**
	 * Keep the bar honest.
	 *
	 * The dot colour, the filename extension and the pill all describe the
	 * selected type. If they stop agreeing with the dropdown, the bar is worse
	 * than not having one — it is a label that lies.
	 */
	function syncChrome(type) {
		if (barEl) {
			// Strips `kh-lang-`. The rename to Keelhob renamed the class this
			// line ADDS and missed the one it REMOVED: the regex still hunted
			// for the old prefix, matched nothing, and so every type switch
			// APPENDED another language class instead of replacing it.
			// Two `kh-lang-*` classes match the dot rule at equal specificity,
			// so source order decided — `.kh-lang-html` is last in studio.css,
			// which is why the dot froze on the first colour it was given and
			// never moved again. test-no-legacy-brand.php could not see it: it
			// searches for the old PRODUCT NAME, and `cp-` is not that word.
			barEl.className = barEl.className.replace(/\bkh-lang-\S+/g, '').trim();
			barEl.className += ' kh-lang-' + type;
		}
		if (nameEl) {
			nameEl.textContent = 'snippet.' + ((cfg.ext && cfg.ext[type]) || 'txt');
		}
		if (pillEl) {
			pillEl.textContent = type.toUpperCase();
		}
	}

	if (typeEl) {
		typeEl.addEventListener('change', function () {
			syncChrome(this.value);
			// Switching the type must re-highlight. Without it, changing a
			// snippet from PHP to CSS leaves you typing CSS into a PHP
			// highlighter, which reads as the editor being broken.
			if (editor && editor.codemirror) {
				editor.codemirror.setOption('mode', (cfg.modes && cfg.modes[this.value]) || 'text/plain');
			}
		});
	}

	/* ================================================================== */
	/* Conditional logic — the rule builder                                */
	/* ================================================================== */

	(function conditions() {
		var enable = document.getElementById('kh-cond-enabled');
		var body = document.getElementById('kh-cond-body');
		var list = document.getElementById('kh-cond-rules');
		var add = document.getElementById('kh-cond-add');
		var data = cfg.conditions || {};
		var conf = data.config || {};

		if (!enable || !body || !list || !add || !conf.types) {
			return;
		}

		list.setAttribute('data-empty', i18n.noRules || '');

		enable.addEventListener('change', function () {
			body.hidden = !this.checked;
			// Ticking the box with nothing in it should give you something to
			// fill in, not an empty panel with one button in it.
			if (this.checked && !list.children.length) {
				addRow();
			}
		});

		function el(tag, attrs, children) {
			var node = document.createElement(tag);
			Object.keys(attrs || {}).forEach(function (k) {
				if (k === 'class') {
					node.className = attrs[k];
				} else if (k === 'text') {
					node.textContent = attrs[k];
				} else {
					node.setAttribute(k, attrs[k]);
				}
			});
			(children || []).forEach(function (c) {
				node.appendChild(c);
			});
			return node;
		}

		function option(value, label, selected) {
			var o = el('option', { value: value, text: label });
			if (selected) {
				o.selected = true;
			}
			return o;
		}

		/** Operators depend on the rule type: URL path is the odd one out. */
		function operatorsFor(type) {
			return (conf.operators && conf.operators[type]) || (conf.operators && conf.operators['default']) || [];
		}

		/**
		 * Build the third field.
		 *
		 * A dropdown when the type has a fixed option set, a text box when it
		 * does not (URL path, post ID). This is the whole reason the builder is
		 * JavaScript: the field's KIND depends on the value of the field before
		 * it, so it cannot be printed once server-side.
		 */
		function valueField(type, current) {
			var opts = conf.values ? conf.values[type] : null;
			if (!opts) {
				return el('input', {
					type: 'text',
					name: 'cond_value[]',
					class: 'kh-cond-value regular-text',
					value: current == null ? '' : current,
					placeholder: i18n.valuePlaceholder || ''
				});
			}
			var sel = el('select', { name: 'cond_value[]', class: 'kh-cond-value' });
			opts.forEach(function (pair) {
				sel.appendChild(option(pair[0], pair[1], String(current) === String(pair[0])));
			});
			return sel;
		}

		function addRow(rule) {
			rule = rule || {};
			var types = Object.keys(conf.types);
			var type = rule.type && conf.types[rule.type] ? rule.type : types[0];

			var row = el('div', { class: 'kh-cond-rule' });

			var typeSel = el('select', { name: 'cond_type[]', class: 'kh-cond-type' });
			types.forEach(function (slug) {
				typeSel.appendChild(option(slug, conf.types[slug], slug === type));
			});

			var opSel = el('select', { name: 'cond_operator[]', class: 'kh-cond-op' });
			operatorsFor(type).forEach(function (pair) {
				opSel.appendChild(option(pair[0], pair[1], pair[0] === rule.operator));
			});

			// An array value means "any of these" and only legacy data and
			// agents write one. The builder edits one value per row, so show the
			// first and let a save rewrite it rather than silently dropping the
			// rest without saying so.
			var current = Array.isArray(rule.value) ? rule.value[0] : rule.value;
			var valField = valueField(type, current);

			var remove = el('button', {
				type: 'button',
				class: 'kh-cond-remove',
				text: i18n.remove || 'Remove',
				'aria-label': i18n.removeRule || 'Remove this rule'
			});

			typeSel.addEventListener('change', function () {
				var next = this.value;

				var newOps = el('select', { name: 'cond_operator[]', class: 'kh-cond-op' });
				operatorsFor(next).forEach(function (pair) {
					newOps.appendChild(option(pair[0], pair[1], false));
				});
				row.replaceChild(newOps, opSel);
				opSel = newOps;

				var newVal = valueField(next, '');
				row.replaceChild(newVal, valField);
				valField = newVal;
			});

			remove.addEventListener('click', function () {
				row.parentNode.removeChild(row);
			});

			row.appendChild(typeSel);
			row.appendChild(opSel);
			row.appendChild(valField);
			row.appendChild(remove);
			list.appendChild(row);
		}

		add.addEventListener('click', function () {
			addRow();
		});

		// Draw what is stored. Done here rather than server-side so the markup
		// for a saved rule and a freshly added one is produced by exactly one
		// piece of code — two would drift, and the drift would only show up on
		// a rule somebody had already saved.
		(data.rules || []).forEach(addRow);
	})();

	/* ================================================================== */
	/* Ask AI what this does                                               */
	/* ================================================================== */

	var btn = document.getElementById('kh-ask-ai');
	if (!btn) {
		return;
	}

	/**
	 * The button is disabled until there is something to ask about.
	 *
	 * Previously it was always live and answered an empty editor with an
	 * alert(). A button that can only tell you off is worse than one that is
	 * plainly not ready yet.
	 */
	function syncAskState() {
		var empty = !codeValue().trim();
		btn.disabled = empty;
		btn.title = empty ? i18n.writeSomethingFirst || '' : '';
	}

	syncAskState();
	if (editor && editor.codemirror) {
		editor.codemirror.on('change', syncAskState);
	} else {
		textarea.addEventListener('input', syncAskState);
	}

	btn.addEventListener('click', function () {
		var code = codeValue();
		if (!code.trim()) {
			return;
		}

		btn.disabled = true;
		btn.textContent = i18n.asking;

		var body = new FormData();
		body.append('action', 'keelhob_studio_review');
		body.append('nonce', cfg.nonce);
		body.append('code', code);
		body.append('snippet_type', typeEl ? typeEl.value : 'php');

		window
			.fetch(cfg.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
			.then(function (r) {
				return r.json();
			})
			.then(function (json) {
				document.getElementById('kh-ai-report').innerHTML =
					json && json.data && json.data.html
						? json.data.html
						: '<div class="notice notice-warning inline"><p>' +
							((json && json.data && json.data.message) || i18n.unreachable) +
							'</p></div>';
			})
			.catch(function () {
				document.getElementById('kh-ai-report').innerHTML =
					'<div class="notice notice-warning inline"><p>' + i18n.unreachableSafe + '</p></div>';
			})
			.finally(function () {
				btn.textContent = i18n.askAi;
				syncAskState();
			});
	});
})();
