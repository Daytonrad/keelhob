/**
 * Agent-written abilities, proved end to end on a real WordPress install.
 *
 *   node tests/rig/check-abilities.mjs --wp http://localhost:8899 \
 *        --rig /c/wp-rig/wordpress --user admin --pass rigpass \
 *        --cli "php C:/wp-cli/wp-cli.phar"
 *
 * WHY THIS FILE EXISTS, AND WHY NOTHING ELSE CAN REPLACE IT.
 *
 * tests/unit/test-abilities-approval.php proves the guards against fixtures.
 * That is worth having and it is not the claim. The claim is about a real site:
 * that PHP a language model wrote, sitting in a real database, called through
 * the real HTTP endpoint a real client connects to, does not run until a real
 * human has pressed a button in a real browser. Every word in that sentence is
 * a layer a stub replaces, so each of the four proofs below checks the WORLD
 * and not the response:
 *
 *   1. PENDING CODE DOES NOT RUN. The ability's body writes a file and sets an
 *      option. It is called over MCP while pending. The response is asserted,
 *      and then the filesystem and the options table are asserted — because a
 *      refusal message is not evidence that nothing happened.
 *
 *   2. APPROVAL WORKS WITH JAVASCRIPT DISABLED. The browser context is created
 *      with javaScriptEnabled: false. If the approval depended on a script, the
 *      tick and the click would do nothing and the ability would stay pending.
 *
 *   3. THE HASH GUARD REFUSES TAMPERED CODE. The stored code is edited in the
 *      database without touching code_hash — exactly what a compromised admin
 *      account, or a careless SQL fix, would do — and the ability stops running.
 *
 *   4. ROLLBACK WORKS. v2 is proposed, approved and shown live; v1 is rolled
 *      back to and shown live again, by what the endpoint actually returns.
 *
 * TEARDOWN IS IN A `finally` AND IT IS NOT OPTIONAL. A pending agent-written
 * ability surviving a test run is the single artifact this feature must never
 * leave behind, so every row, option, file and setting this run touches is
 * removed whether it passes, fails or throws — and the run begins by removing
 * strays a crashed earlier run may have left.
 */

import { args, launch, wpEval, wpJson } from './ui/harness.mjs';
import { existsSync, readFileSync } from 'node:fs';

const opt = args();
const WP = (opt.wp || 'http://localhost:8899').replace(/\/$/, '');
const RIG = opt.rig || '/c/wp-rig/wordpress';
const USER = opt.user || 'admin';
const PASS = opt.pass || 'rigpass';
const CLI = opt.cli || null;
const wpOpts = { path: RIG, cli: CLI };
const ENDPOINT = `${WP}/?rest_route=/keelhob/v1/mcp`;

/* The names and artifacts this run owns. Prefixed so it cannot collide with
   check-admin.mjs's or check-snippets.mjs's fixtures, or delete their rows. */
const ABILITY = 'kh_proof_probe';
const OPTION = 'kh_proof_probe_marker';
const PROOF_DIR = 'kh-ability-proof';

/*
 * A local reporter rather than harness.mjs's Report, whose shape is
 * (baseline, label) for the redesign's before/after matrix. This file has no
 * baseline: a check here either holds or the feature is unsafe. Nothing throws,
 * so a first failure does not hide the rest.
 */
const report = {
  rows: [],
  started: Date.now(),
  group(name) {
    this.rows.push({ group: name });
  },
  ok(condition, label) {
    this.rows.push({ pass: !!condition, label });
  },
  summary() {
    let bad = 0;
    for (const r of this.rows) {
      if (r.group) {
        console.log(`\n${r.group}`);
        continue;
      }
      if (!r.pass) bad++;
      console.log(`  ${r.pass ? 'PASS' : 'FAIL'}  ${r.label}`);
    }
    const total = this.rows.filter((r) => !r.group).length;
    const secs = ((Date.now() - this.started) / 1000).toFixed(1);
    console.log('\n--------------------------------------------------------------');
    console.log(bad === 0 ? `All ${total} checks passed in ${secs}s.` : `${bad} of ${total} checks FAILED in ${secs}s.`);
    return bad === 0 ? 0 : 1;
  },
};

report.group('Setup');

