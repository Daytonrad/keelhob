/**
 * The Keelhob admin panel, asserted in a real browser.
 *
 *   node tests/rig/ui/check-admin.mjs --wp http://localhost:8899 \
 *        --rig /c/wp-rig/wordpress --user admin --pass rigpass
 *   node tests/rig/ui/check-admin.mjs --dark
 *   node tests/rig/ui/check-admin.mjs --width 782
 *   node tests/rig/ui/check-admin.mjs --rtl
 *   node tests/rig/ui/check-admin.mjs --dark --rtl --width 782
 *
 * The three flags are independent and combine, which is the whole matrix:
 * {light, dark} x {LTR, RTL} x {1440, 782}.
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
 * FIXTURES. This file used to have none, and that was a hole rather than a
 * virtue: the dark run reported 96/96 while three elements in the snippets list
 * were genuinely unreadable, because the list was EMPTY and the rows it was
 * measuring did not exist. So it now seeds a snippet, two tasks, a skill, a
 * memory row and an activity-log row, asserts that the seeded text is actually
 * on the screen, and destroys all of it in the `finally` block whether the run
 * passes, fails or throws — same rule as check-snippets.mjs, for the same
 * reason, and with a different name prefix so the two files cannot delete each
 * other's rows.
 *
 * RIG STATE. No shared key is written any more. `--dark` used to flip user 1's
 * `admin_color` meta, which raced with the other lane's runs; each mode now has
 * its own pinned administrator. See PROBE_USERS below.
 *
 * WHAT IS MEASURED. Not just `.kh-u-*`. Every text node in the panel, against
 * the first ancestor that actually paints a background — because WordPress
 * renders admin notices, `.nav-tab` strips, `.widefat` tables, `<pre>` blocks
 * and core buttons INSIDE this panel, none of them know about `.kh-ui`, and the
 * old selector-driven check could not see any of them. That blind spot hid a
 * 1.43:1 safety banner and nineteen 1.15:1 headings in dark mode.
 */

import { args, clearFailures, launch, login, Report, shoot, wpCli, wpEval, wpJson } from './harness.mjs';

const opt = args();
const WP = (opt.wp || 'http://localhost:8899').replace(/\/$/, '');
const RIG = opt.rig || '/c/wp-rig/wordpress';
const USER = opt.user || 'admin';
const PASS = opt.pass || 'rigpass';
const CLI = opt.cli || null;
const wpOpts = { path: RIG, cli: CLI };

const DARK = opt.dark === true || opt.dark === 'true';
const RTL = opt.rtl === true || opt.rtl === 'true';
const WIDTH = Number(opt.width) || 1440;
const HEIGHT = Number(opt.height) || 900;

/*
 * --rtl RUNS AGAINST A GENUINELY RIGHT-TO-LEFT INSTALL. IT DOES NOT FAKE ONE.
 *
 * The first RTL pass on this build forced `body.rtl` on in a real browser and
 * reported a 20px horizontal overflow. The overflow was an artefact of the
 * fake: adding the class mirrors the panel's own negative margin, because that
 * rule is written `body.rtl .kh-ui`, but it does NOT make WordPress enqueue
 * `wp-admin-rtl.css` or `colors-<scheme>-rtl.css`, and those are what move
 * `#wpcontent`'s padding and the admin menu to the other side. So the panel
 * mirrored inside a content column that had not. A cycle was spent on a layout
 * bug that does not exist.
 *
 * What makes an install really RTL is the LOCALE. `is_rtl()` answers from the
 * loaded translation's own `ltr`/`rtl` string, `wp_default_scripts` picks the
 * `-rtl` stylesheets off the same answer, and `locale_stylesheet` and the
 * `dir`/`lang` attributes on `<html>` follow. None of that can be reached from
 * the client side.
 *
 * So `--rtl` installs the `ar` language pack if the rig has not got it and
 * signs in as an administrator whose `locale` user meta is `ar`. The proof is
 * asserted on every screen, not assumed: `<html dir="rtl" lang="ar">`,
 * `body.rtl`, and at least one core `-rtl` stylesheet actually in the
 * document. If any of those is missing the run goes red rather than quietly
 * measuring a left-to-right page and calling it RTL.
 *
 * The pack is installed and left in place, for the same reason the probe users
 * are: creating it is idempotent, removing it is not, and a second agent's run
 * must not have its translation deleted underneath it.
 */
const RTL_LOCALE = 'ar';

/** How the cell names itself in every check label and capture filename, so a
 *  failures/ directory holding four runs can be told apart. */
const MODE_SUFFIX = `${DARK ? ' (dark)' : ''}${RTL ? ' (rtl)' : ''}`;
const SHOT_SUFFIX = `${DARK ? '-dark' : ''}${RTL ? '-rtl' : ''}`;

/*
 * DEDICATED RIG ADMINISTRATORS, ONE PER MODE.
 *
 * `--dark` used to flip one shared piece of rig state — user 1's `admin_color`
 * user meta — and restore it in a `finally`. With two agents running browser
 * checks against the same rig, one run's restore lands mid-flight in the
 * other's, and the browser then sees `admin-color-fresh` while the run expects
 * midnight. That produced three spurious failures on this build, and nothing
 * about them looked like a race: the run printed "Admin colour scheme:
 * midnight" and reported is-dark=false, which reads exactly like a redesign
 * bug.
 *
 * So each mode gets its OWN administrator with its scheme PINNED at setup. The
 * light run and the dark run write disjoint keys and cannot see each other.
 * User 1 is never touched by this file again, which also means the other lane's
 * runs cannot break ours.
 *
 * The users are created if missing and then LEFT IN PLACE. A teardown that
 * deleted them would reintroduce the very race being fixed — run A's delete
 * invalidating run B's session mid-flight. Creating them is idempotent;
 * deleting them is not. They are local-rig fixtures and they carry the same
 * password as the rig's own admin.
 *
 * THE TRAP, which has already cost this build a green run:
 * `update_user_option()` writes the BLOG-PREFIXED meta (`wp_admin_color`) and
 * `get_user_option()` reads the prefixed key FIRST, so a stray prefixed value
 * silently outranks the unprefixed `admin_color` that `update_user_meta()`
 * sets — and the panel renders light while the run swears it set midnight.
 * Both keys are written here, to the same value, and the resolved value is read
 * back and asserted before Chrome is launched.
 */
const PROBE_PASS = 'rigpass';
/** One pinned administrator per CELL of the matrix, not per mode: the RTL pair
 *  carries a `locale` of `ar` and the LTR pair carries none, and a run that
 *  flipped one account's locale back and forth would reintroduce exactly the
 *  shared-state race the per-mode users were created to kill. */
