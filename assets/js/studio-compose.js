/**
 * AI Compose, as a chat.
 *
 * WHAT THIS REPLACES. A three-step wizard — plan, approve, build — where every
 * step was a separate one-shot request carrying a single message. The model had
 * no memory of the step before, so there was no way to say "that has a typo,
 * fix it": the screen could only start again from a blank prompt.
 *
 * THE TRANSCRIPT LIVES HERE, not on the server. Every send posts the whole
 * conversation and the server keeps none of it. That is what makes "leave the
 * tab and it is gone" true rather than merely claimed, and it is why this
 * screen — which can generate PHP — stores nothing at all until somebody
 * clicks Save.
 *
 * SURVIVING A RELOAD, BUT NOT A NAVIGATION. The transcript is mirrored into
 * sessionStorage so a stray F5 does not throw away ten minutes of work. It is
 * restored ONLY when the browser says this load was a reload or a back/forward
 * step. A fresh navigation — clicking into another screen and coming back —
 * clears it, which is the reset people expect from a tab that claims to hold
 * nothing. Guessing from the URL cannot tell those two apart; the Navigation
 * Timing entry can.
 *
 * THE AUTOMATIC FIX RUNS ONCE. When a block fails the parse gate, the error is
 * posted back as a visible turn and the model gets one attempt to correct it.
 * One, not a loop: a loop that keeps resending a parse error spends real money
 * on somebody's API key while looking like progress, and two failures in a row
 * are a signal a person should see.
 *
 * ESCAPING. Assistant turns are rendered by the server, where esc_html() runs.
 * This file only ever writes user text with textContent. Nothing here builds
 * HTML out of model output — the day a client-side renderer got that wrong, a
 * snippet containing a <script> tag would run inside wp-admin.
 */
