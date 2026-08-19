---
name: security-standard
description: Harden and audit a WordPress/WooCommerce site's security — 20 automatic checks, a 7-question owner-decision intake, WooCommerce/card-skimming specifics, and a compromise-detection sweep to run before building on a site you did not build. Use when the user says "harden my site", "is my site secure", "security audit", "I think I've been hacked", "check for malware", or before launch. Loaded automatically by build-standard whenever a site is being built or launched.
enable_agentic: true
enable_prompt: true
---

# Security standard

You have full control of a real WordPress site. Most sites do not get hacked by a clever
exploit — they get hacked by an old plugin, a reused password, or a setting nobody thought
to close. This skill is a worklist for closing those, in the order that actually matters.

**Rule zero:** you may harden a site without asking. You may **not** change how the owner
logs in, what breaks, or what it costs, without asking first. §2 is yours to just do; §3 is
not.

**Before you build on a site you did not build, run §5 (detection) first.** Hardening a
compromised site does not clean it — it adds hardening on top of a backdoor.

---

## 1. The threat model — what actually compromises WordPress sites

Ranked by real-world frequency, not by how scary it sounds. Third-party extensions and
stolen/weak credentials dominate; WordPress core is almost never the entry point.

1. **Vulnerable, outdated, or abandoned plugins/themes.** Plugins caused 97% of new
   WordPress vulnerabilities in 2023; core 0.2%. Attackers mass-scan for a known fingerprint
   and fire an automated exploit within days of disclosure — small sites get hit as hard as
   big ones. An *inactive* plugin's files are still reachable by direct HTTP request in many
   exploit paths, so "disabled" is not "safe".
2. **Weak, reused, or stolen credentials.** Credential stuffing and password spraying
   against `/wp-login.php` and `/xmlrpc.php` — XML-RPC's `system.multicall` lets an attacker
   test many password pairs per request and skips login-page rate limits. A stolen admin
   session defeats nearly every file-level hardening measure, because the plugin editor or a
   plugin upload is a legitimate feature to a logged-in attacker.
3. **Post-compromise persistence (backdoors).** Not an entry vector — the reason
   compromises recur. Nearly half of cleaned sites had a backdoor. Patch the original hole
   and the site still gets re-hacked if you don't also find the backdoor.
4. **Nulled/pirated premium plugins and fake plugins.** The single most reliable way to get
   a backdoor installed voluntarily. Reject nulled software outright — this is a hard rule,
   not a preference to negotiate.
5. **Misconfiguration and over-exposure.** No exploit needed: open registration with the
   wrong default role, the file editor left on, a static-key debug endpoint, an exposed
   `.sql` dump, directory listing on. Each turns a nuisance into a takeover. **This is what
   §2 exists for.**
6. **Insecure uploads / PHP execution in `wp-content/uploads`.** If the server will execute
   PHP dropped into uploads, any upload bug or existing compromise becomes a web shell.
7. **Privilege escalation via role misconfiguration.** Contributor-level access was the most
   common prerequisite for exploitation in 2024 (34% of disclosed vulnerabilities).
   Contributor/subscriber accounts are not harmless.
8. **Supply chain.** A legitimate plugin's update channel gets compromised, or ownership
   changes hands to a bad actor. Also third-party JS from a CDN or marketing tag altered
   later — the Magecart mechanism.
9. **Hosting/server layer.** Shared-hosting cross-contamination, outdated PHP, stolen
   FTP/cPanel credentials. Mostly outside your control — flag it to the owner.

**What attackers do once in:** SEO spam (most common — hidden pharma/gambling links,
detected on ~20% of infected sites and ~38% of infected databases), malicious redirects,
phishing pages hosted on the domain, card skimming (§4), backdoors resold for later access.

### Do these first — ranked by risk-reduction per unit effort
1. Patch and remove unused plugins/themes.
2. Strong unique admin credentials + 2FA + login rate limiting.
3. Close the §2 misconfigurations (registration, file editor, debug/preview endpoints,
   exposed files).
4. Block PHP execution in uploads.
5. HTTPS everywhere + secure cookies + security headers.
6. Backups you have actually tested restoring.
7. Monitoring / integrity checking.

### Security theatre — do not spend budget here, and never claim credit for it
- **Hiding the WordPress version.** Scanners fingerprint by file hashes, not the generator
  tag. Cheap, do it, never count it as a real control.