const PROBE_USERS = {
  light: 'kh_rig_light',
  dark: 'kh_rig_dark',
  'rtl-light': 'kh_rig_rtl_light',
  'rtl-dark': 'kh_rig_rtl_dark',
};
const probeUserKey = () => `${RTL ? 'rtl-' : ''}${DARK ? 'dark' : 'light'}`;
/** `fresh` is excluded from DARK_SCHEMES on purpose, so it is the honest
 *  "this install expressed no preference" scheme for the light run. */
const LIGHT_SCHEME = 'fresh';
/** An explicit `--user` is an escape hatch: run as that account, touch no
 *  scheme at all, and let the is-dark check report whatever it finds. */
const USER_GIVEN = Object.prototype.hasOwnProperty.call(opt, 'user');

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
 *
 * Donate is the SEVENTH, and its absence is the reason it stayed unmigrated for
 * the whole redesign: it renders a `<div class="wrap">` of its own, it is in
 * neither lane's ownership table, and because it was not in this list every
 * "all screens pass" result in this build was true of six screens. A screen
 * nobody measures is a screen nobody fixes, so it is in the list now and the
 * list is the thing that cannot silently shrink.
 *
 * `inStrip: false` says this screen is reachable but is NOT one of the tabs in
 * Keelhob_Admin::tabs(). It carries the strip as its way back into the panel
 * and nothing in that strip is marked current, which is asserted positively
 * below rather than skipped — "no tab claims to be this page" is a fact worth
 * checking, and it is what keeps the ask from being promoted to a seventh tab
 * by accident.
 */
const SCREENS = [
  { name: 'Connect', slug: 'keelhob' },
  { name: 'Skills', slug: 'keelhob-skills' },
  { name: 'Automation', slug: 'keelhob-automation' },
  { name: 'Guide', slug: 'keelhob-guide' },
  { name: 'Activity', slug: 'keelhob-activity' },
  { name: 'Snippets Studio', slug: 'keelhob-studio' },
  { name: 'Donate', slug: 'keelhob-donate', inStrip: false },
];

/** The screens that ARE tabs. Every one of them must be linked from the strip
 *  on every screen, including the ones that are not tabs themselves. */
const STRIP_SCREENS = SCREENS.filter((s) => s.inStrip !== false);

/** The root the whole panel is scoped under. See BUILD-BRIEF §3. */
const ROOT = '.kh-ui';

/** The tab strip. Deliberately NOT `.kh-tabs` — that name is taken by the
 *  Studio's app picker and means something else. See BUILD-BRIEF §3. */
const NAV = '.kh-u-nav';

/**
 * The minimum number of rendered table rows each screen must have before its
 * contrast numbers mean anything, and a string from the fixtures that proves
 * the rows on screen are the seeded ones.
 *
 * WHY: the dark run went 96/96 while `.kh-switch-label` measured 1.41:1,
 * `.kh-snippet-id` 1.92:1 and the On label 2.11:1 — because the snippets list
 * was EMPTY, so none of those elements existed and there was nothing to be
 * wrong with. A green check against no content is the most expensive kind of
 * pass there is. The markers make an empty list a FAILURE rather than a clean
 * run: a contrast assertion that cannot find its subject must go red.
 */
const SEEDED_ROWS = {
  /* Connect renders no table at all — measured, not assumed: the run reports
     "0 table(s)" there, and the `widefat` blocks that look like Connect's in
     class-admin-screens.php belong to render_guide(). */
  'keelhob-skills': 2,
  'keelhob-automation': 3,
  'keelhob-activity': 3,
  'keelhob-studio': 2,
};

const SEEDED_MARKER = {
  'keelhob-skills': 'zz-adm-probe-skill',
  'keelhob-automation': 'ZZ ADM digest',
  'keelhob-activity': 'zz_adm_probe_tool',
  'keelhob-studio': 'ZZ ADM alpha',
};

/** Fixture name prefix. Deliberately NOT check-snippets.mjs's `ZZ UI`: both
 *  files seed the snippets table, and a shared prefix means one file's
 *  teardown deletes the other's fixtures mid-run. */
const PREFIX = 'ZZ ADM';

/**
 * EMPTY states, measured in the same run as the full ones.
 *
 * Seeding fixed one hole and opened its mirror image: a screen only ever
 * measured WITH rows never renders its empty-state card, and that card is
 * core's `.card`, which paints #fff. "No snippets yet." measured 1.52:1 in
 * dark and no run would have seen it. A filter that matches nothing renders
 * the empty state while the fixtures are still in place, so one run covers
 * both.
 */
