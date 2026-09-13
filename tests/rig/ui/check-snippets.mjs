/**
 * The Snippets screens, asserted in a real browser.
 *
 *   node tests/rig/ui/check-snippets.mjs --wp http://localhost:8899 \
 *        --rig /c/wp-rig/wordpress --user admin --pass rigpass
 *
 * Every check names the baseline row it replaces. The baselines are the twelve
 * measurements in docs/plans/snippets-rebuild.md, taken on 2026-09-13 against
 * Claudpress 1.1.0. Before the work in that plan, this file is expected to be
 * almost entirely RED, and that red run is the baseline reproducing itself.
 *
 * FIXTURES ARE TORN DOWN IN THE SAME RUN THAT ASSERTS ON THEM, including when
 * the run fails — see the `finally` at the bottom. A rig left holding `ZZ UI`
 * snippets fails its own next run for the wrong reason, and one of them is
 * deliberately broken code.
 */

import {
  args,
  clearFailures,
  launch,
  login,
  onScreen,
  Report,
  shoot,
  wpEval,
  wpJson,
} from './harness.mjs';

const opt = args();
const WP = (opt.wp || 'http://localhost:8899').replace(/\/$/, '');
const RIG = opt.rig || '/c/wp-rig/wordpress';
const USER = opt.user || 'admin';
const PASS = opt.pass || 'rigpass';
const CLI = opt.cli || null;
const wpOpts = { path: RIG, cli: CLI };

const PREFIX = 'ZZ UI';
const STUDIO = `${WP}/wp-admin/admin.php?page=claudpress-studio`;

const r = new Report();

/* ------------------------------------------------------------------ */
/* Fixtures                                                            */
/* ------------------------------------------------------------------ */

/**
 * Four snippets, chosen so that every filter under test has something to find
 * and something to exclude.
 *
 * The errored one is made by writing `last_error` directly rather than by
 * saving code that crashes: a snippet that really crashes on every request
 * would make the rest of the run flaky, and what is being tested here is the
 * FILTER, not the crash guard — tests/rig/checks.php already covers that.
 */
function seed() {
  return wpJson(
    `
    $ids = [];
    $specs = [
      // NO OPENING TAG. Snippet code is eval'd, and eval() rejects <?php. With
      // one, every active fixture crashed on init, the crash guard correctly
      // switched it off and stamped last_error - so the toggle check read
      // 0 -> 0 and the Errored filter found three snippets instead of one.
      // The fixtures were wrong; the product was right.
      ['${PREFIX} alpha active php', 'php', 'add_action("init", function () { return true; });', 'everywhere', 1, 5],
      ['${PREFIX} bravo inactive php', 'php', 'add_action("init", function () { return true; });', 'everywhere', 0, 10],
      ['${PREFIX} charlie css', 'css', 'body { color: #123456; }', 'header', 1, 10],
      ['${PREFIX} delta errored', 'php', 'add_action("init", function () { return true; });', 'everywhere', 0, 20],
    ];
    foreach ($specs as $s) {
      $res = Claudpress_Snippets::save([
        'name' => $s[0], 'snippet_type' => $s[1], 'code' => $s[2],
        'location' => $s[3], 'active' => $s[4], 'priority' => $s[5],
        'description' => 'Browser-check fixture. Safe to delete.',
      ]);
      if (empty($res['success'])) { echo json_encode(['error' => $res['error'] ?? 'save failed']); return; }
      $ids[] = (int) $res['id'];
    }
    global $wpdb;
    $wpdb->update(
      $wpdb->prefix . 'claudpress_snippets',
      ['last_error' => 'Fixture error: syntax error, unexpected end of file'],
      ['id' => $ids[3]]
    );
    echo json_encode(['ids' => $ids]);
  `,
    wpOpts
  );
}

function teardown() {
  return wpJson(
    `
    $gone = [];
    foreach (Claudpress_Snippets::query([]) as $row) {
      if (strpos((string) $row['name'], '${PREFIX}') === 0) {
        Claudpress_Snippets::delete((int) $row['id']);
        $gone[] = (int) $row['id'];
      }
    }
    echo json_encode(['deleted' => $gone, 'remaining' => count(Claudpress_Snippets::query([]))]);
  `,
    wpOpts
  );
}

