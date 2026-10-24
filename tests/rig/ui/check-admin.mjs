/**
 * The Keelhob admin panel, asserted in a real browser.
 *
 *   node tests/rig/ui/check-admin.mjs --wp http://localhost:8899 \
 *        --rig /c/wp-rig/wordpress --user admin --pass rigpass
 *   node tests/rig/ui/check-admin.mjs --dark
 *   node tests/rig/ui/check-admin.mjs --width 782
 *
 * WHY THIS FILE EXISTS.
 *
 * The admin redesign is a CSS and markup change, and a CSS and markup change is
 * exactly the class of work that reads correct and renders wrong. A stylesheet
 * that is registered but never enqueued, a token block that is scoped under a
 * class the page does not carry, a tab strip that was supposed to become links
 * and is still buttons — every one of those passes a grep and passes
 * `php tests/run.php`, because the stubs never resolve a cascade.
 *
 * So nothing here is asserted from markup. Every check reads a COMPUTED value
 * off a rendered page: `getComputedStyle`, bounding boxes, live DOM. If
 * `assets/css/admin.css` fails to load, `--kh-bg` resolves to the empty string
 * and the first check on every screen goes red, which is the correct answer.
 *
 * Written BEFORE the redesign, deliberately. A browser check written after the
 * fix can only tell you the fix is still there; written before, it tells you
 * the problem was real. The first run of this file against the un-redesigned
 * screens is expected to be mostly RED.
 *
 * FIXTURES: there are none. This file only reads screens. The one piece of rig
 * state it touches is the admin colour scheme under `--dark`, and that is
 * restored in the `finally` block whether the run passes, fails or throws —
 * same rule as check-snippets.mjs, for the same reason.
 */

import { args, clearFailures, launch, login, Report, shoot, wpEval } from './harness.mjs';

const opt = args();
const WP = (opt.wp || 'http://localhost:8899').replace(/\/$/, '');
const RIG = opt.rig || '/c/wp-rig/wordpress';
const USER = opt.user || 'admin';
const PASS = opt.pass || 'rigpass';
const CLI = opt.cli || null;
const wpOpts = { path: RIG, cli: CLI };

const DARK = opt.dark === true || opt.dark === 'true';
const WIDTH = Number(opt.width) || 1440;
const HEIGHT = Number(opt.height) || 900;

const r = new Report();

/* ------------------------------------------------------------------ */
/* The screens                                                         */
/* ------------------------------------------------------------------ */

/**
 * Every Keelhob admin screen, by slug.
 *
 * `slug` is what the tab strip's active link must point at. The Studio is in
 * the list because decision 3 migrates it in this pass; it is the screen most
 * likely to be left behind, since it has 828 lines of its own stylesheet that
 * predate the tokens.
 */
const SCREENS = [
  { name: 'Connect', slug: 'keelhob' },
  { name: 'Skills', slug: 'keelhob-skills' },
  { name: 'Automation', slug: 'keelhob-automation' },
  { name: 'Guide', slug: 'keelhob-guide' },
  { name: 'Activity', slug: 'keelhob-activity' },
  { name: 'Snippets Studio', slug: 'keelhob-studio' },
];

/** The root the whole panel is scoped under. See BUILD-BRIEF §3. */
const ROOT = '.kh-ui';

/** The tab strip. Deliberately NOT `.kh-tabs` — that name is taken by the
 *  Studio's app picker and means something else. See BUILD-BRIEF §3. */
const NAV = '.kh-u-nav';

/* ------------------------------------------------------------------ */
/* Colour maths, run in node rather than in the page                    */
/* ------------------------------------------------------------------ */

/** `rgb(1, 2, 3)` / `rgba(1, 2, 3, .5)` / `#abc` -> [r,g,b,a], or null. */
function parseColor(css) {
  if (!css) return null;
  const s = String(css).trim();
  if (s === 'transparent') return [0, 0, 0, 0];
  const m = s.match(/^rgba?\(([^)]+)\)$/i);
  if (m) {
    const p = m[1].split(/[,\s/]+/).filter(Boolean).map(Number);
    if (p.length < 3 || p.slice(0, 3).some(Number.isNaN)) return null;
    return [p[0], p[1], p[2], p.length > 3 && !Number.isNaN(p[3]) ? p[3] : 1];
  }
  const h = s.match(/^#([0-9a-f]{3}|[0-9a-f]{6})$/i);
  if (h) {
    const x = h[1].length === 3 ? h[1].split('').map((c) => c + c).join('') : h[1];
    return [parseInt(x.slice(0, 2), 16), parseInt(x.slice(2, 4), 16), parseInt(x.slice(4, 6), 16), 1];
  }
  return null;
}