const EMPTY_VIEWS = [
  {
    name: 'Snippets Studio · empty filter',
    slug: 'keelhob-studio',
    query: '&s=zzzz-nothing-matches-this',
    expect: 'No snippets match that filter.',
  },
];

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
  /*
   * The evidence that this page is really right-to-left.
   *
   * Every one of these comes from the SERVER's answer, not from anything the
   * check could have set: `dir` and `lang` are printed by `language_attributes()`
   * from the loaded locale, `body.rtl` by `admin_body_class()`, and the `-rtl`
   * stylesheets are chosen by `wp_default_scripts()`/`wp_style_add_data()` off
   * `is_rtl()`. Forcing `body.rtl` from the client reproduces the class and
   * none of the rest, which is the false result this collects to prevent.
   */
  const rtlProof = {
    htmlDir: document.documentElement.getAttribute('dir'),
    htmlLang: document.documentElement.getAttribute('lang'),
    computedDir: getComputedStyle(document.documentElement).direction,
    bodyRtl: document.body.classList.contains('rtl'),
    /*
     * Core delivers its RTL stylesheets one of two ways and both count:
     * separate files (`colors-midnight-rtl.css`, `common-rtl.css`) when
     * concatenation is off, or one `load-styles.php?...&dir=rtl` when it is
     * on, which is the rig's case. The `dir=rtl` query parameter is the part
     * no client-side class can fabricate, so it is kept in the evidence
     * string rather than stripped with the rest of the query.
     */
    sheets: Array.from(document.querySelectorAll('link[rel="stylesheet"]'))
      .map((l) => l.href)
      .filter((h) => /-rtl(\.min)?\.css|[?&]dir=rtl/.test(h))
      .map((h) => {
        const file = h.split('/').pop();
        const dir = (file.match(/[?&](dir=rtl)/) || [])[1];
        return dir ? `${file.split('?')[0]}?…&${dir}` : file.split('?')[0];
      }),
    /*
     * The colour-scheme stylesheet, IF it is served as its own file.
     *
     * It usually is not. `fresh` is bundled into `load-styles.php` as the
     * `colors` handle and emits no `colors-*.css` link at all, so requiring
     * one made every light RTL screen go red while the page was perfectly
     * right-to-left — six false failures from an assertion that was true of
     * `midnight` and of nothing else. What is worth asserting is the
     * conditional: when core DOES emit a separate scheme sheet, under RTL it
     * must be the `-rtl` build of it (`colors-midnight-rtl.min.css`), because
     * that file is what moves the admin menu to the other side.
     */
    colorHref: (Array.from(document.querySelectorAll('link[rel="stylesheet"]'))
      .map((l) => l.href)
      .find((h) => /\/colors[-/]/.test(h)) || '')
      .split('/').pop().split('?')[0],
  };
  rtlProof.colorSheetRtl = rtlProof.colorHref === '' || /-rtl(\.min)?\.css$/.test(rtlProof.colorHref);

  const out = {
    url: location.href,
    viewport: { w: window.innerWidth, h: window.innerHeight },
    bodyClass: document.body.className,
    rtlProof,
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
  //
  // BOTH EDGES, because "overflow" has no fixed side.
  //
  // This swept `b.right - innerWidth` only, which is the inline-END edge in a
  // left-to-right document and the inline-START edge in a right-to-left one.
  // Under `--rtl` the whole panel is mirrored: the admin menu moves to the
  // right, the content column starts at x=0, and anything that spills does so
  // past the LEFT edge at a negative `b.left`. A right-edge-only sweep cannot
  // see it and would have reported every RTL screen clean whatever it did.
  //
  // Measured on the rig to be sure the mirror is exact rather than assumed: at
  // 1440 the panel's box is {left 160, right 1440} in LTR and {left 0, right
  // 1280} in RTL, with the negative margin on `margin-left` and `margin-right`
  // respectively. So neither edge needs an exemption for the deliberate
  // overhang — in both directions the pulled edge lands exactly ON the
  // viewport boundary, not past it.
  const docW = document.documentElement.scrollWidth;
  const worst = [];
  for (const el of root ? [root, ...root.querySelectorAll('*')] : []) {
    const b = el.getBoundingClientRect();
    if (b.width === 0 || b.height === 0) continue;
    const cs = getComputedStyle(el);
    if (cs.display === 'none' || cs.visibility === 'hidden') continue;
    if (cs.position === 'fixed') continue;
    // PARKED, not overflowing. Core hides `.row-actions` at `left:-129987px`
    // and `.screen-reader-text` behind a 50% inset clip; both have a real box
    // and neither is on screen for anyone. A box wholly outside the viewport
    // is somebody's hiding technique, and counting it as overflow is how a
    // two-edge sweep reports 129,987px on a page that scrolls perfectly.
    if (b.right <= 0 || b.left >= window.innerWidth) continue;
    if (/inset\(50%\)/.test(cs.clipPath) || cs.clip === 'rect(1px, 1px, 1px, 1px)') continue;
    const pastEnd = Math.round(b.right - window.innerWidth);
    const pastStart = Math.round(-b.left);
    const over = Math.max(pastEnd, pastStart);
    if (over > 2) {
      worst.push({
        over,
        side: pastEnd >= pastStart ? 'right' : 'left',
        tag: el.tagName.toLowerCase(),
        cls: (typeof el.className === 'string' ? el.className : '').slice(0, 60),
      });
    }
  }
  worst.sort((a, b) => b.over - a.over);
  out.overflow = {
    docScrollWidth: docW,
    docClientWidth: document.documentElement.clientWidth,
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

  /* --- EVERY word in the panel, measured where it actually lands ---- */
  //
  // The token sampler above only ever sees text painted in a `--kh-*` value it
  // was told to look for, which means it only sees the components this redesign
  // WROTE. WordPress renders plenty inside the panel that nobody here wrote:
  // admin notices (core JavaScript MOVES them into the first `.wrap`, and
  // root_class() keeps `wrap` on purpose), `.nav-tab` strips, `.widefat`
  // tables, `<pre>` blocks, core buttons. Every one of those supplies its own
  // background and ignores `.kh-ui` entirely, so the panel's inherited ink
  // lands on a core-white or core-cream surface and nothing in the token layer
  // knows about it.
  //
  // That is how a dark run reported 96/96 while the "AI abilities are ON"
  // safety banner measured 1.43:1 and nineteen Guide headings measured 1.15:1.
  //
  // So: walk the text nodes, resolve the first ancestor that actually paints a
  // background, alpha-blend, and judge the pair. No selector list to keep in
  // step with the markup, and a new core component cannot arrive unmeasured.
  out.text = { measured: 0, records: [] };
  if (root) {
    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
    const seen = new Set();
    let node;
    while ((node = walker.nextNode())) {
      const text = (node.textContent || '').trim();
      if (!text) continue;
      const el = node.parentElement;
      if (!el) continue;
      // Screen-reader-only text is positioned off-canvas and clipped to 1px by
      // design; it is never read with the eyes and has no surface to sit on.
      if (el.closest('.kh-u-sr, .screen-reader-text, [aria-hidden="true"]')) continue;
      const cs = getComputedStyle(el);
      if (cs.display === 'none' || cs.visibility === 'hidden') continue;
      if (parseFloat(cs.opacity) === 0) continue;
      const b = el.getBoundingClientRect();
      // A 0x0 box is the honest signal for "inside a closed <details>, inside a
      // display:none ancestor, or an <option> in a collapsed <select>".
      if (b.width < 3 || b.height < 3) continue;
      // Core parks `.row-actions` off-canvas: `left:-129987px` in a
      // left-to-right admin, and `left:+130694px` in a right-to-left one,
      // because the offset is written as a margin that flips with the
      // direction. Present, sized, visible by every naive test, and on screen
      // for nobody in either direction.
      //
      // `b.left < -100` alone was the LTR half of that, and under --rtl it let
      // two `.row-actions` separators into the sample at 782px — core leaves
      // them `color: rgba(0,0,0,0)` until hover, so they measured 1.00:1 and
      // reported the Studio as having unreadable text 130,000px to the right
      // of the viewport. Both edges, and the test is now what it always meant:
      // is any part of this box inside the window?
      if (b.right < 0 || b.left > window.innerWidth) continue;
      // Deduplicated by the PAIR, not by the element: two hundred table cells
      // painted the same ink on the same surface are one contrast question.
      const bg = effectiveBg(el);
      const key = `${cs.color}|${bg}|${Math.round(parseFloat(cs.fontSize))}|${cs.fontWeight}`;
      if (seen.has(key)) continue;
      seen.add(key);
      out.text.measured++;
      out.text.records.push({
        fg: cs.color,
        bg,
        fontSize: parseFloat(cs.fontSize),
        fontWeight: cs.fontWeight,
        tag: el.tagName.toLowerCase(),
        cls: (typeof el.className === 'string' ? el.className : '').slice(0, 48),
        inTable: !!el.closest('table'),
        inNotice: !!el.closest('.notice, .updated, .error, #message'),
        text: text.replace(/\s+/g, ' ').slice(0, 44),
      });
      if (out.text.records.length >= 400) break;
    }
  }

  /* --- tables: did any rows actually render? ----------------------- */
  // A contrast check against an EMPTY table asserts nothing and reports green,
  // which is how the dark list-table bug survived the first dark pass.
  out.tables = [];
  if (root) {
    for (const t of root.querySelectorAll('table')) {
      const b = t.getBoundingClientRect();
      if (b.width < 3 || b.height < 3) continue;
      const rows = [...t.querySelectorAll('tbody tr')].filter((tr) => {
        const rb = tr.getBoundingClientRect();
        return rb.width > 2 && rb.height > 2;
      });
      out.tables.push({
        cls: (typeof t.className === 'string' ? t.className : '').slice(0, 48),
        rows: rows.length,
      });
    }
  }

  /* --- color-scheme, for native selects and scrollbars -------------- */
  // A `<select>` and a scrollbar are painted by the OS, not by CSS colours.
  // Without `color-scheme` they stay light inside a dark panel, which is the
  // most visible single defect this redesign could ship.
  if (root) {
    const rcs = getComputedStyle(root);
    const selects = [...root.querySelectorAll('select')];
    const outside = document.querySelector('#adminmenu') || document.body;
    out.colorScheme = {
      root: rcs.colorScheme,
      html: getComputedStyle(document.documentElement).colorScheme,
      body: getComputedStyle(document.body).colorScheme,
      outside: getComputedStyle(outside).colorScheme,
      outsideSel: document.querySelector('#adminmenu') ? '#adminmenu' : 'body',
      selects: selects.length,
      first: selects.length ? getComputedStyle(selects[0]).colorScheme : '',
      wrong: selects.filter((s) => getComputedStyle(s).colorScheme !== rcs.colorScheme).length,
    };
  }

  return out;
}

/* ------------------------------------------------------------------ */
/* The checks                                                          */
/* ------------------------------------------------------------------ */

async function checkScreen(page, screen) {
  const label = `${screen.name}${MODE_SUFFIX} @${WIDTH}px`;
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
    await shoot(page, `admin-${screen.slug}-noroot${SHOT_SUFFIX}`);
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
      d.nav.links >= STRIP_SCREENS.length && d.nav.buttons === 0 && d.nav.anchorsWithoutHref === 0,
      `a[href]=${d.nav.links}, buttons/roles=${d.nav.buttons}, href-less anchors=${d.nav.anchorsWithoutHref}`);
    // A screen that is one of the tabs must have exactly one current tab, and
    // it must be its own. A screen that is NOT one of the tabs — Donate — must
    // have NONE: it carries the strip to get back out of the ask, and marking
    // some other tab current there would tell a screen reader the owner is on a
    // page they are not. Both are assertions; neither screen is skipped.
    if (screen.inStrip === false) {
      r.check(B, `${label} · not a tab, so no aria-current="page" in the strip`,
        d.nav.currentCount === 0,
        d.nav.currentCount === 0
          ? `${d.nav.links} tabs, none current`
          : `found ${d.nav.currentCount}: <${d.nav.currentTag} href="${d.nav.currentHref}">`);
    } else {
      r.check(B, `${label} · exactly one aria-current="page"`,
        d.nav.currentCount === 1, `found ${d.nav.currentCount}`);
      if (d.nav.currentCount === 1) {
        r.check(B, `${label} · aria-current is on this screen's link`,
          d.nav.currentHref.includes(`page=${screen.slug}`) && d.nav.currentTag === 'a',
          `<${d.nav.currentTag} href="${d.nav.currentHref}">`);
      }
    }
    const reachable = STRIP_SCREENS.filter((s) =>
      d.nav.hrefs.some((h) => new RegExp(`page=${s.slug}(?:&|$)`).test(h)));
    r.check(B, `${label} · every Keelhob slug is linked from the strip`,
      reachable.length === STRIP_SCREENS.length,
      `${reachable.length}/${STRIP_SCREENS.length}: missing ${STRIP_SCREENS.filter((s) => !reachable.includes(s)).map((s) => s.slug).join(', ') || 'none'}`);
  }

  /* 6b. Under --rtl, this had better really BE right-to-left. -------- */
  //
  // Asserted on EVERY screen rather than once per run, because the direction
  // is decided per request: `is_rtl()` answers from the loaded translation and
  // a screen that failed to load one would silently render left-to-right while
  // the rest of the run looked fine. All four facts come from the server, and
  // the one a client-side fake can reproduce — `body.rtl` — is deliberately
  // not trusted on its own.
  if (RTL) {
    const p = d.rtlProof;
    r.check(B, `${label} · the served page is genuinely RTL`,
      p.htmlDir === 'rtl' && p.htmlLang === RTL_LOCALE && p.computedDir === 'rtl' && p.bodyRtl,
      `<html dir="${p.htmlDir}" lang="${p.htmlLang}">, computed direction ${p.computedDir}, body.rtl ${p.bodyRtl}`);
    // The stylesheets are the half that forcing a class CANNOT fake, and they
    // are the half that moves #wpcontent's padding and the admin menu to the
    // other side. The first RTL pass on this build forced the class, got none
    // of these, and reported a 20px overflow that does not exist.
    r.check(B, `${label} · core's own -rtl stylesheets are loading`,
      p.sheets.length > 0 && p.colorSheetRtl,
      p.sheets.length
        ? `${p.sheets.join(', ')} · scheme sheet: ${p.colorHref || 'bundled into load-styles.php'}`
        : 'none found — this is a faked RTL, not a real one');
  } else {
    r.check(B, `${label} · the served page is left-to-right`,
      d.rtlProof.htmlDir !== 'rtl' && !d.rtlProof.bodyRtl && d.rtlProof.sheets.length === 0,
      `dir="${d.rtlProof.htmlDir}", body.rtl ${d.rtlProof.bodyRtl}, -rtl sheets ${d.rtlProof.sheets.length}`);
  }

  /* 7. Nothing escapes the viewport sideways. ----------------------- */
  r.check(B, `${label} · no horizontal overflow`,
    !d.overflow.overflows && d.overflow.worst.length === 0,
    d.overflow.worst.length
      ? `widest: ${d.overflow.worst.map((w) => `${w.tag}.${w.cls || '(no class)'} +${w.over}px past the ${w.side}`).join('; ')}`
      : `document scrollWidth ${d.overflow.docScrollWidth} <= viewport ${d.viewport.w}; ` +
        `nothing past either edge (${d.rtlProof.computedDir})`);

  /* 8. Real contrast on the muted and accent text. ------------------ */
  const muted = d.samples.filter((s) => s.token === '--kh-muted');
  const accent = d.samples.filter((s) => s.token === '--kh-accent-ink' || s.token === '--kh-accent-2-ink');
  // The ink tokens were COLLECTED and never asserted, which is most of why the
  // dark run went green: --kh-ink is near-white in dark mode, and the pairs
  // that fail are exactly the ones where it lands on a core-white surface.
  const ink = d.samples.filter((s) => s.token === '--kh-ink' || s.token === '--kh-ink-2');

  for (const [tokenLabel, set] of [['muted text', muted], ['accent text', accent], ['ink text', ink]]) {
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

  /* 9. EVERY word in the panel clears AA on the surface it lands on. -- */
  //
  // The check that would have caught the dark-mode safety banner, the Guide's
  // nineteen 1.15:1 headings, the 1.08:1 <pre> and the 1.11:1 nav-tab strip.
  // Nothing is excluded for being "core's markup": if WordPress renders it
  // inside the panel, the panel is responsible for it being readable.
  {
    const large = (c) => c.fontSize >= 24 || (c.fontSize >= 18.66 && Number(c.fontWeight) >= 700);
    const bad = [];
    let n = 0;
    for (const c of d.text.records) {
      const cr = contrast(c.fg, c.bg);
      if (cr === null) continue;
      n++;
      const need = large(c) ? 3 : 4.5;
      if (cr < need) bad.push({ cr, need, c });
    }
    bad.sort((a, b) => a.cr - b.cr);
    const show = (x) =>
      `${ratio(x.cr)} (needs ${x.need}:1) <${x.c.tag}${x.c.cls ? ` class="${x.c.cls}"` : ''}> ` +
      `${x.c.fg} on ${x.c.bg}${x.c.inNotice ? ' [NOTICE]' : x.c.inTable ? ' [table]' : ''} — "${x.c.text}"`;
    r.check(B, `${label} · every word in the panel clears AA where it lands`,
      n > 0 && bad.length === 0,
      bad.length
        ? `${bad.length} of ${n} distinct ink/surface pairs fail — ${bad.slice(0, 6).map(show).join(' | ')}`
        : `${n} distinct ink/surface pairs measured, worst ${ratio(Math.min(...d.text.records.map((c) => contrast(c.fg, c.bg)).filter((x) => x !== null)))}`);

    // The safety banner is called out on its own line. Hard rule 6 keeps it on
    // the screen; a banner that is present and illegible keeps the letter of
    // that rule and defeats the point of it.
    const notices = d.text.records.filter((c) => c.inNotice);
    if (notices.length) {
      const worstNotice = notices
        .map((c) => ({ cr: contrast(c.fg, c.bg), c }))
        .filter((x) => x.cr !== null)
        .sort((a, b) => a.cr - b.cr)[0];
      r.check(B, `${label} · admin notices inside the panel clear AA`,
        !!worstNotice && worstNotice.cr >= 4.5,
        worstNotice ? show({ ...worstNotice, need: 4.5 }) : 'no measurable notice text');
    }
  }

  /* 10. The seeded content is ON the screen, and the tables have ROWS. --- */
  const marker = SEEDED_MARKER[screen.slug];
  if (marker) {
    const seen = await page.evaluate((m) => {
      const rt = document.querySelector('.kh-ui') || document.querySelector('.wrap');
      return rt ? (rt.innerText || '').includes(m) : false;
    }, marker);
    r.check(B, `${label} · the seeded fixture is rendered on this screen`, seen,
      seen ? `found "${marker}"` : `"${marker}" is not in the panel — the table this screen's contrast is measured on is EMPTY`);
  }

  // Contrast against an empty table is contrast against nothing. These numbers
  // come from the fixtures seeded at the top of the run.
  const wantRows = SEEDED_ROWS[screen.slug] || 0;
  if (wantRows) {
    const rows = d.tables.reduce((acc, t) => acc + t.rows, 0);
    r.check(B, `${label} · seeded rows rendered (>= ${wantRows} table rows)`,
      rows >= wantRows,
      `${d.tables.length} table(s), ${rows} visible tbody row(s): ${d.tables.map((t) => `${t.cls || '(no class)'}=${t.rows}`).join(', ') || 'none'}`);
  }

  /* 11. color-scheme, so native controls follow the panel. ---------- */
  if (d.colorScheme) {
    const want = DARK ? 'dark' : 'light';
    r.check(B, `${label} · panel root declares color-scheme: ${want}`,
      d.colorScheme.root === want,
      `root color-scheme: ${d.colorScheme.root || '(empty)'}`);

    if (d.colorScheme.selects) {
      r.check(B, `${label} · every native <select> inherits the panel's colour scheme`,
        d.colorScheme.wrong === 0,
        `${d.colorScheme.selects} select(s), ${d.colorScheme.wrong} not "${d.colorScheme.root}"; first: ${d.colorScheme.first || '(empty)'}`);
    } else {
      r.ok(B, `${label} · native <select> · none on this screen`, 'nothing to measure');
    }

    // And it must not escape the panel: the admin menu and the document root
    // belong to WordPress, and a stray `color-scheme: dark` on <html> would
    // repaint every scrollbar and form control on every screen of the site.
    if (DARK) {
      r.check(B, `${label} · color-scheme does not leak outside .kh-ui`,
        d.colorScheme.outside !== 'dark' && d.colorScheme.html !== 'dark' && d.colorScheme.body !== 'dark',
        `${d.colorScheme.outsideSel}=${d.colorScheme.outside || 'normal'}, html=${d.colorScheme.html || 'normal'}, body=${d.colorScheme.body || 'normal'}`);
    }
  }

  if (r.failures.some((f) => f.label.startsWith(label))) {
    await shoot(page, `admin-${screen.slug}${SHOT_SUFFIX}-${WIDTH}`);
  }
}