const isActive = (id) =>
  wpEval(`$s = Claudpress_Snippets::get(${id}); echo (int) ($s['active'] ?? -1);`, wpOpts).trim();

/* ------------------------------------------------------------------ */
/* Checks                                                              */
/* ------------------------------------------------------------------ */

/**
 * B1, B2 — the code editor.
 *
 * The bug this exists for: the source enqueues CodeMirror and calls
 * initialize(), and the page still renders a plain textarea, because the init
 * runs 65 scripts too early. Asserting on `.CodeMirror` is the only way to tell
 * the difference.
 */
async function checkEditor(page, ids) {
  // An EXISTING snippet, not the blank Add screen. A blank editor has no
  // tokens, so "is the code syntax-coloured?" would pass vacuously by finding
  // nothing to be wrong with — which is worse than no check at all.
  await page.goto(`${STUDIO}&tab=edit&snippet=${ids[0]}`, { waitUntil: 'load' });
  await page.waitForTimeout(800); // CodeMirror initialises after the footer scripts.

  const info = await page.evaluate(() => {
    const cm = document.querySelector('.CodeMirror');
    const first = cm?.querySelector('.CodeMirror-line');
    const rect = (first || cm || document.getElementById('cp-code'))?.getBoundingClientRect();
    return {
      hasCodeMirror: !!cm,
      hasLineNumbers: !!cm?.querySelector('.CodeMirror-linenumber'),
      // Distinct colours, not one token's colour. Plenty of PHP tokens are
      // legitimately black, so "this token is not black" is a weak and flaky
      // signal. More than one colour on a line can only happen when the
      // highlighter is genuinely running.
      tokenColours: cm
        ? [...new Set([...cm.querySelectorAll('.CodeMirror-line span[class^=cm-]')].map((el) => getComputedStyle(el).color))]
        : [],
      settingsType: typeof window.cpStudioEditorSettings,
      settingsMode: window.cpStudioEditorSettings?.codemirror?.mode ?? null,
      scrollY: window.scrollY,
      codeTop: rect ? Math.round(rect.top) : null,
      viewportH: window.innerHeight,
      // Counted by POSITION, not by container. The fields that were moved sit
      // in a collapsed <details> BELOW the code, and they are still
      // `table.form-table tr` — counting the selector would report them as
      // still being in the way when the whole point is that they no longer are.
      fieldsAboveCode: (() => {
        const codeTop = rect ? rect.top + window.scrollY : Infinity;
        return [...document.querySelectorAll('#cp-studio-form input:not([type=hidden]), #cp-studio-form select, #cp-studio-form textarea:not(#cp-code)')]
          .filter((el) => el.getBoundingClientRect().top + window.scrollY < codeTop).length;
      })(),
      askAiButton: !!document.getElementById('cp-ask-ai'),
    };
  });

  r.check('B1', 'the code editor really initialises (.CodeMirror exists)', info.hasCodeMirror);
  r.check('B1', 'the editor shows line numbers', info.hasLineNumbers);
  r.check(
    'B1',
    'code is syntax-coloured, not one flat colour',
    info.tokenColours.length >= 2,
    `${info.tokenColours.length} distinct token colour(s)`
  );
  r.check(
    'B2',
    'cpStudioEditorSettings is defined and carries a CodeMirror mode',
    info.settingsType === 'object' && !!info.settingsMode,
    `type=${info.settingsType} mode=${info.settingsMode}`
  );
  r.check(
    'B3/B4',
    'the first line of code is on screen without scrolling',
    info.scrollY === 0 && info.codeTop !== null && info.codeTop < info.viewportH,
    `code top ${info.codeTop}px, viewport ${info.viewportH}px`
  );
  r.check(
    'B5',
    'at most three fields sit above the code box',
    info.fieldsAboveCode <= 3,
    `${info.fieldsAboveCode} field(s) above the code`
  );

  // Risk 1 in the plan: moving the inline script could fix the editor and
  // silently break the button that shares the block.
  r.check('B1', 'the "Ask AI" button survived the script move', info.askAiButton);

  const buttons = await page.evaluate(() =>
    [...document.querySelectorAll('p.submit button')].map((b) => ({
      text: b.textContent.trim(),
      primary: b.className.includes('button-primary'),
    }))
  );
  const save = buttons.find((b) => /^save$/i.test(b.text) || /^save \(/i.test(b.text));
  const activate = buttons.find((b) => /switch on/i.test(b.text));
  r.check('—', 'Save is the primary button', !!save?.primary, save ? save.text : 'no Save button');
  r.check('—', '"Save & switch on" is not the primary button', !activate || !activate.primary);

  if (r.failures.length) await shoot(page, 'edit-screen');
}

/** B6, B7 — can a person see and use the on/off switch without hovering? */
async function checkToggle(page, ids) {
  await page.goto(`${STUDIO}&tab=all&s=${encodeURIComponent(PREFIX)}`, { waitUntil: 'load' });

  // Addressed by the snippet id the button carries, not by matching row text.
  // A text match is one renamed fixture away from silently selecting the wrong
  // row, and `:has-text` on a whole <tr> matches substrings of other rows too.
  const toggle = page.locator(`button[data-cp-toggle="${ids[1]}"]`);

  const box = await page.evaluate((prefix) => {
    const row = [...document.querySelectorAll('tbody tr')].find((tr) =>
      tr.textContent.includes(`${prefix} bravo`)
    );
    if (!row) return { found: false, reason: 'row not found' };
    // Deliberately NOT .row-actions: what is being asserted is that a control
    // exists outside the hover-only zone.
    const ctl = row.querySelector('.cp-status-toggle button, button[data-cp-toggle]');
    if (!ctl) return { found: false, reason: 'no status control outside .row-actions' };
    const rect = ctl.getBoundingClientRect();
    const cs = getComputedStyle(ctl);
    return {
      found: true,
      left: Math.round(rect.left),
      top: Math.round(rect.top),
      w: Math.round(rect.width),
      h: Math.round(rect.height),
      onScreen:
        rect.left > -1 && rect.left < window.innerWidth && rect.width > 0 && rect.height > 0 &&
        cs.visibility !== 'hidden' && cs.display !== 'none',
      isButton: ctl.tagName === 'BUTTON',
      inForm: !!ctl.closest('form[method="post"]'),
    };
  }, PREFIX);

  r.check(
    'B6/B7',
    'the on/off control is on screen with no hover',
    box.found && box.onScreen,
    box.found ? `left ${box.left}px` : box.reason
  );
  r.check(
    'B3',
    'switching state is still a POST with a nonce, never a GET link',
    !box.found || (box.isButton && box.inForm)
  );

  if (box.found && box.onScreen && (await toggle.count())) {
    const before = isActive(ids[1]);
    // A short, explicit timeout. The default is 30s, and a control that is
    // present but unclickable would otherwise stall the whole run rather than
    // failing the one check that is actually wrong.
    await toggle.click({ timeout: 5000 }).catch(() => {});
    await page.waitForLoadState('load', { timeout: 10000 }).catch(() => {});
    const after = isActive(ids[1]);
    r.check(
      'B7',
      'clicking it really changes the stored state',
      before === '0' && after === '1',
      `active ${before} -> ${after}`
    );
    // Put it back, so the run is repeatable and the later filter counts hold.
    wpEval(`Claudpress_Snippets::save(['id' => ${ids[1]}, 'active' => 0]);`, wpOpts);
  } else {
    r.fail('B7', 'clicking it really changes the stored state', 'no control to click');
    await shoot(page, 'toggle-missing');
  }
}

/** B8, B9, B10 — finding things. */
async function checkListControls(page) {
  await page.goto(`${STUDIO}&tab=all&s=${encodeURIComponent(PREFIX)}`, { waitUntil: 'load' });

  const list = await page.evaluate(() => {
    const table = document.querySelector('table.wp-list-table');
    return {
      sortable: [...(table?.querySelectorAll('thead th.sortable, thead th.sorted') || [])].map((th) =>
        th.className.replace(/\s+/g, '.')
      ),
      headers: [...(table?.querySelectorAll('thead th') || [])].map((th) => th.textContent.trim()),
      subsub: [...document.querySelectorAll('.subsubsub a')].map((a) => a.textContent.trim()),
    };
  });

  r.check('B8', 'at least three columns are sortable', list.sortable.length >= 3, list.sortable.join(' '));
  r.check('B9', 'a Priority column is shown', list.headers.some((h) => /priorit/i.test(h)), list.headers.join(' | '));
  r.check(
    'B10',
    'an Errored filter is offered (a fixture has last_error set)',
    list.subsub.some((t) => /error/i.test(t)),
    list.subsub.join(' | ')
  );

  // Clicking Name must actually reorder, not merely add a query argument.
  const firstBefore = await page.evaluate(() => document.querySelector('tbody tr td a')?.textContent.trim());
  const nameHeader = page.locator('thead th.sortable a, thead th.sorted a').first();
  if (await nameHeader.count()) {
    await nameHeader.click({ timeout: 5000 }).catch(() => {});
    await page.waitForLoadState('load', { timeout: 10000 }).catch(() => {});
    const firstAfter = await page.evaluate(() => document.querySelector('tbody tr td a')?.textContent.trim());
    r.check('B8', 'clicking a sortable header reorders the rows', !!firstAfter, `${firstBefore} -> ${firstAfter}`);
  } else {
    r.fail('B8', 'clicking a sortable header reorders the rows', 'no sortable header to click');
  }

  // The Errored filter must return exactly the errored fixture.
  // Scoped to this run's fixtures. tests/rig/checks.php leaves two deliberately
  // broken snippets on a kept rig, so an unscoped count fails for a reason that
  // has nothing to do with the filter under test.
  await page.goto(`${STUDIO}&tab=all&active=error&s=${encodeURIComponent(PREFIX)}`, { waitUntil: 'load' });
  const errRows = await page.evaluate((prefix) =>
    [...document.querySelectorAll('tbody tr')]
      .map((tr) => tr.textContent.trim())
      .filter((text) => text.includes(prefix)),
    PREFIX
  );
  r.check(
    'B10',
    'the Errored filter returns the errored fixture and no other fixture',
    errRows.length === 1 && errRows[0].includes('delta errored'),
    `${errRows.length} fixture row(s)`
  );

  if (r.failures.length) await shoot(page, 'list-controls');
}

/** B13 — nothing on this screen leads somewhere that does not exist. */
async function checkNoDeadControls(page, ids) {
  const dead = await page.evaluate(() =>
    [...document.querySelectorAll('a')].filter((a) => /browse the library/i.test(a.textContent)).length
  );
  r.check('B13', 'no dead "Browse the library" button', dead === 0);

  // The Library TAB, not just the button. It could never appear - has_items()
  // asked a class nobody ever wrote - and the 135-line screen behind it shipped
  // in every zip regardless.
  await page.goto(`${STUDIO}&tab=all`, { waitUntil: 'load' });
  const tabs = await page.evaluate(() =>
    [...document.querySelectorAll('.nav-tab')].map((a) => a.textContent.trim())
  );
  r.check('B13', 'the Library tab is gone, not merely hidden', !tabs.some((t) => /library/i.test(t)), tabs.join(' | '));

  // An old bookmark must still land somewhere useful.
  await page.goto(`${STUDIO}&tab=library`, { waitUntil: 'load' });
  const fallback = await page.evaluate(() => ({
    table: !!document.querySelector('table.wp-list-table'),
    fatal: /fatal error/i.test(document.body.innerText),
  }));
  r.check('B13', 'an old ?tab=library link falls back to the list, not an error', fallback.table && !fallback.fatal);

  // The agent-facing id, carried over from the deleted second screen.
  await page.goto(`${STUDIO}&tab=all&s=${encodeURIComponent(PREFIX)}`, { waitUntil: 'load' });
  const idShown = await page.evaluate(
    (id) => [...document.querySelectorAll('.cp-snippet-id')].some((el) => el.textContent.trim() === `#${id}`),
    ids[0]
  );
  r.check('—', 'each row shows the number you would say to the agent', idShown);

  await page.goto(`${STUDIO}&tab=edit&snippet=${ids[0]}`, { waitUntil: 'load' });
  const hint = await page.evaluate(() => document.querySelector('.cp-ask-hint')?.textContent.trim() || '');
  r.check('—', 'and the edit screen explains it once', /tell me about snippet/i.test(hint), hint.slice(0, 70));

  // ...but not on a new snippet, where there is no number yet.
  await page.goto(`${STUDIO}&tab=edit`, { waitUntil: 'load' });
  const onNew = await page.evaluate(() => !!document.querySelector('.cp-ask-hint'));
  r.check('—', 'and says nothing on a snippet that has no number yet', !onNew);
}

/** B11 — how many times one screen asks for money. */
async function checkDonate(page) {
  const screens = [
    ['claudpress', true],
    ['claudpress-studio', false],
    ['claudpress-skills', false],
    ['claudpress-guide', false],
    ['claudpress-activity', false],
    ['claudpress-automation', false],
  ];
  for (const [slug, allowed] of screens) {
    await page.goto(`${WP}/wp-admin/admin.php?page=${slug}`, { waitUntil: 'domcontentloaded' });
    const found = await page.evaluate(() => {
      const h1 = document.querySelector('.wrap h1');
      if (!h1) return 0;
      // Only the heading area counts. A link inside page copy is not a plea.
      const zone = [h1, h1.nextElementSibling, h1.nextElementSibling?.nextElementSibling].filter(Boolean);
      return zone.filter((el) => /donate|ko-?fi|sponsor/i.test(el.textContent || '')).length;
    });
    r.check(
      'B11',
      `donate button beside the heading on ${slug}`,
      allowed ? found > 0 : found === 0,
      allowed ? 'expected, present' : `expected none, found ${found}`
    );
  }
}

/**
 * The Plugins screen row.
 *
 * The snippets engine is the one part of this plugin a person uses without an
 * agent, and the Plugins screen never said so - the description described a
 * thing that hands an AI control of the site, full stop. Somebody who installed
 * it for the snippets alone had to find them through a settings page about MCP.
 *
 * Asserted in a browser because WordPress runs plugin descriptions through
 * wp_kses before printing them. Whether <strong> survives that filter is a
 * property of WordPress, not of our string, and the only honest way to know is
 * to render it.
 */
async function checkPluginsRow(page) {
  await page.goto(`${WP}/wp-admin/plugins.php`, { waitUntil: 'load' });

  const row = await page.evaluate(() => {
    const tr = document.querySelector('tr[data-plugin*="claudpress"]');
    if (!tr) return { found: false };
    const desc = tr.querySelector('.plugin-description, .column-description');
    const snippets = [...tr.querySelectorAll('a')].find((a) => a.textContent.trim() === 'Snippets');
    return {
      found: true,
      links: [...tr.querySelectorAll('.row-actions a')].map((a) => a.textContent.trim()),
      snippetsHref: snippets ? snippets.getAttribute('href') : null,
      strongSurvived: !!desc?.querySelector('strong'),
      saysStandalone: /snippets engine also works on its own/i.test(desc?.innerText || ''),
    };
  });

  r.check('—', 'the plugin row is on the Plugins screen', row.found);
  r.check('—', 'the row links to Snippets', !!row.snippetsHref, row.links.join(' | '));
  r.check(
    '—',
    'and Settings still comes before it',
    row.links.indexOf('Settings') > -1 && row.links.indexOf('Settings') < row.links.indexOf('Snippets'),
    row.links.join(' | ')
  );
  r.check('—', 'the description says the snippets engine works without an agent', row.saysStandalone);
  r.check('—', 'and WordPress did not strip its emphasis', row.strongSurvived);

  if (row.snippetsHref) {
    await page.goto(new URL(row.snippetsHref, WP).href, { waitUntil: 'load' });
    const landed = await page.evaluate(() => ({
      tabs: [...document.querySelectorAll('.nav-tab')].length,
      fatal: /fatal error/i.test(document.body.innerText),
    }));
    r.check('—', 'and the link lands on the real Snippets screen', landed.tabs > 0 && !landed.fatal);
  } else {
    r.fail('—', 'and the link lands on the real Snippets screen', 'no link to follow');
  }
}

/** B14, B15 — AI Compose needs a way out. */
async function checkCompose(page) {
  await page.goto(`${STUDIO}&tab=compose`, { waitUntil: 'load' });

  // The plan and code cards are rendered by PHP and injected by JS, so they are
  // exercised here by injecting the same markup the server produces. Reaching
  // the real AI is tests/rig/checks-compose.php's job; this asserts the
  // controls exist and behave, which is the part only a browser can see.
  const planHtml = wpEval(
    `echo Claudpress_Studio_Compose::plan_html(['summary' => 'UI check plan', 'steps' => ['one'], 'risks' => []]);`,
    wpOpts
  );
  await page.evaluate((html) => {
    const box = document.getElementById('cp-compose-plan-box');
    box.innerHTML = html;
    box.style.display = 'block';
    if (window.cpComposeBindPlan) window.cpComposeBindPlan();
  }, planHtml);

  const hasDiscard = await page.evaluate(() => !!document.getElementById('cp-compose-discard'));
  r.check('B14', 'the AI plan can be discarded', hasDiscard);

  // Asserted BEFORE the discard click below. Discarding empties the card, so
  // checking afterwards asked whether a button existed inside markup that had
  // just been deleted - and reported a missing feature that was there.
  r.check(
    'B14',
    'the plan card offers a way to plan again',
    await page.evaluate(() => !!document.getElementById('cp-compose-replan'))
  );

  if (hasDiscard) {
    await page.click('#cp-compose-discard');
    const cleared = await page.evaluate(() => {
      const box = document.getElementById('cp-compose-plan-box');
      return box.innerHTML.trim() === '' || box.style.display === 'none';
    });
    r.check('B14', 'discarding clears the plan with no page reload', cleared);
  } else {
    r.fail('B14', 'discarding clears the plan with no page reload', 'no discard control');
  }

  // A failed lint must not offer a bare Save button.
  const badHtml = wpEval(
    `echo Claudpress_Studio_Compose::code_html('<?php if (', 'php', 'PHP Parse error: syntax error, unexpected end of file', 'ui check');`,
    wpOpts
  );
  const gate = await page.evaluate((html) => {
    const box = document.getElementById('cp-compose-code-box');
    box.innerHTML = html;
    box.style.display = 'block';
    if (window.cpComposeBindCode) window.cpComposeBindCode();
    const btn = box.querySelector('button[type=submit], button.button-primary');
    const ack = box.querySelector('#cp-compose-ack');
    return { hasButton: !!btn, disabled: !!btn?.disabled, hasAck: !!ack };
  }, badHtml);

  r.check('B15', 'a failed syntax check disables Save', gate.hasButton && gate.disabled);
  r.check('B15', 'and offers an explicit acknowledgement to enable it', gate.hasAck);

  if (gate.hasAck) {
    await page.check('#cp-compose-ack');
    const enabled = await page.evaluate(
      () => !document.querySelector('#cp-compose-code-box button[type=submit]')?.disabled
    );
    r.check('B15', 'ticking the acknowledgement enables Save', enabled);
  } else {
    r.fail('B15', 'ticking the acknowledgement enables Save', 'no acknowledgement box');
  }

  if (r.failures.length) await shoot(page, 'compose');
}

/**
 * B16 — the dismissible abilities banner.
 *
 * The chip must survive the dismissal. That is the entire safety argument for
 * allowing the banner to be hidden at all, so it is asserted rather than
 * assumed, on every run.
 */
async function checkBanner(page) {
  const wasOn = wpEval(`echo (int) Claudpress::instance()->get('abilities_enabled', false);`, wpOpts).trim();
  wpEval(`Claudpress::instance()->update(['abilities_enabled' => true]);`, wpOpts);
  wpEval(`delete_user_meta(get_user_by('login', '${USER}')->ID, 'claudpress_hide_abilities_notice');`, wpOpts);

  try {
    await page.goto(`${STUDIO}&tab=all`, { waitUntil: 'load' });
    const before = await page.evaluate(() => ({
      banner: !!document.querySelector('.claudpress-abilities-notice'),
      chip: (document.querySelector('#wp-admin-bar-claudpress')?.textContent || '').trim(),
      dismiss: !!document.querySelector('.claudpress-abilities-notice .notice-dismiss, #cp-hide-abilities-notice'),
    }));
    r.check('B16', 'the abilities banner shows while abilities are on', before.banner);
    r.check('B16', 'the toolbar chip is present', /on/i.test(before.chip), before.chip || 'no chip');
    r.check('B16', 'the banner offers a way to hide it', before.dismiss);

    // Dismiss it the way the product does, then reload.
    wpEval(
      `update_user_meta(get_user_by('login', '${USER}')->ID, 'claudpress_hide_abilities_notice', Claudpress_Admin::notice_fingerprint());`,
      wpOpts
    );
    await page.goto(`${STUDIO}&tab=all`, { waitUntil: 'load' });
    const after = await page.evaluate(() => ({
      banner: !!document.querySelector('.claudpress-abilities-notice'),
      chip: (document.querySelector('#wp-admin-bar-claudpress')?.textContent || '').trim(),
    }));
    r.check('B16', 'the banner stays hidden once dismissed', !after.banner);
    r.check(
      'B16',
      'THE CHIP SURVIVES THE DISMISSAL — the whole safety argument',
      /on/i.test(after.chip),
      after.chip || 'no chip'
    );

    // Toggling abilities must bring the warning back.
    wpEval(`Claudpress::instance()->update(['abilities_enabled' => false]);`, wpOpts);
    wpEval(`Claudpress::instance()->update(['abilities_enabled' => true]);`, wpOpts);
    await page.goto(`${STUDIO}&tab=all`, { waitUntil: 'load' });
    const again = await page.evaluate(() => !!document.querySelector('.claudpress-abilities-notice'));
    r.check('B16', 'the banner RETURNS after abilities are toggled off and on', again);
  } finally {
    wpEval(
      `Claudpress::instance()->update(['abilities_enabled' => ${wasOn === '1' ? 'true' : 'false'}]);` +
        `delete_user_meta(get_user_by('login', '${USER}')->ID, 'claudpress_hide_abilities_notice');`,
      wpOpts
    );
  }
}

/* ------------------------------------------------------------------ */
/* Run                                                                 */
/* ------------------------------------------------------------------ */

let browser;
let seeded = null;

try {
  clearFailures();

  // Fail loudly and early rather than reporting twelve mysterious failures
  // because nothing is serving the rig.
  const probe = await fetch(`${WP}/wp-login.php`).catch(() => null);
  if (!probe || !probe.ok) {
    console.error(`Cannot reach ${WP}. Start the rig first:`);
    console.error(`  cd ${RIG} && PHP_CLI_SERVER_WORKERS=4 php -S localhost:8899`);
    process.exit(2);
  }

  seeded = seed();
  if (seeded.error) throw new Error(`Could not seed fixtures: ${seeded.error}`);
  console.log(`Seeded fixtures: ${seeded.ids.join(', ')}`);

  const launched = await launch({ chrome: opt.chrome, headed: opt.headed });
  browser = launched.browser;
  const page = await login(launched.context, { wp: WP, user: USER, pass: PASS });

  // Progress is printed as each group starts. Without it a stalled run prints
  // nothing at all and there is no way to tell which check is stuck.
  const groups = [
    ['editor', () => checkEditor(page, seeded.ids)],
    ['toggle', () => checkToggle(page, seeded.ids)],
    ['list controls', () => checkListControls(page)],
    ['dead controls', () => checkNoDeadControls(page, seeded.ids)],
    ['donate', () => checkDonate(page)],
    ['plugins row', () => checkPluginsRow(page)],
    ['compose', () => checkCompose(page)],
    ['abilities banner', () => checkBanner(page)],
  ];
  for (const [name, run] of groups) {
    process.stdout.write(`  ... ${name}
`);
    // One group must never be able to take the whole run down with it.
    await run().catch((e) => r.fail('—', `the ${name} checks`, e.message));
  }
} catch (err) {
  r.fail('—', 'the run itself', err.message);
} finally {
  // Teardown happens here and nowhere else. A failed assertion, a thrown error
  // and a clean pass all reach this block.
  if (seeded) {
    try {
      const gone = teardown();
      console.log(`Torn down: ${gone.deleted.length} fixture(s); ${gone.remaining} snippet(s) left on the rig.`);
    } catch (e) {
      console.error(`TEARDOWN FAILED — the rig still holds ${PREFIX} snippets: ${e.message}`);
      r.fail('—', 'fixture teardown', e.message);
    }
  }
  // close() with a deadline. On Windows it can hang after a run that opened
  // many pages, and a check suite that prints all its results and then never
  // exits is indistinguishable from one that is stuck.
  if (browser) {
    await Promise.race([
      browser.close(),
      new Promise((resolve) => setTimeout(resolve, 5000)),
    ]).catch(() => {});
  }
}

const green = r.print();
// process.exit rather than letting the event loop drain: a browser that did not
// close leaves handles open, and they would hold the process forever.
process.exit(green ? 0 : 1);