function luminance([red, green, blue]) {
  const lin = [red, green, blue].map((v) => {
    const c = v / 255;
    return c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
  });
  return 0.2126 * lin[0] + 0.7152 * lin[1] + 0.0722 * lin[2];
}

/**
 * WCAG contrast of two colours, the foreground flattened onto the background
 * if it is translucent.
 *
 * Flattening matters: a muted grey at `opacity` or `rgba(…, .7)` measures as
 * compliant if you read its nominal value and non-compliant once it is actually
 * composited, and the second number is the one a reader gets.
 */
function contrast(fg, bg) {
  const f = parseColor(fg);
  const b = parseColor(bg);
  if (!f || !b) return null;
  if (b[3] === 0) return null;
  const flat = f[3] >= 1 ? f : f.slice(0, 3).map((c, i) => c * f[3] + b[i] * (1 - f[3]));
  const la = luminance(flat);
  const lb = luminance(b);
  const hi = Math.max(la, lb);
  const lo = Math.min(la, lb);
  return (hi + 0.05) / (lo + 0.05);
}

const ratio = (n) => (n === null ? 'unmeasurable' : `${n.toFixed(2)}:1`);

/* ------------------------------------------------------------------ */
/* What the page is asked for                                          */
/* ------------------------------------------------------------------ */

/**
 * Everything one screen can be asked in a single `evaluate`, because a round
 * trip per assertion turns a six-screen run into ninety of them.
 *
 * `effectiveBg` walks ancestors until it finds a background that is not fully
 * transparent, which is the only honest way to ask what a word is sitting on:
 * a `<span>` almost never paints its own background, so reading
 * `getComputedStyle(span).backgroundColor` gives you `rgba(0,0,0,0)` and a
 * contrast ratio of infinity against nothing.
 */
