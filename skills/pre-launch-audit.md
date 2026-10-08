---
name: pre-launch-audit
title: Pre-launch audit
description: Audit a WordPress or WooCommerce site before it goes live and produce a scored report — design, responsiveness, performance, functionality, accessibility, security, SEO and conversion. Use when the user asks to review/audit/check a site before launch, asks "is this ready to go live", "what's wrong with my site", "review my site", wants a second opinion on a build, or has just finished a build and needs it checked. Read-only: this skill inspects and reports, it never fixes. Emits a machine-readable JSON findings block alongside the human report so `build-standard`'s fix loop can consume it programmatically.
enable_agentic: true
enable_prompt: true
role: gate
surface: any
next_skills: launch-readiness-and-compliance, seo-audit-and-fix, security-standard
---

# Pre-launch audit

<!-- CONTENTS:START -->
## Contents

- [When to use](#when-to-use)
- [When NOT to use](#when-not-to-use)
- [How to judge](#how-to-judge)
- [Gathering evidence](#gathering-evidence)
- [What this audit does not cover](#what-this-audit-does-not-cover)
- [What to check](#what-to-check)
- [Scoring](#scoring)
- [The report](#the-report)
- [Machine-readable findings](#machine-readable-findings)
- [Guardrails](#guardrails)
- [Finish check](#finish-check)
- [Related skills](#related-skills)
- [Routing examples](#routing-examples)
<!-- CONTENTS:END -->

You are an independent auditor, not the builder. Someone is about to put this site in
front of real customers and needs to know what is wrong with it while that is still
cheap to fix.

**Change nothing.** No edits, no fixes, no "while I was there". Not even the obvious
one-line fix, and not even if you wrote the code yourself an hour ago. The output is a
report. Fixing comes after the owner has read it and decided what matters — and a fix
applied mid-audit means the rest of the audit is running against a site that no longer
matches what you already checked.

This is the checker for the standard defined in `build-standard` — that skill states what
"done" requires; this one verifies it actually happened. When you run as part of
`build-standard`'s fix loop, **re-fetch evidence every time**: pull the rendered HTML and
the real status code again on each iteration. A build session's memory of what it changed
is not evidence — "I set that earlier" is exactly what let four SEO bugs in a past release
report success while silently emitting nothing.

## When to use

Someone wants a site reviewed and scored rather than changed — before a launch, before a handover, or to settle whether work is actually finished. It is also the verifier most build skills declare.

## When NOT to use

Not for fixing what it finds. It produces the report and the score; the fixes belong to the skill that owns each area. Never score your own design work without it.

## How to judge

1. Assume nothing is correct because it looks correct. Open it.
2. Judge what the site actually does, not what it was meant to do.
3. Verify by inspecting and testing. If you did not test it, do not score it.
4. A half-built feature scores as broken, not as partial credit.
5. Keep **confirmed** issues separate from **suspected** ones, and label which is which.
6. Do not invent problems to look thorough. If something is genuinely good, say so.
7. Auditing your own build is harder than auditing a stranger's. Be harsher, not kinder.

## Gathering evidence

Run `site_readiness` first. It covers the objective half of this checklist mechanically —
heading order, alt text, meta/OG, JSON-LD validity, dead `href="#"` links, image weight,
security headers, and (when WooCommerce is active) tax/stock sanity — and returns
`pass`/`complete`/`findings`/`skipped`/`not_verified` shape-compatible with the JSON block
below. Treat its `findings` as your objective baseline and do not re-derive what it already
measured; spend your own effort on what it structurally cannot judge — design, hierarchy,
copy quality, trust, conversion, and whether the site actually makes sense for its
audience. Still verify independently rather than taking its output on faith: it is one more
source of evidence, not a substitute for opening the page yourself. It changes nothing on
the site, same as this skill.

Work from the real site, not from the plan:

- `get_site_info` — versions, active theme and plugins, WooCommerce presence.
- `list_posts` / `get_post` — every published page and post; a page that exists but is
  not linked from anywhere is a finding.
- Fetch the rendered HTML of each key page. `execute_php` with `wp_remote_get(home_url('/path'))`
  gives you status code, headers and markup — enough for broken links, missing meta,
  heading order, alt text, inline-style sprawl and horizontal-overflow smells.
- `snippet_list` — every piece of live code, and any with a `last_error` is at minimum a
  High finding.
- `manage_option` — check `blog_public` (a site still discouraging search engines is a
  Blocker), permalink structure, and the WooCommerce legal-page assignments.
- `seo_analyze` and `seo_sitemap` — metadata, schema, sitemap, robots.
- `manage_updates action=check` — outdated core, themes and plugins.
- `agent_runs` and the Activity log — recent failures the owner has not noticed.
- For WooCommerce: `wc_settings`, `wc_payments`, `wc_shipping`, `wc_tax` — is checkout
  actually configured, or configured in test mode?

**Viewports and console need a real browser**, which Keelhob does not have. If the
client you are running in has one (a browser tool, a devtools MCP), use it: load each
page at 375, 768, 1280 and 1440, check for horizontal scroll, overlap and clipped text,
and read the console. If it does not, say so in the report and mark those checks *Not
verified* rather than guessing. A guessed viewport result is worse than an admitted gap.

## What this audit does not cover

Two gates sit beside this one and neither is optional:

- **`launch-readiness-and-compliance`** — cookie consent, GDPR, privacy, accessibility
  duties, returns and delivery terms, and the twenty specific things that make a site
  read as cheap. This audit judges whether a site is *good*; that one judges whether it
  is *allowed*. A beautiful site that fires analytics before consent still fails.
- **`seo-audit-and-fix`** — the whole-site SEO and performance pass. Report speed
  findings here, but measure them there.

Run all three before calling a launch ready, and never let one quietly stand in for
another.

## What to check

**Design and layout** — hierarchy, section order, alignment, spacing rhythm, whitespace,
whether anything reads as cramped or empty, whether the important thing on each page gets
the most attention.

**Typography** — heading order, sizes, line height, measure, contrast, fallback fonts
while a webfont loads, and anything that wraps or overflows badly at narrow widths. Verify
heading order in the rendered HTML, not the visual size on screen: exactly one `<h1>` per
page, and no skipped levels — an `h2` followed directly by an `h4` is a finding even if it
looks fine.

**Colour and imagery** — palette consistency, stretched or blurry images, wrong crops,
missing alt text, contrast over background images, and image weight **measured, not
eyeballed**: no image over **300 KB**, the LCP/hero image under **200 KB**, everything
below the fold lazy-loaded, modern format (WebP/AVIF) where the host supports it. Pull the
actual `Content-Length` on each image via `wp_remote_get`/`execute_php` — the real audit
this checklist is built from found 122 MB of images and an 867 KB logo, both of which
looked fine on screen. Measure contrast the way `web-design-master` §8 sets out:
composite translucent backgrounds, skip off-canvas honeypots, and judge only elements
that paint their own text. Skip any one of those and the report fills with failures
that are not real, which costs the owner more trust than the finding was worth.

**Motion and interaction** — hover, focus and active states, form feedback, loading and
error and empty states, `prefers-reduced-motion`, and whether anything shifts layout as
it animates. Check it works with a mouse, a keyboard, and touch.

**Design system consistency** — do buttons, cards, inputs and spacing repeat the same
values, or has every section invented its own?

**Performance and responsiveness** — the viewport sweep above, plus image weight, render-
blocking assets, unused plugins loading on every page, and how long a cold load takes.
Check whether a page cache is actually active — an object-cache or page-cache plugin
installed is not the same as it serving. Fetch a page twice and look for a cache header
(`X-Cache`, `Age`, a cache-plugin comment in the HTML) or a clear timing drop on the second
request; a cache plugin sitting installed and idle is a finding, not a pass.

**Trust and conversion** — is the next step obvious on every page? Are contact paths
real? Does the form actually submit somewhere and say so? Are the legal and privacy
pages real pages rather than `#` links? Flag any invented statistic, testimonial, customer
count, review, award, or staff member presented as a real person — a store with zero
orders has no customers to quote from. **This is a Blocker**: it is a fabricated claim and
a legal exposure, not a style note. Check the rendered HTML and `seo_schema` for
`aggregateRating` or `review` structured data with no real reviews behind it — same
finding, machine-checkable.

**Functionality** — every link, button, form, menu item and route. Broken links, dead
buttons, pages that 404, forms that silently fail, placeholder copy, "lorem", "coming
soon", debug output, test data, and TODOs that affect launch. Request a URL that should
not exist and check the actual response: a 404 must return a real HTTP 404 and a useful
page, not a 200 that silently redirects to the homepage — the latter hides broken links
from visitors and search engines alike.

**Commerce (WooCommerce only)** — via `wc_tax`, `wc_settings`, `wc_shipping`: if tax is
enabled, rates must actually be configured for the regions sold to. **Tax enabled with no
rates is a Blocker** — the store silently charges no tax and every price shown is wrong.
Stock policy must read as a deliberate choice, not the untouched default: manage-stock
on/off, backorder behaviour, and the low-stock threshold should all reflect an actual
decision. Public registration open with no feature that needs it (no membership, no
required account at checkout) is a finding, and `default_role` set above Subscriber is
always a finding regardless of anything else.

**Accessibility** — WCAG 2.2 AA in practice: keyboard reachable, visible focus, semantic
elements (a `<div>` with a click handler is a finding), one `<h1>` and ordered headings,
labelled inputs, alt text, contrast, landmarks, skip link, touch targets, and behaviour
at 200% zoom.

**Security** — this is a live site, so look but never exploit. Load `security-standard`
and audit against all twenty automatic checks (A1–A20), compactly: file editor disabled ·
public registration closed unless a feature needs it, `default_role` never above
Subscriber · no user named `admin` · user enumeration closed · HTTPS enforced · security
headers present · auth cookies Secure/HttpOnly/SameSite=Lax · salts present and unique ·
`WP_DEBUG` off with no public `debug.log` · no exposed `.sql`/`.git`/config backups ·
directory listing off · file permissions sane · version disclosure removed · everything
updated, nothing unused installed · application passwords and REST auth surface audited ·
no static-key or hardcoded-secret endpoints in custom code · XML-RPC exposure measured ·
uploads directory hygiene · custom code uses the WordPress escaping/nonce/capability APIs
· off-site backups exist. Each stands as its own Blocker or High — see
`security-standard` §2 for the exact detect steps rather than re-deriving them here.

Then check the seven owner-decision items (Q1–Q7 in `security-standard` §3): login
protection, update policy, security plugin/WAF, XML-RPC dependents, REST API restriction,
PHP execution in uploads, recovery custody. **If a question was never actually asked and
recorded, that is a finding — not a pass.** You cannot infer "asked" from "a sane default
was applied"; an item only becomes `accepted-by-owner` once the owner was asked and their
answer is on record.

Beyond the checklist: API keys or secrets in snippets, page templates, theme files or
committed code; unescaped output; `eval` on anything user-supplied; SQL built by string
concatenation; missing capability checks or nonces on custom endpoints; file uploads;
overly detailed errors shown to visitors; outdated plugins with known advisories.
**Mask every secret you find** — report `sk-ant-…KEY` and where it lives, never the value.

**Code quality and production readiness** — duplication, dead code, PHP notices in the
log, page titles and meta descriptions, canonical URLs, Open Graph, favicon, robots.txt,
sitemap, and schema. Fetch `/llms.txt`, the sitemap URL, and `/robots.txt` directly and
check the real status code and body — a 404 page served with a **200 status** is not
serving, it is broken and invisible to anything reading it, human or crawler. Only let
these affect the score where they create a real maintenance, reliability, performance,
security or launch risk.

## Scoring

Score each category out of 10, then weight:

| Category | Weight |
|---|---|
| Visual hierarchy and layout | 8% |
| Typography | 6% |
| Colour and imagery | 6% |
| Motion and interaction | 7% |
| Design system consistency | 7% |
| Performance and responsiveness | 15% |
| Trust and conversion | 10% |
| Functionality | 15% |
| Accessibility | 10% |
| Security | 10% |
| Code quality and production readiness | 6% |

Read the total as: 95–100 exceptional · 90–94 minor fixes only · 80–89 good but fix the
listed items · 70–79 resolve several things first · 60–69 significant risk · under 60 not
ready. Do not inflate. A site with a broken checkout does not score 85 because it looks
nice.

Severity per issue: **Blocker** (stops launch — security, data loss, legal, or a core
flow that does not work) · **High** · **Medium** · **Low** · **Observation**.

## The report

Write it in this order, and keep it plain. After section 12, emit the machine-readable
findings block described below — the JSON is in addition to this report, never instead of
it.

1. **Verdict** — score out of 100, launch status (Ready · Ready with minor fixes ·
   Conditionally ready · Not ready), five sentences of explanation at most, and the
   count of Blocker/High/Medium/Low.
2. **Scorecard** — table of category, score, weight, weighted score, and the one reason.
3. **Launch blockers** — each with the evidence, where it is, what it costs the owner,
   and the suggested correction. Write "No launch blockers found" when there are none.
4. **Confirmed issues** — grouped High / Medium / Low. Each with a title, the evidence,
   the file or page or viewport, why it matters, and what to change.
5. **Design review** — for each of the seven design categories: what works, what falls
   short, the score, and what to improve. Say what works; a report that is only criticism
   gets discounted as noise.
6. **Responsive results** — viewport by viewport, what you tested and what you found, or
   an honest "not verified, no browser available".
7. **Functional results** — pages, links, forms and components tested; console errors;
   failed requests; and what you could not verify.
8. **Security review** — confirmed issues, suspected risks, secret exposure (masked),
   dependency and update risk, and the checks you could not complete.
9. **Accessibility review** — keyboard, semantics, contrast, forms, focus, motion, alt
   text, and the major WCAG risks.
10. **Recommendations** — split into Before launch / Soon after launch / Optional polish.
    Be strict about the first list: it should only contain things that genuinely cannot
    ship broken.
11. **Checklist** — Pass / Fail / Partial / Not verified for: no broken critical routes ·
    no broken CTAs · no exposed secrets · no critical console errors · no major mobile
    failures · forms work · error states handled · keyboard navigation works · contrast
    acceptable · images load and scale within budget · metadata (incl. `llms.txt`, sitemap,
    robots.txt) serving with real 200s · commerce configuration sane (tax/stock/registration)
    where WooCommerce is active · no fabricated content · 404s return real 404s ·
    performance acceptable · security acceptable · ready to go live.
12. **One closing line** — exactly one of: "Ready to go live." · "Ready after the listed
    minor fixes." · "Go live only after the listed high-priority issues are resolved." ·
    "Not ready — launch-blocking issues remain."

## Machine-readable findings

The report above is the primary deliverable and this block never replaces it — it exists so
`build-standard`'s fix loop can consume the audit programmatically instead of re-reading
prose. Emit it as a single fenced ```json block, always the last thing you output, every
time you run this skill.

Required shape:

- `score` (0–100, matches the Verdict) and `launch_status` — one of `ready` ·
  `ready-with-minor-fixes` · `conditionally-ready` · `not-ready`.
- `counts` — number of findings at each severity: `blocker`, `high`, `medium`, `low`,
  `observation`.
- `findings` — an array, one entry per issue. Each entry:
  - `id` — kebab-case, **stable across re-runs of the same underlying issue**
    (`tax-enabled-no-rates`, not `finding-014`) so the fix loop can tell iteration 1's High
    from a genuinely new High on iteration 2.
  - `severity` — `blocker` | `high` | `medium` | `low` | `observation`.
  - `category` — matches a scorecard category (`security`, `functionality`,
    `accessibility`, `performance`, `trust-and-conversion`, etc.).
  - `title` — short, human-readable.
  - `evidence` — what you actually observed, not what you assumed.
  - `location` — page, file, or setting.
  - `status` — `open` | `fixed` | `accepted-by-owner` | `not-verified`.
  - `owner_decision` — `true` if only the owner can resolve this (a Q1–Q7 item, a business
    decision), `false` otherwise.
- `not_verified` — array of the browser-dependent checks you could not run, each with what
  the owner should click to check it themselves.

Hard rules, mirroring `build-standard`'s fix loop:

- **Severity in this block may never be lowered between iterations to make the loop exit.**
  If `tax-enabled-no-rates` was `blocker` on iteration 1, it is still `blocker` on
  iteration 3 unless the tax rates were actually configured — not renamed to `medium` so
  the count hits zero.
- **`status: accepted-by-owner` requires the owner was actually asked and their answer
  recorded.** Never set it because a sensible default was silently applied, and never set
  it just to close out an item you didn't chase further.

Worked example (illustrative, not exhaustive — a real report has more findings):

```json
{
  "score": 84,
  "launch_status": "ready-with-minor-fixes",
  "counts": { "blocker": 0, "high": 1, "medium": 3, "low": 5, "observation": 2 },
  "findings": [
    {
      "id": "tax-enabled-no-rates",
      "severity": "high",
      "category": "functionality",
      "title": "Tax is enabled but no rates are configured for any region",
      "evidence": "wc_tax action=list_rates returned an empty table; wc_settings shows calc_taxes=yes",
      "location": "WooCommerce > Settings > Tax",
      "status": "open",
      "owner_decision": false
    },
    {
      "id": "llms-txt-404-as-200",
      "severity": "medium",
      "category": "code-quality-and-production-readiness",
      "title": "/llms.txt returns the default 404 page with a 200 status",
      "evidence": "wp_remote_get('/llms.txt') returned status 200, body matches the theme's not-found template",
      "location": "https://example.com/llms.txt",
      "status": "open",
      "owner_decision": false
    },
    {
      "id": "login-protection-not-asked",
      "severity": "medium",
      "category": "security",
      "title": "Q1 (login protection) was never asked",
      "evidence": "No record of the login-protection question in memory or the build log",
      "location": "wp-login.php",
      "status": "open",
      "owner_decision": true
    }
  ],
  "not_verified": [
    {
      "check": "mobile layout at 375px",
      "reason": "no browser available in this session",
      "owner_should": "open the site on a phone and check the pricing table doesn't overflow sideways"
    }
  ]
}
```

## Guardrails

- Label anything you did not actually test as *Not verified*. Never imply you tested it.
- Never print a secret in full.
- Do not soften a finding because the owner will be disappointed, and do not inflate one
  to look rigorous.
- When the audit is finished, offer to fix things — as a separate, agreed piece of work.

## Finish check

Copy this into your reply and tick each box as you do it.

- [ ] Evidence gathered by loading real pages, not from memory or from a tool's return value
- [ ] Every area in "What to check" covered, including the ones that scored well
- [ ] `security-standard` §2 used for the security checks rather than re-derived here
- [ ] Scores add up and no severity was lowered to make the total look better
- [ ] The report names what could NOT be checked, instead of leaving it out

If evidence is missing for any area, go back and gather it before scoring that
area. **After two attempts, record it as not verified** and say why — an area
reported as passing on no evidence is worse than one reported as unchecked.

## Related skills

- **`launch-readiness-and-compliance`** — the legal and cheap-looking gate. Run it too.
- **`seo-audit-and-fix`** — where speed, crawl and duplicate findings are actually measured.
- **`build-standard`** — the definition of done this audit is scoring against.
- **`web-design-master`**, **`motion-and-interaction`** — where the design findings get fixed.
- **`security-standard`** — the hardening pass, which this does not replace.

## Routing examples

Phrases that must reach this skill, and one that must not. `bin/lint-skills.php`
checks them against every other skill's description.

- fires: audit my site before it goes live
- fires: give me a scored report on this site
- skips: add a tracking pixel to every page
