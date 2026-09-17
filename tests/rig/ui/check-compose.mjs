/**
 * AI Compose, asserted in a real browser.
 *
 *   node tests/rig/ui/check-compose.mjs --wp http://localhost:8899 \
 *        --rig /c/wp-rig/wordpress --user admin --pass rigpass
 *
 * WHY THIS FILE EXISTS. Compose was rebuilt from a three-step wizard into a
 * chat, and the two failures that prompted the rebuild were both INVISIBLE to
 * every other test layer:
 *
 *   - a model answering in prose instead of JSON produced "The AI replied, but
 *     not in a form this screen could read" over a perfectly good answer;
 *   - a syntax error was rendered for the person and never shown to the model,
 *     so there was no way to say "fix that".
 *
 * Unit tests prove the parsing. The rig checks prove the server flow. Neither
 * can prove that the transcript appears on screen, that the code block is
 * legible, or that the composer box is still reachable after twenty messages —
 * and a chat screen that scrolls its own input off the bottom is useless while
 * passing every other suite.
 *
 * NO AI CALL IS MADE. Every assistant turn here is server-rendered markup
 * injected through the same path the live screen uses. That keeps the run free
 * and deterministic; what is under test is the SCREEN, not the model.
 *
 * FIXTURES ARE TORN DOWN IN THE SAME RUN THAT ASSERTS ON THEM, including when
 * the run fails — see the `finally` at the bottom.
 */

import { args, clearFailures, launch, login, onScreen, Report, shoot, wpJson } from './harness.mjs';

const opt = args();
const WP = (opt.wp || 'http://localhost:8899').replace(/\/$/, '');
const RIG = opt.rig || '/c/wp-rig/wordpress';
const USER = opt.user || 'admin';
const PASS = opt.pass || 'rigpass';
const CLI = opt.cli || null;
const wpOpts = { path: RIG, cli: CLI };

const PREFIX = 'ZZ CHAT';
const COMPOSE = `${WP}/wp-admin/admin.php?page=keelhob-studio&tab=compose`;

const r = new Report();

/**
 * Render an assistant turn on the SERVER, exactly as ajax_chat would.
 *
 * Building the markup here in JavaScript instead would test a renderer that
 * does not ship. The whole escaping argument for server-side rendering rests on
 * this being the same code path.
 */
function renderTurn(replyText) {
  return wpJson(
    `
    $text = base64_decode('${Buffer.from(replyText, 'utf8').toString('base64')}');
    $segments = Keelhob_Studio_Chat::segments($text);
    foreach ($segments as $i => $seg) {
        if (($seg['kind'] ?? '') === 'code') {
            $segments[$i]['error'] = Keelhob_Snippets::lint_php((string) $seg['code'], (string) $seg['type']);
        }
    }
    echo json_encode(['html' => Keelhob_Studio_Compose::message_html($segments)]);
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
    echo json_encode(['deleted' => count($gone), 'remaining' => count(Keelhob_Snippets::query([]))]);
    `,
    wpOpts
  );
}

let browser;

clearFailures();

