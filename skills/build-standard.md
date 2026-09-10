---
name: build-standard
title: Build standard
description: The Definition of Done for any build work on this site — what "finished" requires before a page, a site, or a launch can be reported as complete. Load this BEFORE building anything: a new site, a new page, an edit to an existing page, a store launch, or a pre-launch pass. Triggers on "build me a...", "add a page", "make this live", "is this done", "ready to launch", "finish the site", or the start of any site-build-kickoff/website-building/store-launch-and-deploy task. Routes to the right skill for each part of the job and defines the fix loop, the security floor, and the completion report. Not an audit tool itself — pre-launch-audit does the checking; this is the standard it checks against.
enable_agentic: true
enable_prompt: true
role: gate
surface: any
next_skills: site-build-kickoff, website-building, blog-publishing, seo-optimization, code-snippets, woocommerce-store-operations, launch-readiness-and-compliance, skill-creator, site-safety, security-standard, web-design-master, motion-and-interaction, seo-audit-and-fix, elementor-page-building, solace-header-footer, store-launch-and-deploy
verified_by: pre-launch-audit
---

# Build standard

## When to use

Before any build work at all: a new site, a new page, an edit to a page a visitor will see, a store setup, a launch. It carries the Definition of Done and the router table that says which skill owns which part of the job.

## When NOT to use

Not for reading or reporting on a site nobody is changing. An audit with no edits belongs to `pre-launch-audit`.

## What this is

A build is not finished when it looks right. The obligation to check that is already in
your operating protocol — you may not report a site or page done without checking your own
work and resolving every Blocker and High. **`site_readiness` is the checker on every
site.** `pre-launch-audit` is the second, deeper pass, and it ships with the plugin like
every other skill — load it with `skill_get pre-launch-audit`. This skill
is the detail behind that obligation: what "done" actually requires, which skill handles
each part, and how the fix loop runs so it terminates instead of stalling or gaming itself.

Read this before the first page gets built, not after the last one.

## The Definition of Done

Three tiers, because they are provable in different ways: things you check on every page,
things you check once for the whole site, and things only the owner can decide.

### Page level — every page, every time

Cheap, so run it on every page as you build it, not as a batch at the end.

- One `<h1>` per page; heading levels never skip (`h2` → `h4` is a finding).
- Every content image has meaningful alt text; purely decorative images get `alt=""`.
- Title and meta description present; Open Graph tags on anything shareable.
- Every link resolves. No `href="#"`, no "coming soon", no lorem, no placeholder image.
- No image over **300 KB**; the LCP/hero image under **200 KB**; everything below the
  fold lazy-loaded; a modern format (WebP/AVIF) where the host supports it.
- Body-text contrast at least **4.5:1** against its actual rendered background, not the
  design-file background.
- Every factual claim on the page is true and agrees with every other page.

### Site level — before "the site is done", and before launch

Run once the pages exist, and again right before going live.

- Legal pages exist, are published, are linked from somewhere a visitor would find them,
  and are wired into the relevant settings (WooCommerce checkout terms, footer, etc.) —
  not just sitting unlinked at a URL.
- Nothing contradicts anything: the FAQ, the policy pages, and the actual configuration
  must all say the same thing about shipping, returns, and payment.
- Product schema where applicable — and **never** `aggregateRating` or `review` markup
  without real reviews behind it. A store with no orders has no reviews to schema.
- Sitemap, robots.txt, and `llms.txt` serve correctly, and each returns an actual **200**
  — a 404 page served with a 200 status is not "serving," it's broken and invisible.
- Commerce gates: tax enabled implies rates are actually configured (or tax is off);
  stock policy is explicit; review policy is explicit; **one real order has been placed
  and refunded** before the store takes a customer's money for real.
- The full security floor, below.
- 404s return an actual HTTP 404 and are useful pages, not a silent redirect to home.

### Security — AUTOMATIC (do it, don't ask)

Full detail and the "detect → fix → blast radius" for each item lives in the
`security-standard` skill — load it before hardening or auditing anything. All twenty
(A1–A20), compressed:

file editor disabled · public registration closed unless a feature needs it · no user
named `admin` · user enumeration closed · HTTPS enforced · security headers present · auth
cookies Secure/HttpOnly/SameSite=Lax · salts present and unique · `WP_DEBUG` off with no
public `debug.log` · no exposed `.sql`/`.git`/config backups · directory listing off ·
file permissions sane · version disclosure removed (`readme.html`, `license.txt`,
generator meta) · everything updated, nothing unused installed · application
passwords and REST auth surface audited · **no static-key or hardcoded-secret endpoints
in custom code** · XML-RPC exposure measured · uploads directory hygiene · custom code
uses the WordPress escaping/nonce/capability APIs · off-site backups exist.