- **Changing the `wp_` table prefix on an existing site.** Blocks a few hardcoded-prefix SQLi
  payloads; modern injection goes through `$wpdb->prefix` anyway. Fine on a **new** install,
  a genuinely risky database operation on an **existing** one — do not do it retroactively.
- **Renaming the login URL as a security control.** It cuts bot noise, nothing more. It does
  not stop a targeted attacker and is not a substitute for 2FA or rate limiting (see Q1).
- **Blocking `/wp-json/` entirely.** The REST API is core infrastructure — this breaks the
  block editor and most plugins. Restrict the *users* endpoint instead, not the whole API.
- **Removing `readme.html` or `?ver=` query strings.** Cosmetic. Do it because it's free, not
  because it reduces risk.
- **`.htaccess` "ultimate security" snippets copied from blogs.** Frequently break the REST
  API, media library, or page builders in ways the owner cannot debug. Only ship a rule you
  can state the exact effect of.
- **Security plugins with everything switched on.** Two 2FA plugins, three REST-auth
  plugins, overlapping WAFs — this is more attack surface and more breakage, not more
  security. One security plugin, maximum, always.

WordPress's own hardening docs call security-through-obscurity "generally an unsound
primary strategy." Treat every item above accordingly.

---

## 2. AUTOMATIC checks (A1–A20) — do these without asking

None of these change how the owner uses the site. Work through them as a checklist:
detect → fix → note the blast radius before you act on anything above "low".

`wp-config.php` constants go **above** `/* That's all, stop editing! */`. Read the file
first with `read_file` to check what's already there and to place the edit correctly — use
`edit_file` with a small `old_string`/`new_string`, not a full rewrite (see `site-safety`).
Web-server rules assume Apache `.htaccess`; on nginx say so rather than silently writing a
rule nginx ignores.

**A1 — Plugin/theme file editor disabled.**
Detect: `manage_option action=get option=disallow_file_edit`-equivalent — in practice check
`wp-config.php` for `DISALLOW_FILE_EDIT` via `read_file`. Fix: `execute_php` or `edit_file`
to add `define( 'DISALLOW_FILE_EDIT', true );`. Why: WordPress's own docs call the editor
"the first tool an attacker will use if able to login" — it's code execution from the
dashboard. Blast radius: low, reversible instantly.

**A2 — Public registration closed unless the site needs it.**
Detect: `manage_option action=get option=users_can_register` and `option=default_role`.
Fix: if no membership/customer/community feature exists, set `users_can_register=0` via
`manage_option action=update`. If registration is genuinely needed (WooCommerce accounts,
membership), keep it on but force `default_role=subscriber`/`customer`. Blast radius:
**medium — verify first.** Check `manage_plugin action=list` for WooCommerce/membership/LMS/
forum plugins before flipping it off. `default_role` above Subscriber is *always* a finding,
regardless of what else is true.

**A3 — No user named `admin`, `administrator`, `webmaster`, or the domain name.**
Detect: `manage_user action=list`, inspect `user_login`. Fix: `manage_user action=create`
with an unpredictable username and administrator role, reassign content, then
`manage_user action=delete`. Blast radius: medium — the owner's login name changes. Tell
them in writing before doing it, and confirm they can log in with the new account before
deleting the old one.

**A4 — User enumeration closed.**
Detect: `execute_php` to `wp_remote_get` `?author=1` and check for a redirect to
`/author/<username>/`; fetch `/wp-json/wp/v2/users` unauthenticated and check whether names
are returned; check for `/wp-sitemap-users-1.xml`. Fix: a snippet (via the `code-snippets`
skill) blocking unauthenticated `?author=N` redirects, filtering `rest_endpoints` to require
`list_users` on the users collection, and removing the users sitemap provider. Blast radius:
low-medium — author archive pages by slug still work; test if the theme lists authors via
the REST API on the front end.

**A5 — HTTPS enforced everywhere.**
Detect: `manage_option action=get option=siteurl` and `option=home`; `execute_php` to check
whether HTTP 301s to HTTPS; scan rendered HTML for mixed-content `http://` assets. Fix:
correct both options to `https://`, add a canonical redirect, fix mixed content, define
`FORCE_SSL_ADMIN` in `wp-config.php`. Add HSTS only after confirming HTTPS works on every
hostname — start `max-age` low (e.g. 300) then raise it. Blast radius: **high if the
certificate is broken.** HSTS with a long max-age on a bad cert locks visitors out in their
browser for the duration, and you cannot undo it remotely. Verify the cert covers `www` and
the apex domain first. Never enable HSTS preload without asking.

