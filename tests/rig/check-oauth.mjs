/**
 * The OAuth flow, end to end, against the real rig.
 *
 * WHY THIS EXISTS AND WHAT ONLY IT CAN SEE.
 *
 * Four of the six OAuth rules are sequencing or rendering, and a unit test
 * cannot see either:
 *
 *   - **The code dies on first use.** That is the ORDER of a read, a delete and
 *     a validation inside one request handler. The only way to know it holds is
 *     to redeem a code twice against a running server and watch the second
 *     attempt fail.
 *   - **The consent screen renders.** `render_consent()` ends in `exit`. It has
 *     no return value, and its CSS is inline because it is served outside
 *     wp-admin — so "does it look like a consent screen" is a question only a
 *     browser answers.
 *   - **An error is shown, not redirected.** The assertion is that the browser
 *     is still on this site's own host afterwards. Nothing in the PHP says so.
 *   - **The 401 names where to sign in.** Without that header no client can get
 *     from a URL to a consent screen, and every other part of the feature is
 *     unreachable.
 *
 * TEARDOWN IS IN A `finally` AND IT IS NOT OPTIONAL. This run registers OAuth
 * clients on a live rig and switches a security feature ON. Leaving either
 * behind means the next run starts from a state nobody chose.
 *
 *   node tests/rig/check-oauth.mjs --wp http://localhost:8899 --rig /c/wp-rig/wordpress
 */

import { createHash, randomBytes } from 'node:crypto';
import { request as httpRequest } from 'node:http';
import { args, Report, launch, wpEval, wpJson } from './ui/harness.mjs';

const opt = args();
const WP = opt.wp || 'http://localhost:8899';
const RIG = opt.rig || '/c/wp-rig/wordpress';
const USER = opt.user || 'admin';
const PASS = opt.pass || 'rigpass';
const wpOpts = { path: RIG, cli: opt.cli };

const report = new Report();

/*
 * ok() here, NOT ok().
 *
 * `Report.ok(baseline, label)` in ui/harness.mjs records an UNCONDITIONAL pass
 * — its first argument is a baseline tag, not a condition. The first version of
 * this file called it as `ok(condition, label)`, so every assertion
 * passed no matter what, and a run whose consent screen never rendered reported
 * "All 14 browser checks passed". `report.check()` is the asserting one. This
 * wrapper exists so that mistake cannot be made again in this file.
 */
const ok = (condition, label, detail = '') => report.check('oauth', label, !!condition, detail);

/** The loopback address this run registers. Nothing listens on it; Playwright
 *  fulfils the request so the redirect completes and its URL can be read. */
const CALLBACK = 'http://127.0.0.1:59999/cb';

/* ----------------------------------------------------------------- http */

function post(path, body, headers = {}) {
  return new Promise((resolve) => {
    const payload = typeof body === 'string' ? body : JSON.stringify(body);
    const req = httpRequest(
      {
        host: new URL(WP).hostname,
        port: new URL(WP).port || 80,
        path,
        method: 'POST',
        headers: { 'Content-Length': Buffer.byteLength(payload), ...headers },
      },
      (res) => {
        let raw = '';
        res.on('data', (d) => (raw += d));
        res.on('end', () => {
          let json = null;
          try { json = JSON.parse(raw); } catch { /* not every response is JSON */ }
          resolve({ code: res.statusCode, headers: res.headers, raw, json });
        });
      }
    );
    req.on('error', (e) => resolve({ code: 0, raw: String(e.message), headers: {}, json: null }));
    req.write(payload);
    req.end();
  });
}

function get(path, headers = {}) {
  return new Promise((resolve) => {
    const req = httpRequest(
      { host: new URL(WP).hostname, port: new URL(WP).port || 80, path, method: 'GET', headers },
      (res) => {
        let raw = '';
        res.on('data', (d) => (raw += d));
        res.on('end', () => {
          let json = null;
          try { json = JSON.parse(raw); } catch { /* not every response is JSON */ }
          resolve({ code: res.statusCode, headers: res.headers, raw, json });
        });
      }
    );
    req.on('error', (e) => resolve({ code: 0, raw: String(e.message), headers: {}, json: null }));
    req.end();
  });
}

const route = (p) => `/index.php?rest_route=/keelhob/v1/${p}`;
const form = (obj) => new URLSearchParams(obj).toString();
const FORM = { 'Content-Type': 'application/x-www-form-urlencoded' };
const JSONH = { 'Content-Type': 'application/json' };

/* ------------------------------------------------------------ the state */

let browser = null;
let before = null;
let clientId = '';

function settings() {
  return wpJson('echo wp_json_encode( get_option( "keelhob_settings", array() ) );', wpOpts);
}

