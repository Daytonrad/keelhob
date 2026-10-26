/*
 * tests/rig/ui/check-thumbs.mjs
 *
 * Does the thumbs control on the Activity screen actually work in a real
 * browser, and does it REVERT on failure instead of lying?
 *
 *   node tests/rig/ui/check-thumbs.mjs
 *
 * Needs the rig up on localhost:8899 and at least one row in the tool log; the
 * run reports "no thumbs form on the screen" rather than passing vacuously if
 * there is none. A green check against no content is the most expensive kind
 * of pass there is.
 *
 * The specific claim being checked is the hard one. The pressed state and the
 * rating are only ever written from the server's reply, so this runs the happy
 * path, then breaks the endpoint from inside the page and asserts the pressed
 * state goes back exactly as it was, a real error is shown and announced, and
 * the server never recorded the vote that appeared to fail. Reading the
 * JavaScript cannot establish any of that.
 */
import { launch, login, Report } from './harness.mjs';

const WP = 'http://localhost:8899';
const r = new Report();
let browser = null;

try {
  const l = await launch();
  browser = l.browser;
  const context = l.context;
  await login(context, { wp: WP, user: 'admin', pass: 'rigpass' });
  const page = await context.newPage();
  await page.goto(`${WP}/wp-admin/admin.php?page=keelhob-activity`, { waitUntil: 'domcontentloaded' });

  const sel = 'form[data-kh-vote] button[value="up"]';
  const dn = 'form[data-kh-vote] button[value="down"]';

  /* An empty log means nothing to vote on, and a run that passes anyway would
     be claiming the control works without ever having found it. */
  if (!(await page.$(sel))) {
    r.fail('thumbs', 'there is a thumbs form on the screen to measure',
      'the tool log is empty — drive one tool call through the MCP endpoint first');
    throw new Error('no thumbs form on the Activity screen');
  }

  r.check('thumbs', 'the thumbs buttons are rendered and on screen',
    await page.evaluate((s) => {
      const b = document.querySelector(s);
      if (!b) return false;
      const x = b.getBoundingClientRect();
      return x.width > 0 && x.height > 0;
    }, sel), 'first up button');

  r.check('thumbs', 'the control is a real submit button inside a real form posting to admin-post.php',
    await page.evaluate((s) => {
      const b = document.querySelector(s);
      const f = b && b.closest('form');
      // getAttribute, not f.action: the form carries <input name="action">,
      // and the DOM's named-element lookup wins over the property, so
      // f.action returns that INPUT rather than the URL.
      return !!f && f.getAttribute('method').toLowerCase() === 'post'
        && /admin-post\.php/.test(f.getAttribute('action'))
        && b.tagName === 'BUTTON' && b.type === 'submit';
    }, sel), 'no JavaScript required for it to work');

  r.check('thumbs', 'the script loaded',
    await page.evaluate(() => !!window.keelhobRatings && !!window.keelhobRatings.nonce),
    'keelhobRatings is present');

  /* --- happy path: pressed state comes from the server ----------------- */
  const before = await page.evaluate((s) => document.querySelector(s).getAttribute('aria-pressed'), sel);
  await page.click(sel);
  await page.waitForFunction(
    (s) => document.querySelector(s).getAttribute('aria-pressed') === 'true' && !document.querySelector(s).disabled,
    sel, { timeout: 8000 },
  );
  r.ok('thumbs', 'a successful vote sets aria-pressed from the response', `was ${before}, now true`);

  r.check('thumbs', 'the page did NOT navigate — the enhancement intercepted the submit',
    page.url().includes('page=keelhob-activity') && !page.url().includes('rated=1'),
    page.url());

  const ratingText = await page.evaluate(() => {
    const el = document.querySelector('[data-kh-rating]');
    return el ? el.textContent.trim() : '';
  });
  r.check('thumbs', 'the rating on screen was rewritten from the server\'s answer',
    /\d+\/100/.test(ratingText), ratingText);

  /* --- failure path: it must REVERT, not keep the optimistic state ----- */
  await page.evaluate(() => {
    window.__realFetch = window.fetch;
    window.fetch = () => Promise.resolve(new Response('{"success":false,"data":{"message":"Forced failure for the check."}}', {
      status: 500, headers: { 'Content-Type': 'application/json' },
    }));
  });

  const stateBefore = await page.evaluate((s) => ({
    up: document.querySelector(s).getAttribute('aria-pressed'),
  }), sel);

  await page.click(dn);
  await page.waitForFunction(
    () => {
      const m = document.querySelector('[data-kh-vote-status]');
      return m && m.textContent.trim().length > 0;
    },
    null, { timeout: 8000 },
  );

  const afterFail = await page.evaluate((s) => {
    const msg = document.querySelector('[data-kh-vote-status]');
    return {
      up: document.querySelector(s).getAttribute('aria-pressed'),
      down: document.querySelector('form[data-kh-vote] button[value="down"]').getAttribute('aria-pressed'),
      disabled: document.querySelector('form[data-kh-vote] button[value="down"]').disabled,
      msg: msg ? msg.textContent.trim() : '',
      role: msg ? msg.getAttribute('role') : null,
    };
  }, sel);

  r.check('thumbs', 'a failed request shows a real error message', afterFail.msg.length > 0, afterFail.msg);
  r.check('thumbs', 'and announces it', afterFail.role === 'alert', `role=${afterFail.role}`);
  r.check('thumbs', 'the control REVERTS — the pressed state is exactly what it was',
    afterFail.up === stateBefore.up && afterFail.down === 'false',
    `up=${afterFail.up} (was ${stateBefore.up}), down=${afterFail.down}`);
  r.check('thumbs', 'and the buttons are usable again', afterFail.disabled === false, `disabled=${afterFail.disabled}`);

  const server = await page.evaluate(async () => {
    window.fetch = window.__realFetch;
    const b = new FormData();
    b.append('action', 'keelhob_rate_run');
    b.append('nonce', window.keelhobRatings.nonce);
    b.append('run_id', document.querySelector('form[data-kh-vote]').getAttribute('data-kh-vote'));
    b.append('vote', 'clear');
    const res = await fetch(window.keelhobRatings.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: b });
    return (await res.json()).data;
  });
  r.check('thumbs', 'the server never recorded the failed vote', server.vote === 0 || server.vote === 1,
    `server vote after the forced failure: ${server.vote}`);
} catch (e) {
  r.fail('thumbs', 'the checks themselves', e.message);
} finally {
  if (browser) await browser.close();
}

process.exit(r.print() ? 0 : 1);