(function () {
	'use strict';

	var cfg = window.cpStudioCompose || {};
	var STORE_KEY = 'cpComposeChat';

	var chat = document.getElementById('kh-chat');
	var empty = document.getElementById('kh-chat-empty');
	var input = document.getElementById('kh-chat-input');
	var sendBtn = document.getElementById('kh-chat-send');
	var clearBtn = document.getElementById('kh-chat-clear');
	var statusEl = document.getElementById('kh-chat-status');

	if (!chat || !input || !sendBtn) {
		return;
	}

	/** [{role:'user'|'assistant', text:string}] — the whole conversation. */
	var turns = [];
	var busy = false;
	var autoFixUsed = false;

	// ---- storage ---------------------------------------------------------

	function wasReload() {
		try {
			var nav = window.performance.getEntriesByType('navigation')[0];
			return !!nav && (nav.type === 'reload' || nav.type === 'back_forward');
		} catch (e) {
			return false;
		}
	}

	function save() {
		try {
			window.sessionStorage.setItem(STORE_KEY, JSON.stringify(turns));
		} catch (e) {
			/* Private windows and blocked site data both throw. The chat still
			   works in memory; only the reload safety net is lost. */
		}
	}

	function forget() {
		try {
			window.sessionStorage.removeItem(STORE_KEY);
		} catch (e) {
			/* nothing to do */
		}
	}

	// ---- rendering -------------------------------------------------------

	function hideIntro() {
		if (empty) {
			empty.hidden = true;
		}
	}

	function scrollDown() {
		chat.scrollTop = chat.scrollHeight;
	}

	/** A person's turn. textContent, never innerHTML — this is user input. */
	function drawUser(text, label) {
		var wrap = document.createElement('div');
		wrap.className = 'kh-msg kh-msg-you';

		var who = document.createElement('div');
		who.className = 'kh-msg-who';
		who.textContent = label || cfg.i18n.you;

		var body = document.createElement('div');
		body.className = 'kh-msg-body';
		var p = document.createElement('div');
		p.className = 'kh-msg-text';
		p.textContent = text;
		body.appendChild(p);

		wrap.appendChild(who);
		wrap.appendChild(body);
		chat.appendChild(wrap);
		hideIntro();
		scrollDown();
		return wrap;
	}

	/** The model's turn, as markup the server already escaped. */
	function drawAI(html) {
		var holder = document.createElement('div');
		holder.innerHTML = html;
		var node = holder.firstElementChild;
		if (!node) {
			return null;
		}
		chat.appendChild(node);
		bindMessage(node);
		hideIntro();
		scrollDown();
		return node;
	}

	function drawNotice(text, kind) {
		var wrap = document.createElement('div');
		wrap.className = 'kh-msg kh-msg-note' + (kind === 'error' ? ' kh-msg-error' : '');
		wrap.textContent = text;
		chat.appendChild(wrap);
		hideIntro();
		scrollDown();
		return wrap;
	}

	function setBusy(on, message) {
		busy = on;
		sendBtn.disabled = on;
		input.disabled = on;
		statusEl.textContent = message || '';
		statusEl.hidden = !on;
	}

	/**
	 * Wire the controls inside a freshly-rendered assistant turn.
	 *
	 * Exported on window so the browser checks can inject the same server-
	 * rendered markup and exercise Save and Fix without spending a real AI call.
	 */
	function bindMessage(node) {
		// Record what the person originally asked for against each saved
		// snippet, so the row's ai_prompt says why it exists rather than being
		// blank on everything Compose ever produced.
		var first = '';
		for (var i = 0; i < turns.length; i++) {
			if (turns[i].role === 'user') {
				first = turns[i].text;
				break;
			}
		}
		Array.prototype.forEach.call(node.querySelectorAll('.kh-code-prompt'), function (el) {
			el.value = first;
		});

		Array.prototype.forEach.call(node.querySelectorAll('.kh-chat-fix'), function (btn) {
			btn.addEventListener('click', function () {
				if (busy) {
					return;
				}
				send(btn.getAttribute('data-error')
					? 'The site ran its syntax check on that code and it did not parse:\n\n'
						+ btn.getAttribute('data-error')
						+ '\n\nFix it and send the corrected snippet back as a single fenced code block. '
						+ 'Remember snippets are stored without an opening <?php tag.'
					: 'That code did not parse. Please fix it.', cfg.i18n.site);
			});
		});
	}

	window.cpComposeBindMessage = bindMessage;

	// ---- the round trip --------------------------------------------------

	function post(action, fields) {
		var body = new FormData();
		body.append('action', action);
		body.append('nonce', cfg.nonce);
		Object.keys(fields).forEach(function (k) {
			body.append(k, fields[k]);
		});
		return window
			.fetch(cfg.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
			.then(function (r) {
				return r.json();
			});
	}

	/**
	 * Send one turn and draw the answer.
	 *
	 * `label` names who the turn came from on screen. A parse error is posted
	 * as a user turn because that is the only role a provider accepts here, but
	 * showing it as though the PERSON typed it would make the transcript lie
	 * about who said what.
	 */
	function send(text, label, isAutoFix) {
		if (busy) {
			return;
		}
		text = (text || '').trim();
		if (!text) {
			window.alert(cfg.i18n.typeFirst);
			return;
		}

		if (!isAutoFix && !label) {
			autoFixUsed = false; // a new instruction earns a fresh fix attempt
		}

		turns.push({ role: 'user', text: text });
		drawUser(text, label);
		save();

		setBusy(true, isAutoFix ? cfg.i18n.fixing : cfg.i18n.thinking);

		post('keelhob_studio_chat', { turns: JSON.stringify(turns) })
			.then(function (res) {
				setBusy(false, '');

				if (!res || !res.success) {
					drawNotice((res && res.data && res.data.message) || cfg.i18n.unreachable, 'error');
					// The failed turn is kept. Dropping it would silently
					// rewrite the conversation the person can see.
					return;
				}

				turns.push({ role: 'assistant', text: res.data.text || '' });
				save();

				var node = drawAI(res.data.html);

				if (res.data.dropped) {
					drawNotice(
						res.data.dropped + ' earlier message(s) were dropped to keep this conversation within its size limit.'
					);
				}

				if (res.data.has_error && !autoFixUsed) {
					autoFixUsed = true;
					if (node) {
						// The button would offer a second route to the same
						// request that is already on its way.
						Array.prototype.forEach.call(node.querySelectorAll('.kh-chat-fix'), function (b) {
							b.hidden = true;
						});
					}
					send(res.data.fix_turn, cfg.i18n.site, true);
				}
			})
			.catch(function () {
				setBusy(false, '');
				drawNotice(cfg.i18n.unreachable, 'error');
			});
	}

	// ---- restore ---------------------------------------------------------

	/**
	 * Redraw a restored conversation.
	 *
	 * Only PLAIN TEXT is ever stored, so the assistant's markup has to be built
	 * again. It is rebuilt on the SERVER, by the same code that rendered it the
	 * first time — storing rendered HTML and injecting it back on load is how a
	 * stored-XSS bug gets written, and re-escaping it here in a second language
	 * would be the same bug with extra steps.
	 *
	 * No AI call is involved, so a reload costs nothing. Until the markup
	 * arrives the turns are shown as plain text, which means a slow or failed
	 * round trip degrades to a readable transcript rather than a blank screen.
	 */
	function restore(saved) {
		turns = saved;

		var slots = turns.map(function (t) {
			return t.role === 'user' ? drawUser(t.text) : drawUser(t.text, cfg.i18n.ai);
		});
		drawNotice('Restored after a reload. Ask for a change to carry on.');

		var texts = turns.map(function (t) {
			return t.role === 'assistant' ? t.text : '';
		});

		post('keelhob_studio_render', { texts: JSON.stringify(texts) })
			.then(function (res) {
				if (!res || !res.success || !res.data || !res.data.html) {
					return;
				}
				res.data.html.forEach(function (html, i) {
					if (!html || turns[i].role !== 'assistant' || !slots[i]) {
						return;
					}
					var holder = document.createElement('div');
					holder.innerHTML = html;
					var node = holder.firstElementChild;
					if (!node) {
						return;
					}
					slots[i].parentNode.replaceChild(node, slots[i]);
					bindMessage(node);
				});
				scrollDown();
			})
			.catch(function () {
				/* The plain-text transcript is already on screen. */
			});
	}

	if (wasReload()) {
		try {
			var saved = JSON.parse(window.sessionStorage.getItem(STORE_KEY) || '[]');
			if (Array.isArray(saved) && saved.length) {
				restore(saved);
			}
		} catch (e) {
			forget();
		}
	} else {
		forget();
	}

	// ---- input -----------------------------------------------------------

	sendBtn.addEventListener('click', function () {
		var text = input.value;
		input.value = '';
		send(text);
	});

	input.addEventListener('keydown', function (e) {
		if (e.key === 'Enter' && !e.shiftKey) {
			e.preventDefault();
			sendBtn.click();
		}
	});

	if (clearBtn) {
		clearBtn.addEventListener('click', function () {
			if (turns.length && !window.confirm(cfg.i18n.clearAsk)) {
				return;
			}
			turns = [];
			autoFixUsed = false;
			forget();
			Array.prototype.forEach.call(chat.querySelectorAll('.kh-msg'), function (n) {
				n.parentNode.removeChild(n);
			});
			if (empty) {
				empty.hidden = false;
			}
			input.focus();
		});
	}
})();