function probe() {
  const rootSel = '.kh-ui';
  const navSel = '.kh-u-nav';

  const effectiveBg = (el) => {
    let node = el;
    while (node && node !== document.documentElement) {
      const bg = getComputedStyle(node).backgroundColor;
      const m = bg.match(/^rgba?\(([^)]+)\)$/i);
      if (m) {
        const p = m[1].split(/[,\s/]+/).filter(Boolean).map(Number);
        const a = p.length > 3 ? p[3] : 1;
        if (a > 0) return bg;
      }
      node = node.parentElement;
    }
    return getComputedStyle(document.body).backgroundColor;
  };

  // The panel root if the screen has one, otherwise wp-admin's own `.wrap`.
  //
  // The fallback is the whole point of the first red run. Bailing out on a
  // missing `.kh-ui` would report one failure per screen and tell you nothing
  // about what IS being painted there; measuring `.wrap` instead gives a real
  // number for the background and the ink, which is the baseline the redesign
  // has to beat.
  const found = document.querySelector(rootSel);
  const root = found || document.querySelector('.wrap');
  const out = {
    url: location.href,
    viewport: { w: window.innerWidth, h: window.innerHeight },
    bodyClass: document.body.className,
    rootFound: !!found,
    measuredOn: found ? rootSel : '.wrap (fallback — no .kh-ui on this screen)',
    root: null,
    tokens: {},
    nav: null,
    overflow: null,
    samples: [],
  };

  /* --- the panel shell ------------------------------------------- */
  if (root) {
    const cs = getComputedStyle(root);
    out.root = {
      classes: root.className,
      isDark: root.classList.contains('is-dark'),
      background: cs.backgroundColor,
      color: cs.color,
      fontFamily: cs.fontFamily,
      minHeight: cs.minHeight,
    };
    for (const t of [
      '--kh-bg', '--kh-card', '--kh-soft', '--kh-ink', '--kh-ink-2', '--kh-muted',
      '--kh-line', '--kh-accent', '--kh-accent-ink', '--kh-accent-2', '--kh-accent-2-ink',
    ]) {
      out.tokens[t] = cs.getPropertyValue(t).trim();
    }
  }

  /* --- the tab strip ---------------------------------------------- */
  const nav = document.querySelector(navSel);
  if (nav) {
    const links = [...nav.querySelectorAll('a[href]')];
    const current = [...nav.querySelectorAll('[aria-current="page"]')];
    out.nav = {
      links: links.length,
      buttons: nav.querySelectorAll('button, [role="tab"], [role="switch"]').length,
      anchorsWithoutHref: nav.querySelectorAll('a:not([href])').length,
      currentCount: current.length,
      currentHref: current.length === 1 ? (current[0].getAttribute('href') || '') : '',
      currentTag: current.length === 1 ? current[0].tagName.toLowerCase() : '',
      hrefs: links.map((a) => a.getAttribute('href') || ''),
      onScreen: (() => {
        const b = nav.getBoundingClientRect();
        const cs = getComputedStyle(nav);
        return b.width > 0 && b.height > 0 && b.top > -1 && b.left > -1 &&
          cs.display !== 'none' && cs.visibility !== 'hidden' && cs.opacity !== '0';
      })(),
    };
  }

  /* --- horizontal overflow ---------------------------------------- */
  // Measured against the DOCUMENT, not against `.kh-ui`: the panel is pulled
  // left by a negative margin on purpose, so its own box legitimately starts
  // outside the content column. What must not happen is the page gaining a
  // horizontal scrollbar.
  const docW = document.documentElement.scrollWidth;
  const worst = [];
  for (const el of root ? [root, ...root.querySelectorAll('*')] : []) {
    const b = el.getBoundingClientRect();
    if (b.width === 0 || b.height === 0) continue;
    const cs = getComputedStyle(el);
    if (cs.display === 'none' || cs.visibility === 'hidden') continue;
    if (cs.position === 'fixed') continue;
    const over = Math.round(b.right - window.innerWidth);
    if (over > 2) {
      worst.push({
        over,
        tag: el.tagName.toLowerCase(),
        cls: (typeof el.className === 'string' ? el.className : '').slice(0, 60),
      });
    }
  }
  worst.sort((a, b) => b.over - a.over);
  out.overflow = {
    docScrollWidth: docW,
    overflows: docW > window.innerWidth + 1,
    worst: worst.slice(0, 4),
  };

  /* --- real text, sampled by the colour it actually resolved to ----- */
  // The sample is taken from the live DOM rather than from the token values
  // alone. A token can be perfectly compliant and still be applied to a word
  // sitting on the wrong surface — a muted grey designed against --kh-bg and
  // used inside a dark soft panel. Only the rendered pair tells you that.
  if (root) {
    const cs = getComputedStyle(root);
    const want = {};
    for (const t of ['--kh-muted', '--kh-accent-ink', '--kh-accent-2-ink', '--kh-ink', '--kh-ink-2']) {
      const v = cs.getPropertyValue(t).trim();
      if (v) want[v.toLowerCase()] = t;
    }
    const seen = new Set();
    const probe2 = document.createElement('span');
    root.appendChild(probe2);
    const resolve = (v) => {
      probe2.style.color = '';
      probe2.style.color = v;
      return getComputedStyle(probe2).color;
    };
    const wantRgb = {};
    for (const [hex, token] of Object.entries(want)) wantRgb[resolve(hex)] = token;
    probe2.remove();

    for (const el of root.querySelectorAll('*')) {
      const text = (el.textContent || '').trim();
      if (!text) continue;
      // Only leaf-ish nodes: a wrapper inherits its children's text and would
      // be credited with a colour none of its own characters are painted in.
      if (el.children.length && !/\S/.test([...el.childNodes].filter((n) => n.nodeType === 3).map((n) => n.textContent).join(''))) continue;
      const cs2 = getComputedStyle(el);
      const token = wantRgb[cs2.color];
      if (!token) continue;
      const key = `${token}|${cs2.color}|${effectiveBg(el)}`;
      if (seen.has(key)) continue;
      seen.add(key);
      const b = el.getBoundingClientRect();
      out.samples.push({
        token,
        fg: cs2.color,
        bg: effectiveBg(el),
        fontSize: parseFloat(cs2.fontSize),
        fontWeight: cs2.fontWeight,
        text: text.replace(/\s+/g, ' ').slice(0, 40),
        onScreen: b.width > 0 && b.height > 0,
      });
      if (out.samples.length >= 40) break;
    }
  }

  return out;
}