/** The body the model "wrote". It writes a file AND an option, on purpose. */
const body = (v) => `
$dir = WP_CONTENT_DIR . '/${PROOF_DIR}';
if ( ! is_dir( $dir ) ) { @mkdir( $dir, 0777, true ); }
file_put_contents( $dir . '/ran.txt', 'v${v}' );
update_option( '${OPTION}', 'v${v}' );
return array( 'body_version' => ${v} );
`.trim();

/* --------------------------------------------------------------- MCP client */

let TOKEN = null;

async function mcp(method, params) {
  const res = await fetch(ENDPOINT, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json, text/event-stream',
      Authorization: `Bearer ${TOKEN}`,
    },
    body: JSON.stringify({ jsonrpc: '2.0', id: Date.now(), method, params }),
  });
  const text = await res.text();
  let json = null;
  try {
    json = JSON.parse(text);
  } catch {
    throw new Error(`MCP ${method} returned non-JSON (${res.status}): ${text.slice(0, 400)}`);
  }
  return { status: res.status, json };
}

/** tools/call, unwrapped to the tool's own JSON payload plus the isError flag. */
async function callTool(name, args_ = {}) {
  const { json } = await mcp('tools/call', { name, arguments: args_ });
  const result = json.result || {};
  const raw = result.content?.[0]?.text ?? '';
  let data = {};
  try {
    data = JSON.parse(raw);
  } catch {
    data = { _raw: raw };
  }
  return { isError: !!result.isError, data, raw };
}

/* ------------------------------------------------------------ rig state i/o */