function teardown() {
  wpEval(
    `$s = get_option( 'keelhob_settings', array() );
     if ( ! is_array( $s ) ) { $s = array(); }
     $s['oauth_enabled'] = ${before && before.oauth_enabled ? 'true' : 'false'};
     update_option( 'keelhob_settings', $s );
     delete_option( 'keelhob_oauth_clients' );
     global $wpdb;
     $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_keelhob_oauth_%' OR option_name LIKE '_transient_timeout_keelhob_oauth_%' " );
     echo 'torn down';`,
    wpOpts
  );
}

/* -------------------------------------------------------------- the run */

async function main() {
  before = settings();
  ok(before !== null, 'read the rig settings so they can be put back exactly');

  // Switch the feature on for the duration of this run.
  wpEval(
    `$s = get_option( 'keelhob_settings', array() );
     if ( ! is_array( $s ) ) { $s = array(); }
     $s['oauth_enabled'] = true;
     update_option( 'keelhob_settings', $s );
     delete_option( 'keelhob_oauth_clients' );
     echo 'on';`,
    wpOpts
  );

  /* ---- discovery ---- */

  /*
   * GET, because the route is registered GET-only. The first version POSTed
   * here and accepted "405 or 200" — it got a 404 and reported a pass anyway,
   * which is how the fake-assertion bug above stayed invisible.
   */
  const asDoc = await get(route('oauth/metadata'));
  ok(asDoc.code === 200, `the metadata route answers 200 (got ${asDoc.code})`);
  ok(
    !!(asDoc.json && asDoc.json.authorization_server && asDoc.json.protected_resource),
    'and carries both discovery documents'
  );
  ok(
    asDoc.json
      && Array.isArray(asDoc.json.authorization_server.code_challenge_methods_supported)
      && asDoc.json.authorization_server.code_challenge_methods_supported.join() === 'S256',
    'advertising S256 and nothing else — the advertisement is the policy'
  );

  /* ---- registration ---- */

  const reg = await post(
    route('oauth/register'),
    { client_name: 'Keelhob rig probe', redirect_uris: [CALLBACK] },
    JSONH
  );
  ok(reg.code === 201, `registration returns 201 (got ${reg.code})`);
  ok(!!(reg.json && reg.json.client_id), 'and a client_id');
  clientId = reg.json ? String(reg.json.client_id) : '';
  ok(
    reg.json ? reg.json.client_secret === undefined : false,
    'a public client is issued NO secret — PKCE stands in for one'
  );

  // The userinfo trick, against the live endpoint.
  const bad = await post(
    route('oauth/register'),
    { client_name: 'Trick', redirect_uris: ['https://evil.test@good.test/cb'] },
    JSONH
  );
  ok(bad.code === 400, `a userinfo redirect_uri is refused with 400 (got ${bad.code})`);

  const badScheme = await post(
    route('oauth/register'),
    { client_name: 'Trick2', redirect_uris: ['javascript:alert(1)'] },
    JSONH
  );
  ok(badScheme.code === 400, `a javascript: redirect_uri is refused with 400 (got ${badScheme.code})`);

  /* ---- the 401 that makes sign-in discoverable ---- */

  const mcp401 = await post(route('mcp'), { jsonrpc: '2.0', id: 1, method: 'tools/list' }, JSONH);
  ok(mcp401.code === 401, `an unauthenticated MCP call is 401 (got ${mcp401.code})`);
  const challenge = String(mcp401.headers['www-authenticate'] || '');
  ok(
    challenge.includes('resource_metadata='),
    `the 401 names where to sign in (${challenge || 'HEADER ABSENT'})`
  );
  ok(
    challenge.includes('/.well-known/oauth-protected-resource'),
    'at the path RFC 9728 defines'
  );

  /* ---- consent, in a browser ---- */

  const verifier = randomBytes(32).toString('base64url');
  const codeChallenge = createHash('sha256').update(verifier).digest('base64url');

  const launched = await launch({ chrome: opt.chrome, headed: opt.headed });
  browser = launched.browser;

  /*
   * Sign in here rather than through harness login().
   *
   * That helper wraps its click in `Promise.all([waitForNavigation, click])`,
   * and on this rig the navigation can complete before the waiter attaches —
   * the run then dies with "Timeout 15000ms exceeded" having ALREADY reached
   * /wp-admin/. Waiting for the destination instead of for the event is both
   * simpler and immune to that race, and the assertion below is what proves it
   * worked rather than the absence of an exception.
   */
  const page = await launched.context.newPage();
  await page.goto(`${WP}/wp-login.php`, { waitUntil: 'load' });
  if (await page.locator('#user_login').count()) {
    await page.fill('#user_login', USER);
    await page.fill('#user_pass', PASS);
    await page.click('#wp-submit');
    await page.waitForURL(/wp-admin/, { timeout: 20000 }).catch(() => {});
  }
  await page.goto(`${WP}/wp-admin/`, { waitUntil: 'load' });
  ok(
    (await page.locator('#wpadminbar').count()) === 1,
    'signed in to wp-admin (the admin bar is on the page)'
  );

  /*
   * Capture the callback URL from the REQUEST, and let the connection fail.
   *
   * Nothing listens on that port, so the navigation ends in
   * ERR_CONNECTION_REFUSED. Two attempts to paper over that — fulfilling the
   * request through page.route() and then through context.route() — both left
   * the navigation refused, and the run died before reaching the replay check
   * that is the reason this file exists.
   *
   * The fix is to stop trying to make the navigation succeed. A `request`
   * event fires before the connection is attempted, so the URL (with the code
   * and state in its query) is readable whether or not anything answers. That
   * is all this run needs, and it is closer to the truth: in real life the
   * thing listening on that port is the AI client, not this test.
   */
  let callbackUrl = '';
  page.on('request', (r) => {
    if (r.url().startsWith('http://127.0.0.1:59999/')) {
      callbackUrl = r.url();
    }
  });

  const authorizeUrl =
    `${WP}/wp-admin/admin-post.php?action=keelhob_authorize` +
    `&client_id=${encodeURIComponent(clientId)}` +
    `&response_type=code&redirect_uri=${encodeURIComponent(CALLBACK)}` +
    `&code_challenge=${codeChallenge}&code_challenge_method=S256&state=rigstate`;

  await page.goto(authorizeUrl, { waitUntil: 'load' });

  const heading = (await page.locator('h1').first().innerText().catch(() => '')).trim();
  ok(/allow this app to connect/i.test(heading), `the consent page renders its question (${heading || 'NO H1'})`);
  ok(
    (await page.locator('.kh-card').count()) === 1,
    'inside the standalone card, not wp-admin chrome'
  );
  ok(
    (await page.locator('.kh-pill').first().innerText()).includes('Keelhob rig probe'),
    "and names the app that asked"
  );

  // The sentence the whole feature rests on.
  const boxText = await page.locator('.kh-box').first().innerText();
  ok(boxText.trim().length > 40, 'the consent box states what is being granted, in a sentence');

  // Measured, not read off the markup: the card has to be readable.
  const contrast = await page.evaluate(() => {
    const el = document.querySelector('.kh-card h1');
    const cs = getComputedStyle(el);
    const bg = getComputedStyle(document.querySelector('.kh-card')).backgroundColor;
    return { fg: cs.color, bg };
  });
  ok(
    contrast.bg !== 'rgba(0, 0, 0, 0)',
    `the card paints a real background, so the text is on something (${contrast.bg})`
  );

  /* ---- Allow ---- */

  // The click navigates to a port nothing answers on, so the promise rejects.
  // The URL has already been captured by then; the rejection is the expected
  // outcome, not an error, and swallowing it is deliberate.
  await page.locator('button[value="allow"]').click().catch(() => {});
  for (let i = 0; i < 40 && callbackUrl === ''; i++) {
    await page.waitForTimeout(100);
  }
  ok(callbackUrl !== '', 'pressing Allow sends the browser to the callback');
  const landed = new URL(callbackUrl || 'http://unreached.invalid/');
  const code = landed.searchParams.get('code') || '';
  ok(landed.origin === 'http://127.0.0.1:59999', 'Allow redirects to the registered callback');
  ok(code.length === 64, `with a 64-character code (${code.length})`);
  ok(landed.searchParams.get('state') === 'rigstate', 'and the state the client sent');

  /* ---- redeem ---- */

  const tok = await post(
    route('oauth/token'),
    form({
      grant_type: 'authorization_code',
      code,
      client_id: clientId,
      redirect_uri: CALLBACK,
      code_verifier: verifier,
    }),
    FORM
  );
  ok(tok.code === 200, `the code exchanges for a token (${tok.code})`);
  const access = tok.json ? String(tok.json.access_token || '') : '';
  const refresh = tok.json ? String(tok.json.refresh_token || '') : '';
  ok(access.startsWith('kha_'), 'an access token with its prefix');
  ok(refresh.startsWith('khr_'), 'and a refresh token');
  ok(tok.json && tok.json.expires_in === 3600, 'expiring in one hour');
  ok(
    String(tok.headers['cache-control'] || '').includes('no-store'),
    'and the response is no-store, so no proxy keeps a copy'
  );

  /* ---- THE REPLAY. The reason this file exists. ---- */

  const replay = await post(
    route('oauth/token'),
    form({
      grant_type: 'authorization_code',
      code,
      client_id: clientId,
      redirect_uri: CALLBACK,
      code_verifier: verifier,
    }),
    FORM
  );
  ok(replay.code === 400, `replaying the SAME code is refused (${replay.code})`);
  ok(
    replay.json && replay.json.error === 'invalid_grant',
    `with invalid_grant (${replay.json ? replay.json.error : replay.raw.slice(0, 60)})`
  );

  /* ---- the token actually works on MCP ---- */

  const call = await post(
    route('mcp'),
    { jsonrpc: '2.0', id: 1, method: 'tools/list' },
    { ...JSONH, Authorization: `Bearer ${access}` }
  );
  ok(call.code === 200, `the access token authenticates an MCP call (${call.code})`);
  ok(
    !!(call.json && call.json.result && Array.isArray(call.json.result.tools) && call.json.result.tools.length > 0),
    'and tools/list comes back with tools'
  );

  /* ---- refresh ---- */

  const refreshed = await post(
    route('oauth/token'),
    form({ grant_type: 'refresh_token', refresh_token: refresh, client_id: clientId }),
    FORM
  );
  ok(refreshed.code === 200, `the refresh token mints a new access token (${refreshed.code})`);
  ok(
    refreshed.json && refreshed.json.refresh_token === refresh,
    'and returns the SAME refresh token — reuse, not rotation (see class-oauth-token.php)'
  );

  /* ---- PKCE really is required ---- */

  callbackUrl = '';
  const noPkce = await page.goto(
    `${WP}/wp-admin/admin-post.php?action=keelhob_authorize&client_id=${encodeURIComponent(clientId)}` +
      `&response_type=code&redirect_uri=${encodeURIComponent(CALLBACK)}`,
    { waitUntil: 'load' }
  );
  ok(
    new URL(page.url()).hostname === new URL(WP).hostname,
    'a request with no PKCE challenge is NOT redirected to the client — the error is shown here'
  );
  ok(
    /not valid/i.test(await page.locator('h1').first().innerText()),
    'and says so on screen'
  );
  ok(noPkce ? noPkce.status() === 400 : false, `with a 400 status (${noPkce ? noPkce.status() : '?'})`);

  /* ---- rule 2, in a browser ---- */

  await page.goto(
    `${WP}/wp-admin/admin-post.php?action=keelhob_authorize&client_id=${encodeURIComponent(clientId)}` +
      `&response_type=code&redirect_uri=${encodeURIComponent('http://127.0.0.1:59999/cb/../cb')}` +
      `&code_challenge=${codeChallenge}&code_challenge_method=S256`,
    { waitUntil: 'load' }
  );
  ok(
    new URL(page.url()).hostname === new URL(WP).hostname,
    'a near-miss redirect_uri is refused on screen rather than followed'
  );

  /* ---- removing the client revokes immediately ---- */

  wpEval(
    `$c = get_option( 'keelhob_oauth_clients', array() );
     unset( $c['${clientId.replace(/[^a-z0-9_]/gi, '')}'] );
     update_option( 'keelhob_oauth_clients', $c, false );
     echo 'removed';`,
    wpOpts
  );
  const afterRemoval = await post(
    route('mcp'),
    { jsonrpc: '2.0', id: 1, method: 'tools/list' },
    { ...JSONH, Authorization: `Bearer ${access}` }
  );
  ok(
    afterRemoval.code === 401,
    `removing the client kills its token on the very next call (${afterRemoval.code})`
  );

  /* ---- and switching the feature off kills everything ---- */

  wpEval(
    `$s = get_option( 'keelhob_settings', array() );
     $s['oauth_enabled'] = false;
     update_option( 'keelhob_settings', $s );
     echo 'off';`,
    wpOpts
  );
  const offDoc = await post(route('oauth/register'), { redirect_uris: [CALLBACK] }, JSONH);
  ok(offDoc.code === 404, `with the switch off, registration 404s (${offDoc.code})`);
}

try {
  await main();
} catch (err) {
  ok(false, `the run threw: ${err && err.message ? err.message : err}`);
} finally {
  try {
    if (browser) await browser.close();
  } catch { /* nothing to do about a browser that will not close */ }

  try {
    teardown();
    const left = wpJson('echo wp_json_encode( array( "clients" => get_option( "keelhob_oauth_clients", array() ) ) );', wpOpts);
    ok(
      !left || !left.clients || Object.keys(left.clients).length === 0,
      'teardown: no OAuth clients survive this run'
    );
    const now = settings();
    ok(
      !!now && !!before && Boolean(now.oauth_enabled) === Boolean(before.oauth_enabled),
      `teardown: the oauth switch is back where it was (${before ? Boolean(before.oauth_enabled) : '?'})`
    );
    const stale = wpJson(
      `global $wpdb;
       echo wp_json_encode( array( 'n' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_keelhob_oauth_%'" ) ) );`,
      wpOpts
    );
    ok(stale && stale.n === 0, `teardown: no OAuth transients left behind (${stale ? stale.n : '?'})`);
  } catch (err) {
    ok(false, `teardown failed: ${err && err.message ? err.message : err}`);
  }
}

process.exit(report.print() ? 0 : 1);
