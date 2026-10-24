/**
 * The Snippets screens, asserted in a real browser.
 *
 *   node tests/rig/ui/check-snippets.mjs --wp http://localhost:8899 \
 *        --rig /c/wp-rig/wordpress --user admin --pass rigpass
 *
 * Every check names the baseline row it replaces. The baselines are the twelve
 * measurements in docs/plans/snippets-rebuild.md, taken on 2026-09-13 against
 * Keelhob 1.1.0. Before the work in that plan, this file is expected to be
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
const STUDIO = `${WP}/wp-admin/admin.php?page=keelhob-studio`;

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
      $res = Keelhob_Snippets::save([
        'name' => $s[0], 'snippet_type' => $s[1], 'code' => $s[2],
        'location' => $s[3], 'active' => $s[4], 'priority' => $s[5],
        'description' => 'Browser-check fixture. Safe to delete.',
        // purpose and impact are REQUIRED on a create since the security gate
        // landed (class-snippets.php:654). Without them save() refuses every
        // fixture and the whole file dies in seed() before it has rendered a
        // single screen, which is exactly how this check went quiet.
        'purpose' => 'Fixture for tests/rig/ui/check-snippets.mjs.',
        'impact'  => 'None. It hooks init and returns. Deleting it breaks nothing.',
      ]);
      if (empty($res['success'])) { echo json_encode(['error' => $res['error'] ?? 'save failed']); return; }
      $ids[] = (int) $res['id'];
    }
    global $wpdb;
    $wpdb->update(
      $wpdb->prefix . 'keelhob_snippets',
      ['last_error' => 'Fixture error: syntax error, unexpected end of file'],
      ['id' => $ids[3]]
    );

    /*
     * alpha is the snippet every edit-screen check opens, so it carries the two
     * things the 1.2.0 rebuild added: stored conditions, and a history.
     *
     * Both have to be REAL rather than injected straight into the columns. A
     * revision row written by hand would prove the panel can render a row, not
     * that saving records one — and "the feature exists but never fires" is
     * precisely the failure mode this whole file was written for.
     */
    Keelhob_Snippets::save([
      'id' => $ids[0],
      'conditions' => ['enabled' => true, 'relation' => 'AND', 'rules' => [
        ['type' => 'user_status', 'operator' => 'is', 'value' => 'logged_in'],
        ['type' => 'device', 'operator' => 'is', 'value' => 'mobile'],
      ]],
    ]);
    // A different body, so save() records a revision the way an edit would.
    Keelhob_Snippets::save([
      'id' => $ids[0],
      'code' => 'add_action("init", function () { return true; }); // v2',
    ]);

    echo json_encode(['ids' => $ids]);
  `,
    wpOpts
  );
}

function teardown() {
  return wpJson(
    `
    $gone = [];
    foreach (Keelhob_Snippets::query([]) as $row) {
      if (strpos((string) $row['name'], '${PREFIX}') === 0) {
        Keelhob_Snippets::delete((int) $row['id']);
        $gone[] = (int) $row['id'];
      }
    }
    echo json_encode(['deleted' => $gone, 'remaining' => count(Keelhob_Snippets::query([]))]);
  `,
    wpOpts
  );
}

const isActive = (id) =>
  wpEval(`$s = Keelhob_Snippets::get(${id}); echo (int) ($s['active'] ?? -1);`, wpOpts).trim();

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
    const rect = (first || cm || document.getElementById('kh-code'))?.getBoundingClientRect();
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
        return [...document.querySelectorAll('#kh-studio-form input:not([type=hidden]), #kh-studio-form select, #kh-studio-form textarea:not(#kh-code)')]
          .filter((el) => el.getBoundingClientRect().top + window.scrollY < codeTop).length;
      })(),
      askAiButton: !!document.getElementById('kh-ask-ai'),
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

  /*
   * Every submit button in the editor form, wherever the layout has put it.
   *
   * This used to look in `p.submit`, which is where the buttons were before the
   * 1.2.0 two-column rebuild moved them into the sidebar's Save panel. The
   * guarantee being asserted — Save is the loud one, "switch on" is the quiet
   * one — has nothing to do with which box they sit in, so the check should not
   * have been pinned to one. Anchored on the form instead.
   */
  const buttons = await page.evaluate(() =>
    [...document.querySelectorAll('#kh-studio-form button[type="submit"]')].map((b) => ({
      text: b.textContent.trim(),
      primary: b.className.includes('button-primary'),
    }))
  );
  const save = buttons.find((b) => /^save$/i.test(b.text) || /^save \(/i.test(b.text));
  const activate = buttons.find((b) => /switch on/i.test(b.text));
  r.check('—', 'Save is the primary button', !!save?.primary, save ? save.text : 'no Save button');
  r.check('—', '"Save & switch on" is not the primary button', !activate || !activate.primary);

  /*
   * B17-B19 — the 1.2.0 rebuild.
   *
   * All three were reported as MISSING by somebody using the plugin. Two of
   * them existed and were merely unfindable; the third had an engine and no
   * screen. The lesson these checks encode is that "shipped" and "reachable"
   * are different claims, so each one asserts a measured position or a real
   * populated control, never the presence of markup.
   */
  const layout = await page.evaluate(() => {
    const rect = (s) => {
      const e = document.querySelector(s);
      return e ? e.getBoundingClientRect() : null;
    };
    const main = rect('.kh-main');
    const side = rect('.kh-side');
    const bar = rect('.kh-editor-bar');
    const cm = rect('.kh-editor-shell .CodeMirror');
    return {
      hasGrid: !!(main && side),
      // A real two-column layout, not two stacked blocks with a grid class on
      // them: the rail starts at or after the main column's right edge, and
      // they share a top.
      sideOnTheRight: !!(main && side) && side.x >= main.x + main.width - 1 && Math.abs(side.y - main.y) < 4,
      barAboveCode: !!(bar && cm) && bar.y + bar.height <= cm.y + 1,
      fileName: document.getElementById('kh-file-name')?.textContent || '',
      pill: document.getElementById('kh-lang-pill')?.textContent || '',
      dotPainted: (() => {
        const d = document.querySelector('.kh-lang-dot');
        if (!d) return false;
        const c = getComputedStyle(d).backgroundColor;
        // Not transparent, and not the default grey — the dot must be saying
        // something about the language rather than just existing.
        return c !== 'rgba(0, 0, 0, 0)' && c !== 'rgb(140, 143, 148)';
      })(),
      panels: [...document.querySelectorAll('.kh-panel h3')].map((e) => e.textContent.trim()),
      condRows: document.querySelectorAll('.kh-cond-rule').length,
      condBodyVisible: !document.getElementById('kh-cond-body')?.hidden,
      // A populated rule row: three controls, all with a value.
      condRowComplete: (() => {
        const row = document.querySelector('.kh-cond-rule');
        if (!row) return false;
        const fields = [...row.querySelectorAll('select, input')];
        return fields.length >= 3 && fields.slice(0, 3).every((f) => f.value !== '');
      })(),
      revisionRows: document.querySelectorAll('.kh-revisions li').length,
      restoreWired: [...document.querySelectorAll('.kh-rev-restore')].every(
        (b) => b.getAttribute('form') === 'kh-restore-form'
      ),
    };
  });

  r.check('B17', 'the editor screen is two columns, settings on the right', layout.sideOnTheRight);
  r.check('B17', 'the code has an editor toolbar above it', layout.barAboveCode);
  r.check(
    'B17',
    'the toolbar names the file and the language',
    /^snippet\.(php|css|js|html)$/.test(layout.fileName) && layout.pill.length > 0,
    `${layout.fileName} / ${layout.pill}`
  );
  r.check('B17', 'and the language dot is actually coloured for that language', layout.dotPainted);
  r.check(
    'B18',
    'the sidebar carries Save, Behaviour and Revisions',
    ['Save', 'Behaviour', 'Revisions'].every((p) => layout.panels.includes(p)),
    layout.panels.join(' | ')
  );
  r.check(
    'B18',
    'revisions are listed on the edit screen, not buried below the fold',
    layout.revisionRows > 0,
    `${layout.revisionRows} revision(s)`
  );
  r.check('B18', 'each Restore button is wired to the restore form', layout.restoreWired);
  r.check('B19', 'stored conditions open the rule builder', layout.condBodyVisible);
  r.check('B19', 'and draw one row per stored rule', layout.condRows === 2, `${layout.condRows} row(s)`);
  r.check('B19', 'with every field in the row actually filled in', layout.condRowComplete);

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
    const ctl = row.querySelector('.kh-status-toggle button, button[data-cp-toggle]');
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
    wpEval(`Keelhob_Snippets::save(['id' => ${ids[1]}, 'active' => 0]);`, wpOpts);
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
    (id) => [...document.querySelectorAll('.kh-snippet-id')].some((el) => el.textContent.trim() === `#${id}`),
    ids[0]
  );
  r.check('—', 'each row shows the number you would say to the agent', idShown);

  await page.goto(`${STUDIO}&tab=edit&snippet=${ids[0]}`, { waitUntil: 'load' });
  const hint = await page.evaluate(() => document.querySelector('.kh-ask-hint')?.textContent.trim() || '');
  r.check('—', 'and the edit screen explains it once', /tell me about snippet/i.test(hint), hint.slice(0, 70));

  // ...but not on a new snippet, where there is no number yet.
  await page.goto(`${STUDIO}&tab=edit`, { waitUntil: 'load' });
  const onNew = await page.evaluate(() => !!document.querySelector('.kh-ask-hint'));
  r.check('—', 'and says nothing on a snippet that has no number yet', !onNew);
}