const phpEsc = (s) => String(s).replace(/\\/g, '\\\\').replace(/'/g, "\\'");

function abilityRows() {
  return wpJson(
    `global $wpdb;
    $rows = $wpdb->get_results( "SELECT id, name, version, status, code_hash, LENGTH(code) AS len FROM {$wpdb->prefix}keelhob_abilities WHERE name = '${phpEsc(ABILITY)}' ORDER BY version ASC, id ASC", ARRAY_A );
    echo wp_json_encode( $rows ?: array() );`,
    wpOpts
  );
}

function optionValue() {
  return wpEval(`echo (string) get_option( '${phpEsc(OPTION)}', '' );`, wpOpts).trim();
}

function proofFile() {
  const dir = wpEval(`echo WP_CONTENT_DIR;`, wpOpts).trim();
  const path = `${dir.replace(/\\/g, '/')}/${PROOF_DIR}/ran.txt`;
  return { path, exists: existsSync(path), body: existsSync(path) ? readFileSync(path, 'utf8') : null };
}

/*
 * The four tables a tool run writes to besides its own.
 *
 * Calling an ability does not only touch keelhob_abilities. Running any tool
 * writes a run-log row, and 2.0.0 made that log the home of ratings too, so a
 * single probe leaves rows in the log, the per-tool stats, the lessons and the
 * feedback queue. The first version of this teardown deleted the ability and
 * nothing else, so every run left four kinds of row behind on the rig and the
 * "no artifacts survive" assertion was true only of the row it looked at.
 *
 * Deleting by tool NAME would be wrong: `ability_create` is a real tool and a
 * real run on this rig may have used it. So the run records the highest id in
 * each table before it seeds anything and deletes only what came after. That
 * cannot reach a pre-existing row, and it catches rows this run caused
 * indirectly — which deleting by name would miss.
 */
const RUN_TABLES = ['keelhob_log', 'keelhob_tool_stats', 'keelhob_lessons', 'keelhob_feedback'];

/** Highest id in each RUN_TABLES table, or 0 for a table that is not installed. */
function watermarks() {
  const php = RUN_TABLES.map(
    (t) => `"${t}" => (int) $wpdb->get_var( "SELECT COALESCE(MAX(id),0) FROM {$wpdb->prefix}${t}" )`
  ).join(', ');
  return wpJson(`global $wpdb; echo wp_json_encode( array( ${php} ) );`, wpOpts);
}

/** How many rows each table has gained since the watermark. */
function rowsSince(marks) {
  const php = RUN_TABLES.map(
    (t) => `"${t}" => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}${t} WHERE id > ${Number(marks[t]) || 0}" )`
  ).join(', ');
  return wpJson(`global $wpdb; echo wp_json_encode( array( ${php} ) );`, wpOpts);
}

/** Everything this run created, gone. Safe to call twice. */
function teardown(marks = null) {
  const since = marks
    ? RUN_TABLES.map(
        (t) => `$wpdb->query( "DELETE FROM {$wpdb->prefix}${t} WHERE id > ${Number(marks[t]) || 0}" );`
      ).join(String.fromCharCode(10) + '    ')
    : '';
  wpEval(
    `global $wpdb;
    $wpdb->query( "DELETE FROM {$wpdb->prefix}keelhob_abilities WHERE name = '${phpEsc(ABILITY)}'" );
    delete_option( '${phpEsc(OPTION)}' );
    $dir = WP_CONTENT_DIR . '/${PROOF_DIR}';
    foreach ( ( glob( $dir . '/*' ) ?: array() ) as $f ) { @unlink( $f ); }
    @rmdir( $dir );
    ${since}
    echo 'torn down';`,
    wpOpts
  );
}

/* ---------------------------------------------------------------- the run */

let before = null;
let browser = null;
/* Set once the strays are gone, so teardown only ever deletes rows this run
 * is responsible for. Null until then, which makes the stray sweep below a
 * name-only delete on purpose. */
let marks = null;

async function main() {
  // Strays from a crashed run, removed BEFORE anything is seeded. A pending
  // ability left on a rig is the artifact that must not exist, so this run does
  // not start by assuming it is the first.
  const strays = abilityRows();
  if (strays.length) {
    console.log(`  cleaning ${strays.length} stray row(s) from an earlier run`);
    teardown();
  }
  report.ok(abilityRows().length === 0, 'the rig starts with no rows for this ability');

  // Taken AFTER the stray sweep and BEFORE anything is seeded: everything past
  // these ids is this run's doing.
  marks = watermarks();
  console.log(`  run-log watermark: ${RUN_TABLES.map((t) => `${t}=${marks[t]}`).join(' ')}`);

  // The rig's own settings, saved so they can be put back exactly.
  before = wpJson(
    `$s = get_option( 'keelhob_settings', array() );
    echo wp_json_encode( array(
      'abilities_enabled' => $s['abilities_enabled'] ?? null,
      'cap'               => $s['capabilities']['abilities'] ?? null,
      'log_calls'         => $s['log_calls'] ?? null,
    ) );`,
    wpOpts
  );

  report.ok(before.cap !== true, 'and the "write new code tools" capability is NOT on by default — a fresh install refuses to let a model author PHP');

  // Switch on what the proofs need, and mint a token. set_token() is the same
  // path the Connect screen uses.
  TOKEN = wpEval(
    `$kh = Keelhob::instance();
    $s = $kh->settings();
    $caps = is_array( $s['capabilities'] ?? null ) ? $s['capabilities'] : array();
    $caps['abilities'] = true;
    $kh->update( array( 'abilities_enabled' => true, 'capabilities' => $caps ) );
    echo Keelhob::set_token();`,
    wpOpts
  ).trim();
  report.ok(/^kh_/.test(TOKEN), `a bearer token was minted (${TOKEN.slice(0, 6)}…)`);

  const tools = await mcp('tools/list', {});
  const names = (tools.json.result?.tools || []).map((t) => t.name);
  report.ok(names.includes('ability_create'), 'with the capability on, ability_create is in tools/list');
  report.ok(names.includes('ability_improve'), 'and so is ability_improve');
  report.ok(!names.includes(ABILITY), 'and the ability under test does not exist yet');

  /* ============================================ PROOF 1 — pending never runs */

  report.group('Proof 1 — pending code does not run');

  const created = await callTool('ability_create', {
    name: ABILITY,
    label: 'Proof probe',
    description: 'Writes a file and an option, so a run can be detected from outside the response.',
    code: body(1),
    writes: true,
    risky: true,
    input_schema: { type: 'object', properties: {} },
  });
  report.ok(created.data.success === true, 'ability_create over the real MCP endpoint succeeded');
  report.ok(created.data.status === 'pending_approval', `and the status it reports is pending_approval (got "${created.data.status}")`);

  let rows = abilityRows();
  report.ok(rows.length === 1, 'exactly one row was written');
  report.ok(rows[0].status === 'pending', `and its status in the database is pending (got "${rows[0].status}")`);
  report.ok(rows[0].code_hash === '', 'with an EMPTY code_hash — nothing the model can write carries a hash');

  const callPending = await callTool(ABILITY, {});
  report.ok(callPending.isError === true, `calling it over MCP is an error (${JSON.stringify(callPending.data.error || callPending.raw).slice(0, 110)})`);

  // The part that is actually the proof.
  let file = proofFile();
  report.ok(file.exists === false, `the file its body would have written does not exist (${file.path})`);
  report.ok(optionValue() === '', 'and the option its body would have set is unset');

  // Pending code is also not run by anything that merely LOOKS at it: the
  // review screen renders the code, and the lint parses it.
  const lint = wpJson(
    `global $wpdb;
    $row = $wpdb->get_row( "SELECT code FROM {$wpdb->prefix}keelhob_abilities WHERE name = '${phpEsc(ABILITY)}'", ARRAY_A );
    echo wp_json_encode( array( 'lint' => Keelhob_Abilities::lint_code( (string) $row['code'] ) ) );`,
    wpOpts
  );
  report.ok(lint.lint === '', 'the lint parses the stored body cleanly');
  file = proofFile();
  report.ok(file.exists === false && optionValue() === '', 'and linting it did not run it either');

  /* ====================================== PROOF 2 — approval, JavaScript off */

  report.group('Proof 2 — approval in a real browser with JavaScript DISABLED');

  // harness.launch() is what knows where a Chrome lives on this machine. Its
  // own context is discarded: this check needs one of its own, because the
  // whole point is a context with scripting switched off.
  const launched = await launch({ chrome: opt.chrome, headed: opt.headed === true });
  browser = launched.browser;
  await launched.context.close();

  const context = await browser.newContext({
    viewport: { width: 1536, height: 900 },
    ignoreHTTPSErrors: true,
    // THE POINT OF THIS CHECK.
    javaScriptEnabled: false,
  });
  context.setDefaultTimeout(15_000);
  context.setDefaultNavigationTimeout(20_000);

  const page = await context.newPage();
  await page.goto(`${WP}/wp-login.php`, { waitUntil: 'domcontentloaded' });
  await page.fill('#user_login', USER);
  await page.fill('#user_pass', PASS);
  await page.click('#wp-submit');
  await page.waitForLoadState('domcontentloaded');
  report.ok((await page.locator('#user_login').count()) === 0, 'signed in to wp-admin with no scripts running');

  /*
   * `load`, NOT `domcontentloaded`.
   *
   * This run measures the code block's resolved background, and
   * domcontentloaded fires before the stylesheets have arrived. A <link> that
   * has not loaded yet has no CSSStyleSheet attached, so it is absent from
   * document.styleSheets and none of its rules apply. With
   * javaScriptEnabled:false there is nothing on the page to delay the read
   * either, so the measurement was simply racing the network: it reported
   * `background: rgba(0,0,0,0)` and `color: rgb(0,0,0)` — the unstyled
   * defaults — and read as a dark-mode bug in the panel. Diagnosed by dumping
   * document.styleSheets from inside the failing run, which held six inline
   * sheets and no admin.css at all.
   */
  await page.goto(`${WP}/wp-admin/admin.php?page=keelhob`, { waitUntil: 'load' });

  /*
   * And then say so out loud, because "the stylesheet is on this screen" is
   * worth an assertion of its own: enqueue_ui() ships it on Keelhob screens
   * and nowhere else, so a change to that allowlist is a real way for every
   * measurement below to start reporting unstyled defaults.
   */
  const sheetOk = await page
    .waitForFunction(
      () => [...document.styleSheets].some((s) => (s.href || '').includes('/keelhob/assets/css/admin.css')),
      null,
      { timeout: 10_000 }
    )
    .then(() => true, () => false);
  report.ok(sheetOk, 'admin.css is actually loaded on this screen, so the styles below are measurable');

  report.ok((await page.locator('#kh-abilities').count()) === 1, 'the Connect screen carries the abilities section');
  report.ok((await page.locator('#pending').count()) === 1, 'with a #pending anchor, which is where the model tells the owner to look');

  const card = page.locator('.kh-u-review').first();
  report.ok((await card.count()) === 1, 'and one review card');
  report.ok((await card.locator('.kh-u-badge.is-pending').innerText()).trim() === 'New ability', 'badged "New ability" for a first version');
  report.ok((await card.locator('.kh-u-mono').first().innerText()).includes(ABILITY), 'showing the mono tool name');
  report.ok((await card.locator('.kh-u-badge.is-writes').count()) === 1, 'with a "Changes the site" badge');
  report.ok((await card.locator('.kh-u-badge.is-risky').count()) === 1, 'and a "Risky" badge');

  // The code block: open, dark, and carrying the code as TEXT.
  const codeBlock = card.locator('pre.kh-u-code').first();
  report.ok(await card.locator('details.kh-u-code-wrap[open]').first().isVisible(), 'the code <details> is OPEN, so the owner cannot approve without the code on screen');
  const shownCode = await codeBlock.innerText();
  report.ok(shownCode.includes('file_put_contents'), 'the code block shows the real body');
  report.ok(shownCode.includes(`update_option( '${OPTION}'`), 'including the line that writes the option');

  const codeStyle = await codeBlock.evaluate((el) => {
    const cs = getComputedStyle(el);
    return { bg: cs.backgroundColor, fg: cs.color };
  });
  report.ok(codeStyle.bg === 'rgb(22, 25, 27)', `the code block is a dark block, measured not asserted from markup (${codeStyle.bg})`);
  report.ok(codeStyle.fg === 'rgb(232, 227, 220)', `with the token ink on it (${codeStyle.fg})`);

  // The code is DISPLAYED, never executed: no HTML from the body reached the DOM.
  const bodyHtml = await page.content();
  report.ok(!bodyHtml.includes('<?php'), 'nothing on the page re-opened a PHP tag');
  report.ok(proofFile().exists === false && optionValue() === '', 'and rendering the review screen did not run the pending code');

  /*
   * CONTRAST ON THE REVIEW CARD, MEASURED IN BOTH MODES.
   *
   * check-admin.mjs sweeps every text node on every screen in all eight cells
   * of its matrix, and it cannot see this card: the section renders nothing
   * unless something is pending, and nothing is pending during its run. So the
   * measurement happens here, where a card exists.
   *
   * The dark pass is produced by adding `is-dark` to the panel root in the page
   * rather than by changing anybody's admin_color. The scheme meta is shared
   * rig state, and the comment at the top of check-admin.mjs records what
   * happened last time a run flipped it: it raced with the other lane. This
   * toggles the exact class Keelhob_Admin::root_class() would have written, on
   * the exact markup already on screen, and writes nothing anywhere.
   */
  const measure = () =>
    card.evaluate((root) => {
      const lum = (c) => {
        const [r, g, b] = c.map((v) => {
          const s = v / 255;
          return s <= 0.03928 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4);
        });
        return 0.2126 * r + 0.7152 * g + 0.0722 * b;
      };
      const parse = (s) => (s.match(/[\d.]+/g) || []).slice(0, 3).map(Number);
      const painted = (el) => {
        for (let n = el; n; n = n.parentElement) {
          const bg = getComputedStyle(n).backgroundColor;
          const a = (bg.match(/[\d.]+/g) || [])[3];
          if (bg && bg !== 'transparent' && a !== '0') return bg;
        }
        return 'rgb(255,255,255)';
      };
      const out = [];
      for (const el of root.querySelectorAll('.kh-u-badge, .kh-u-review-label, .kh-u-review-desc, .kh-u-review-note, .kh-u-confirm span, summary')) {
        const text = (el.textContent || '').trim();
        if (!text) continue;
        const f = lum(parse(getComputedStyle(el).color));
        const b = lum(parse(painted(el)));
        const ratio = (Math.max(f, b) + 0.05) / (Math.min(f, b) + 0.05);
        out.push({ text: text.slice(0, 40), ratio: Math.round(ratio * 100) / 100 });
      }
      return out;
    });

  for (const mode of ['light', 'dark']) {
    if (mode === 'dark') {
      await page.evaluate(() => document.querySelector('.kh-ui').classList.add('is-dark'));
    }
    const measured = await measure();
    report.ok(measured.length >= 6, `${mode}: ${measured.length} text runs measured on the review card`);
    const worst = measured.reduce((a, b) => (b.ratio < a.ratio ? b : a));
    report.ok(worst.ratio >= 4.5, `${mode}: the worst text on the card clears AA — ${worst.ratio}:1 on "${worst.text}"`);
  }
  await page.evaluate(() => document.querySelector('.kh-ui').classList.remove('is-dark'));

  // Approve. A required checkbox, then a submit button. No script anywhere.
  const confirm = card.locator('input[name="kh_ability_confirm"]');
  report.ok((await confirm.count()) === 1, 'the confirm is a real <input type="checkbox">');
  report.ok((await confirm.getAttribute('required')) !== null, 'marked required, so the browser enforces it without a script');

  await confirm.check();
  await page.locator('.kh-u-review-act button.button-primary').first().click();
  await page.waitForLoadState('domcontentloaded');

  report.ok(page.url().includes('ability_approved=1'), `the approval posted to admin-post.php and came back (${page.url().split('?')[1] || ''})`);

  rows = abilityRows();
  report.ok(rows.length === 1 && rows[0].status === 'active', `v1 is now active in the database (status "${rows[0].status}")`);
  report.ok(/^[0-9a-f]{64}$/.test(rows[0].code_hash), 'and a sha256 code_hash was written at the moment of approval');

  // Now it runs.
  const ranV1 = await callTool(ABILITY, {});
  report.ok(ranV1.isError === false, 'the approved ability runs over the real MCP endpoint');
  report.ok(ranV1.data.body_version === 1, `and returns what its body returns (body_version ${ranV1.data.body_version})`);

  file = proofFile();
  report.ok(file.exists === true, 'the file its body writes now EXISTS, which is the same check that was negative while it was pending');
  report.ok(file.body === 'v1', `with v1's contents ("${file.body}")`);
  report.ok(optionValue() === 'v1', 'and the option it sets now reads v1');

  /* ============================================= PROOF 3 — the hash guard */

  report.group('Proof 3 — tampered code is refused');

  const beforeTamper = abilityRows()[0];

  wpEval(
    `global $wpdb;
    $wpdb->query( $wpdb->prepare(
      "UPDATE {$wpdb->prefix}keelhob_abilities SET code = %s WHERE name = %s AND status = 'active'",
      "update_option( '${phpEsc(OPTION)}', 'TAMPERED' ); return array( 'body_version' => 99 );",
      '${phpEsc(ABILITY)}'
    ) );
    echo 'tampered';`,
    wpOpts
  );

  const afterTamper = abilityRows()[0];
  report.ok(
    Number(afterTamper.len) !== Number(beforeTamper.len),
    `the stored code really changed (${beforeTamper.len} bytes -> ${afterTamper.len} bytes)`
  );
  report.ok(
    afterTamper.code_hash === beforeTamper.code_hash,
    'and code_hash was NOT updated — which is what a database edit or a careless SQL fix looks like'
  );

  const blocked = await callTool(ABILITY, {});
  report.ok(blocked.isError === true, 'the ability is now refused');
  report.ok(
    String(blocked.data.error || '').includes('changed after it was approved'),
    `and the reason names the cause (${String(blocked.data.error || blocked.raw).slice(0, 110)})`
  );
  report.ok(optionValue() === 'v1', 'the option still reads v1 — the tampered body never ran');

  // Put the approved bytes back, so the rest of the run is testing the feature
  // rather than the damage.
  /*
   * base64, not a quoted PHP string. The body contains `$dir`, and a
   * double-quoted PHP literal would INTERPOLATE it — restoring bytes that are
   * not the bytes that were approved, which then fails the hash check for a
   * reason that has nothing to do with the feature. That cost a red run.
   */
  wpEval(
    `global $wpdb;
    $wpdb->query( $wpdb->prepare(
      "UPDATE {$wpdb->prefix}keelhob_abilities SET code = %s WHERE name = %s AND status = 'active'",
      base64_decode( '${Buffer.from(body(1), 'utf8').toString('base64')}' ),
      '${phpEsc(ABILITY)}'
    ) );
    echo 'restored';`,
    wpOpts
  );
  const healed = await callTool(ABILITY, {});
  report.ok(healed.isError === false && healed.data.body_version === 1, 'restoring the approved bytes makes it run again — the guard is the hash, not a flag');

  /* ================================================= PROOF 4 — rollback */

  report.group('Proof 4 — approve v2, roll back to v1');

  const improved = await callTool('ability_improve', {
    name: ABILITY,
    code: body(2),
    change_note: 'Writes v2 instead of v1, so which version ran can be read off the option.',
  });
  report.ok(improved.data.success === true, 'ability_improve over MCP succeeded');
  report.ok(improved.data.version === 2, `and it is version 2 (got ${improved.data.version})`);

  const duringReview = await callTool(ABILITY, {});
  report.ok(duringReview.data.body_version === 1, 'while v2 waits for review, v1 KEEPS WORKING — the owner is never left without the ability they approved');
  report.ok(optionValue() === 'v1', 'and it is still v1 that runs, not the pending draft');

  await page.goto(`${WP}/wp-admin/admin.php?page=keelhob`, { waitUntil: 'domcontentloaded' });
  const card2 = page.locator('.kh-u-review').first();
  report.ok((await card2.locator('.kh-u-badge.is-pending').innerText()).trim() === 'New version 2', 'the review card is badged "New version 2"');
  const noteText = await card2.locator('.kh-u-review-note').innerText();
  report.ok(noteText.includes('Writes v2 instead of v1'), 'and carries the "What changed" note the model had to supply');
  report.ok((await card2.locator('details.kh-u-code-wrap').count()) === 2, 'with a second <details> holding the version running now, for comparison');
  // Closed, so innerText would be empty — open it the way a person would, with
  // a click on the summary and no script involved.
  const comparePanel = card2.locator('details.kh-u-code-wrap').nth(1);
  await comparePanel.locator('summary').click();
  const oldCode = await card2.locator('pre.kh-u-code.is-old').innerText();
  report.ok(oldCode.includes("'v1'"), `which really does show v1's code (${oldCode.replace(/\s+/g, ' ').slice(0, 70)}…)`);

  await card2.locator('input[name="kh_ability_confirm"]').check();
  await card2.locator('.kh-u-review-act button.button-primary').first().click();
  await page.waitForLoadState('domcontentloaded');

  const ranV2 = await callTool(ABILITY, {});
  report.ok(ranV2.data.body_version === 2, `v2 is live and runs (body_version ${ranV2.data.body_version})`);
  report.ok(optionValue() === 'v2', 'and the option now reads v2');

  rows = abilityRows();
  const byVersion = Object.fromEntries(rows.map((r) => [Number(r.version), r.status]));
  report.ok(byVersion[1] === 'retired', 'v1 is retired, not deleted — "Roll back to this" needs it');
  report.ok(byVersion[2] === 'active', 'and v2 is active');

  // Roll back, in the browser, still with no JavaScript.
  await page.goto(`${WP}/wp-admin/admin.php?page=keelhob`, { waitUntil: 'domcontentloaded' });
  // The versions table lives in a closed <details>. Opening it is the browser's
  // own behaviour, not a script's — which is exactly why it still works here
  // with scripting off, and worth asserting rather than assuming.
  const versionsPanel = page.locator('.kh-u-ability details.kh-u-code-wrap').last();
  await versionsPanel.locator('summary').click();
  report.ok(await versionsPanel.evaluate((el) => el.open), 'the Versions panel opens with no JavaScript — a <details> is native');

  const rollback = page.locator(`.kh-u-ability form button:text-is("Roll back to this")`).first();
  report.ok((await rollback.count()) === 1, 'the live card offers exactly one "Roll back to this" — for the retired version, not the active one');
  await rollback.click();
  await page.waitForLoadState('domcontentloaded');
  report.ok(page.url().includes('ability_rolled_back=1'), 'the rollback posted and came back');

  rows = abilityRows();
  const after = Object.fromEntries(rows.map((r) => [Number(r.version), r.status]));
  report.ok(after[1] === 'active', 'v1 is active again');
  report.ok(after[2] === 'retired', 'and v2 is retired');

  const backToV1 = await callTool(ABILITY, {});
  report.ok(backToV1.data.body_version === 1, `and it is v1 that runs (body_version ${backToV1.data.body_version})`);
  report.ok(optionValue() === 'v1', 'proved from outside the response: the option reads v1 again');

  /* ================================ the gate, once more, from the other side */

  report.group('The capability gate, from the other side');

  wpEval(
    `$kh = Keelhob::instance();
    $s = $kh->settings();
    $caps = is_array( $s['capabilities'] ?? null ) ? $s['capabilities'] : array();
    $caps['abilities'] = false;
    $kh->update( array( 'capabilities' => $caps ) );
    echo 'off';`,
    wpOpts
  );

  const toolsOff = await mcp('tools/list', {});
  const namesOff = (toolsOff.json.result?.tools || []).map((t) => t.name);
  report.ok(!namesOff.includes('ability_create'), 'with the capability off, ability_create is gone from tools/list entirely');
  report.ok(!namesOff.includes('ability_improve'), 'and so is ability_improve');
  report.ok(namesOff.includes(ABILITY), 'but the ability the owner APPROVED still works — the switch governs authoring, not what they said yes to');

  const refusedCreate = await callTool('ability_create', {
    name: 'kh_proof_should_not_exist',
    label: 'Nope',
    description: 'Should never be stored.',
    code: 'return array();',
  });
  report.ok(refusedCreate.isError === true, 'and calling it anyway is refused by the endpoint');
  const strayCheck = wpJson(
    `global $wpdb;
    echo wp_json_encode( array( 'n' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}keelhob_abilities WHERE name = 'kh_proof_should_not_exist'" ) ) );`,
    wpOpts
  );
  report.ok(strayCheck.n === 0, 'with nothing written to the table');
}

