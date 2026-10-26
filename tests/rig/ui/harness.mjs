/**
 * A very small browser-check harness.
 *
 * WHY THIS LAYER EXISTS AT ALL, in one example.
 *
 * `Keelhob_Studio_Edit::render()` calls `wp_enqueue_code_editor()`, enqueues
 * `wp-codemirror`, and runs `wp.codeEditor.initialize()`. Read the source and
 * the screen has a syntax-highlighting editor. Render the page and it has a
 * plain `<textarea>`, because the inline init script prints 65 scripts before
 * the library it needs. Grep could never have seen that, and neither could the
 * PHP rig checks, which run inside WordPress and never open a browser.
 *
 * So: everything here asserts against a REAL rendered page — computed styles,
 * bounding boxes, live DOM — and never against markup read off disk.
 *
 * Deliberately not a test framework. No Playwright test runner, no describe /
 * it, no config file, no reporters. One dependency (`playwright-core`), one
 * `node` command, plain functions. A browser layer that needs its own toolchain
 * is a browser layer nobody runs.
 */

import { chromium } from 'playwright-core';
import { existsSync, mkdirSync, readdirSync, rmSync, unlinkSync, writeFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { dirname, join } from 'node:path';
import { tmpdir } from 'node:os';
import { fileURLToPath } from 'node:url';

export const HERE = dirname(fileURLToPath(import.meta.url));
export const FAILURE_DIR = join(HERE, 'failures');

/** Read `--name value` / `--name=value` / `--flag` out of argv. */
export function args(argv = process.argv.slice(2)) {
  const out = {};
  for (let i = 0; i < argv.length; i++) {
    const a = argv[i];
    if (!a.startsWith('--')) continue;
    const eq = a.indexOf('=');
    if (eq > -1) {
      out[a.slice(2, eq)] = a.slice(eq + 1);
    } else if (argv[i + 1] && !argv[i + 1].startsWith('--')) {
      out[a.slice(2)] = argv[++i];
    } else {
      out[a.slice(2)] = true;
    }
  }
  return out;
}

/**
 * The result collector.
 *
 * Every check carries the baseline row it replaces (`B4`, `B6`, ...). Without
 * that, a red line six months from now says "the toggle was not visible" and
 * nobody remembers whether that was ever true or whether the check is wrong.
 */
export class Report {
  constructor() {
    this.rows = [];
    this.startedAt = Date.now();
  }

  ok(baseline, label, detail = '') {
    this.rows.push({ pass: true, baseline, label, detail });
  }

  fail(baseline, label, detail = '') {
    this.rows.push({ pass: false, baseline, label, detail });
  }

  /**
   * Assert, without ever throwing.
   *
   * A thrown assertion stops the run at the first failure, which on a first
   * red run — the deliberate one, against the unfixed screen — would report
   * exactly one of the twelve things that are wrong. The whole point of the
   * baseline run is to see all of them at once.
   */
  check(baseline, label, condition, detail = '') {
    condition ? this.ok(baseline, label, detail) : this.fail(baseline, label, detail);
    return !!condition;
  }

  get failures() {
    return this.rows.filter((r) => !r.pass);
  }

  print() {
    const secs = ((Date.now() - this.startedAt) / 1000).toFixed(1);
    console.log('');
    for (const r of this.rows) {
      const mark = r.pass ? 'PASS' : 'FAIL';
      const tag = r.baseline ? ` [${r.baseline}]` : '';
      console.log(`${mark}${tag} ${r.label}${r.detail ? `  — ${r.detail}` : ''}`);
    }
    console.log('');
    console.log('--------------------------------------------------------------');
    const bad = this.failures.length;
    if (bad === 0) {
      console.log(`All ${this.rows.length} browser checks passed in ${secs}s.`);
    } else {
      console.log(`${bad} of ${this.rows.length} browser checks FAILED in ${secs}s.`);
      console.log(`Screenshots: ${FAILURE_DIR}`);
    }
    return bad === 0;
  }
}

/** A screenshot is worth more than the sentence that describes the failure. */
export async function shoot(page, name) {
  try {
    mkdirSync(FAILURE_DIR, { recursive: true });
    await page.screenshot({ path: join(FAILURE_DIR, `${name}.png`), fullPage: true });
  } catch {
    /* A screenshot that cannot be written must never be the reason a run dies. */
  }
}

export function clearFailures() {
  try {
    rmSync(FAILURE_DIR, { recursive: true, force: true });
  } catch {
    /* ignore */
  }
}

/**
 * Find a Chrome this machine already has.
 *
 * `playwright-core` ships no browsers — that is the point of using it here,
 * because a PHP plugin repo should not carry a 150 MB browser download in its
 * dev dependencies. But it also pins the exact build it expects, so a machine
 * with a perfectly good Chromium two revisions newer gets told to run
 * `npx playwright install` and download a third copy.
 *
 * So: look for what is already installed, newest first, and only give up if
 * there is genuinely nothing. Order is deliberate — an explicit `--chrome` wins,
 * then Playwright's own cache (closest to what CI uses), then the system Chrome.
 */
function findChrome(explicit) {
  if (explicit) return explicit;

  const home = process.env.USERPROFILE || process.env.HOME || '';
  const cache =
    process.env.PLAYWRIGHT_BROWSERS_PATH ||
    (process.platform === 'win32'
      ? join(home, 'AppData', 'Local', 'ms-playwright')
      : process.platform === 'darwin'
        ? join(home, 'Library', 'Caches', 'ms-playwright')
        : join(home, '.cache', 'ms-playwright'));

  if (existsSync(cache)) {
    const builds = readdirSync(cache)
      .filter((d) => /^chromium-\d+$/.test(d))
      .sort((a, b) => Number(b.split('-')[1]) - Number(a.split('-')[1]));
    for (const b of builds) {
      for (const rel of [
        ['chrome-win64', 'chrome.exe'],
        ['chrome-win', 'chrome.exe'],
        ['chrome-linux', 'chrome'],
        ['chrome-mac', 'Chromium.app', 'Contents', 'MacOS', 'Chromium'],
      ]) {
        const p = join(cache, b, ...rel);
        if (existsSync(p)) return p;
      }
    }
  }

  for (const p of [
    'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
    'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
    '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
    '/usr/bin/google-chrome',
    '/usr/bin/chromium-browser',
    '/usr/bin/chromium',
  ]) {
    if (existsSync(p)) return p;
  }

  // Nothing found: let Playwright try its own default and produce its own,
  // better, error message rather than inventing one here.
  return null;
}

/** Launch Chrome at the pinned viewport. */
export async function launch(opts = {}) {
  const launchOpts = { headless: opts.headed !== true };
  const exe = findChrome(opts.chrome);
  if (exe) launchOpts.executablePath = exe;
  const browser = await chromium.launch(launchOpts);
  const context = await browser.newContext({
    // The baseline numbers in docs/plans/snippets-rebuild.md were taken at this
    // size. Change it and every "visible without scrolling" check changes
    // meaning, so it is pinned rather than inherited from whatever window the
    // run happens to get.
    viewport: { width: 1536, height: 674 },
    ignoreHTTPSErrors: true,
  });
  // Ten seconds, not Playwright's default thirty. A control that is present but
  // unclickable should fail its own check in ten seconds, not stall a run of
  // thirty-four checks for half a minute each time.
  context.setDefaultTimeout(10_000);
  context.setDefaultNavigationTimeout(15_000);
  return { browser, context };
}

/** Log into wp-admin and keep the cookie on the context. */
export async function login(context, { wp, user, pass }) {
  const page = await context.newPage();
  await page.goto(`${wp}/wp-login.php`, { waitUntil: 'domcontentloaded' });

  // Already signed in: WordPress bounces wp-login.php straight to the dashboard
  // for a live session, and filling a form that is not there fails confusingly.
  if (!(await page.locator('#user_login').count())) {
    return page;
  }

  await page.fill('#user_login', user);
  await page.fill('#user_pass', pass);
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
    page.click('#wp-submit'),
  ]);

  if (await page.locator('#user_login').count()) {
    throw new Error(`Could not sign in to ${wp} as ${user}. Check the rig credentials.`);
  }
  return page;
}