Call out the static-key check by name, every time: this plugin's own site shipped a
preview bypass on a hardcoded key with a week-long cookie. Grep every snippet, mu-plugin,
and theme file you write or touch for a hardcoded token compared with `==`, a `$_GET`-gated
preview/debug bypass, or anything authenticating from a URL parameter. That is a backdoor
whether you meant it as one or not, and it is non-negotiable at handover.

### Security — NEEDS THE OWNER'S DECISION

Seven questions, asked once, each with a default you apply if the owner says "you decide."
Full wording in `security-standard` §3 — the defaults, verbatim:

| Question | Default if "you decide" |
|---|---|
| Q1 — login protection (2FA / lockout / hidden login URL) | 2FA on every admin account, lockout after 5 failed attempts with a 20-minute cooldown, login URL left at `/wp-login.php`. |
| Q2 — update policy | Core security auto-updates on; plugin/theme auto-updates on except the payment gateway, page builder, and theme, which update manually with a backup and a checkout test. |
| Q3 — security plugin / WAF, and who pays | One free plugin firewall, alerts to the owner's email; Cloudflare in front if DNS is already there. Never a paid tier without explicit approval. |
| Q4 — XML-RPC | Disable entirely if Jetpack/the mobile app are not in use; otherwise keep it, disable pingbacks, rate-limit it. |
| Q5 — REST API restriction | Keep the API on; require auth on the users endpoint and any endpoint exposing personal data; leave post/page endpoints public. |
| Q6 — PHP execution in uploads | Block it, then verify uploads, the media library, and any gallery/caching plugin still work. |
| Q7 — account recovery custody | Verify the admin email is deliverable and off-domain; list every administrator account and every credential you created in the handover. |

Renaming the login URL is explicitly **not** a silent default, even under "you decide": it
reduces bot noise, it is not a security control, and it carries a real lockout risk if the
owner forgets the new address. Only apply it if the owner actively picks it, and only with
the recovery path stated first (the secret URL emailed and documented, and how to restore
`/wp-login.php` if they lose it).

### Needs a browser — state, never fake

Claudpress has no browser. Viewport sweeps at 375/768/1280/1440, console errors, real
touch-target sizes, and animation smoothness cannot be verified from markup alone — do not
claim they were. Use markup-level proxies instead:

- fixed pixel widths on containers (a smell for horizontal overflow at narrow widths)
- images with no `max-width: 100%` or missing `width`/`height` (layout shift, overflow)
- inputs with no associated `<label>` (an accessibility finding, not a browser one)
- tap targets styled smaller than roughly 44×44px in the CSS

These are proxies, not proof. List every check you could only approximate this way as an
explicit **outstanding item** in the completion report — never as a silent pass. If the
client you are running in *does* have a browser or a devtools MCP, use it; the proxy list
is a fallback, not a preference.

## The skill router

Load the skill for the part of the job you're actually doing, not everything at once.

| Job | Skill |
|---|---|
| Starting a brand-new site from scratch | `site-build-kickoff` |
| Building or editing an Elementor page/header/footer/template | `elementor-page-building` |
| Header/footer on the Solace theme specifically | `solace-header-footer` |
| General page-building guidance, taste, and the page-level checklist | `website-building`, `web-design-master` |
| Animation, scroll effects, hover states, "make it feel alive/premium/expensive" | `motion-and-interaction` |
| Writing and publishing a blog post | `blog-publishing` |
| Meta, schema, sitemap, robots, `llms.txt`, AI-search visibility, one page at a time | `seo-optimization` |
| Auditing and fixing the SEO of a WHOLE site — scores, speed, crawl, duplicates, local | `seo-audit-and-fix` (agree the cost first) |
| Adding live code — hooks, filters, shortcodes, custom endpoints | `code-snippets` |
| Running the store — products, stock, orders, coupons, reports | `woocommerce-store-operations` |
| Turning on payments/shipping/tax, or deploying staging → production | `store-launch-and-deploy` |
| Hardening or auditing security, or the intake questionnaire | `security-standard` |
| Change-safety rules for any create/update/delete/install/PHP/SQL op | `site-safety` |
| Scoring the finished work, before launch or on request | `site_readiness` (always), then `pre-launch-audit` |
| Cookie consent, GDPR, privacy, accessibility, returns — and the twenty things that make a site look cheap | `launch-readiness-and-compliance` |
| Building a new repeatable skill | `skill-creator` |

## The skill tree

The table above says which skill owns a job. This one says what each skill owes
the others — what it needs loaded first, where to go when it is finished, and
what has to run before the work counts as done. **"Not done until" is binding:**
finishing the steps in a skill is not the same as the work being right.

