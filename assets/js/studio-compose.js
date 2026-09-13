/**
 * AI Compose: describe an outcome, read the plan, decide.
 *
 * A FILE, NOT AN INLINE <script>, for the same reason as studio-edit.js: an
 * inline block printed by a render callback runs before everything the callback
 * enqueued, and that already cost this plugin a code editor that never loaded.
 *
 * WHAT CHANGED BEYOND THE MOVE. The old version had no way out. Once a plan
 * appeared it was only ever replaced, never cleared, so somebody handed a plan
 * they did not want could re-plan or reload the page and nothing else. The
 * prompt box is 300px above the plan you are reacting to, so in practice people
 * reloaded.
 *
 * Two ways out now, both next to the thing being rejected:
 *   Discard          - clears everything, no network call, no server state.
 *   Plan again       - scrolls the prompt back into view with its text selected.
 *
 * THE RULE THAT DOES NOT MOVE: nothing is written to the snippets table until
 * somebody clicks Save. Planning and building are pure. Auto-saving a draft
 * "for convenience" would mean a screen that generates PHP quietly fills a site
 * with rows nobody asked for.
 */
(function () {
	'use strict';

	var cfg = window.cpStudioCompose || {};
	var statusEl = document.getElementById('cp-compose-status');
	var planBox = document.getElementById('cp-compose-plan-box');
	var codeBox = document.getElementById('cp-compose-code-box');
	var promptEl = document.getElementById('cp-compose-prompt');
	var planBtn = document.getElementById('cp-compose-plan');
	var approvedPlan = '';

	if (!planBtn || !promptEl) {
		return;
	}

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

	function busy(message) {
		statusEl.textContent = message || '';
	}

	function error(box, message) {
		box.innerHTML = '<div class="notice notice-error inline"><p>' + message + '</p></div>';
		box.hidden = false;
	}

	/** Back to a blank slate. Nothing was ever stored, so nothing has to be undone. */
	function discard() {
		approvedPlan = '';
		planBox.innerHTML = '';
		planBox.hidden = true;
		codeBox.innerHTML = '';
		codeBox.hidden = true;
		busy('');
		promptEl.focus();
	}

	/**
	 * Re-plan, from where the person is actually looking.
	 *
	 * The prompt is far above the plan card. Selecting the text means the next
	 * keystroke replaces it, which is what somebody rejecting a plan almost
	 * always wants.
	 */
	function replan() {
		promptEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
		promptEl.focus();
		promptEl.select();
	}

	/**
	 * Wire the buttons inside a freshly-injected plan card.
	 *
	 * Exported on `window` so the browser checks can inject the same server-
	 * rendered markup and exercise these controls without spending a real AI
	 * call. The card is replaced wholesale each time, so listeners are attached
	 * to new elements and never accumulate.
	 */
	function bindPlan() {
		var build = document.getElementById('cp-compose-build');
		var drop = document.getElementById('cp-compose-discard');
		var again = document.getElementById('cp-compose-replan');

		if (drop) {
			drop.addEventListener('click', discard);
		}
		if (again) {
			again.addEventListener('click', replan);
		}
		if (!build) {
			return;
		}

		build.addEventListener('click', function () {
			busy(cfg.i18n.writing);
			post('claudpress_studio_build', {
				prompt: promptEl.value.trim(),
				plan: approvedPlan,
			}).then(function (res) {
				busy('');
				if (res && res.success) {
					codeBox.innerHTML = res.data.html;
					codeBox.hidden = false;
					bindCode();
					return;
				}
				error(codeBox, (res && res.data && res.data.message) || cfg.i18n.unreachable);
			});
		});
	}

	/**
	 * The acknowledgement gate on a failed syntax check.
	 *
	 * The server renders Save disabled when the parse failed. This turns it back
	 * on once the person ticks the box saying they have read the error.
	 *
	 * It is a gate, NOT a refusal, and the difference matters. save() parses
	 * again on the server and stores failing code switched off, which is the
	 * right behaviour and does not change here - throwing away somebody's work
	 * to protect them from a typo they can already see is its own bug. What was
	 * wrong was the screen saying "failed" and then offering an identical Save
	 * button, as though it had not just said that.
	 */
	function bindCode() {
		var ack = document.getElementById('cp-compose-ack');
		var save = document.getElementById('cp-compose-save');
		if (!ack || !save) {
			return;
		}
		ack.addEventListener('change', function () {
			save.disabled = !ack.checked;
		});
	}

	window.cpComposeBindPlan = bindPlan;
	window.cpComposeBindCode = bindCode;

	planBtn.addEventListener('click', function () {
		var prompt = promptEl.value.trim();
		if (!prompt) {
			window.alert(cfg.i18n.describeFirst);
			return;
		}

		busy(cfg.i18n.thinking);
		codeBox.hidden = true;

		post('claudpress_studio_plan', { prompt: prompt }).then(function (json) {
			busy('');
			if (!json || !json.success) {
				error(planBox, (json && json.data && json.data.message) || cfg.i18n.unreachable);
				return;
			}
			approvedPlan = json.data.plan_text || '';
			planBox.innerHTML = json.data.html;
			planBox.hidden = false;
			bindPlan();
		});
	});
})();