/**
 * Is this element genuinely on screen for a person who has not hovered anything?
 *
 * This is the check that catches WordPress's `.row-actions` trick. Those links
 * are in the DOM, are `visibility: visible`, have a real width and height, and
 * sit at `left: -129987px`. Every naive "is it visible" test passes on them.
 * Only the bounding box tells the truth.
 */
export async function onScreen(page, selector) {
  return page.evaluate((sel) => {
    const el = document.querySelector(sel);
    if (!el) return { found: false };
    const r = el.getBoundingClientRect();
    const cs = getComputedStyle(el);
    return {
      found: true,
      rect: { top: Math.round(r.top), left: Math.round(r.left), w: Math.round(r.width), h: Math.round(r.height) },
      hidden: cs.display === 'none' || cs.visibility === 'hidden' || cs.opacity === '0',
      inViewport:
        r.width > 0 &&
        r.height > 0 &&
        r.left > -1 &&
        r.top > -1 &&
        r.left < window.innerWidth &&
        r.top < window.innerHeight &&
        cs.display !== 'none' &&
        cs.visibility !== 'hidden' &&
        cs.opacity !== '0',
    };
  }, selector);
}

/**
 * Run PHP inside the rig's WordPress, through WP-CLI.
 *
 * Fixtures are created and destroyed here rather than through the admin screens
 * on purpose. Seeding a snippet by driving the very form under test would mean
 * a broken form produced no fixtures and therefore no failures, and the run
 * would go green having asserted nothing.
 *
 * WP-CLI is often only a phar on Windows, so the command is configurable and
 * falls back to the path this machine uses.
 */