Generated from the front matter in `skills/*.md` by `php bin/build-skill-router.php`.
Do not edit the table by hand — change the skill file and regenerate.

<!-- ROUTER:START -->
| Skill | Role | Runs on | Requires first | Then consider | Not done until |
|---|---|---|---|---|---|
| `blog-publishing` | procedure | any | — | `seo-optimization`, `web-design-master` | `seo-optimization` |
| `build-standard` | gate | any | — | `site-build-kickoff`, `website-building`, `blog-publishing`, `seo-optimization`, `code-snippets`, `woocommerce-store-operations`, `launch-readiness-and-compliance`, `skill-creator`, `site-safety`, `security-standard`, `web-design-master`, `motion-and-interaction`, `seo-audit-and-fix`, `elementor-page-building`, `solace-header-footer`, `store-launch-and-deploy` | `pre-launch-audit` |
| `code-snippets` | procedure | any | `site-safety` | `motion-and-interaction`, `security-standard` | — |
| `elementor-page-building` | procedure | any | `web-design-master` | `motion-and-interaction`, `seo-optimization` | `pre-launch-audit` |
| `launch-readiness-and-compliance` | gate | any | — | `store-launch-and-deploy`, `security-standard` | `pre-launch-audit` |
| `motion-and-interaction` | reference | any | — | `web-design-master`, `seo-audit-and-fix` | `pre-launch-audit` |
| `pre-launch-audit` | gate | any | — | `launch-readiness-and-compliance`, `seo-audit-and-fix`, `security-standard` | — |
| `security-standard` | reference | any | — | `site-safety`, `pre-launch-audit` | — |
| `seo-audit-and-fix` | procedure | any | — | `seo-optimization`, `motion-and-interaction` | `pre-launch-audit` |
| `seo-optimization` | gate | any | — | `seo-audit-and-fix` | `pre-launch-audit` |
| `site-build-kickoff` | procedure | interactive | `build-standard` | `website-building`, `web-design-master` | `launch-readiness-and-compliance` |
| `site-safety` | gate | any | — | `security-standard`, `code-snippets` | `pre-launch-audit` |
| `skill-creator` | procedure | any | — | `build-standard` | — |
| `solace-header-footer` | procedure | any | `web-design-master` | `website-building`, `motion-and-interaction` | `pre-launch-audit` |
| `store-launch-and-deploy` | procedure | any | — | `launch-readiness-and-compliance`, `woocommerce-store-operations` | `pre-launch-audit` |
| `web-design-master` | reference | any | — | `motion-and-interaction`, `website-building` | `pre-launch-audit` |
| `website-building` | procedure | any | `web-design-master` | `motion-and-interaction`, `seo-optimization`, `elementor-page-building`, `solace-header-footer`, `code-snippets` | `pre-launch-audit` |
| `woocommerce-store-operations` | procedure | any | — | `store-launch-and-deploy`, `seo-optimization` | `site-safety` |
<!-- ROUTER:END -->

## Page-level vs site-level gates

A full-site audit for one new page is disproportionate — it slows down small edits until
they stop happening. Run the two tiers at different moments:

- **Page-level checklist** — every time a page is created or substantially edited, before
  you hand it over or move to the next page. Minutes, not a production.
- **Site-level checklist and the fix loop below** — before you tell the owner "the site is
  done," and again before launch. Not after every single-page tweak.

If a change only touches one page (a copy edit, a new blog post), the page-level list is
the gate. Reach for the site-level pass only when the scope is "the site" or "launch."

## The fix loop

1. Run `site_readiness` first, against the current build. It is the objective exit code:
   fast, machine-checkable, and unarguable — heading order, alt text, meta/OG, JSON-LD,
   dead links, image weight, security headers, and (when WooCommerce is active) tax/stock
   sanity, all checked from the rendered site, not from memory.
2. Run `pre-launch-audit` on top of it. It is the judgement layer `site_readiness`
   cannot provide — design, copy quality, hierarchy, trust, conversion, whether the site
   actually makes sense for its audience. Neither replaces the other. Do not fake it by
   scoring your own design work — an agent grading its own taste is the exact failure
   this whole standard exists to prevent.
3. Run `launch-readiness-and-compliance` before anything goes public. It asks a different
   question from the other two: not "is this good" but "is this allowed, and does it look
   paid-for". A fast, handsome site that fires analytics before consent is not ready.
4. Fix every **Blocker** and every **High** from whichever sources ran.
5. Re-run them.
6. Repeat steps 4–5 to a maximum of **3 iterations total**.
7. Anything still failing after 3 iterations goes into the completion report as an
   explicit outstanding item, with the reason it wasn't resolved — never reported as a
   pass.

