/*
 * Thumbs up / down on a logged tool call, without reloading a long table.
 *
 * THIS FILE IS AN ENHANCEMENT AND NOTHING DEPENDS ON IT.
 *
 * Every control it touches is already a real <form> posting to admin-post.php
 * with a nonce, and that path is what runs when this script is absent, blocked,
 * or fails to parse. Removing the file changes how a vote feels and not whether
 * it works.
 *
 * NO OPTIMISTIC UI. The pressed state and the rating are only ever written from
 * the server's reply. On any failure — HTTP error, 403, expired nonce, a row
 * that has since been trimmed out of the ring buffer, or no network at all —
 * the buttons go back exactly as they were and a real error message is shown.
 * What is on screen is a record of what the server stored; a guess in its place
 * would be a lie about the site's own history.
 */
(function () {
	'use strict';

	var cfg = window.keelhobRatings || {};
	if (!cfg.ajaxUrl || !cfg.nonce || !window.fetch || !window.FormData) {
		return; // The forms still work. Leave them alone.
	}

	var forms = document.querySelectorAll('form[data-kh-vote]');
	if (!forms.length) {
		return;
	}

	function text(el, value) {
		if (el) {
			el.textContent = value;
		}
	}

	Array.prototype.forEach.call(forms, function (form) {
		form.addEventListener('submit', function (event) {
			var button = form.kh_pressed;
			form.kh_pressed = null;
			if (!button) {
				return; // Could not tell which button; let the form post.
			}

			event.preventDefault();

			var runId = form.getAttribute('data-kh-vote');
			var vote = button.value;
			var buttons = form.querySelectorAll('button[name="vote"]');
			var status = form.querySelector('[data-kh-vote-status]');
			var ratingCell = document.querySelector('[data-kh-rating="' + form.getAttribute('data-kh-tool') + '"]');

			/* Remember the exact state so a failure can restore it rather than
			   approximate it. */
			var was = Array.prototype.map.call(buttons, function (b) {
				return b.getAttribute('aria-pressed');
			});
			var wasStatus = status ? status.textContent : '';

			Array.prototype.forEach.call(buttons, function (b) {
				b.disabled = true;
			});
			text(status, '');

			var body = new FormData();
			body.append('action', 'keelhob_rate_run');
			body.append('nonce', cfg.nonce);
			body.append('run_id', runId);
			body.append('vote', vote);

			function restore(message) {
				Array.prototype.forEach.call(buttons, function (b, i) {
					b.disabled = false;
					if (was[i] === null) {
						b.removeAttribute('aria-pressed');
					} else {
						b.setAttribute('aria-pressed', was[i]);
					}
				});
				text(status, message || wasStatus);
				if (status) {
					status.setAttribute('role', 'alert');
				}
			}

			fetch(cfg.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: body
			}).then(function (response) {
				return response.json().then(function (json) {
					return { ok: response.ok, json: json };
				}, function () {
					return { ok: false, json: null };
				});
			}).then(function (result) {
				var payload = result.json && result.json.data ? result.json.data : null;
				if (!result.ok || !result.json || !result.json.success || !payload) {
					restore((payload && payload.message) || (cfg.i18n && cfg.i18n.failed) || 'Could not save that.');
					return;
				}

				/* Only now, with the server's answer in hand. */
				Array.prototype.forEach.call(buttons, function (b) {
					b.disabled = false;
					b.setAttribute(
						'aria-pressed',
						(b.value === 'up' && payload.vote === 1) || (b.value === 'down' && payload.vote === -1)
							? 'true'
							: 'false'
					);
				});
				text(status, '');
				if (status) {
					status.removeAttribute('role');
				}
				if (ratingCell && payload.rating) {
					ratingCell.textContent = payload.rating;
				}
			}).catch(function () {
				restore((cfg.i18n && cfg.i18n.offline) || 'Could not reach the site to save that.');
			});
		});

		/* Which of the two buttons was used. `submitter` is not available
		   everywhere this plugin runs, so it is recorded on the way in. */
		Array.prototype.forEach.call(form.querySelectorAll('button[name="vote"]'), function (b) {
			b.addEventListener('click', function () {
				form.kh_pressed = b;
			});
		});
	});
})();