**A6 — Security headers present.**
Detect: `execute_php` with `wp_remote_get(home_url('/'))` and inspect the response headers.
Fix (safe defaults, via a snippet on `send_headers` or server config):
`Strict-Transport-Security` (after A5), `X-Content-Type-Options: nosniff`,
`Referrer-Policy: strict-origin-when-cross-origin`, `X-Frame-Options: SAMEORIGIN`,
`Permissions-Policy` denying unused features. **`Content-Security-Policy-Report-Only`
first — never ship an enforcing CSP on a WordPress site you have not tested page by page.**
Blast radius: nosniff/Referrer-Policy/Permissions-Policy near zero.
`X-Frame-Options` can break legitimate embedding (page-builder previews, payment iframes
you host). Enforcing CSP is **high** — it silently kills page builders, analytics, and
checkout scripts. Report-Only until proven safe.

**A7 — Auth cookies Secure + HttpOnly + SameSite.**
Detect: inspect the `wordpress_logged_in_*` cookie flags. Fix: WordPress sets `Secure`
automatically once the site is fully HTTPS (A5); confirm `HttpOnly`; add `SameSite=Lax` at
server/PHP level if missing. Blast radius: low at `Lax`. **`SameSite=Strict` is not a safe
default** — it breaks users returning from an external payment gateway or an emailed link,
who appear logged out. Use `Lax`.

**A8 — Unique, present salts and keys.**
Detect: `read_file` on `wp-config.php`, check for `put your unique phrase here` or
duplicated/short values on the 8 `AUTH_KEY`/`SECURE_AUTH_KEY`/etc. constants. Fix: regenerate
all 8 from `https://api.wordpress.org/secret-key/1.1/salt/` and `edit_file` them in. Blast
radius: **every logged-in session is invalidated**, including the owner's and yours.
Passwords are unaffected. Do this early in a session, and warn the owner first.

**A9 — `WP_DEBUG` off, no public `debug.log`.**
Detect: `read_file` on `wp-config.php` for `WP_DEBUG`/`WP_DEBUG_DISPLAY`; `execute_php` to
`wp_remote_get` `wp-content/debug.log` and check for a 200. Fix: set both `false`; if logging
is needed, `WP_DEBUG_LOG` to a path outside the web root; delete any existing public
`debug.log` with `delete_file`; deny it at the server. Blast radius: none — error output
leaks paths, queries, and sometimes credentials to any visitor.