/** B11 — how many times one screen asks for money. */
async function checkDonate(page) {
  const screens = [
    ['keelhob', true],
    ['keelhob-studio', false],
    ['keelhob-skills', false],
    ['keelhob-guide', false],
    ['keelhob-activity', false],
    ['keelhob-automation', false],
  ];
  for (const [slug, allowed] of screens) {
    await page.goto(`${WP}/wp-admin/admin.php?page=${slug}`, { waitUntil: 'domcontentloaded' });
    const found = await page.evaluate(() => {
      const h1 = document.querySelector('.wrap h1');
      if (!h1) return 0;
      /*
       * Only the heading AREA counts. A link inside page copy is not a plea.
       *
       * The <h1> used to be a direct child of `.wrap`, so "the heading and its
       * next two siblings" was the heading area. The admin redesign moved the
       * heading into the panel's header line (`.kh-u-head` > `.kh-u-head-id` >
       * `.kh-u-head-text` > h1), which left the donate button a sibling of the
       * header rather than of the heading — and this check read that as the
       * button having disappeared, on the one screen where it is supposed to be.
       *
       * So the zone is now: the header block the heading lives in, plus the
       * first two elements of the panel body after it. That is the same region
       * of the page it always meant, expressed against the new DOM, and it is
       * still nowhere near page copy. On a screen with no panel shell it falls
       * back to the old sibling walk.
       */
      const head = h1.closest('.kh-u-head');
      const zone = head
        ? [head, head.nextElementSibling, head.nextElementSibling?.nextElementSibling]
        : [h1, h1.nextElementSibling, h1.nextElementSibling?.nextElementSibling];
      return zone.filter(Boolean)
        .filter((el) => /donate|ko-?fi|sponsor/i.test(el.textContent || '')).length;
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
    const tr = document.querySelector('tr[data-plugin*="keelhob"]');
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

/**
 * B14, B15 — AI Compose needs a way out.
 *
 * THE SCREEN THESE BASELINES WERE TAKEN AGAINST NO LONGER EXISTS. Compose was a
 * three-step wizard — plan, approve, build — and both baselines were about
 * escaping it: B14 that a plan could be discarded, B15 that a failed syntax
 * check did not offer a bare Save button.
 *
 * It is a chat now, and the escapes changed shape with it:
 *
 *   B14  "discard the plan" became "clear the chat", because there is no plan
 *        card to reject — you say what is wrong and it answers.
 *   B15  the acknowledgement tick became a "Fix this" button. The tick existed
 *        because a syntax error was a DEAD END: the screen could only offer to
 *        save broken code, so it made you say you had read the error first.
 *        Now the error goes back to the model, which is the thing somebody
 *        actually wants. Save is still offered — the AI wrote it, you may want
 *        to fix one semicolon by hand — and still saves switched OFF, which is
 *        the guarantee that never depended on the tick.
 *
 * The full chat screen is asserted in tests/rig/ui/check-compose.mjs, 26 checks
 * including the escaping of model output and the composer surviving a long
 * conversation. This keeps the two baselines answerable from their own file.
 */
async function checkCompose(page) {
  await page.goto(`${STUDIO}&tab=compose`, { waitUntil: 'load' });

  const shell = await page.evaluate(() => ({
    chat: !!document.getElementById('kh-chat'),
    input: !!document.getElementById('kh-chat-input'),
    clear: !!document.getElementById('kh-chat-clear'),
    wired: typeof window.cpComposeBindMessage === 'function',
  }));

  r.check('B14', 'Compose is a conversation with a message box', shell.chat && shell.input);
  r.check('B14', 'and there is a way out of it without reloading', shell.clear);
  r.check('B15', 'the chat script loaded and ran', shell.wired);

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
  const wasOn = wpEval(`echo (int) Keelhob::instance()->get('abilities_enabled', false);`, wpOpts).trim();
  wpEval(`Keelhob::instance()->update(['abilities_enabled' => true]);`, wpOpts);
  wpEval(`delete_user_meta(get_user_by('login', '${USER}')->ID, 'keelhob_hide_abilities_notice');`, wpOpts);

  try {
    await page.goto(`${STUDIO}&tab=all`, { waitUntil: 'load' });
    const before = await page.evaluate(() => ({
      banner: !!document.querySelector('.keelhob-abilities-notice'),
      chip: (document.querySelector('#wp-admin-bar-keelhob')?.textContent || '').trim(),
      dismiss: !!document.querySelector('.keelhob-abilities-notice .notice-dismiss, #kh-hide-abilities-notice'),
    }));
    r.check('B16', 'the abilities banner shows while abilities are on', before.banner);
    r.check('B16', 'the toolbar chip is present', /on/i.test(before.chip), before.chip || 'no chip');
    r.check('B16', 'the banner offers a way to hide it', before.dismiss);

    // Dismiss it the way the product does, then reload.
    wpEval(
      `update_user_meta(get_user_by('login', '${USER}')->ID, 'keelhob_hide_abilities_notice', Keelhob_Admin::notice_fingerprint());`,
      wpOpts
    );
    await page.goto(`${STUDIO}&tab=all`, { waitUntil: 'load' });
    const after = await page.evaluate(() => ({
      banner: !!document.querySelector('.keelhob-abilities-notice'),
      chip: (document.querySelector('#wp-admin-bar-keelhob')?.textContent || '').trim(),
    }));
    r.check('B16', 'the banner stays hidden once dismissed', !after.banner);
    r.check(
      'B16',
      'THE CHIP SURVIVES THE DISMISSAL — the whole safety argument',
      /on/i.test(after.chip),
      after.chip || 'no chip'
    );

    // Toggling abilities must bring the warning back.
    wpEval(`Keelhob::instance()->update(['abilities_enabled' => false]);`, wpOpts);
    wpEval(`Keelhob::instance()->update(['abilities_enabled' => true]);`, wpOpts);
    await page.goto(`${STUDIO}&tab=all`, { waitUntil: 'load' });
    const again = await page.evaluate(() => !!document.querySelector('.keelhob-abilities-notice'));
    r.check('B16', 'the banner RETURNS after abilities are toggled off and on', again);
  } finally {
    wpEval(
      `Keelhob::instance()->update(['abilities_enabled' => ${wasOn === '1' ? 'true' : 'false'}]);` +
        `delete_user_meta(get_user_by('login', '${USER}')->ID, 'keelhob_hide_abilities_notice');`,
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