/**
 * An empty-state view: the panel root, and every word in it.
 *
 * No nav, slug or row assertions — this is the same screen as the main pass,
 * reached with a filter that matches nothing, so repeating those would be
 * repeating the main pass. What is different about it is the surface the words
 * land on, and that is what gets measured.
 */
/**
 * The capability switch knob must sit in the same place in both states.
 *
 * WHY THIS IS A CHECK, AND WHY IT IS GEOMETRY RATHER THAN CSS.
 *
 * Core's forms.css sets `input[type=checkbox]:checked::before { content:
 * "147"; margin: -3px 0 0 -4px; }` so its dashicon tick overhangs the box.
 * Our rule overrode `content`, `top`, `left`, `width` and `height` but not
 * `margin`, so core's margin survived on the CHECKED knob alone: measured in
 * the rig, ON carried `margin: -3px 0px 0px -4px` while OFF carried
 * `margin: 0px`. The knob was lifted 1px clear of the top of its own track and
 * pulled 4px back from its travel. OFF looked centred, ON looked stuck to the
 * top, and the owner found it in a screenshot.
 *
 * Nothing in our stylesheet said so, which is the point: the declaration that
 * broke it is not in our file. Only the resolved box shows it, so this asserts
 * the box. It compares ON against OFF rather than against fixed numbers, so
 * resizing the switch cannot make the check lie.
 */