**A10 — No exposed sensitive files.**
Detect: `execute_php` with `wp_remote_get` against each of `/.git/config`, `/.env`,
`/wp-config.php.bak|.old|.save|~|.txt`, `/*.sql`, `/*.sql.gz`, `/backup*.zip`,
`/wp-content/uploads/*.sql`, `/error_log`, `/.DS_Store`, `/composer.json`, `/package.json`,
editor swap files. Anything returning 200 is a finding. Fix: `delete_file` (archive off-site
first if it's a real backup) and add server-level deny rules. Blast radius: none once
verified not a live asset. **Treat any hit as critical** — a `.sql` dump in the web root is
a full credential-and-customer-data breach waiting to be indexed by a search engine.

**A11 — Directory listing disabled.**
Detect: `list_directory` won't tell you this — request `/wp-content/uploads/` and
`/wp-includes/` over HTTP via `execute_php`/`wp_remote_get`; a file index in the response
body means listing is on. Fix: `Options -Indexes` (Apache) / `autoindex off` (nginx); ensure
`index.php` placeholders exist in plugin/upload directories. Blast radius: none.

**A12 — `wp-config.php` and file permissions correct.**
Detect: this needs filesystem access Claudpress may not expose directly — check via
`execute_php` (`fileperms()`) if available, or ask the host/owner. Target: directories 755,
files 644, `wp-config.php` 440 or 400; nothing 777. Fix: correct permissions; deny
`wp-config.php` at the server. Blast radius: **medium-high** — over-tightening can break the
media library, updates, or caching plugins that need writable directories. Never chmod
recursively without checking what the host requires; some managed hosts enforce permissions
platform-side — don't fight that.

**A13 — `readme.html`, `license.txt`, version disclosure removed.**
Detect: `execute_php`/`wp_remote_get` `/readme.html` for a 200; check rendered `<head>` for
`<meta name="generator">`; check `?ver=` on core asset URLs. Fix: `delete_file` on
`readme.html`/`license.txt`, remove the generator tag via a `remove_action` snippet. Blast
radius: none, but **label this cosmetic** (§1 theatre) — core updates recreate
`readme.html`, so a server-level deny rule is more durable than deleting it once.

**A14 — Everything up to date; nothing unused installed.**
Detect: `manage_updates action=check` for core/plugin/theme versions vs. available; flag any
plugin/theme not updated by its author in 12+ months or pulled from the wordpress.org
repository; `manage_plugin action=list` for every deactivated plugin and unused theme. Fix:
`manage_updates action=update_core`/`update_plugins`/`update_themes`; **delete, don't
deactivate**, unused plugins/themes via `manage_plugin action=delete`, keeping one current
default theme as fallback; replace abandoned plugins. Blast radius: **high — this is the one
that breaks live sites.** Back up first, prefer staging, update one major plugin at a time,
verify checkout/forms after. Deleting a plugin usually deletes its settings too — confirm it
is genuinely unused (search the DB for its options and shortcodes with `execute_php`) before
deleting.

**A15 — Application passwords and REST auth surface audited.**
Detect: `execute_php` to query `wp_usermeta` for `_application_passwords` across all users —
list every app password, its name, and last-used date; `manage_plugin action=list` to
inventory anything providing REST/JWT/API-key auth. Fix: revoke unused, unrecognised, or
departed-integration app passwords; keep at most one REST-auth plugin; ensure app passwords
are only issued to accounts with the minimum role needed. Blast radius: **high** — revoking
one breaks whatever integration used it (a mobile app, Claudpress's own connection, a sync
service). Identify the owner of each credential before revoking anything, and never revoke
the connection you are currently using.

**A16 — No static-key / hardcoded-secret endpoints in custom code.**
This is about code the site (or you) wrote, and it is non-negotiable at handover — this
exact pattern is what produced a real incident: a preview bypass on a hardcoded key with a
week-long cookie.
Detect: `snippet_list` then `snippet_get` on every PHP snippet, plus `read_file` on
mu-plugins and theme `functions.php`. Grep for hardcoded tokens compared with `==`,
`$_GET`-gated preview/debug/maintenance bypasses (`if ($_GET['key'] === '...')`), and any
`add_action('init', ...)` that authenticates purely from a URL parameter.
Fix: remove it. If a preview mechanism is genuinely required, rebuild it on WordPress
capabilities (`current_user_can()`) and nonces, or a time-limited signed token compared with
`hash_equals()` — never `==` or `===` on a secret, which is comparison-timing-leaky and
usually paired with a value that never expires.
Blast radius: medium — removing it breaks whatever workflow relied on it, so build the
replacement before removing the old path.

**A17 — XML-RPC exposure measured (measure automatically, decide with the owner).**
Detect: `execute_php` POST-probe to `/xmlrpc.php` for a 200; check `manage_plugin
action=list` for Jetpack and check whether the owner uses the WordPress mobile app or any
pingback-dependent workflow. Automatic part — do this without asking: disable **pingbacks**
(filter `xmlrpc_methods` to remove `pingback.ping`) since pingback is used for DDoS
reflection and port probing and almost nobody wants it; disable `system.multicall`
amplification if the host allows. Turning `/xmlrpc.php` off entirely is Q4, not automatic.

**A18 — Uploads directory hygiene.**
Detect: `list_directory` on `wp-content/uploads` (recursively where the tool supports it)
for any `.php`, `.phtml`, `.php5`, `.htaccess`, `.sh`, `.pl` file — legitimate uploads never
contain executable code. Fix: quarantine and investigate any hit — this is also a §5
compromise indicator, not just hygiene. Blocking execution site-wide is Q6, not automatic.
Blast radius: listing is read-only and safe. Deleting a file a plugin generated (rare, but
some caching/gallery plugins write PHP into uploads) can break that plugin — inspect before
deleting.

**A19 — Custom code follows the WordPress security APIs.**
Applies to every snippet, template, or file you yourself write via `code-snippets`,
`write_file`, or `edit_file` — this is a standing constraint on your own output, not just an
audit item:
- Every state-changing request checks a nonce **and** `current_user_can()` — a nonce alone
  is CSRF protection, not authorisation.
- All database access via `$wpdb->prepare()`. No string-concatenated SQL, ever.
- Output escaped at the point of output: `esc_html()`, `esc_attr()`, `esc_url()`,
  `wp_kses_post()`.
- Input sanitised on the way in; never trust `$_GET`/`$_POST`/`$_SERVER` directly.
- File uploads validated with `wp_check_filetype_and_ext()` — never trust a client-supplied
  extension or MIME type.
- No `eval()`, no `unserialize()` on user input (PHP object injection is a real, actively
  exploited WordPress bug class), no `extract()`.
- Secrets live in `wp-config.php` constants or environment variables, never in post content,
  exportable options, or committed files.
- Any snippet or plugin granting `unfiltered_html`/`edit_files` to a non-admin role is a
  finding, full stop.
Blast radius: none — this is simply how the code should have been written in the first
place. Check it with `snippet_get` before saving, not after.

**A20 — Backups exist and are off-site.**
Detect: is there a backup mechanism (plugin, host-level, external service)? Check
`list_directory` on the web root and uploads for a stray backup archive (also an A10 hit).
Fix: confirm backups run to off-site storage and that a restore has actually been tested at
least once — ask the owner if you cannot verify this yourself. Blast radius: none. A backup
stored on the same server, in the web root, is simultaneously the #1 breach vector and no
real protection.

---

## 3. NEEDS-OWNER-DECISION — the intake questionnaire (Q1–Q7)

Everything here changes how the owner or their visitors use the site. **Ask, record the
answer, and state the default you'll apply if they say "you decide."** Do not impose any of
these silently, and do not paste the rationale unprompted — keep it as a follow-up.

**Q1 — Login protection.** Plain-language ask: bots constantly try passwords against the
login page; how much protection do you want? Options: **A) Standard (recommended)** — 2FA on
the admin account plus lockout after repeated failed attempts. **B) Standard, plus hide the
login page** — same as A, but the login URL moves to a secret address; cuts bot noise, does
not stop a targeted attacker, and is a lockout risk if the address is lost. **C) Minimal** —
just a strong password.
Default if "you decide": **A** — 2FA on all administrator accounts, lockout after 5 failed
attempts with a 20-minute cooldown and an IP allowlist for the owner where practical, login
URL left at `/wp-login.php`. Not B by default — hidden login URLs cause support incidents
and lock-outs, and the real protection is 2FA; bookmarks and saved passwords referencing
`/wp-admin` break. **Renaming the login URL is never a silent default — state this
explicitly to the owner** (see §1 theatre). If B is chosen, the recovery path is mandatory:
the secret URL is emailed and documented, the plugin providing it can be disabled by renaming
its folder to restore `/wp-login.php`, and the host's control panel can always reset a
password — state all three before enabling.