try {
  await main();
} catch (err) {
  report.ok(false, `the run threw: ${err && err.message ? err.message : err}`);
} finally {
  // IN THE SAME RUN, WHETHER IT PASSED OR NOT.
  try {
    if (browser) await browser.close();
  } catch { /* nothing to do about a browser that will not close */ }

  try {
    teardown(marks);
    const left = abilityRows();
    report.ok(left.length === 0, 'teardown: no ability rows survive this run');
    report.ok(optionValue() === '', 'teardown: the option is gone');
    report.ok(proofFile().exists === false, 'teardown: the file is gone');

    /*
     * The run log, the per-tool stats, the lessons and the feedback queue.
     * Every tool call writes to these, so a run that only deleted its ability
     * row left four kinds of row behind and still reported a clean teardown.
     * Asserted per table, so a failure names which one leaked.
     */
    if (marks) {
      const over = rowsSince(marks);
      for (const t of RUN_TABLES) {
        report.ok(
          over[t] === 0,
          over[t] === 0
            ? `teardown: ${t} is back to where it started`
            : `teardown: ${t} kept ${over[t]} row(s) past id ${marks[t]}`
        );
      }
    } else {
      report.ok(false, 'teardown: no watermark was taken, so the run log cannot be checked');
    }
  } catch (err) {
    report.ok(false, `teardown failed: ${err && err.message ? err.message : err}`);
  }

  if (before) {
    try {
      wpEval(
        `$kh = Keelhob::instance();
        $s = $kh->settings();
        $caps = is_array( $s['capabilities'] ?? null ) ? $s['capabilities'] : array();
        ${before.cap === null ? `unset( $caps['abilities'] );` : `$caps['abilities'] = ${before.cap ? 'true' : 'false'};`}
        $kh->update( array(
          'abilities_enabled' => ${before.abilities_enabled ? 'true' : 'false'},
          'capabilities'      => $caps,
        ) );
        echo 'restored';`,
        wpOpts
      );
      const now = wpJson(
        `$s = get_option( 'keelhob_settings', array() );
        echo wp_json_encode( array(
          'abilities_enabled' => $s['abilities_enabled'] ?? null,
          'cap'               => $s['capabilities']['abilities'] ?? null,
        ) );`,
        wpOpts
      );
      report.ok(
        now.abilities_enabled === before.abilities_enabled && (now.cap ?? null) === (before.cap ?? null),
        'teardown: the rig\'s settings are exactly as they were'
      );
    } catch (err) {
      report.ok(false, `teardown: could not restore settings: ${err && err.message ? err.message : err}`);
    }
  }

  process.exit(report.summary());
}
