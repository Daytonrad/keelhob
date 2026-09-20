# Keelhob Security Standard

**Status:** reference standard for agents building or maintaining WordPress sites via Keelhob.
**Scope:** defensive hardening and auditing of sites the owner controls. No exploit code, no payloads.
**Audience:** a competent but literal agent, working for a beginner site owner.

## How to use this document

1. Before you build anything: run **§6 Detection** (is the site already compromised?).
2. During build: apply every **AUTOMATIC** item in **§2/§5**. These are safe defaults — do them without asking.
3. Before handover: run the **§3 intake questionnaire** (7 questions) for anything that changes how the owner uses their own site. Never impose these silently.
4. If money is involved: add **§4 WooCommerce**.
5. Ship the **§5 definition of done** checklist as part of the handover, with each line marked done/declined.

**Rule zero:** an agent may harden a site without asking. An agent may **not** change how the owner logs in, what breaks, or what it costs, without asking.

---

## 1. The real threat model, ranked

Ranked by how often it actually causes compromises in the wild, not by how scary it sounds. The evidence base is the vulnerability-disclosure data (Patchstack, Wordfence) plus the remediation data from sites that were actually cleaned (Sucuri). Note the two datasets answer different questions — disclosure data says *where bugs are found*, remediation data says *what was found on hacked sites*. They agree on the headline: third-party extensions and stolen/weak credentials dominate; WordPress core almost never does.

### 1.1 Vulnerable, outdated or abandoned plugins (and to a lesser extent themes)