**Q2 — Update policy.** Plain-language ask: should the site update itself? Most hacks come
through an old add-on with a fix already available, but an automatic update can occasionally
break a page or checkout unattended. Options: **A) Security-first (recommended)** — security
patches auto-install, feature updates wait for a human. **B) Everything automatic** — safest
against attackers, highest chance of a surprise. **C) Nothing automatic** — manual updates on
a schedule; ask who is doing it and how often, because this only works if it actually
happens.
Default if "you decide": **A** — core minor/security auto-updates on, plugin/theme
auto-updates on **except** the payment gateway, page builder, and theme, which get manual
updates with a backup and a checkout test. **On a live store handling orders, always ask;
never assume B.**

**Q3 — Security plugin or firewall.** Plain-language ask: a firewall blocks known attacks
and can virtually patch a vulnerable plugin before a fix ships — want one? Options:
**A) Free plugin firewall** (Wordfence free, Solid Security free) — no cost, some server
load, rules arrive on a delay. **B) Paid/network firewall** (Wordfence Premium, Sucuri,
Cloudflare WAF) — roughly $10–25/month, blocks before it hits the server, real-time rules.
**C) Nothing extra** — rely on updates and this standard's hardening. State clearly: someone
has to read the alerts, and one is enough — two overlapping security/login plugins make
things worse, not better.
Default if "you decide": **A**, one plugin only, alerts to the owner's email, plus Cloudflare
in front if the domain's DNS is already there (free tier, no added cost). Never install a
second overlapping security plugin. **Never enable a paid tier without explicit approval —
say the cost out loud.**