`site_readiness` returns `pass` and `complete` as two different signals — read both, never
just `pass`. `pass` means nothing that actually ran failed (zero open Blockers and Highs).
`complete` means every check ran (nothing in `skipped` — WooCommerce inactive, no HTTPS, a
capability the owner disabled, etc. all land there). A store with WooCommerce off can come
back `pass: true, complete: false`, and that is not a green light: a skipped check is never
a pass. If `complete` is false, every item in `skipped` goes into the completion report's
"Not verified" section alongside the browser-dependent checks — it was never actually
checked, so it cannot be reported as one.

Hard rules, because a loop that scores its own exit is easy to game by accident:

- **Severity may never be lowered to make the loop exit.** If it was a High on iteration
  1, it is still a High on iteration 3 unless the underlying issue is actually fixed.
- **An owner-decision item is neither a failure nor a pass.** It becomes
  `accepted-by-owner` only once the owner was actually asked and their answer is recorded
  — not assumed, not defaulted-and-forgotten.
- **Evidence, not recall.** Re-fetch the rendered HTML and the real status code before
  claiming something is fixed. "I set that earlier" is not evidence — four SEO bugs in a
  past release all reported success while silently emitting nothing.
- **Prefer a fresh auditor.** Where the environment supports sub-agents, run the audit in
  one with no build context. You auditing your own work is the weakest link in this loop;
  a stranger checking it is not.
- **Snapshot before fixing.** Record the prior value of every option and file the loop is
  about to change, so a bad automated fix can be reversed instead of compounded.

## The completion report

Mandatory, exactly three sections, every time you report a build or a launch as done.

1. **Score and what was fixed automatically** — the audit score, and a short list of what
   the fix loop resolved without needing the owner.
2. **Needs you** — every item only the owner can decide or provide. For each: why it
   matters, and what happens if it's skipped. This is where payment credentials, tax
   position, real testimonials, legal review, DNS, and 2FA recovery custody live — never
   silently defaulted.
3. **Not verified** — every browser-dependent check you could not run, with exactly what
   the owner should click to check it themselves.

A short worked example:

> **1. Score and fixes** — Pre-launch audit: 84/100. Fixed automatically: 12 missing alt
> tags, 3 broken internal links, meta descriptions on all 9 pages, `llms.txt` now serving
> a 200, tax rate table populated for the one configured region.
>
> **2. Needs you**
> - *Stripe live keys* — checkout is wired but running in test mode. Without your live
>   keys, no real order can be charged. Add them in WooCommerce → Payments before launch.
> - *Real testimonials* — the About page had three placeholder quotes; I removed them
>   rather than invent people. The page now has no social proof section until you supply
>   real ones or approve removing the section.
> - *2FA recovery codes* — 2FA is enabled on your admin account. Save the recovery codes
>   somewhere off this site — losing your phone without them locks you out.
>
> **3. Not verified**
> - *Mobile layout at 375px* — no browser available in this session. Please open the site
>   on your phone and check the pricing table doesn't overflow sideways.
> - *Checkout end-to-end* — one test order was placed in test mode and refunded; a real
>   card charge has not been attempted. Place one real order after adding live keys.

## The beginner promise

State this at kickoff, in plain language, before building anything:

> "I'll build this, then audit my own work, fix what I find, and show you the score. You
> won't have to ask me to check SEO, accessibility, speed or security — that's part of
> building it. Anything only you can decide, I'll list plainly at the end."

## Existing sites (adoption)

Claudpress is often pointed at a site that already exists, not a blank install. Building
rules do not apply cleanly to someone else's work, and the compromise-detection checks in
`security-standard` §5 have to run **before** anything else — building on a site that is
already backdoored just adds a second layer to clean up later.

The rule: **audit first, report, then ask before changing anything you did not build.**
Never mass-rewrite existing content. Never bulk-toggle settings. Never "fix" a design
decision nobody asked you to touch. Present the findings and let the owner choose what
happens next. See `site-safety` for the full change-safety rules once you do start
touching a live site.

## Routing examples

Phrases that must reach this skill, and one that must not. `bin/lint-skills.php`
checks them against every other skill's description.

- fires: what is the definition of done here
- fires: can i report this build as complete
- skips: write a blog post about padel courts

## Related skills

- **Next** — site-build-kickoff, website-building, blog-publishing, seo-optimization, code-snippets, woocommerce-store-operations, launch-readiness-and-compliance, skill-creator, site-safety, security-standard, web-design-master, motion-and-interaction, seo-audit-and-fix, elementor-page-building, solace-header-footer, store-launch-and-deploy. Consider these when this skill is done.
- **Verified by** — pre-launch-audit. The work is not finished until this has run and passed.