async function checkSwitchGeometry(page) {
  await page.goto(`${WP}/wp-admin/admin.php?page=keelhob`, { waitUntil: 'load' });
  await page.waitForTimeout(300);

  const m = await page.evaluate(() => {
    const sw = [...document.querySelectorAll('.kh-ui input.kh-u-switch')];
    const on = sw.find((e) => e.checked), off = sw.find((e) => !e.checked);
    if (!on || !off) return { total: sw.length };
    const read = (el) => {
      const b = getComputedStyle(el, '::before');
      /* clientWidth/clientHeight, NOT getBoundingClientRect(). The knob is
       * absolutely positioned, so its containing block is the switch's PADDING
       * box — inside the 1px border. The bounding rect is the border box, and
       * using it made this check report 2px against 4px on a correctly centred
       * knob: a false failure on right code, which is worse than no check. */
      const r = { width: el.clientWidth, height: el.clientHeight };
      const px = (v) => parseFloat(v) || 0;
      /* The travel is applied with a transform, so it is read off the matrix
       * rather than guessed from the rule. */
      const tx = new DOMMatrixReadOnly(b.transform === 'none' ? '' : b.transform).m41;
      return {
        trackH: r.height, trackW: r.width,
        knobH: px(b.height), knobW: px(b.width),
        top: px(b.top) + px(b.marginTop),
        left: px(b.left) + px(b.marginLeft) + tx,
      };
    };
    return { total: sw.length, on: read(on), off: read(off) };
  });

  const label = `Capabilities${MODE_SUFFIX} @${WIDTH}px · the switch knob`;
  if (!m.on || !m.off) {
    r.fail('keelhob', label, `needed one ON and one OFF switch; found ${m.total} switches`);
    return;
  }

  /* Vertically centred, to the half pixel, in BOTH states. */
  for (const [state, g] of [['ON', m.on], ['OFF', m.off]]) {
    const gapTop = g.top;
    const gapBottom = g.trackH - g.knobH - g.top;
    r.check('keelhob', `${label} is vertically centred when ${state}`,
      Math.abs(gapTop - gapBottom) <= 0.5,
      `top ${gapTop}px vs bottom ${gapBottom}px of a ${g.trackH}px track`);
  }

  /* And the ON knob's gap at its own end matches the OFF knob's gap at hers,
   * which is what "the same place, mirrored" actually means. */
  const offGap = m.off.left;
  const onGap  = m.on.trackW - m.on.knobW - m.on.left;
  r.check('keelhob', `${label} travels to a matching inset`,
    Math.abs(offGap - onGap) <= 0.5,
    `OFF sits ${offGap}px from its end, ON sits ${onGap}px from hers`);
}