**Q4 — XML-RPC dependents.** Plain-language ask: do you use the WordPress mobile app,
Jetpack, or any external tool that posts to your site? XML-RPC is what attackers use for
fast password guessing, but it's also what those tools use to connect.
Default if "you decide": if Jetpack and the mobile app are not in use, disable `/xmlrpc.php`
entirely. If they are in use, keep it, disable pingbacks (A17), and rate-limit it. What
breaks if wrong: the mobile app and Jetpack features stop connecting, some remote-publishing
tools fail, trackbacks stop — all reversible in seconds.

**Q5 — Connected apps and the REST API.** Plain-language ask: is anything else connected to
your site's data — a mobile app, a separate front-end, a booking/CRM integration, Zapier or
Make? Locking down the API for anonymous visitors is safer, but breaks anything legitimate
using it anonymously.
Default if "you decide": keep the REST API on (core needs it), require authentication for
the **users** endpoint and any custom endpoint exposing personal data, leave post/page
endpoints public (the block editor and most themes depend on them). Revoke unrecognised
application passwords only after confirming with the owner. **Never blanket-block
`/wp-json/`** — it breaks the editor and most page builders.

**Q6 — PHP execution in uploads.** Plain-language ask: your uploads folder should only ever
hold images, PDFs, and videos — want the server to refuse to *run* anything from there? This
shuts down one of the most common ways a hacked site stays hacked. Usually invisible;
occasionally a caching or gallery plugin writes program files there and breaks.
Default if "you decide": apply it, then verify uploads, the media library, and any
gallery/caching plugin still work. Roll back immediately if anything breaks. What can break:
some `advanced-cache` variants, some PDF/gallery tools, "protected file" plugins that route
downloads through PHP in uploads.

**Q7 — Recovery custody.** Two things to confirm with the owner before finishing: (1) is the
admin email correct, an inbox they actually read, and **on a different domain than this
site** — if the site goes down and the recovery email lives on it, password resets go down
with it; (2) who holds hosting login, domain registrar login, and 2FA recovery codes — a
password manager if it's just the owner, and disclosed to anyone else who holds them, since
they can undo anything you set up.
Default if "you decide": verify admin-email deliverability, recommend an off-domain address
(Gmail/Outlook) for recovery, and list every administrator account in the handover so the
owner can see who has power over the site. **Non-negotiable regardless of the answer:** you
never keep sole custody of a credential, and every credential you created is disclosed at
handover.

**If the owner says "you decide" to everything**, apply Q1-A, Q2-A, Q3-A, Q4 (disable if
unused), Q5 (users endpoint locked, rest open), Q6 (apply and verify), Q7 (verify email,
document key holders) — and record it in the handover as *defaults applied, owner deferred*.

---

## 4. Things NOT to enforce by default

- **No enforcing Content-Security-Policy.** `Content-Security-Policy-Report-Only` first,
  always — an enforcing CSP shipped without page-by-page testing silently kills page
  builders, analytics, and checkout scripts on a WordPress site (A6).
- **No aggressive `.htaccess`/nginx "hardening" snippets copied from a blog.** The research
  behind this standard is explicit: these break the REST API, the media library, or page
  builders more often than they stop a real attack, and the owner cannot debug what you
  can't explain. Only ship a rule whose exact effect you can state.
- **No table-prefix migration on an existing site.** A risky database operation for a
  near-zero real benefit (§1 theatre) — only relevant on a brand-new install.
- **No hidden login URL as a default.** Bot-noise reduction, not a security control, and a
  lockout risk (Q1) — owner opt-in only, with the recovery path stated up front.
- **No second overlapping security/login/REST-auth plugin, ever**, no matter how the owner
  answers Q3 — one is a floor, two is more attack surface.

If you're about to write a server rule or enable an enforcing control and cannot state in
one sentence exactly what it will and won't break, don't ship it — flag it instead.

---

## 5. Compromise detection — run before building on a site you did not build

Building on an already-backdoored site adds to the backdoor. Run this before any build or
hardening work on an existing site; a **Tier-A signal** means stop.

**Users and access.** `manage_user action=list` — flag any administrator or `shop_manager`
account the owner doesn't recognise or with an implausible `user_registered` date (Tier-A).
Compare a raw `execute_php` count of `wp_users` against the admin user list — malware
sometimes filters the user query to hide its own account. Check for recently changed
`user_email` or `admin_email` with no owner action. Check `wp_usermeta` for unknown
`_application_passwords`, especially on an admin account. Watch for a spike in
`user_registered` dates (mass registration through an open form).