/* ------------------------------------------------------------------ */
/* The checks                                                          */
/* ------------------------------------------------------------------ */

async function checkScreen(page, screen) {
  const label = `${screen.name}${DARK ? ' (dark)' : ''} @${WIDTH}px`;
  const B = screen.slug;

  await page.goto(`${WP}/wp-admin/admin.php?page=${screen.slug}`, { waitUntil: 'domcontentloaded' });
  // The Studio initialises CodeMirror from an inline script. Give the page a
  // beat so a style applied by JS is in place before anything is measured.
  await page.waitForTimeout(600);

  const d = await page.evaluate(probe);

  /* 1. The panel root is on the page at all. ----------------------- */
  r.check(B, `${label} · panel root ${ROOT} is present`, d.rootFound,
    d.rootFound ? '' : 'no .kh-ui element — the screen is not wrapped in the panel shell');

  // Without any root at all there is nothing on the page to measure, so the
  // remaining style checks would have no subject. Everything else still runs.
  if (!d.root) {
    r.fail(B, `${label} · there is a styleable container on the screen`, 'neither .kh-ui nor .wrap');
    await shoot(page, `admin-${screen.slug}-noroot${DARK ? '-dark' : ''}`);
    return;
  }

  /* 2. The token layer actually resolved. -------------------------- */
  // If admin.css did not load, or loaded but is scoped under a class this page
  // does not carry, every custom property comes back as the empty string. That
  // single check is the difference between "the stylesheet is in the repo" and
  // "the stylesheet reached the browser".
  const missing = Object.entries(d.tokens).filter(([, v]) => !v).map(([k]) => k);
  r.check(B, `${label} · the --kh-* tokens resolve`, missing.length === 0,
    missing.length ? `unresolved: ${missing.join(', ')}` : `--kh-bg=${d.tokens['--kh-bg']}`);

  /* 3. The panel background is PAINTED, not inherited. -------------- */
  const bg = parseColor(d.root.background);
  const bgOpaque = !!bg && bg[3] > 0;
  r.check(B, `${label} · panel background is applied`, bgOpaque,
    bgOpaque
      ? `${d.root.background} on ${d.measuredOn}`
      : `background-color is ${d.root.background} on ${d.measuredOn} — the panel is painting nothing and wp-admin's own grey shows through`);

  // And it is OUR background, not whatever wp-admin happens to be. Compared
  // against the resolved token rather than a hard-coded hex, so a later change
  // to the palette does not make this check a liar.
  if (bgOpaque && d.tokens['--kh-bg']) {
    const want = await page.evaluate((v) => {
      const s = document.createElement('span');
      s.style.color = v;
      document.body.appendChild(s);
      const out = getComputedStyle(s).color;
      s.remove();
      return out;
    }, d.tokens['--kh-bg']);
    const same = parseColor(want) && parseColor(d.root.background) &&
      parseColor(want).slice(0, 3).join() === parseColor(d.root.background).slice(0, 3).join();
    r.check(B, `${label} · panel background is --kh-bg`, !!same,
      same ? '' : `painted ${d.root.background}, token says ${want}`);
  }

  /* 4. Text colour resolves to the ink token. ---------------------- */
  const fg = parseColor(d.root.color);
  r.check(B, `${label} · panel text colour resolves`, !!fg && fg[3] > 0,
    d.root.color);

  const shell = contrast(d.root.color, d.root.background);
  r.check(B, `${label} · panel ink on panel background clears 4.5:1`,
    shell !== null && shell >= 4.5, `${ratio(shell)} (${d.root.color} on ${d.root.background})`);

  /* 5. Dark is keyed to the admin colour scheme, not the OS. -------- */
  r.check(B, `${label} · is-dark matches the admin colour scheme`,
    d.root.isDark === DARK,
    `root classes: ${d.root.classes}; body: ${(d.bodyClass.match(/admin-color-[\w-]+/) || ['?'])[0]}`);

  /* 6. The tab strip is REAL LINKS. -------------------------------- */
  // Decision 4, and hard rule 4. The old `kh-tabs` button strip is the
  // anti-pattern this replaces: a button strip cannot be middle-clicked,
  // cannot be bookmarked and does not work with JS off.
  const navOk = r.check(B, `${label} · tab strip ${NAV} is rendered`, !!d.nav,
    d.nav ? '' : `no ${NAV} on the page`);

  if (navOk) {
    r.check(B, `${label} · tab strip is on screen`, d.nav.onScreen,
      `links=${d.nav.links}`);
    r.check(B, `${label} · every tab is an a[href]`,
      d.nav.links >= SCREENS.length && d.nav.buttons === 0 && d.nav.anchorsWithoutHref === 0,
      `a[href]=${d.nav.links}, buttons/roles=${d.nav.buttons}, href-less anchors=${d.nav.anchorsWithoutHref}`);
    r.check(B, `${label} · exactly one aria-current="page"`,
      d.nav.currentCount === 1, `found ${d.nav.currentCount}`);
    if (d.nav.currentCount === 1) {
      r.check(B, `${label} · aria-current is on this screen's link`,
        d.nav.currentHref.includes(`page=${screen.slug}`) && d.nav.currentTag === 'a',
        `<${d.nav.currentTag} href="${d.nav.currentHref}">`);
    }
    const reachable = SCREENS.filter((s) =>
      d.nav.hrefs.some((h) => new RegExp(`page=${s.slug}(?:&|$)`).test(h)));
    r.check(B, `${label} · every Keelhob slug is linked from the strip`,
      reachable.length === SCREENS.length,
      `${reachable.length}/${SCREENS.length}: missing ${SCREENS.filter((s) => !reachable.includes(s)).map((s) => s.slug).join(', ') || 'none'}`);
  }

  /* 7. Nothing escapes the viewport sideways. ----------------------- */
  r.check(B, `${label} · no horizontal overflow`,
    !d.overflow.overflows && d.overflow.worst.length === 0,
    d.overflow.worst.length
      ? `widest: ${d.overflow.worst.map((w) => `${w.tag}.${w.cls || '(no class)'} +${w.over}px`).join('; ')}`
      : `document scrollWidth ${d.overflow.docScrollWidth} <= viewport ${d.viewport.w}`);

  /* 8. Real contrast on the muted and accent text. ------------------ */
  const muted = d.samples.filter((s) => s.token === '--kh-muted');
  const accent = d.samples.filter((s) => s.token === '--kh-accent-ink' || s.token === '--kh-accent-2-ink');

  for (const [tokenLabel, set] of [['muted text', muted], ['accent text', accent]]) {
    if (!set.length) {
      // Not a failure on a screen that legitimately uses neither. Recorded so
      // a silently-unstyled screen cannot look like a clean pass.
      r.ok(B, `${label} · ${tokenLabel} · none on this screen`, 'nothing to measure');
      continue;
    }
    let worstRatio = Infinity;
    let worstOn = '';
    for (const s of set) {
      const c = contrast(s.fg, s.bg);
      if (c === null) continue;
      if (c < worstRatio) {
        worstRatio = c;
        worstOn = `${s.token} ${s.fg} on ${s.bg} — "${s.text}"`;
      }
    }
    const measured = Number.isFinite(worstRatio);
    r.check(B, `${label} · ${tokenLabel} clears 4.5:1`,
      measured && worstRatio >= 4.5,
      measured ? `worst ${ratio(worstRatio)} — ${worstOn}` : 'no measurable pair');
  }

  if (r.failures.some((f) => f.label.startsWith(label))) {
    await shoot(page, `admin-${screen.slug}${DARK ? '-dark' : ''}-${WIDTH}`);
  }
}