**Evidence.** Patchstack: plugins were responsible for **97%** of all new WordPress vulnerabilities in 2023; themes 3%; core **0.2%** ([Patchstack 2024](https://patchstack.com/whitepaper/state-of-wordpress-security-in-2024/)). In 2024 Patchstack recorded **1,018** vulnerabilities affecting components with 100,000+ installs, and **1,614** vulnerable plugins/themes were closed/removed from the wordpress.org repository — usually *without* the sites running them being told ([Patchstack 2025](https://patchstack.com/whitepaper/state-of-wordpress-security-in-2025/)). Wordfence reports disclosures up **68%** year-on-year in 2024, with roughly **35%** of them still unpatched at time of reporting ([Wordfence 2024 annual report](https://www.wordfence.com/blog/2025/04/2024-annual-wordpress-security-report-by-wordfence/)). Sucuri found **13.97%** of the sites it cleaned in 2023 still had at least one known-vulnerable plugin or theme at remediation time ([Sucuri 2023](https://sucuri.net/reports/2023-hacked-website-report/)).

**Mechanism.** Attackers mass-scan the internet for a fingerprint of a specific vulnerable version, then fire a known exploit. This is fully automated and indiscriminate — small sites are hit as hard as big ones, usually within days of public disclosure.

**Attacker gains.** Depends on the bug class: arbitrary file upload / PHP object injection / unauthenticated option update → remote code execution → a persistent backdoor and full site control. Privilege-escalation bugs → a new admin account.

**What stops it:** keeping everything patched, deleting plugins/themes you do not use (an *inactive* plugin's files are still reachable by a direct HTTP request in many exploit paths), refusing to install abandoned plugins, and a WAF for virtual patching when you cannot patch immediately.

### 1.2 Credentials — weak, reused, stolen, or brute-forced

**Mechanism.** Credential stuffing and password spraying against `/wp-login.php` and `/xmlrpc.php`. XML-RPC is the more efficient target because `system.multicall` lets an attacker test many credential pairs per HTTP request, and because it bypasses login-page-only rate limits. Also: admin passwords stolen from the *owner's own computer* by infostealer malware, and reused passwords from unrelated breaches.

**Attacker gains.** A legitimate admin session — which defeats nearly every file-level hardening measure, because from there the plugin/theme editor or a plugin upload is a legitimate feature.

**What stops it:** unique strong passwords, 2FA on admin accounts, login rate limiting, no user named `admin`, blocking user enumeration so the attacker does not know which usernames to spray.

### 1.3 Post-compromise persistence: backdoors

**Evidence.** **49.21%** of compromised sites Sucuri cleaned in 2023 contained at least one backdoor; **2.14%** had a malicious cron job dropping further malware ([Sucuri 2023](https://sucuri.net/reports/2023-hacked-website-report/)).

**Mechanism.** Not an initial vector but the reason compromises recur. After the first entry the attacker plants uploaders, web shells, tiny RCE stubs (sometimes <100 bytes) in legitimate-looking files, fake plugins (**4.18%** of cleaned sites), rogue admin users, malicious WP-Cron events, and rogue application passwords. Patch the original hole and the site still gets re-hacked.

**What stops it:** never trust a site that was compromised — enumerate persistence (§6) rather than only fixing the entry point. File integrity monitoring.

### 1.4 Nulled / pirated premium plugins and themes, and fake plugins

**Mechanism.** Pirated premium extensions are the single most reliable way to get a backdoor installed voluntarily. Fake plugins with plausible names hide in `wp-content/plugins` and never appear in the plugin list.

**What stops it:** only install from wordpress.org, the vendor directly, or a licensed marketplace. Reject nulled software outright — this is a hard rule, not a preference to ask about.

### 1.5 Misconfiguration and over-exposure (this is the failure mode Keelhob caused)

**Mechanism.** No exploit needed. Public registration left open with a non-Subscriber default role; the plugin/theme file editor enabled; a static-key "preview"/debug endpoint left in the code; multiple overlapping REST-auth plugins each widening the auth surface; directory listing on; database dumps or `.git` left in the web root; debug output written to a publicly readable `debug.log`. Each turns a nuisance into a takeover.

**Attacker gains.** Direct authenticated access, or the information needed to get it, with zero skill required.

**What stops it:** §2, in full, applied at build time. This is what this document exists for.

### 1.6 Insecure file uploads and PHP execution in `wp-content/uploads`

**Mechanism.** A plugin's upload handler fails to validate type/extension, or an existing compromise drops a `.php` file into uploads. If the web server will execute PHP from `uploads/`, that file is a web shell reachable by URL.

**What stops it:** deny PHP execution under `uploads/` at the server level, plus §1.1 patching.

### 1.7 Privilege escalation via misconfigured roles and capabilities

**Mechanism.** `users_can_register` on with `default_role` set to something above Subscriber; a plugin that grants `edit_files`/`manage_options` to a customer-facing role; contributor-level accounts — Wordfence found contributor-level access was the most common prerequisite for exploitation in 2024, at **34%** of disclosed vulnerabilities. Contributor/subscriber accounts are not "harmless".

**What stops it:** least-privilege role audit; treat any self-registerable role above Subscriber as a finding.

### 1.8 Supply chain

**Mechanism.** A legitimate plugin's release or update channel is compromised, or a maintainer sells the plugin to a bad actor and pushes malware to the existing install base. Also: third-party JS loaded from a CDN or marketing tag that is later altered — the same mechanism as Magecart card skimming.

**What stops it:** minimise the number of extensions, prefer maintained ones, minimise third-party front-end scripts especially on checkout, keep integrity monitoring on.

### 1.9 Hosting/server-layer and shared-hosting cross-contamination

**Mechanism.** One infected site on a shared account infects its neighbours through a common parent directory; outdated PHP; stolen FTP/cPanel credentials. Largely outside an agent's control but must be flagged to the owner.

### What attackers actually do once in (why this matters to the owner)

- **SEO spam** — detected on **20.30%** of infected sites; **38.3%** of compromised *databases* contained SEO spam, mostly hidden pharma/gambling links. Gambling spam detections rose **200%** year-on-year (Sucuri 2023). This is the most common monetisation, and it destroys search rankings.
- **Malicious redirects / drive-by malware** — SocGholish (fake-browser-update) detected on 143,242 sites; Balada Injector on 135,309 in 2023 (Sucuri).
- **Phishing pages** hosted on the victim's domain — **5.06%** of compromised sites.
- **Card skimming** — see §4.
- **Backdoors** for resale of access — see §1.3.

### Ranked by risk-reduction-per-unit-effort (what to actually do first)

1. Patch and remove unused plugins/themes.
2. Strong unique admin credentials + 2FA + login rate limiting.
3. Close the misconfigurations in §2 (registration, file editor, debug/preview endpoints, exposed files).
4. Block PHP execution in uploads.
5. HTTPS everywhere + secure cookies + security headers.
6. Backups you have actually tested restoring.
7. Monitoring/integrity checking.

### Security theatre — do not spend budget here

Be honest with the owner about these. Doing them is not harmful (mostly), but presenting them as "the site is now secure" is a lie.

- **Hiding the WordPress version.** Scanners fingerprint by asset hashes and file presence, not the generator tag. Near-zero value on its own. Cheap, so do it, but never count it.
- **Changing the `wp_` table prefix on an existing site.** The official docs note it can block *some* published SQL-injection payloads that hardcode `wp_`, but modern injection is usually via `$wpdb->prefix`. On a **new** install pick a custom prefix (free). On an **existing** install, migrating the prefix is a genuinely risky database operation with serialised-data and options-key breakage — the risk exceeds the benefit. Do not do it retroactively.
- **Renaming the login URL as a security control.** It cuts bot noise and log spam. It does not stop a targeted attacker, and it is not a substitute for 2FA or rate limiting. See §3.
- **Blocking `/wp-json/` entirely** because "the REST API is dangerous". The REST API is core infrastructure; blocking it breaks the block editor and many plugins. Restrict the *users* endpoint instead.
- **Removing `readme.html` / disabling `?ver=` query strings** as "hardening". Version disclosure alone is information, not a vulnerability. Do it, cost nothing, claim nothing.
- **`.htaccess` "ultimate security" snippets copied from blogs.** Many break the REST API, the media library, or page builders, and the owner cannot debug them. Only apply rules you can state the effect of.
- **Security plugins with everything switched on.** Overlapping WAFs, two 2FA plugins, three REST-auth plugins — this is what produced the audited failure. More security plugins is not more security; it is more attack surface and more breakage. One security plugin, maximum.

The WordPress project itself states security through obscurity "is generally an unsound primary strategy" ([Hardening WordPress](https://developer.wordpress.org/advanced-administration/security/hardening/)).

---

## 2. AUTOMATIC checks — do these without asking

These do not change how the owner uses the site. Each entry: **detect → fix → blast radius if wrong**.

> Convention: `wp-config.php` constants go **above** the `/* That's all, stop editing! */` line. Web-server rules assume Apache `.htaccess`; on nginx the equivalent goes in the server block and the agent must say so rather than silently writing an ignored `.htaccess`.

### A1. Plugin/theme file editor disabled
- **Detect:** `DISALLOW_FILE_EDIT` not defined/true in `wp-config.php`; Appearance → Theme File Editor present.
- **Fix:** `define( 'DISALLOW_FILE_EDIT', true );`
- **Why:** WordPress's own docs call the editor "the first tool an attacker will use if able to login, since it allows code execution."
- **Blast radius:** low. A developer who edits theme files from the dashboard loses that (they should use SFTP/deploy anyway). Reversible instantly.

### A2. Public registration closed unless the site needs it
- **Detect:** option `users_can_register = 1` while the site has no membership/customer/community feature; or `default_role` above `subscriber`.
- **Fix:** set `users_can_register = 0`. If registration IS needed (WooCommerce customer accounts, membership), keep it on but force `default_role = subscriber`/`customer` and add spam-registration protection.
- **Blast radius:** **medium — verify first.** Closing registration on a store with customer accounts breaks signups. Check for WooCommerce/membership/LMS/forum plugins before flipping it. `default_role` above Subscriber is *always* a finding.

### A3. No user named `admin`, `administrator`, `webmaster`, or the domain name
- **Detect:** query `wp_users.user_login`.
- **Fix:** create a new admin with an unpredictable username, reassign content, delete the old. Never `UPDATE wp_users` blindly on a live site without checking for hardcoded references.
- **Blast radius:** medium — the owner's login name changes. Tell them, in writing, before doing it, and confirm they can log in with the new one before deleting the old.

### A4. User enumeration closed
- **Detect:** `https://site/?author=1` redirects to `/author/<username>/`; `https://site/wp-json/wp/v2/users` returns names/slugs to an unauthenticated request; `/wp-sitemap-users-1.xml` present.
- **Fix:** block unauthenticated `?author=N` redirects; filter `rest_endpoints` to require `list_users` on the users collection; remove the users sitemap provider.
- **Blast radius:** low-medium. Author archive pages still work by slug. If the theme genuinely lists authors via the REST API on the front end (rare), test it.

### A5. HTTPS enforced everywhere
- **Detect:** `siteurl`/`home` not `https://`; HTTP does not 301 to HTTPS; mixed-content warnings; no HSTS.
- **Fix:** correct both options, add a canonical redirect, fix mixed content, define `FORCE_SSL_ADMIN`. Add HSTS **only after** confirming HTTPS works on all hostnames — start `max-age` low (e.g. 300) then raise.
- **Blast radius:** **high if the certificate is broken.** HSTS with a long max-age on a misconfigured cert locks visitors out of the site in their browser for the duration and cannot be undone remotely. Verify the cert covers `www` and apex first. Never preload without asking.

### A6. Security headers present
- **Detect:** fetch the home page, inspect response headers.
- **Fix (safe defaults):**
  - `Strict-Transport-Security: max-age=31536000; includeSubDomains` (after A5 verified)
  - `X-Content-Type-Options: nosniff`
  - `Referrer-Policy: strict-origin-when-cross-origin`
  - `X-Frame-Options: SAMEORIGIN` (or CSP `frame-ancestors 'self'`)
  - `Permissions-Policy` denying unused features (`geolocation=(), camera=(), microphone=()`)
  - `Content-Security-Policy-Report-Only` first — **never** ship an enforcing CSP on a WordPress site you have not tested page by page.
- **Blast radius:** nosniff/Referrer-Policy/Permissions-Policy: near zero. `X-Frame-Options`: breaks legitimate embedding (some page-builder previews, some payment iframes hosted by *you*). **Enforcing CSP: high** — silently kills page builders, analytics, and checkout scripts. Report-Only until proven.

### A7. Auth cookies Secure + HttpOnly (+ SameSite)
- **Detect:** log-in cookie flags on the `wordpress_logged_in_*` cookie.
- **Fix:** ensure the site is fully HTTPS (WP sets Secure automatically for HTTPS sites); confirm `HttpOnly`. Set `SameSite=Lax` at the server/PHP level if not present.
- **Blast radius:** low at `Lax`. **`SameSite=Strict` is not safe by default** — it breaks flows where the user returns from an external payment gateway or an emailed link and appears logged out. Use `Lax`.

### A8. Unique, present salts and keys
- **Detect:** `wp-config.php` still contains `put your unique phrase here`, or duplicated/short keys.
- **Fix:** regenerate all 8 from `https://api.wordpress.org/secret-key/1.1/salt/`.
- **Blast radius:** **all logged-in sessions are invalidated** (including the owner's and the agent's). Passwords are unaffected. Do it early, and warn.

### A9. `WP_DEBUG` off, no public `debug.log`
- **Detect:** `WP_DEBUG`/`WP_DEBUG_DISPLAY` true on production; `wp-content/debug.log` fetchable over HTTP; PHP errors visible in page output.
- **Fix:** `WP_DEBUG false`, `WP_DEBUG_DISPLAY false`; if logging is needed, `WP_DEBUG_LOG` to a path outside the web root; delete any existing public `debug.log`; deny it at the server.
- **Blast radius:** none. Error output leaks paths, queries, and sometimes credentials.

### A10. No exposed sensitive files
- **Detect:** HTTP GET each of: `/.git/config`, `/.env`, `/wp-config.php.bak|.old|.save|~|.txt`, `/*.sql`, `/*.sql.gz`, `/backup*.zip`, `/wp-content/uploads/*.sql`, `/error_log`, `/.DS_Store`, `/composer.json`, `/package.json`, editor swap files. Anything that returns 200 is a finding.
- **Fix:** delete it (after archiving off-site if it is a real backup), and add server-level deny rules.
- **Blast radius:** none if you verify it is not a live asset first. A `.sql` dump in the web root is a full credential-and-customer-data breach waiting to be indexed. Treat as **critical**.

### A11. Directory listing disabled
- **Detect:** request `/wp-content/uploads/` and `/wp-includes/` — a file index means listing is on.
- **Fix:** `Options -Indexes` (Apache) / `autoindex off` (nginx). Ensure `index.php`/`index.html` placeholders exist in plugin/upload dirs.
- **Blast radius:** none.

### A12. `wp-config.php` and file permissions correct
- **Detect:** `wp-config.php` world-readable; directories not `755`, files not `644`; anything `777`.
- **Fix:** directories `755`, files `644`, `wp-config.php` `440` or `400` ([WP hardening docs](https://developer.wordpress.org/advanced-administration/security/hardening/)); deny `wp-config.php` at the server.
- **Blast radius:** **medium-high.** Over-tightening can break the media library, updates, or caching plugins that need writable dirs. Never chmod recursively without checking what the host requires. On some managed hosts permissions are enforced by the platform — do not fight it.

### A13. `readme.html`, `license.txt`, version disclosure removed
- **Detect:** `/readme.html` returns 200; `<meta name="generator">` shows version; `?ver=` on core assets.
- **Fix:** delete/deny `readme.html` and `license.txt`; remove the generator tag.
- **Blast radius:** none. **But label this cosmetic** (see §1 theatre) — it does not reduce real risk. Core updates recreate `readme.html`; a deny rule is more durable than deleting it.

### A14. Everything up to date; nothing unused installed
- **Detect:** list core/plugin/theme versions vs available updates; flag any plugin/theme not updated by its author in 12+ months or removed from the repository; list all deactivated plugins and all unused themes.
- **Fix:** update core, plugins, themes. **Delete** — not deactivate — unused plugins and themes; keep exactly one fallback default theme (the current Twenty*) alongside the active one. Replace abandoned plugins.
- **Blast radius:** **high — this is the one that breaks live sites.** Back up first, prefer staging, update one major plugin at a time, verify checkout/forms afterwards. Deleting a plugin usually deletes its settings — confirm it is genuinely unused (search the DB for its options and shortcodes) before deleting.

### A15. Application passwords and REST auth surface audited
- **Detect:** enumerate `wp_usermeta` key `_application_passwords` — list every app password, its name, and last-used date. Inventory every plugin providing REST/JWT/API-key authentication.
- **Fix:** revoke app passwords that are unused, unrecognised, or belong to a departed integration. **Remove redundant REST-auth plugins — keep at most one.** Ensure app passwords are only issued to accounts with the minimum role needed.
- **Blast radius:** **high** — revoking an app password breaks whatever integration uses it (mobile app, Keelhob itself, a sync service). Identify the owner of each credential before revoking; never revoke your own live connection.

### A16. No static-key / hardcoded-secret endpoints in custom code
- **Detect:** grep custom snippets, mu-plugins and theme files for hardcoded tokens compared with `==`, `$_GET`-gated preview/debug/maintenance bypasses, `if ($_GET['key'] === '...')`, and any `add_action('init', ...)` that authenticates from a URL parameter.
- **Fix:** remove it. If a preview mechanism is genuinely required, use WordPress capabilities and nonces, or a time-limited signed token compared with `hash_equals()`.
- **Blast radius:** medium — removing it breaks whatever workflow relied on it, so replace before removing. **This is a backdoor by any other name and is non-negotiable at handover.**

### A17. XML-RPC exposure measured (measure automatically, decide with the owner)
- **Detect:** POST-probe `/xmlrpc.php` for a 200; check whether Jetpack, the WordPress mobile app, or any pingback workflow is in use.
- **Automatic part:** disable **pingbacks** (`xmlrpc_methods` minus `pingback.ping`) — pingback is used for DDoS reflection and internal port probing and almost no modern site wants it. Disable `system.multicall` amplification if the host allows.
- **Owner decision:** turning `/xmlrpc.php` off entirely → §3 Q4.

### A18. Uploads directory hygiene
- **Detect:** list any `.php`, `.phtml`, `.php5`, `.htaccess`, `.sh`, `.pl` file under `wp-content/uploads`. Legitimate uploads never contain executable PHP.
- **Fix:** quarantine and investigate any hit (this is also a §6 compromise indicator). Blocking execution site-wide is §3 Q6.
- **Blast radius:** listing is read-only. Deleting a file some plugin generated (rare, but some caching/gallery plugins write PHP into uploads) can break that plugin — inspect before deleting.

### A19. Custom code follows WordPress security APIs
Applies to every snippet/template Keelhob itself writes:
- Every state-changing request checks a nonce **and** `current_user_can()` — a nonce alone is CSRF protection, not authorisation.
- All DB access via `$wpdb->prepare()`. No string-concatenated SQL.
- Output escaped at the point of output: `esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses_post()`.
- Input sanitised on the way in; never trust `$_GET`/`$_POST`/`$_SERVER`.
- File uploads validated with `wp_check_filetype_and_ext()`; never trust the client-supplied extension or MIME.
- No `eval()`, no `unserialize()` on user input (PHP object injection is a real, actively exploited WordPress bug class), no `extract()`.
- Secrets in `wp-config.php` constants or environment, never in post content, options that get exported, or committed files.
- Any `unfiltered_html`/`edit_files` capability grant is a finding.
**Blast radius:** none — this is how the code should have been written.

### A20. Backups exist and are off-site
- **Detect:** is there a backup mechanism? Is a backup archive sitting in the web root (see A10)?
- **Fix:** ensure backups run to off-site storage, and that a restore has been tested at least once.
- **Blast radius:** none. Backups stored *on the same server, in the web root* are simultaneously the #1 breach vector and no protection at all.

**Count: 20 AUTOMATIC checks.**

---

## 3. NEEDS-OWNER-DECISION — the intake questionnaire

Everything below changes how the owner (or their visitors) uses the site. The agent **must** ask, must record the answer, and must state the default it will apply if the owner says "you decide".

Ask these **seven** questions. Ask them in plain language. Do not paste the rationale unless asked — keep it available as a follow-up.

---

### Q1 — Logging in: how much protection do you want on your admin account?

> "Right now, anyone in the world can reach your login page and try passwords. That's normal, but it means bots hammer it constantly. I can add protections. Which of these sounds right?
>
> **A) Standard (recommended).** Two-factor authentication on your admin account — you enter a 6-digit code from your phone after your password — plus an automatic lockout after several failed password attempts.
> **B) Standard, plus hide the login page.** Same as A, but your login moves from `/wp-login.php` to a secret address only you know. This mostly cuts bot traffic; it doesn't stop a determined attacker. **If you lose that address you can't log in** — I'd email it to you and write it in your handover document, and your host can always restore the normal login if you're locked out.
> **C) Minimal.** Just a strong password. Fastest, least safe.
>
> A couple of details either way:
> - Lockouts can lock **you** out if you mistype your own password several times. It clears itself after a set time (I'd use 20 minutes), and I'll make sure you have a recovery route.
> - Two-factor needs a phone app (Google Authenticator, 1Password, etc.). If you lose your phone, recovery codes get you back in — **save them somewhere that isn't your website.**"

**Covers:** 2FA, login rate limiting/lockout, login URL renaming.
**Default if "you decide":** A. 2FA on all administrator accounts, lockout after 5 failed attempts with a 20-minute cooldown and an allowlist for the owner's IP where practical, login URL left at `/wp-login.php`.
**Why not B by default:** hidden login URLs generate support incidents and lock-outs, and the real protection is 2FA. Bookmarks, saved passwords, and any documentation referencing `/wp-admin` break.
**Safe recovery path (mandatory if B is chosen):** (1) the secret URL is written in the handover doc and emailed; (2) the plugin providing it can be disabled by renaming its folder over SFTP/host file manager, which restores `/wp-login.php`; (3) the host's control panel can always reset a password. State all three to the owner *before* enabling.

---

### Q2 — Updates: should the site update itself?

> "WordPress and its add-ons release security fixes constantly. Most sites get hacked through an old add-on that had a known fix available. But an automatic update can occasionally break a page or a checkout while you're not looking. Which do you want?
>
> **A) Security-first (recommended for most sites).** Security patches install automatically, larger feature updates wait for a human.
> **B) Everything automatic.** Safest against hackers, highest chance of a surprise.
> **C) Nothing automatic.** You or I update manually on a schedule — this only works if it *actually happens*. Who's doing it, and how often?"

**Default if "you decide":** A — core minor/security auto-updates on, plugin/theme auto-updates on **except** for the payment gateway, the page builder, and the theme, which are updated manually with a backup and a checkout test. **On a live store handling orders, always ask; never assume B.**
**Trade-off to state plainly:** the risk of an update breaking a store for a few hours is real; the risk of an unpatched plugin is a rebuild plus a Google "this site may be hacked" label. Auto-updates win on expectation unless there is a staging site and a person who genuinely patches weekly.

---

### Q3 — Do you want a security plugin or firewall, and who maintains it?

> "A firewall blocks known attacks before they reach your site, and can 'virtually patch' a vulnerable add-on before the fix arrives. Options:
>
> **A) Free plugin firewall** (e.g. Wordfence free, Solid Security free) — no cost, runs on your server, adds some load, sends you alerts. New attack rules arrive on a delay on free tiers.
> **B) Paid / network firewall** (Wordfence Premium, Sucuri, Cloudflare WAF) — roughly $10–25/month; blocks attacks before they hit your server; real-time rules.
> **C) Nothing extra** — rely on updates and the hardening I'm doing.
>
> Two things matter more than which you pick: **someone has to read the alerts**, and **one is enough** — running two firewalls or two login-security plugins makes things worse, not better. Who is going to be the person receiving those emails?"

**Default if "you decide":** A, one plugin only, alerts to the owner's email, plus Cloudflare in front if the domain's DNS is already there (free tier, no extra cost). Never install a second overlapping security plugin.
**Say the cost out loud.** Never enable a paid tier without explicit approval.

---

### Q4 — Do you use the WordPress mobile app, Jetpack, or any external tool that posts to your site?

> "There's an old interface in WordPress called XML-RPC. Attackers use it to guess passwords very quickly, so turning it off is a real security win — but it's also what the official WordPress mobile app, Jetpack, and some publishing tools use to connect. Do you use any of those? If you're not sure, I can check what's connected."

**Covers:** XML-RPC disable.
**Default if "you decide":** if Jetpack and the mobile app are not in use → disable `/xmlrpc.php` entirely. If they are in use → keep it, disable pingbacks, and rate-limit it.
**What breaks if wrong:** the mobile app and Jetpack features stop connecting, some remote publishing tools fail, trackbacks/pingbacks from other blogs stop. All reversible in seconds.

---

### Q5 — Is anything else connected to your website's data?

> "Some setups let outside apps read and write your site through its API — a mobile app, a separate front-end, a booking or CRM integration, or certain page builders. I want to lock down that door for anonymous visitors, which is safer, but I need to know if something legitimate is using it. Is there anything connected — an app, another website pulling your content, an automation like Zapier or Make?"

**Covers:** REST API access for unauthenticated requests; application passwords; who holds API credentials.
**Default if "you decide":** keep the REST API on (core needs it) but require authentication for the **users** endpoint and any custom endpoint that exposes personal data; leave post/page endpoints public (the block editor and many themes depend on them). Revoke unrecognised application passwords after confirming with the owner. **Do not blanket-block `/wp-json/`** — it breaks the editor and many page builders.

---

### Q6 — Does anything on your site generate or run code out of the uploads folder?

> "Your uploads folder should only ever hold images, PDFs and videos. I'd like to tell the server to refuse to *run* anything from there, which shuts down one of the most common ways a hacked site keeps itself hacked. It's usually invisible. Very occasionally a caching or gallery plugin writes program files there and would stop working — I'll test after making the change."

**Covers:** blocking PHP execution in `wp-content/uploads`.
**Default if "you decide":** apply it, then verify uploads, the media library, and any gallery/caching plugin still work. Roll back immediately if anything breaks.
**What breaks:** a small number of caching plugins (advanced-cache variants), some PDF/gallery tools, and certain "protected file" plugins that route downloads through PHP in uploads.

---

### Q7 — If you lose access, who gets you back in?

> "Two things I need from you before I finish:
> 1. **The admin email on the site** — is it correct, is it an inbox you actually read, and is it *different* from an address hosted on this same domain? If your site goes down and your email is on the same domain, password resets can go down with it.
> 2. **Who holds the keys?** Your hosting login, your domain registrar login, and your two-factor recovery codes. If it's just you, store them in a password manager, not in a file on the site. If someone else (an agency, a developer) holds them, they can undo anything I've set up — and they need to know about the changes."

**Covers:** admin email validity, 2FA recovery custody, account ownership, offboarding.
**Default if "you decide":** verify the admin email deliverability, recommend an off-domain address (Gmail/Outlook) for recovery, and list every account with administrator access in the handover so the owner can see who has power over the site.
**Non-negotiable:** the agent never keeps sole custody of a credential, and every credential the agent created is disclosed at handover.

---

**Count: 7 NEEDS-OWNER-DECISION questions, covering 11 distinct controls** (2FA, login lockout, login URL renaming, auto-updates, security plugin/WAF choice, WAF cost, XML-RPC, REST API restriction, application-password revocation, uploads PHP execution, admin email/key custody).

**If the owner says "you decide" to everything**, apply: Q1-A, Q2-A, Q3-A, Q4 (disable if unused), Q5 (users endpoint locked, rest open), Q6 (apply and verify), Q7 (verify email, document key holders). Record this in the handover as *defaults applied, owner deferred*.

---

## 4. WooCommerce and e-commerce specifics

Money changes three things: the attacker's motive (direct cash, not just SEO spam), the regulatory scope (PCI DSS, GDPR), and the blast radius of a mistake (customer card data, not a defaced page).

### 4.1 The card-skimming reality

Sucuri found **1.34%** of all infected sites contained a credit card skimmer, and among sites with e-commerce malware the most common single skimmer was the **WooCommerce `form-checkout` skimmer** at **37.5%**, lodged in `wp-content/plugins/woocommerce/templates/checkout/form-checkout.php` — a server-side PHP loader that pulls a JavaScript skimmer from a third-party server at page render. A second family hides in `wp-includes/meta.php` and `wp-includes/vars.php` and exfiltrates by both email and HTTP POST ([Sucuri 2023](https://sucuri.net/reports/2023-hacked-website-report/)).

**Implication for the agent:** the highest-value integrity check on a WooCommerce site is *file integrity on core, on the WooCommerce plugin's template files, and on anything that renders checkout*. Modified files under `wp-includes/` are an immediate, treat-as-compromised finding.

### 4.2 Injection points to check on every store

| Injection point | Check |
|---|---|
| WooCommerce checkout templates | Compare `templates/checkout/*` against the shipped plugin files — any local modification is suspect. |
| Theme checkout overrides | `yourtheme/woocommerce/` overrides are legitimate but must be reviewed line by line. |
| `wp-includes/` and `wp-admin/` | Should be byte-identical to core. Any diff = incident. |
| MU-plugins (`wp-content/mu-plugins/`) | Always active, never shown in the plugin list, no update mechanism. Enumerate every file. |
| Third-party front-end scripts | Every `<script src>` on the checkout page. Each one can read the card form. Minimise ruthlessly. |
| Tag managers / analytics on checkout | A GTM container is a remote code execution channel by design. Anyone with GTM access can skim. Restrict who has it, or exclude checkout. |
| Custom snippets and `functions.php` | Look for hooks on `woocommerce_checkout_process`, `woocommerce_after_checkout_validation`, `wp_footer` that touch POST data. |
| Order notes / meta | A skimmer that writes card data into `_order_notes` or postmeta for later retrieval is a known pattern — search order meta for PAN-shaped strings. |

### 4.3 Payment gateway integrity

- Prefer gateways where the card fields are hosted by the processor (Stripe Elements, PayPal, hosted checkout) — the card number never touches the site's DOM in a form the site controls, which massively reduces both skimmer impact and PCI scope.
- Verify the gateway plugin is the genuine vendor plugin, current version, and that its API keys are **live** keys in `wp-config.php`/environment, not test keys and not stored in options that get exported.
- Verify webhook endpoints validate signatures. An unauthenticated order-status webhook is a free-goods vulnerability.
- Confirm no second, forgotten gateway plugin is installed and half-configured.

### 4.4 PCI scope in one paragraph the owner can understand

If the store takes card details in fields rendered by the site itself (direct/inline card fields), the site is in scope for the heavier PCI DSS self-assessment (SAQ A-EP or D) and every script on the checkout page is in scope with it. If the card fields are an iframe/redirect hosted by the processor, scope drops to the lightest questionnaire (SAQ A). **PCI DSS v4.0 requires scripts on the payment page to be inventoried, justified, and integrity-checked** — which is exactly the Magecart control. Tell the owner which situation they are in; do not attempt to certify compliance.

### 4.5 What must never be logged, stored, or sent

Never write to logs, order notes, postmeta, debug output, email, analytics, or any external service:
- Full card number (PAN), expiry, cardholder name in combination with PAN.
- **CVV/CVC — never stored, under any circumstance, even encrypted, even temporarily.** This is an absolute PCI prohibition.
- Full magnetic-stripe/track data, PIN.
- Gateway secret keys, webhook signing secrets, API tokens.
- Customer passwords or password-reset tokens.
- Full billing addresses or emails in third-party analytics without consent.

Also: turn off verbose gateway logging (`WooCommerce → Status → Logs`) on production after debugging, and delete the logs — WooCommerce gateway debug logs are a known accidental data store and they live in `wp-content/uploads/wc-logs/`, which must not be web-accessible.

### 4.6 Order, coupon and account abuse

- **Coupons:** set usage limits per coupon and **per user**, expiry dates, minimum spend, and product/category restrictions. Unlimited-use, no-expiry, guessable coupon codes get scraped and posted to deal sites within hours. Do not generate coupon codes from predictable patterns (`SAVE10`, `SAVE20`, …) — they are enumerable.
- **Card testing / fake orders:** bots place many small orders to validate stolen cards, which shows up as a spike in failed payments and burns gateway fees and reputation. Mitigations: gateway-side fraud rules and 3-D Secure, rate limiting on checkout, CAPTCHA on checkout/registration, and blocking obviously scripted traffic at the WAF. Alert on failed-payment rate, not just successful orders.
- **Account/registration spam:** if customer registration is open (it usually must be), add anti-bot protection on the account creation form and keep `default_role` at `customer`.
- **Refund/return abuse and guest-checkout order lookups:** ensure order-tracking endpoints require the order key *and* email, and are rate limited — sequential order IDs plus a weak lookup is a customer-data leak.
- **Stock/price manipulation:** verify no filter or snippet can set price from a request parameter; recalculate totals server-side always.

### 4.7 Customer PII

- The `wp_users`, `wp_usermeta`, and order tables are a personal-data store: names, addresses, phone numbers, purchase history. A `.sql` dump in the web root (A10) is a reportable data breach.
- Restrict who has Shop Manager/Administrator. Shop Manager can export all customer data.
- Set order data retention (WooCommerce → Settings → Accounts & Privacy) rather than keeping personal data forever.
- Exports (`WooCommerce → Reports`, CSV exporters) must not write to a public uploads path.

---

## 5. Definition of done — security

Ordered by risk reduction. Every line is verifiable. `[A]` = AUTOMATIC (agent just does it). `[D]` = NEEDS-OWNER-DECISION.

### Tier 1 — must be true before handover, no exceptions
1. `[A]` Compromise scan (§6) run and clean, or the compromise declared to the owner before any build work.
2. `[A]` Core, all plugins, all themes on latest versions; zero known-vulnerable components installed.
3. `[A]` Zero abandoned components (no author update in 12+ months, or removed from the repository).
4. `[A]` All unused plugins **deleted**; all unused themes deleted except one current default fallback.
5. `[A]` No nulled/pirated plugin or theme present. (Hard rule — never negotiable.)
6. `[A]` `DISALLOW_FILE_EDIT` = true and the Theme/Plugin File Editor is absent from the dashboard.
7. `[A]` No hardcoded-token / URL-parameter bypass endpoints anywhere in custom code, snippets, mu-plugins, or theme.
8. `[A]` No user named `admin`/`administrator`/`webmaster`; every administrator account is expected and named in the handover.
9. `[A]` `users_can_register` off unless a feature requires it; `default_role` is `subscriber` or `customer`, never higher.
10. `[A]` Every administrator password is unique, 16+ chars, generated, and stored in a password manager — not in a file on the site.
11. `[A]` No `.sql`, `.zip` backup, `.git`, `.env`, `wp-config` backup, or `error_log`/`debug.log` reachable over HTTP (verified by actual request, not by looking at the directory).
12. `[A]` `WP_DEBUG` and `WP_DEBUG_DISPLAY` false in production.
13. `[A]` HTTPS enforced; HTTP 301s to HTTPS; no mixed content; `FORCE_SSL_ADMIN` true.
14. `[A]` All 8 salts/keys unique and non-default.
15. `[A]` Application passwords enumerated; every one is recognised and attributed; unknown ones revoked.
16. `[A]` At most **one** security plugin and at most **one** REST/API-auth plugin installed.
17. `[A]` Off-site backups configured and one restore verified.

### Tier 2 — apply unless there's a documented reason not to
18. `[A]` User enumeration blocked: `?author=N`, `wp-json/wp/v2/users`, users sitemap.
19. `[A]` Security headers set: HSTS, `nosniff`, `Referrer-Policy`, frame-ancestors, `Permissions-Policy`. CSP in Report-Only if present at all.
20. `[A]` Auth cookies `Secure` + `HttpOnly` + `SameSite=Lax`.
21. `[A]` Directory listing off for `/wp-content/uploads/` and `/wp-includes/` (verified by request).
22. `[A]` File permissions: dirs 755, files 644, `wp-config.php` 440/400; nothing 777.
23. `[A]` `wp-config.php` denied at the web-server level.
24. `[A]` XML-RPC pingback method disabled.
25. `[A]` No executable file types present under `wp-content/uploads`.
26. `[A]` All custom code passes §A19 (nonce + capability check, `$wpdb->prepare`, escaped output, no `eval`/`unserialize` on input).
27. `[A]` Role/capability audit: no non-admin role holds `edit_files`, `manage_options`, `unfiltered_html`, or `install_plugins`.
28. `[A]` `readme.html`/`license.txt`/generator version removed. *(Cosmetic — do it, don't count it.)*

### Tier 3 — owner's call, recorded in writing
29. `[D]` 2FA enabled on every administrator account. *(Q1)*
30. `[D]` Login attempt limiting/lockout configured, with a stated recovery path. *(Q1)*
31. `[D]` Login URL renamed — only if chosen, with the URL emailed, documented, and the recovery route explained. *(Q1)*
32. `[D]` Auto-update policy decided and configured; exclusions (gateway, builder, theme) named. *(Q2)*
33. `[D]` Security plugin / WAF chosen, installed once, alert recipient named, cost approved. *(Q3)*
34. `[D]` XML-RPC fully disabled, or explicitly kept for Jetpack/mobile. *(Q4)*
35. `[D]` REST API restrictions applied to the extent the connected integrations allow. *(Q5)*
36. `[D]` PHP execution blocked in `wp-content/uploads`, and uploads/media verified working after. *(Q6)*
37. `[D]` Admin email verified, deliverable, and ideally off-domain; 2FA recovery codes in the owner's custody. *(Q7)*
38. `[D]` All administrator accounts and every credential the agent created listed in the handover document. *(Q7)*

### Tier 4 — WooCommerce only
39. `[A]` WooCommerce core template files unmodified; theme `woocommerce/` overrides reviewed line by line.
40. `[A]` `wp-includes/` and `wp-admin/` byte-identical to core.
41. `[A]` Full script inventory of the checkout page; every third-party script justified or removed.
42. `[A]` Gateway plugin genuine, current, keys in config/env, webhook signatures validated.
43. `[A]` No PAN/CVV/secret key present in logs, order notes, order meta, or debug output (searched, not assumed).
44. `[A]` Gateway debug logging off in production; `wc-logs/` not web-accessible.
45. `[A]` Every coupon has a usage limit, a per-user limit, and an expiry.
46. `[A]` Order-lookup/tracking endpoints require order key + email and are rate limited.
47. `[D]` Anti-bot protection on checkout and customer registration (has a UX cost — ask). *(fold into Q3)*
48. `[A]` PCI scope stated to the owner (hosted fields vs inline fields) in plain language.

**Totals: 20 automatic checks in §2 → 38 automatic and 10 owner-decision lines here (a few automatic checks expand into multiple verifiable lines).**

---

## 6. Detection — is the site already compromised?

Run this **before** touching anything. If any Tier-A signal fires, stop building and tell the owner: hardening a compromised site does not clean it, and building on one means shipping the attacker's backdoor.

### 6.1 Users and access
- **Unexpected administrators.** List every user with `administrator` (and `shop_manager`) role, with `user_registered` date. Any account the owner does not recognise, or registered outside a plausible window, is a Tier-A signal.
- **Users invisible in the UI.** Compare `SELECT COUNT(*) FROM wp_users` against the admin user list — malware sometimes hides its account by filtering the user query.
- **Recently changed admin emails.** `wp_users.user_email` changed, or `admin_email` option changed, with no owner action.
- **Unknown application passwords** in `wp_usermeta` (`_application_passwords`), especially on an admin account.
- **A spike in `user_registered` dates** — mass registration through an open form.

### 6.2 Scheduled tasks
- Enumerate `cron` in `wp_options` (or `wp cron event list`). Signals: events with no matching hook in any installed plugin; obfuscated or random-looking hook names; hourly events you cannot attribute. Sucuri found malicious cron jobs on **2.14%** of compromised sites, usually re-dropping backdoors.
- A cron entry that survives your cleanup is why the site re-infects.

### 6.3 File-level signals
- **Core integrity.** Compare `wp-admin/`, `wp-includes/` and root PHP files against the official checksums for the installed version (`wp core verify-checksums`). **Any** mismatch is Tier-A.
- **Recently modified files.** List files under `wp-content` and web root modified in the last 30 days, and anything modified *outside* an update window. Sort by mtime; attackers often backdate mtimes, so also compare ctime.
- **Executable files where they don't belong:** any `.php`/`.phtml`/`.php7`/`.suspected` under `wp-content/uploads`, or an `.htaccess` inside uploads that re-enables PHP.
- **Suspicious code patterns** (indicators for *review*, not proof — legitimate code occasionally uses these):
  `eval(`, `base64_decode(`, `gzinflate(`, `str_rot13(`, `create_function(`, `assert(`, `preg_replace` with the `/e` modifier, `system(`/`exec(`/`shell_exec(`/`passthru(`, `$_COOKIE`/`$_POST` passed straight into a callable, `file_get_contents('http` in a theme, long single-line hex/base64 blobs, `@include` of a file with a random name, `FilesMan`/`WSO` strings.
  Weight the finding by **location**: these patterns in `wp-includes/`, in `functions.php`, in an mu-plugin, or in an uploads file are far more serious than in a minified vendor library.
- **`wp-content/mu-plugins/`** — enumerate every file. Always-active, invisible in the plugin list, no update path. A favourite hiding place.
- **Fake plugins** — a plugin directory with no readme, no repository entry, a plausible-but-wrong name, a single PHP file with a fabricated header, or a directory whose name mimics a real plugin. Sucuri found fake plugins on **4.18%** of cleaned sites.
- **Modified `.htaccess` / `wp-config.php`** — injected redirect rules, `auto_prepend_file`, unexpected `php_value` directives.
- **Files in the web root that aren't WordPress:** stray `.php` files with random names, `.ico`/`.txt` files containing PHP, `/wp-content/uploads/*.zip`.

### 6.4 Content and database signals
- **Spam posts/pages/comments:** posts with `post_status` publish that the owner didn't write, especially pharma/gambling/essay-writing keywords; posts with future or very old `post_date`; huge numbers of auto-drafts.
- **Injected links in the database:** search `wp_posts.post_content` and `wp_options.option_value` for external anchor tags, hidden `display:none` blocks, and `<script src>` pointing off-domain. **38.3%** of compromised databases Sucuri examined contained SEO spam (Sucuri 2023).
- **Modified core options:** `siteurl`/`home` pointing somewhere unexpected, unexpected `active_plugins` entries, an unrecognised `template`/`stylesheet`.
- **`wp_options` autoloaded rows that are enormous or opaque** — a common malware storage location.
- **Third-party confirmation:** Google Search Console security issues / manual actions; the site's SERP snippets showing keywords the site doesn't sell (a classic cloaked SEO-spam tell — spam served to Googlebot but not to normal visitors); browser/host blocklist warnings.

### 6.5 Behavioural signals
- **Unexpected outbound requests** — the site calling domains that aren't its CDN, gateway, or update servers. Skimmers exfiltrate this way, and the `form-checkout` family fetches its payload from a third-party host at render time.
- **Outbound mail volume** — a spike in mail from the site means it's sending spam or phishing; check the mail log or the host's send counters.
- **Different content for different visitors** — fetch the home page as a normal browser and as Googlebot/a mobile UA; differing output is cloaking.
- **Unexplained load** or the host warning about resource abuse.
- **Redirects only on first visit / only from search referrers** — check with a search-engine referrer header and a clean-cookie session.

### 6.6 What to do on a positive finding

1. **Stop.** Do not build, do not "clean up as you go", do not update plugins over the top of malware.
2. **Preserve evidence** — copy the suspicious files and a DB snapshot off-site before touching anything.
3. **Tell the owner in plain language**, including: what was found, whether customer or card data is implicated (if yes, this may be a legally reportable breach and a payment-processor notification), and that clean-up requires the entry point *and* every persistence mechanism.
4. **Recommend a specialist clean-up or a rebuild from a known-good backup predating the infection** plus a full credential rotation (all users, hosting, FTP/SSH, DB, gateway keys, salts, application passwords).
5. Only then apply this standard.

---

## Sources

- Patchstack — *State of WordPress Security in 2025* (data updated 14.03.2025): https://patchstack.com/whitepaper/state-of-wordpress-security-in-2025/
- Patchstack — *State of WordPress Security in 2024* (data updated 21.03.2024): https://patchstack.com/whitepaper/state-of-wordpress-security-in-2024/
- Patchstack vulnerability database: https://patchstack.com/database/
- Wordfence — *2024 Annual WordPress Security Report* (blog + PDF): https://www.wordfence.com/blog/2025/04/2024-annual-wordpress-security-report-by-wordfence/ · https://www.wordfence.com/wp-content/uploads/2025/04/2024-Annual-WordPress-Security-Report-by-Wordfence.pdf
- Wordfence Intelligence vulnerability database: https://www.wordfence.com/threat-intel/
- Sucuri — *2023 Hacked Website & Malware Threat Report*: https://sucuri.net/reports/2023-hacked-website-report/
- Sucuri reports index (for the current year's edition): https://sucuri.net/reports/
- WordPress — *Hardening WordPress*, Advanced Administration Handbook: https://developer.wordpress.org/advanced-administration/security/hardening/
- WordPress — Security & Data protection APIs (nonces, sanitisation, escaping): https://developer.wordpress.org/apis/security/
- WPScan vulnerability database: https://wpscan.com/
- OWASP Top 10: https://owasp.org/www-project-top-ten/ · 2021 edition: https://owasp.org/Top10/
- PCI Security Standards Council — PCI DSS v4.x (payment-page script controls, prohibited storage of sensitive authentication data): https://www.pcisecuritystandards.org/document_library/
- WooCommerce — security & privacy documentation: https://woocommerce.com/document/

### Notes on the evidence

- **Disclosure data ≠ breach data.** Patchstack/Wordfence count *vulnerabilities disclosed*; Sucuri counts *what was found on sites that were actually hacked*. A plugin bug count does not directly translate into a compromise count, and neither dataset measures credential-based compromises well (a stolen password leaves no CVE). Treat §1.2's ranking as informed judgement from the remediation evidence, not a measured statistic.
- **Where sources differ:** Wordfence characterises the majority of 2024 disclosures as low real-world risk (over 68% low-risk by authentication level/user interaction; ~7.4% high-threat), while Patchstack emphasises the large share requiring no authentication (43% in 2024) and rising high/critical severity (42.9% in 2023). These are compatible — they weight *exploitability in practice* versus *CVSS severity* differently. The practical takeaway is the same: patch promptly, and prioritise the unauthenticated-RCE and privilege-escalation classes over the long tail of contributor-level XSS.
- **Sucuri's remediation figures are drawn from sites that hired a clean-up service**, which is a biased sample (worse than average). Use them for *what attackers do*, not for base rates across all WordPress sites.
- Any figure not attributed above was not verifiable and has deliberately been described qualitatively instead.