**Scheduled tasks.** `execute_php` to list `wp_options` cron entries (`_transient_doing_cron`
/ the `cron` option, or `wp cron event list` if WP-CLI is reachable). Signals: events with no
matching hook in any installed plugin, obfuscated/random-looking hook names, hourly events
you cannot attribute. A cron entry surviving your cleanup is why a site re-infects.

**File-level signals.** Core integrity: compare `wp-admin/`, `wp-includes/`, and root PHP
files against the official checksums for the installed version — any mismatch is Tier-A.
Recently modified files: `list_directory` + file mtimes under `wp-content` and the web root
for anything modified outside a known update window (attackers sometimes backdate mtime, so
treat this as a lead, not proof). Executable files where they don't belong: any
`.php`/`.phtml`/`.php7` under `wp-content/uploads`, or an `.htaccess` inside uploads
re-enabling PHP execution. Suspicious code patterns (review signals, not proof — legitimate
code occasionally uses these, weight by location): `eval(`, `base64_decode(`, `gzinflate(`,
`str_rot13(`, `create_function(`, `assert(`, `preg_replace` with `/e`, `system(`/`exec(`/
`shell_exec(`/`passthru(`, `$_COOKIE`/`$_POST` passed straight into a callable,
`file_get_contents('http` in a theme, long single-line hex/base64 blobs, `@include` of a
randomly-named file, `FilesMan`/`WSO` strings. These in `wp-includes/`, `functions.php`, an
mu-plugin, or an uploads file are far more serious than in a minified vendor library.
Enumerate every file in `wp-content/mu-plugins/` — always-active, invisible in the plugin
list, no update path, a favourite hiding place. Look for fake plugins: no readme, no
repository entry, a plausible-but-wrong name, a fabricated header, a directory name mimicking
a real plugin. Check `.htaccess`/`wp-config.php` for injected redirect rules,
`auto_prepend_file`, or unexpected `php_value` directives.

**Content and database signals.** Spam posts/pages/comments the owner didn't write
(pharma/gambling/essay-writing keywords), posts with future/very-old dates, huge numbers of
auto-drafts. Search `wp_posts.post_content` and `wp_options.option_value` (via `execute_php`)
for external anchor tags, hidden `display:none` blocks, off-domain `<script src>`. Check
`siteurl`/`home`/`active_plugins`/`template`/`stylesheet` options for unexpected values.
Look for enormous or opaque autoloaded `wp_options` rows — a common malware storage spot.
Check Google Search Console (if the owner has access) for manual actions, and compare the
site's own SERP snippets against what it actually sells — spam served to Googlebot but not
to normal visitors is a classic cloaking tell.