/* ------------------------------------------------------------------ */
/* Run                                                                 */
/* ------------------------------------------------------------------ */

/**
 * The dark scheme to switch to, read from the plugin rather than guessed.
 *
 * Decision 6 keys dark to the WordPress admin colour scheme, so this check can
 * only exercise dark mode by switching the user to a scheme the plugin agrees
 * is dark. Asking the plugin, instead of hard-coding `midnight`, means a later
 * correction to `DARK_SCHEMES` does not silently turn the dark run into a
 * second light run that passes.
 *
 * The class is only loaded on admin requests, so under WP-CLI it may not exist
 * at all; the file is required explicitly and a miss falls back loudly rather
 * than killing the run.
 */
function darkScheme() {
  const out = wpEval(
    `$f = WP_PLUGIN_DIR . '/keelhob/includes/class-admin.php';
     if (!class_exists('Keelhob_Admin') && file_exists($f)) { require_once $f; }
     if (!class_exists('Keelhob_Admin') || !defined('Keelhob_Admin::DARK_SCHEMES')) { echo ''; return; }
     $l = Keelhob_Admin::DARK_SCHEMES;
     echo is_array($l) && $l ? (string) reset($l) : '';`,
    wpOpts
  ).trim();
  if (out) return out;
  // `midnight` has a dark menu background in every WordPress that ships it, so
  // it is the safe fallback — but say so, because a dark run against a scheme
  // the plugin does not class as dark proves nothing.
  console.error('Could not read Keelhob_Admin::DARK_SCHEMES — falling back to `midnight`.');
  return 'midnight';
}

