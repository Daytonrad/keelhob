/* Screenshots for docs/img, taken on the kept rig at localhost:8899.
 *
 * Order matters. The Capabilities shot must be taken BEFORE anything else
 * touches the capability option, because the whole point of that shot is that
 * the option is ABSENT and the defaults are what you see.
 *
 * Both the token and the seeded snippets are torn down in the same run that
 * shoots them, in a finally, so a crash cannot leave either behind. */
import { mkdirSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { launch, login } from './harness.mjs';

const WP  = 'http://localhost:8899';
const OUT = process.env.SHOT_OUT || 'docs/img';
mkdirSync(OUT, { recursive: true });

const RIG = 'C:/wp-rig/wordpress';
function wp(php) {
  return execFileSync('php', ['C:/wp-cli/wp-cli.phar', `--path=${RIG}`, '--skip-themes', 'eval', php],
    { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }).trim();
}

const { browser, context } = await launch({});
await context.close();
const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, ignoreHTTPSErrors: true });
ctx.setDefaultTimeout(20000);
ctx.setDefaultNavigationTimeout(30000);
const page = await login(ctx, { wp: WP, user: 'admin', pass: 'rigpass' });

/* The rig carries another plugin in its sidebar and an update badge, neither of
 * which belongs in product documentation. The shot list allows cropping the
 * sidebar as long as it is done the same way in every shot, so it is collapsed
 * once here rather than per shot. */
await page.goto(`${WP}/wp-admin/`, { waitUntil: 'load' });
if (await page.locator('#collapse-button').count()) {
  const folded = await page.evaluate(() => document.body.classList.contains('folded'));
  if (!folded) { await page.click('#collapse-button'); await page.waitForTimeout(400); }
}

/* The card that a given h2 lives in, as a clip box. */
async function cardClip(headingText) {
  return page.evaluate((t) => {
    const h = [...document.querySelectorAll('.kh-ui h2')].find((e) => e.textContent.trim().startsWith(t));
    if (!h) return null;
    const card = h.closest('.kh-u-card, .card, .kh-u-panel, section, form') || h.parentElement;
    const b = card.getBoundingClientRect();
    return { x: Math.max(0, b.x - 8), y: Math.max(0, b.y + window.scrollY - 8),
             width: Math.min(1440, b.width + 16), height: b.height + 16 };
  }, headingText);
}

async function shotSection(slug, heading, file) {
  await page.goto(`${WP}/wp-admin/admin.php?page=${slug}`, { waitUntil: 'load' });
  await page.waitForTimeout(800);
  const found = await page.evaluate((t) => {
    const h = [...document.querySelectorAll('.kh-ui h2')].find((e) => e.textContent.trim().startsWith(t));
    if (!h) return false;
    const card = h.closest('.kh-u-card, .card, .kh-u-panel, section, form') || h.parentElement;
    /* Just enough headroom that the card does not touch the admin bar. More than
       this and the tail of the PREVIOUS card sits at the top of the frame. */
    window.scrollTo(0, Math.max(0, card.getBoundingClientRect().top + window.scrollY - 60));
    return true;
  }, heading);
  if (!found) { console.log(`!! no card for "${heading}" on ${slug}`); return false; }
  await page.waitForTimeout(400);
  await page.screenshot({ path: `${OUT}/${file}`, fullPage: false });
  console.log(`${file}  viewport 1440x900  <- scrolled to "${heading}"`);
  return true;
}

let tokenMade = false, seeded = false;
const realTitle = wp('echo get_option("blogname");').split(String.fromCharCode(10)).pop().trim();
wp('update_option("blogname", "Northwind Supply");');
console.log(`site title: "${realTitle}" -> "Northwind Supply" for the shots`);
try {
  /* ---- 03 capabilities: defaults, untouched, nothing saved yet ---------- */
  const before = wp('echo get_option("keelhob_capabilities") === false ? "ABSENT" : "PRESENT";');
  console.log(`capability option before the shot: ${before}`);
  if (before !== 'ABSENT') console.log('!! not defaults — the shot would be wrong');
  await shotSection('keelhob', 'Capabilities', '03-capabilities.png');

  /* ---- 01 connect: a real command block, with a rig-only token --------- */
  await shotSection('keelhob', '2 · Connect your AI app', '01-connect.png');

  /* ---- 02 studio: seeded rows, clearly disclosed ----------------------- */
  seeded = true;
  wp(`
    global $wpdb; $t = $wpdb->prefix . "keelhob_snippets";
    $rows = [
      ["Hide the admin bar for customers","Logged-in customers saw the WordPress bar over the hero.","php","everywhere",1,"","Customers who had an account saw the grey WordPress bar across the top of every page, over the hero image.","Removes the admin bar for anyone who cannot edit posts. Editors and administrators still see it."],
      ["Hero spacing on phones","The hero crowded the fold under 480px.","css","header",1,"","The headline and the button touched on small phones, so the first thing a visitor saw looked broken.","Adds breathing room under 480px only. Nothing above that width changes."],
      ["Free delivery notice","Shows how much more to spend for free delivery.","php","everywhere",1,"","Shoppers abandoned the cart without knowing free delivery was close.","Prints one line in the cart totals. Reads the cart only; changes no price and no order."],
      ["Christmas banner","Seasonal strip above the header. Off until December.","html","header",0,"","A seasonal strip was wanted, but not yet.","Nothing, while it is switched off. When switched on it prints one strip above the header."],
      ["Preload the hero image","Meant to cut about 400ms off the largest paint.","php","everywhere",0,"Fatal error: Call to undefined function kh_hero_src() in snippet 5 on line 4 — this snippet was deactivated automatically.","The hero image was the slowest thing on the page.","It called a helper that does not exist. Keelhob caught the fatal error, switched the snippet off and wrote the reason here, so the site stayed up."],
    ];
    foreach ($rows as $i => $r) {
      $wpdb->insert($t, [
        "uid" => "shot-" . ($i+1), "name" => $r[0], "description" => $r[1],
        "snippet_type" => $r[2], "location" => $r[3], "active" => $r[4], "last_error" => $r[5],
        "purpose" => $r[6], "impact" => $r[7],
        "code" => "// demo snippet for the documentation screenshot",
        "source" => "ai", "created_at" => current_time("mysql"), "updated_at" => current_time("mysql"),
      ]);
    }
    echo "seeded " . (int) $wpdb->get_var("SELECT COUNT(*) FROM $t");
  `);
  await page.goto(`${WP}/wp-admin/admin.php?page=keelhob-studio`, { waitUntil: 'load' });
  await page.waitForTimeout(900);
  await page.screenshot({ path: `${OUT}/02-studio.png`, fullPage: false });
  console.log('02-studio.png  viewport 1440x900');
} finally {
  if (tokenMade) { wp('delete_option("keelhob_token"); echo "token deleted";'); console.log('token deleted'); }
  wp(`update_option("blogname", ${JSON.stringify(realTitle)}); echo get_option("blogname");`);
  console.log(`site title restored to "${realTitle}"`);
  if (seeded) {
    const left = wp('global $wpdb; $t=$wpdb->prefix."keelhob_snippets"; $wpdb->query("DELETE FROM $t WHERE uid LIKE \'shot-%\'"); echo $wpdb->get_var("SELECT COUNT(*) FROM $t");');
    console.log(`seeded rows removed; snippets left in the rig: ${left}`);
  }
  await browser.close();
}