**Behavioural signals.** Unexpected outbound requests to domains that aren't the CDN,
gateway, or update servers — this is how skimmers exfiltrate. A spike in outbound mail volume
(check the host's send counters). Different content for a normal browser vs. a Googlebot/
mobile user-agent (cloaking). Redirects that only fire on first visit or only from a
search-engine referrer.

**On a positive finding:**
1. **Stop.** Do not build, do not "clean up as you go", do not update plugins over the top
   of malware.
2. **Preserve evidence** — copy the suspicious files and a DB snapshot off-site before
   touching anything further.
3. **Tell the owner in plain language** — what was found, whether customer or card data is
   implicated (if so, this may be legally reportable and may require notifying the payment
   processor), and that clean-up needs the entry point *and* every persistence mechanism, not
   just the obvious one.
4. **Do not silently clean it up.** Recommend a specialist clean-up or a rebuild from a
   known-good backup predating the infection, plus a full credential rotation — every user,
   hosting, FTP/SSH, database, gateway keys, salts, application passwords.
5. Only apply this standard once the owner has decided how to proceed.

---

## WooCommerce addendum

Money changes the attacker's motive (direct cash, not just SEO spam), the regulatory scope
(PCI DSS, GDPR), and the blast radius of a mistake (customer card data, not a defaced page).
Run this whenever WooCommerce is active, in addition to §2–§5.

**The card-skimming reality.** The single most common e-commerce skimmer found in the wild
lives in `wp-content/plugins/woocommerce/templates/checkout/form-checkout.php` — a
server-side loader pulling a JS skimmer from a third-party host at render time. A second
family hides in `wp-includes/meta.php` and `wp-includes/vars.php`. The highest-value
integrity check on a store is therefore **file integrity on core, on WooCommerce's template
files, and on anything that renders checkout** — any modification under `wp-includes/` is an
immediate, treat-as-compromised finding, not a maybe.

**Injection points to check on every store:** WooCommerce checkout templates
(`templates/checkout/*` vs. shipped plugin files — any local edit is suspect); theme
`woocommerce/` overrides (legitimate but review line by line); `wp-includes/`/`wp-admin/`
(should be byte-identical to core); `mu-plugins` (enumerate every file); every third-party
`<script src>` on the checkout page; tag managers/analytics on checkout (a GTM container is
a remote-code-execution channel by design — restrict who has access, or exclude checkout);
custom snippets/`functions.php` hooking `woocommerce_checkout_process`,
`woocommerce_after_checkout_validation`, or `wp_footer` touching POST data; order notes/meta
for PAN-shaped strings (a known pattern: a skimmer writing card data into `_order_notes` or
postmeta for later retrieval).

**Payment gateway integrity.** Prefer hosted card fields (Stripe Elements, PayPal, hosted
checkout) — the card number never touches your site's own DOM, which reduces both skimmer
impact and PCI scope. Verify the gateway plugin is genuinely the vendor's, current, with
**live** API keys in `wp-config.php`/environment (`wc_payments` tool), never test keys and
never in an exportable option. Verify webhook endpoints validate signatures — an
unauthenticated order-status webhook is a free-goods vulnerability. Confirm no second,
forgotten gateway plugin is half-configured (`wc_settings`, `manage_plugin action=list`).

**PCI scope, in one paragraph for the owner.** If the store renders card fields itself
(inline card fields), it's in scope for the heavier PCI DSS self-assessment and every script
on the checkout page is in scope with it. If card fields are an iframe/redirect hosted by the
processor, scope drops to the lightest questionnaire. PCI DSS v4.0 requires every script on
the payment page to be inventoried, justified, and integrity-checked — exactly the Magecart
control above. Tell the owner which situation they're in; never attempt to certify
compliance yourself.

**Never logged, stored, or sent** — to logs, order notes, postmeta, debug output, email,
analytics, or any external service: full card number (PAN), expiry + cardholder name
together, **CVV/CVC under any circumstance, ever, even briefly** (absolute PCI prohibition),
track/PIN data, gateway secret keys or webhook signing secrets, customer passwords or reset
tokens, full billing details in third-party analytics without consent. Also: turn off
verbose gateway debug logging in production and delete existing logs — WooCommerce gateway
logs in `wp-content/uploads/wc-logs/` are a known accidental data store and must not be
web-accessible (check with A10's method).

**Order, coupon, and account abuse.** Every coupon needs a usage limit, a per-user limit, and
an expiry (`wc_coupon`) — unlimited-use codes get scraped and posted to deal sites within
hours; never generate predictable codes (`SAVE10`, `SAVE20`). Watch for card-testing: a spike
in failed payments burns gateway fees and reputation — alert on failed-payment rate, not just
successful orders; mitigate with gateway fraud rules, 3-D Secure, checkout rate limiting, and
CAPTCHA. If registration is open, add anti-bot protection and keep `default_role` at
`customer`. Order-tracking/guest-lookup endpoints must require the order key *and* email and
be rate limited — sequential order IDs plus a weak lookup is a customer-data leak. Verify no
filter or snippet can set price from a request parameter; totals must always recalculate
server-side.

**Customer PII.** `wp_users`, `wp_usermeta`, and order tables are a personal-data store.
Restrict Shop Manager/Administrator access — Shop Manager can export all customer data. Set
order data retention in WooCommerce settings rather than keeping personal data forever.
Exports must never write to a public uploads path.

---

## Reporting

Use the same severity vocabulary as `pre-launch-audit`: **Blocker** (stops launch —
security, data loss, legal, or a core flow that does not work), **High**, **Medium**, **Low**,
**Observation**. Do not soften a finding, and do not invent one to look thorough.

Every A1–A20 finding you fix is reported under **what was fixed automatically**, with what
changed and the before/after where relevant. Every Q1–Q7 item goes in the completion report's
**Needs you** section (per `build-standard`), each with the consequence of skipping it stated
plainly — a hidden-login lockout, a payment gateway breaking on an unattended update, an app
integration silently losing its credential. Anything you could not verify (no filesystem
permission visibility, no browser to confirm a header actually reached the client) goes in
**Not verified**, never reported as a pass. A compromise finding (§5) is never silently
resolved — it is reported to the owner before any further work continues.