function getScheme(user) {
  return wpEval(
    `$u = get_user_by('login', '${user}'); echo $u ? (string) get_user_meta($u->ID, 'admin_color', true) : '';`,
    wpOpts
  ).trim();
}

function setScheme(user, scheme) {
  wpEval(
    `$u = get_user_by('login', '${user}');
     if ($u) { update_user_meta($u->ID, 'admin_color', '${scheme}'); }
     echo 'ok';`,
    wpOpts
  );
}

clearFailures();

let browser = null;
let restoreScheme = null;

try {
  const ping = await fetch(`${WP}/wp-login.php`).then((x) => x.ok).catch(() => false);
  if (!ping) {
    console.error(`Cannot reach ${WP}. Start the rig first:`);
    console.error(`  cd ${RIG} && PHP_CLI_SERVER_WORKERS=4 php -S localhost:8899`);
    console.error('  (on Windows: start that command three times — see tests/rig/README.md)');
    process.exit(2);
  }

  if (DARK) {
    const want = darkScheme();
    const had = getScheme(USER) || 'fresh';
    if (had !== want) {
      setScheme(USER, want);
      restoreScheme = had;
    }
    console.log(`Admin colour scheme: ${want} (was ${had})`);
  }

  const launched = await launch({ chrome: opt.chrome, headed: opt.headed });
  browser = launched.browser;
  const page = await login(launched.context, { wp: WP, user: USER, pass: PASS });
  await page.setViewportSize({ width: WIDTH, height: HEIGHT });

  for (const screen of SCREENS) {
    process.stdout.write(`  ... ${screen.name}\n`);
    // One screen must never be able to take the whole run down with it.
    await checkScreen(page, screen).catch((e) =>
      r.fail(screen.slug, `${screen.name}${DARK ? ' (dark)' : ''} @${WIDTH}px · the checks themselves`, e.message));
  }
} catch (err) {
  r.fail('—', 'the run itself', err.message);
} finally {
  // Rig state is restored here and nowhere else. A failed assertion, a thrown
  // error and a clean pass all reach this block — a rig left on `midnight`
  // would make the next light-mode run fail for a reason that is not a bug.
  if (restoreScheme) {
    try {
      setScheme(USER, restoreScheme);
      console.log(`Admin colour scheme restored to ${restoreScheme}.`);
    } catch (e) {
      console.error(`COULD NOT RESTORE the admin colour scheme: ${e.message}`);
      r.fail('—', 'colour scheme teardown', e.message);
    }
  }

  // Chrome is killed here, on every path.
  //
  // close() alone is not enough: on Windows it can hang after a run that
  // navigated many admin pages, and an orphaned Chrome holding the rig's
  // session cookie has stalled this project's browser checks before. So:
  // close with a deadline, then kill the process outright if the deadline
  // passes.
  if (browser) {
    const proc = typeof browser.process === 'function' ? browser.process() : null;
    const closed = await Promise.race([
      browser.close().then(() => true),
      new Promise((resolve) => setTimeout(() => resolve(false), 5000)),
    ]).catch(() => false);
    if (!closed && proc && proc.pid) {
      try {
        process.kill(proc.pid, 'SIGKILL');
        console.error(`Chrome did not close; killed pid ${proc.pid}.`);
      } catch {
        /* Already gone. Nothing to do and nothing to report. */
      }
    }
  }
}

const green = r.print();
// process.exit rather than letting the loop drain: a browser that did not close
// leaves handles open and they would hold the process open forever.
process.exit(green ? 0 : 1);