async function checkEmptyView(page, view) {
  const label = `${view.name}${MODE_SUFFIX} @${WIDTH}px`;
  const B = view.slug;

  await page.goto(`${WP}/wp-admin/admin.php?page=${view.slug}${view.query}`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(400);

  const d = await page.evaluate(probe);

  r.check(B, `${label} · panel root ${ROOT} is present`, d.rootFound,
    d.rootFound ? '' : 'no .kh-ui element');

  if (view.expect) {
    const seen = await page.evaluate((m) => {
      const rt = document.querySelector('.kh-ui') || document.querySelector('.wrap');
      return rt ? (rt.innerText || '').includes(m) : false;
    }, view.expect);
    r.check(B, `${label} · the empty state is actually rendered`, seen,
      seen ? `found "${view.expect}"` : `"${view.expect}" is not on the screen — this view is measuring something else`);
  }

  if (!d.root) return;

  const large = (c) => c.fontSize >= 24 || (c.fontSize >= 18.66 && Number(c.fontWeight) >= 700);
  const bad = [];
  let n = 0;
  for (const c of d.text.records) {
    const cr = contrast(c.fg, c.bg);
    if (cr === null) continue;
    n++;
    const need = large(c) ? 3 : 4.5;
    if (cr < need) bad.push({ cr, need, c });
  }
  bad.sort((a, b) => a.cr - b.cr);
  r.check(B, `${label} · every word in the empty state clears AA where it lands`,
    n > 0 && bad.length === 0,
    bad.length
      ? `${bad.length} of ${n} pairs fail — ${bad.slice(0, 4).map((x) =>
          `${ratio(x.cr)} (needs ${x.need}:1) <${x.c.tag}${x.c.cls ? ` class="${x.c.cls}"` : ''}> ${x.c.fg} on ${x.c.bg} — "${x.c.text}"`).join(' | ')}`
      : `${n} distinct ink/surface pairs measured`);

  if (r.failures.some((f) => f.label.startsWith(label))) {
    await shoot(page, `admin-${view.slug}-empty${SHOT_SUFFIX}-${WIDTH}`);
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

/**
 * Create this mode's probe administrator if it is missing, pin its colour
 * scheme, and report what WordPress actually resolves for it.
 *
 * Idempotent on every path: an existing user has its role re-asserted and its
 * scheme rewritten, so a later correction to `DARK_SCHEMES` reaches the fixture
 * without anybody having to remember to reset it.
 */
/**
 * Make sure the rig can actually serve the RTL locale, and say so either way.
 *
 * Installing a translation is the one fixture that cannot be done from inside
 * `wpEval`: `wp_download_language_pack()` goes straight to HTTP, while
 * `wp language core install` resolves the same request through WP-CLI's own
 * on-disk cache and therefore works on a machine with no network once the pack
 * has been fetched once. See `wpCli` in harness.mjs.
 *
 * Returns what it found rather than throwing, so a rig that genuinely cannot
 * get the pack produces one explicit red check — "--rtl cannot run" — instead
 * of a stack trace, or worse, a silent left-to-right run wearing an RTL label.
 */
function ensureLanguage(locale) {
  const list = wpCli(['language', 'core', 'list', '--status=installed', '--field=language'], wpOpts);
  const installed = list.stdout.split(/\r?\n/).map((l) => l.trim()).filter(Boolean);
  if (installed.includes(locale)) return { locale, present: true, installed: false };

  const add = wpCli(['language', 'core', 'install', locale], wpOpts);
  const after = wpCli(['language', 'core', 'list', '--status=installed', '--field=language'], wpOpts);
  const now = after.stdout.split(/\r?\n/).map((l) => l.trim()).filter(Boolean);
  return {
    locale,
    present: now.includes(locale),
    installed: now.includes(locale),
    error: now.includes(locale) ? '' : (add.stderr || add.stdout || `exit ${add.status}`),
  };
}

function ensureProbeUser(loginName, scheme, locale = '') {
  return wpJson(
    `
    $login  = '${loginName}';
    $scheme = '${scheme}';
    $locale = '${locale}';
    $u = get_user_by('login', $login);
    $created = false;
    if (!$u) {
      $id = wp_insert_user([
        'user_login'   => $login,
        'user_pass'    => '${PROBE_PASS}',
        'user_email'   => $login . '@rig.invalid',
        'role'         => 'administrator',
        'display_name' => 'Keelhob rig probe',
      ]);
      if (is_wp_error($id)) { echo json_encode(['error' => $id->get_error_message()]); return; }
      $u = get_user_by('id', (int) $id);
      $created = true;
    }
    if (!$u) { echo json_encode(['error' => 'could not create or load ' . $login]); return; }
    if (!user_can($u, 'manage_options')) { $u->set_role('administrator'); }

    /*
     * BOTH keys, to the same value. update_user_option() writes the
     * blog-prefixed meta and get_user_option() reads that one first, so the
     * unprefixed key alone is silently outranked by any stray prefixed value.
     */
    update_user_meta($u->ID, 'admin_color', $scheme);
    update_user_option($u->ID, 'admin_color', $scheme);

    /*
     * The user locale, which is what actually makes the admin RTL.
     *
     * NO BACKTICKS IN THIS COMMENT: it lives inside a JavaScript template
     * literal, where a backtick ends the string. One pair here turned the rest
     * of the PHP into JavaScript and the file stopped parsing.
     *
     * The locale meta is NOT one of the options get_user_option() prefixes, so
     * there is no second key to be outranked by here — but it is rewritten on every
     * run anyway, and set to the empty string for the LTR pair, so an account
     * cannot be left carrying the other cell's locale. An empty value is how
     * core spells "use the site locale", which is the LTR case.
     */
    if ($locale === '') {
      delete_user_meta($u->ID, 'locale');
    } else {
      update_user_meta($u->ID, 'locale', $locale);
    }

    global $wpdb;
    echo json_encode([
      'id'       => (int) $u->ID,
      'created'  => $created,
      'resolved' => (string) get_user_option('admin_color', $u->ID),
      'meta'     => (string) get_user_meta($u->ID, 'admin_color', true),
      'prefixed' => (string) get_user_meta($u->ID, $wpdb->get_blog_prefix() . 'admin_color', true),
      'locale'   => (string) get_user_meta($u->ID, 'locale', true),
      'user_locale' => (string) get_user_locale($u->ID),
    ]);
  `,
    wpOpts
  );
}

/**
 * Give every screen that renders a table something to render.
 *
 * Deliberately through the product's own save paths, not through raw INSERTs:
 * `Keelhob_Snippets::save()` has REQUIRED `purpose` and `impact` on a create
 * since the security gate landed (class-snippets.php:654), and a seed without
 * them is refused — leaving the list empty, every row-dependent check with
 * nothing to measure, and the run green. That is the bug this job exists to
 * close, so the seeder goes through the gate that caused it.
 */
function seed() {
  return wpJson(
    `
    $out = ['snippets' => [], 'tasks' => [], 'skill' => '', 'memory' => false, 'log' => false];

    /* Snippet code carries NO opening tag: snippet bodies are eval'd and eval()
       rejects one, so a fixture with a tag crashes on init and the crash guard
       correctly switches it straight back off. */
    $specs = [
      ['${PREFIX} alpha active php', 'php', 'add_action("init", function () { return true; });', 'everywhere', 1, 5],
      ['${PREFIX} bravo inactive css', 'css', 'body { color: #123456; }', 'header', 0, 10],
    ];
    foreach ($specs as $sp) {
      $res = Keelhob_Snippets::save([
        'name' => $sp[0], 'snippet_type' => $sp[1], 'code' => $sp[2],
        'location' => $sp[3], 'active' => $sp[4], 'priority' => $sp[5],
        'description' => 'Browser-check fixture. Safe to delete.',
        'purpose' => 'Fixture for tests/rig/ui/check-admin.mjs.',
        'impact'  => 'None. It hooks init and returns. Deleting it breaks nothing.',
      ]);
      if (empty($res['success'])) { echo json_encode(['error' => 'snippet: ' . ($res['error'] ?? 'save refused')]); return; }
      $out['snippets'][] = (int) $res['id'];
    }

    foreach ([
      ['${PREFIX} digest task', 'digest', ''],
      ['${PREFIX} autonomous task', 'autonomous', 'Look at the site and report what changed.'],
    ] as $tk) {
      $res = Keelhob_Tasks::save([
        'name' => $tk[0], 'mode' => $tk[1], 'instruction' => $tk[2],
        'schedule' => 'daily', 'enabled' => 1, 'notify' => 0,
      ]);
      if (empty($res['success'])) { echo json_encode(['error' => 'task: ' . ($res['error'] ?? 'save refused')]); return; }
      $out['tasks'][] = (int) $res['id'];
    }

    $res = Keelhob::instance()->skills->save([
      'name' => 'zz-adm-probe-skill',
      'description' => 'Browser-check fixture for tests/rig/ui/check-admin.mjs. Safe to delete.',
      'body' => 'Do nothing. This row exists so the Skills table has a row to measure.',
      'version' => '0.0.1',
    ]);
    if (empty($res['success'])) { echo json_encode(['error' => 'skill: ' . ($res['error'] ?? 'save refused')]); return; }
    $out['skill'] = (string) $res['name'];

    /* The Activity screen's three tables. Memory is best-effort rather than
       fatal: its save() uses ON DUPLICATE KEY UPDATE, which the rig's SQLite
       drop-in does not always translate, and a snippet-log row plus an
       activity-log row are enough to put rows under the ink. */
    try {
      $m = Keelhob::instance()->memory->save('zz_adm_probe', 'Browser-check fixture value. Safe to delete.', 'general');
      $out['memory'] = !empty($m['success']);
    } catch (Throwable $e) {
      $out['memory'] = false;
    }

    Keelhob::instance()->logger->record(
      'zz_adm_probe_tool',
      ['note' => 'browser-check fixture'],
      ['success' => true, 'message' => 'Fixture row. Safe to delete.']
    );
    $out['log'] = true;

    echo json_encode($out);
  `,
    wpOpts
  );
}

/** Torn down in the SAME run that asserts on it, including on failure. A rig
 *  left holding an ACTIVE fixture snippet runs that code on every request. */
function teardown() {
  return wpJson(
    `
    $gone = ['snippets' => [], 'tasks' => [], 'skill' => false, 'memory' => false, 'log' => 0, 'snippet_log' => 0];

    foreach (Keelhob_Snippets::query([]) as $row) {
      if (strpos((string) $row['name'], '${PREFIX}') === 0) {
        Keelhob_Snippets::delete((int) $row['id']);
        $gone['snippets'][] = (int) $row['id'];
      }
    }
    foreach (Keelhob_Tasks::all() as $t) {
      if (strpos((string) $t['name'], '${PREFIX}') === 0) {
        Keelhob_Tasks::delete((int) $t['id']);
        $gone['tasks'][] = (int) $t['id'];
      }
    }
    $gone['skill'] = !empty(Keelhob::instance()->skills->delete('zz-adm-probe-skill')['success']);
    try {
      $gone['memory'] = !empty(Keelhob::instance()->memory->delete('zz_adm_probe')['success']);
    } catch (Throwable $e) {
      $gone['memory'] = false;
    }

    global $wpdb;
    $gone['log'] = (int) $wpdb->delete(Keelhob_Logger::table(), ['tool' => 'zz_adm_probe_tool']);
    $gone['snippet_log'] = (int) $wpdb->query(
      "DELETE FROM " . Keelhob_Snippet_Log::table() . " WHERE name LIKE '${PREFIX}%'"
    );

    $gone['remaining_snippets'] = count(Keelhob_Snippets::query([]));
    echo json_encode($gone);
  `,
    wpOpts
  );
}

clearFailures();

let browser = null;
let didSeed = false;

try {
  const ping = await fetch(`${WP}/wp-login.php`).then((x) => x.ok).catch(() => false);
  if (!ping) {
    console.error(`Cannot reach ${WP}. Start the rig first:`);
    console.error(`  cd ${RIG} && PHP_CLI_SERVER_WORKERS=4 php -S localhost:8899`);
    console.error('  (on Windows: start that command three times — see tests/rig/README.md)');
    process.exit(2);
  }

  /* Who we run as, and in which colour scheme. No shared rig state is
     touched: see the PROBE_USERS comment at the top of this file. */
  let user = USER;
  let pass = PASS;
  const wantScheme = DARK ? darkScheme() : LIGHT_SCHEME;

  /* The translation has to be on disk before the probe user's locale means
     anything: a `locale` of `ar` with no `ar` pack installed falls straight
     back to en_US, and the run would measure a left-to-right page. */
  if (RTL) {
    const lang = ensureLanguage(RTL_LOCALE);
    r.check('—', `the ${RTL_LOCALE} language pack is installed on the rig`,
      lang.present,
      lang.present
        ? (lang.installed ? `installed by this run` : 'already present')
        : `could not install it: ${lang.error}`);
    if (!lang.present) {
      // Thrown rather than measured on: a left-to-right page reported under an
      // RTL label is worse than no result at all. The catch below records it
      // and the report still prints every row gathered so far.
      throw new Error(
        `--rtl cannot run without the ${RTL_LOCALE} language pack (${lang.error}). ` +
        `Nothing was measured.`
      );
    }
  }

  if (USER_GIVEN) {
    console.log(`--user given explicitly: running as ${USER}, no colour scheme and no locale written.`);
  } else {
    const who = PROBE_USERS[probeUserKey()];
    const wantLocale = RTL ? RTL_LOCALE : '';
    const info = ensureProbeUser(who, wantScheme, wantLocale);
    if (info.error) {
      console.error(`Could not prepare the rig probe user ${who}: ${info.error}`);
      process.exit(2);
    }
    user = who;
    pass = PROBE_PASS;
    console.log(
      `Probe user ${who} (#${info.id}${info.created ? ', created' : ''}) · ` +
      `admin_color resolves to "${info.resolved}" (unprefixed "${info.meta}", blog-prefixed "${info.prefixed}") · ` +
      `locale meta "${info.locale}" → get_user_locale() "${info.user_locale}"`
    );
    // Asserted, not assumed. A prefixed value that disagrees with the
    // unprefixed one is the exact failure that made a dark run report light,
    // and it has to be loud here rather than six screens later.
    r.check('—', `the ${DARK ? 'dark' : 'light'}${RTL ? ' RTL' : ''} fixture resolves to "${wantScheme}"`,
      info.resolved === wantScheme && info.prefixed === wantScheme && info.meta === wantScheme,
      `get_user_option="${info.resolved}", admin_color="${info.meta}", prefixed="${info.prefixed}"`);

    /* The locale, asserted the same way and for the same reason. `ar` in the
       meta but `en_US` out of get_user_locale() means the pack is missing or
       the meta was written to the wrong key, and either way the RTL run is
       about to measure a left-to-right page. */
    r.check('—', `the fixture's locale is ${RTL ? `"${RTL_LOCALE}"` : 'the site default'}`,
      info.locale === wantLocale && (RTL ? info.user_locale === RTL_LOCALE : info.user_locale !== RTL_LOCALE),
      `locale meta="${info.locale}", get_user_locale()="${info.user_locale}"`);
  }

  /* Fixtures, before anything is measured. A contrast check against an empty
     table reports green and asserts nothing. */
  const seeded = seed();
  didSeed = true;
  if (seeded.error) {
    r.fail('—', 'seeding the screens', seeded.error);
  } else {
    r.ok('—', 'screens seeded with real content',
      `snippets ${seeded.snippets.join(',')} · tasks ${seeded.tasks.join(',')} · skill ${seeded.skill} · memory ${seeded.memory} · log ${seeded.log}`);
  }

  const launched = await launch({ chrome: opt.chrome, headed: opt.headed });
  browser = launched.browser;
  const page = await login(launched.context, { wp: WP, user, pass });
  await page.setViewportSize({ width: WIDTH, height: HEIGHT });

  for (const screen of SCREENS) {
    process.stdout.write(`  ... ${screen.name}\n`);
    // One screen must never be able to take the whole run down with it.
    await checkScreen(page, screen).catch((e) =>
      r.fail(screen.slug, `${screen.name}${MODE_SUFFIX} @${WIDTH}px · the checks themselves`, e.message));
  }

  for (const view of EMPTY_VIEWS) {
    process.stdout.write(`  ... ${view.name}\n`);
    await checkEmptyView(page, view).catch((e) =>
      r.fail(view.slug, `${view.name}${MODE_SUFFIX} @${WIDTH}px · the checks themselves`, e.message));
  }

  console.log('  ... switch geometry');
  await checkSwitchGeometry(page).catch((e) =>
    r.fail('keelhob', `the switch knob${MODE_SUFFIX} @${WIDTH}px · the checks themselves`, e.message));
} catch (err) {
  r.fail('—', 'the run itself', err.message);
} finally {
  // Fixtures are destroyed here and nowhere else. A failed assertion, a thrown
  // error and a clean pass all reach this block. One of the seeded snippets is
  // ACTIVE, which means it runs on every request to the rig until it is gone —
  // leaving test artifacts on a live rig has taken a site down on this project
  // before.
  if (didSeed) {
    try {
      const gone = teardown();
      console.log(
        `Fixtures removed: snippets [${gone.snippets.join(',')}], tasks [${gone.tasks.join(',')}], ` +
        `skill ${gone.skill}, memory ${gone.memory}, log rows ${gone.log}, snippet-log rows ${gone.snippet_log} ` +
        `(${gone.remaining_snippets} snippet(s) left on the rig)`
      );
    } catch (e) {
      console.error(`COULD NOT TEAR DOWN the fixtures: ${e.message}`);
      r.fail('—', 'fixture teardown', e.message);
    }
  }

  // The colour scheme needs no restoring: this file no longer writes a shared
  // key. Each mode's scheme lives on that mode's own probe user.

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
