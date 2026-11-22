/* docs/img/04-built-site.png — the front end of a store the agent built.
 *
 * This one is not shot on the rig. The rig has no real build in it, and the
 * whole point of this image is that it is a real site. It is shot against the
 * live demo store, LOGGED OUT, in a fresh browser context with no cookies.
 *
 * Logged out is not a nicety. A WordPress admin bar across the top is the one
 * mistake that nobody notices until the README is public, so this script
 * refuses to save a shot that has one rather than trusting the operator to
 * look.
 *
 * The hero is the frame. The page is scroll-driven: scrolling down to the
 * product row catches a pinned section mid-animation, and the shot comes out
 * with a black band across the top. SHOT_Y exists so that can be re-checked
 * when the site changes, not because a lower frame is better today.
 *
 *   node tests/rig/ui/make-built-site-shot.mjs
 *   SHOT_URL=https://other.example SHOT_Y=5990 node tests/rig/ui/make-built-site-shot.mjs
 */
import { mkdirSync } from 'node:fs';
import { dirname } from 'node:path';
import { launch } from './harness.mjs';

const URL = process.env.SHOT_URL || 'https://test.keelhob.com/';
const OUT = process.env.SHOT_OUT || 'docs/img/04-built-site.png';
const Y   = Number(process.env.SHOT_Y || 0);

mkdirSync(dirname(OUT), { recursive: true });

/* launch() hands back a context that carries the rig's login. Throw it away:
 * this shot must be what a stranger sees, not what an administrator sees. */
const { browser, context } = await launch({});
await context.close();

const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, ignoreHTTPSErrors: true });
ctx.setDefaultTimeout(20000);
ctx.setDefaultNavigationTimeout(60000);
const page = await ctx.newPage();

try {
  await page.goto(URL, { waitUntil: 'networkidle' });
  await page.waitForTimeout(1500);

  const info = await page.evaluate(() => ({
    title:    document.title,
    adminBar: !!document.getElementById('wpadminbar'),
    loggedIn: /logged-in/.test(document.body.className),
    h1:       (document.querySelector('h1') || {}).innerText || null,
    height:   document.documentElement.scrollHeight,
  }));

  if (info.adminBar || info.loggedIn) {
    throw new Error(
      `Refusing to save: the admin bar is in frame (adminBar=${info.adminBar}, ` +
      `loggedIn=${info.loggedIn}). Shoot this logged out.`
    );
  }
  if (!info.h1) {
    throw new Error('Refusing to save: the page has no <h1>, so it probably did not render.');
  }

  if (Y) {
    await page.evaluate((v) => window.scrollTo(0, v), Y);
    await page.waitForTimeout(900);
  }

  await page.screenshot({ path: OUT, fullPage: false });
  console.log(`${OUT}  viewport 1440x900  scrollY ${Y}  <- "${info.h1}" (${info.height}px page)`);
} finally {
  await browser.close();
}