/** WP-CLI's argv, as an array. A phar on this machine, `wp` on a PATH. */
function wpBin(cli) {
  const bin = cli || (existsSync('/c/wp-cli/wp-cli.phar') ? 'php /c/wp-cli/wp-cli.phar' : 'wp');
  return bin.split(' ');
}

/**
 * `/c/wp-rig/wordpress` is the path every command in the docs and in these
 * files uses, because they are all written for Git Bash. PHP on Windows does
 * not understand it: WP-CLI answers "This does not seem to be a WordPress
 * installation. The used path is: /c/wp-rig/wordpress/" and the whole run dies
 * on its first fixture call. Translating the MSYS drive prefix here, once, is
 * better than every caller remembering to pass --rig C:/...
 */
function rigPathFor(path) {
  return process.platform === 'win32'
    ? String(path).replace(/^\/([a-z])\//i, (_m, d) => `${d.toUpperCase()}:/`)
    : path;
}

/** WP-CLI's own deprecation chatter on PHP 8.4, which every command prints
 *  whatever it was asked to do. run-all.sh filters the same lines. */
function cleanCli(text) {
  return (text || '')
    .split(/\r?\n/)
    .filter((l) => !/deprecated|^\s*$/i.test(l))
    .join('\n')
    .trim();
}

/**
 * Run an arbitrary WP-CLI command against the rig.
 *
 * `wpEval` covers anything expressible in PHP inside a loaded WordPress, which
 * is almost everything a fixture needs. It does NOT cover the commands that
 * reach outside that runtime — installing a translation is the case this was
 * added for. `wp language core install ar` resolves api.wordpress.org through
 * WP-CLI's own on-disk cache, so it works on a machine with no network as long
 * as the pack has been fetched once; doing the same thing from
 * `wp_download_language_pack()` inside `wpEval` does not, because that path
 * goes straight to HTTP.
 *
 * Returns the exit status and the cleaned streams rather than throwing: a
 * caller that is probing for something absent needs to see a failure without
 * losing the run, and `--rtl` has to be able to say "the `ar` pack is missing
 * and I could not fetch it" as a check rather than as a crash.
 */
export function wpCli(argv, { path, cli } = {}) {
  const parts = wpBin(cli);
  const res = spawnSync(
    parts[0],
    [...parts.slice(1), ...argv, `--path=${rigPathFor(path)}`],
    { encoding: 'utf8', shell: process.platform === 'win32' }
  );
  return { status: res.status, stdout: cleanCli(res.stdout), stderr: cleanCli(res.stderr) };
}
export function wpEval(php, { path, cli }) {
  const parts = wpBin(cli);
  const rigPath = rigPathFor(path);

  // The PHP goes through a FILE, not through argv.
  //
  // `wp eval "<php>"` looked simpler and is unusable here: on Windows the call
  // needs `shell: true`, and the shell then re-parses every quote, dollar sign
  // and newline in the snippet. The first version of this helper failed with a
  // WP-CLI deprecation notice as its only visible symptom, because the mangled
  // argument had already made the command meaningless.
  const tmp = join(tmpdir(), `kh-ui-${Date.now()}-${Math.random().toString(36).slice(2)}.php`);
  writeFileSync(tmp, `<?php
${php}
`, 'utf8');

  try {
    const res = spawnSync(parts[0], [...parts.slice(1), 'eval-file', tmp, `--path=${rigPath}`], {
      encoding: 'utf8',
      shell: process.platform === 'win32',
    });

    // WP-CLI prints core's deprecation notices on 8.4 whatever the command
    // does. run-all.sh filters the same lines for the same reason.
    const out = cleanCli(res.stdout);
    const err = cleanCli(res.stderr);

    // Exit status alone is not trustworthy here: WP-CLI can exit non-zero on a
    // deprecation it also printed. Treat it as a failure only when it also had
    // something real to say.
    if (res.status !== 0 && err !== '') {
      throw new Error(`wp eval-file failed (${res.status}): ${err.slice(0, 500)}`);
    }
    return out;
  } finally {
    try {
      unlinkSync(tmp);
    } catch {
      /* A leftover temp file is not worth failing a run over. */
    }
  }
}

/** Same, but the PHP is expected to echo JSON. */
export function wpJson(php, opts) {
  const raw = wpEval(php, opts);
  try {
    return JSON.parse(raw);
  } catch {
    throw new Error(`Expected JSON from wp eval-file, got: ${raw.slice(0, 300)}`);
  }
}