try {
  const launched = await launch({ chrome: opt.chrome, headed: opt.headed });
  browser = launched.browser;
  const page = await login(launched.context, { wp: WP, user: USER, pass: PASS });

  await page.goto(COMPOSE, { waitUntil: 'domcontentloaded' });

  /* ---------------------------------------------------------------- */
  /* 1. The shell is there and is actually visible                     */
  /* ---------------------------------------------------------------- */

  const chat = await onScreen(page, '#kh-chat');
  r.check('chat-1', 'the transcript area is on screen', chat.found && chat.inViewport, JSON.stringify(chat.rect || {}));

  const input = await onScreen(page, '#kh-chat-input');
  r.check('chat-2', 'the message box is on screen', input.found && input.inViewport);

  const send = await onScreen(page, '#kh-chat-send');
  r.check('chat-3', 'the Send button is on screen', send.found && send.inViewport);

  // The old wizard shipped an inline <script>, which runs BEFORE anything the
  // render callback enqueued. That is the exact mistake that left the snippet
  // editor with no code editor on every install for as long as it existed.
  const wired = await page.evaluate(() => typeof window.cpComposeBindMessage === 'function');
  r.check('chat-4', 'the chat script actually loaded and ran', wired);

  // The transcript scrolls; the page does not. A chat built out of ordinary
  // page flow walks its own input box off the bottom of the screen.
  const scrolls = await page.evaluate(() => {
    const el = document.getElementById('kh-chat');
    return el ? getComputedStyle(el).overflowY : '';
  });
  r.check('chat-5', 'the transcript is its own scroller', scrolls === 'auto' || scrolls === 'scroll', scrolls);

  await shoot(page, 'compose-empty');

  /* ---------------------------------------------------------------- */
  /* 2. A reply that carries clean code                                */
  /* ---------------------------------------------------------------- */

  const good = renderTurn(
    "Sure. This appends a note to every post:\n\n```php\nadd_filter('the_content', function ($c) {\n    return $c . '<!-- note -->';\n});\n```\n\nIt runs on every front-end request."
  );

  await page.evaluate((html) => {
    const holder = document.createElement('div');
    holder.innerHTML = html;
    document.getElementById('kh-chat').appendChild(holder.firstElementChild);
    document.getElementById('kh-chat-empty').hidden = true;
  }, good.html);

  const bubble = await onScreen(page, '.kh-msg-ai .kh-msg-body');
  r.check('chat-6', 'the assistant bubble renders and is visible', bubble.found && bubble.inViewport);

  // ORDER. The prose above the block and the prose below it must both survive,
  // in place. Stacking all prose then all code would turn "here is the CSS, and
  // here is the PHP" into two unlabelled blocks under one paragraph.
  const order = await page.evaluate(() => {
    const kids = Array.from(document.querySelectorAll('.kh-msg-ai .kh-msg-body > *'));
    return kids.map((k) => (k.classList.contains('kh-code') ? 'code' : 'text'));
  });
  r.check('chat-7', 'prose and code render in the order the model wrote them', JSON.stringify(order) === JSON.stringify(['text', 'code', 'text']), JSON.stringify(order));

  const lintOk = await onScreen(page, '.kh-lint-ok');
  r.check('chat-8', 'clean code shows a passed badge', lintOk.found && lintOk.inViewport);

  // A code block people cannot read is not a feature. Assert the CONTRAST is
  // real, not that a class name is present.
  const codeLook = await page.evaluate(() => {
    const el = document.querySelector('.kh-code-body');
    if (!el) return null;
    const cs = getComputedStyle(el);
    const r = el.getBoundingClientRect();
    return { bg: cs.backgroundColor, fg: cs.color, mono: cs.fontFamily.toLowerCase(), w: Math.round(r.width), overflow: cs.overflowX };
  });
  r.check('chat-9', 'the code block has a real background, not the page default', !!codeLook && codeLook.bg !== 'rgba(0, 0, 0, 0)' && codeLook.bg !== 'transparent', codeLook && codeLook.bg);
  r.check('chat-10', 'and its text colour differs from its background', !!codeLook && codeLook.fg !== codeLook.bg);
  r.check('chat-11', 'the code block can scroll a long line instead of stretching the page', !!codeLook && (codeLook.overflow === 'auto' || codeLook.overflow === 'scroll'), codeLook && codeLook.overflow);

  const saveBtn = await onScreen(page, '.kh-code-save button');
  r.check('chat-12', 'every code block carries its own Save', saveBtn.found && saveBtn.inViewport);

  /* ---------------------------------------------------------------- */
  /* 3. A reply whose code does NOT parse                              */
  /* ---------------------------------------------------------------- */

  const bad = renderTurn("Here you go:\n\n```php\nadd_filter('the_content', function ($c) {\n    return $c;\n```\n\nThat should do it.");

  await page.evaluate((html) => {
    const holder = document.createElement('div');
    holder.innerHTML = html;
    const node = holder.firstElementChild;
    const chat = document.getElementById('kh-chat');
    chat.appendChild(node);
    window.cpComposeBindMessage(node);
    // drawAI() scrolls after every real turn; these are injected straight in.
    chat.scrollTop = chat.scrollHeight;
  }, bad.html);

  const lintBad = await onScreen(page, '.kh-lint-bad');
  r.check('chat-13', 'broken code shows a failed badge', lintBad.found && lintBad.inViewport);

  // The MESSAGE, not just the word "failed". A person cannot fix what they
  // cannot read, and the model cannot either.
  const errText = await page.evaluate(() => {
    const el = document.querySelector('.kh-lint-detail code');
    return el ? el.textContent.trim() : '';
  });
  r.check('chat-14', 'the actual parse error is printed, not just "failed"', errText.length > 10, errText.slice(0, 60));

  // THE THING THE WHOLE REWRITE EXISTS FOR. Before this, a syntax error was a
  // dead end: save it broken, or start over from a blank prompt.
  const fix = await onScreen(page, '.kh-chat-fix');
  r.check('chat-15', 'a failed block offers to send the error back to the AI', fix.found && fix.inViewport);

  const carries = await page.evaluate(() => {
    const el = document.querySelector('.kh-chat-fix');
    return el ? (el.getAttribute('data-error') || '').length : 0;
  });
  r.check('chat-16', 'and that button carries the real error with it', carries > 10, String(carries));

  // A failed parse must still be saveable. The AI wrote it, the person may want
  // to fix one semicolon by hand, and throwing the work away to protect them
  // from an error they can already read is its own bug.
  const saveAnyway = await page.evaluate(() => {
    const forms = document.querySelectorAll('.kh-code-bad .kh-code-save button');
    return forms.length === 1 && !forms[0].disabled;
  });
  r.check('chat-17', 'broken code can still be saved by hand (it saves switched off)', saveAnyway);

  await shoot(page, 'compose-with-error');

  /* ---------------------------------------------------------------- */
  /* 4. Model output is ESCAPED, not executed                          */
  /* ---------------------------------------------------------------- */

  // The day a renderer gets this wrong, a snippet containing a <script> tag
  // runs inside wp-admin, with an administrator logged in.
  await page.evaluate(() => {
    window.__cpXss = false;
  });
  const nasty = renderTurn(
    'Try this:\n\n```html\n<script>window.__cpXss = true;</script>\n```\n\nAnd <img src=x onerror="window.__cpXss = true"> in the prose too.'
  );
  await page.evaluate((html) => {
    const holder = document.createElement('div');
    holder.innerHTML = html;
    document.getElementById('kh-chat').appendChild(holder.firstElementChild);
  }, nasty.html);

  await new Promise((resolve) => setTimeout(resolve, 300));

  const xss = await page.evaluate(() => window.__cpXss === true);
  r.check('chat-18', 'a <script> tag in model output does NOT execute', !xss);

  const injected = await page.evaluate(() => document.querySelectorAll('#kh-chat img').length);
  r.check('chat-19', 'and an <img onerror> in prose is text, not an element', injected === 0, String(injected));

  const shownAsText = await page.evaluate(() => {
    const all = document.querySelectorAll('.kh-code-body code');
    const el = all[all.length - 1];
    return el ? el.textContent.includes('<script>') : false;
  });
  r.check('chat-20', 'the script tag is still READABLE as code', shownAsText);

  /* ---------------------------------------------------------------- */
  /* 5. The composer survives a long conversation                      */
  /* ---------------------------------------------------------------- */

  await page.evaluate((html) => {
    for (let i = 0; i < 20; i++) {
      const holder = document.createElement('div');
      holder.innerHTML = html;
      document.getElementById('kh-chat').appendChild(holder.firstElementChild);
    }
  }, good.html);

  const inputAfter = await onScreen(page, '#kh-chat-input');
  r.check('chat-21', 'the message box is STILL on screen after 20+ messages', inputAfter.found && inputAfter.inViewport, JSON.stringify(inputAfter.rect || {}));

  const noSideScroll = await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1);
  r.check('chat-22', 'and the page never scrolls sideways', noSideScroll);

  await shoot(page, 'compose-long');

  /* ---------------------------------------------------------------- */
  /* 6. Clear empties it                                               */
  /* ---------------------------------------------------------------- */

  page.on('dialog', (d) => d.accept());
  await page.click('#kh-chat-clear');
  await new Promise((resolve) => setTimeout(resolve, 300));

  const left = await page.evaluate(() => document.querySelectorAll('#kh-chat .kh-msg').length);
  r.check('chat-23', 'Clear chat empties the transcript', left === 0, String(left));

  const introBack = await onScreen(page, '#kh-chat-empty');
  r.check('chat-24', 'and the getting-started text comes back', introBack.found && introBack.inViewport);

  /* ---------------------------------------------------------------- */
  /* 7. Narrow screen                                                  */
  /* ---------------------------------------------------------------- */

  await page.setViewportSize({ width: 420, height: 900 });
  await page.reload({ waitUntil: 'domcontentloaded' });

  const narrowInput = await onScreen(page, '#kh-chat-input');
  r.check('chat-25', 'the message box works at phone width', narrowInput.found && narrowInput.inViewport);

  const narrowNoScroll = await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 2);
  r.check('chat-26', 'and the screen still does not scroll sideways', narrowNoScroll);

  await shoot(page, 'compose-narrow');
} catch (err) {
  r.fail('—', 'the run itself', err.message);
} finally {
  try {
    const gone = teardown();
    console.log(`Torn down: ${gone.deleted} fixture(s); ${gone.remaining} snippet(s) left on the rig.`);
  } catch (e) {
    console.error(`TEARDOWN FAILED — the rig may still hold ${PREFIX} snippets: ${e.message}`);
    r.fail('—', 'fixture teardown', e.message);
  }
  if (browser) {
    await Promise.race([browser.close(), new Promise((resolve) => setTimeout(resolve, 5000))]).catch(() => {});
  }
}

const green = r.print();
process.exit(green ? 0 : 1);
