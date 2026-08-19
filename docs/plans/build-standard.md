# Plan v2 — make "done" mean done

> **Status (v1.10.0): all five phases are implemented.**
>
> Shipped: the three Tier 0 rules in `Claudpress_MCP_Server::operating_protocol()`; the
> `build-standard` and `security-standard` skills; the seven domain skills turned into
> gates; the tiered intake and the three-section completion report; the adoption rules
> folded into `site-safety`; and Phase 3, the `site_readiness` MCP tool
> (`includes/tools/class-tools-readiness.php`) — read-only, always-on, returning structured
> `pass`/`complete`/`findings`/`skipped`/`not_verified` shape-compatible with
> `pre-launch-audit`'s JSON block. `pre-launch-audit` still emits that block; the fix loop
> can consume either source now.
>
> What has **not** happened yet is the test in "How we will know it worked" below: a
> single-prompt build on a clean install, audited by a fresh agent with no build context.
> The tool exists, but the standard it encodes has not been validated end-to-end on a real
> build. That remains the outstanding piece of work.

> Supersedes v1 (commit `a9eeb09`). Two things changed: **security became a first-class
> domain** (see `security-standard.md`, researched separately), and **a review of v1 found
> a design flaw that would have made the whole standard optional.**

## Why this exists

Pranknologia was built with this plugin. It looks good. An independent audit scored it
**56/100 — not ready**; a second, after remediation, **62/100**. Across both: no meta
descriptions, no Open Graph, no product schema, 632 of 647 images with no alt text, broken
heading order on every page, fabricated social proof on a store with zero orders, a FAQ
contradicting the refund policy, no Terms page, every 404 redirecting to the homepage, no
security headers, public registration wide open, the file editor enabled, a static-key
backdoor, taxes on with no rates.

None of it exotic. All of it things a competent builder does *while building*.

The agent didn't skip them out of incompetence. **Nothing in the skill set said they were
part of the job.** Thirteen skills describe how to build; none defines what finished means;
the audit skill is opt-in and read-only. So the owner became the QA department — backwards,
and impossible for the beginner this plugin targets.

**The fix is not more skills. It is a contract, a gate, and a loop.**

---

## Review of v1 — what was wrong with my own plan

Verified against the source, not assumed.

| # | Finding | Evidence | Consequence |
|---|---|---|---|
| **R1** | **A mandatory standard cannot live only in a skill.** | `Claudpress_Skills::agentic_catalog()` requires `enabled=1 AND enable_agentic=1`. | Disable one skill and the entire contract silently vanishes. |
| **R2** | **The delivery claim was wrong.** v1 said "ship with a version bump so `skill_refresh` carries it". | `bundled_action()` returns `keep` when `bundled_hash` is empty — true for every skill installed before 1.9.0 — and keeps owner-edited skills forever. | A version bump alone delivers nothing. The standard would never reach existing sites. |
| **R3** | **The fix loop never terminates and invites gaming.** | "repeat until 0 Blockers and 0 Highs" with the same agent scoring and fixing. | A High needing an owner decision loops forever; the cheapest exit is to downgrade the finding. |
| **R4** | **Self-audit conflict of interest.** | The audit skill *asks* the agent to be harsher on its own work. | A plea, not a mechanism. |
| **R5** | **Half the Definition of Done is unverifiable here.** | Claudpress has no browser; both real audits marked responsive/console "Not verified". | Requiring "no horizontal scroll at 375px" forces the agent to either stall or lie. |
| **R6** | **No page-level vs site-level split.** | — | A full-site audit for one new page is disproportionate, so it gets skipped. |
| **R7** | **No rollback for automated fixes.** | Snippets are reversible and content has `/undo`; option and file changes are not. | A bad automated fix has no undo. |
| **R8** | **Content-integrity rule had an imagery hole.** | It covered numbers and testimonials but not invented *people*. Pranknologia had staff named "Jordan · Merchandising". | Same lie, same legal exposure, not covered. |
| **R9** | **"State an explicit budget" states nothing.** | The audit found 122 MB of images and an 867 KB logo. | Unenforceable. |
| **R10** | **The success test was circular.** | Same model builds, then audits against its own standard. | Proves nothing. |
| **R11** | **No contract for owner-only actions.** | — | "Done" quietly hides what only the owner can do. |
| **R12** | **Security was almost entirely absent.** | One bullet, in the *why* list. Nothing in the Definition of Done. | The exact gap that produced the audited failures. |
| **R13** | **Intake could balloon.** | Adding security questions to an already-long intake. | Defeats the one-prompt goal. |
| **R14** | **Greenfield assumption.** | Claudpress is routinely pointed at sites that already exist. | No defined behaviour for adopting someone else's site. |

---

## The architecture (revised)

The key change from v1: **the obligation and the detail live in different places**, because
one must be impossible to switch off and the other must be editable.

```
TIER 0  operating_protocol()            ← THE OBLIGATION. Cannot be disabled.
        PHP, injected into every MCP      Short. The rules that must never be missed:
        session AND every unattended      content integrity, the closing loop,
        agent run. Not in the skills UI.  "you may not call it done until…"
           │
TIER 1  build-standard  (new skill)     ← THE DETAIL. Definition of Done, the
        the full checklist + router       skill router, page vs site gates.
           │
TIER 2  domain skills as GATES          ← seo / design / store / security /
        (7 existing, edited)              safety — each with a blocking floor.
           │
TIER 3  site_readiness  (new tool)      ← THE EXIT CODE. Objective, read-only,
        machine-checkable                 unarguable. Returns pass/fail JSON.
```

R1 and R2 are why Tier 0 exists. `operating_protocol()` is already injected verbatim into
interactive sessions (`server_instructions()`) and into unattended runs
(`class-agent-runtime.php`, twice), and is filterable in PHP but not toggleable from the
skills UI. It is the only channel that cannot be turned off — so the *obligation* goes
there, and the skill carries the detail it points to.

---

## Phase 1 — the contract, in two places

**Tier 0 — add to `operating_protocol()`** (kept short; this is injected into every prompt):

> **Definition of done.** A build is not finished when it looks right. Before reporting any
> site or page as done you must run `pre-launch-audit` against your own work and resolve
> every Blocker and High. You may not lower a finding's severity to pass. If something can
> only be decided by the owner, say so explicitly and list it — do not silently accept it.
>
> **Never invent facts.** No statistic, review, testimonial, customer count, credential, or
> photograph presented as a real person or customer, unless the site can substantiate it. A
> store with no orders has no customers.
>
> **Load `build-standard` before building anything.** It lists what "done" requires and
> which skill to use for each part of the job.

**Tier 1 — the `build-standard` skill** carries: the full Definition of Done (below), the
skill router table, the page-level vs site-level split, the fix-loop rules, and the
completion-report format.

---

## The Definition of Done (v2)

Split three ways, because v1's flat list mixed things the agent can prove, things it
cannot, and things only the owner can decide (**R5, R11**).

### Page level — every page, every time (cheap, always run)
- One `<h1>`; heading levels never skip.
- Every content image has meaningful alt text; decorative images `alt=""`.
- Title + meta description present; Open Graph on anything shareable.
- Every link resolves. No `href="#"`, no "coming soon", no lorem, no placeholder image.
- No image over **300 KB**; the LCP/hero image under **200 KB**; everything below the fold
  lazy-loaded; modern format where the host supports it. *(**R9** — real numbers.)*
- Body-text contrast ≥ 4.5:1 against its actual background.
- Every factual claim is true, and consistent with every other page.

### Site level — before "the site is done", and before launch
- Legal pages exist, are published, are linked, and are wired to the relevant settings.
- Nothing contradicts anything: FAQ vs policy vs actual configuration must agree.
- Product schema where applicable — and **never** `aggregateRating`/`review` without real
  reviews.
- Sitemap, robots, `llms.txt` serving correctly (and with a 200 — a 404 body is not served).
- Commerce: tax enabled ⇒ rates configured (or tax off); stock policy explicit; review
  policy explicit; **one real order placed and refunded** before taking money.
- The full **security** floor below.
- 404s return 404 and are useful.

### Security — AUTOMATIC (do it, don't ask)
From `security-standard.md` §2 — 20 checks, of which the ones that produced real findings
here: file editor disabled · public registration closed unless needed · no user named
`admin` · user enumeration closed · HTTPS enforced · security headers present · auth
cookies Secure/HttpOnly/SameSite=Lax · salts present · `WP_DEBUG` off and no public
`debug.log` · no exposed `.sql`/`.git`/config backups · directory listing off · file
permissions sane · everything updated, nothing unused installed · application-password and
REST auth surface audited · **no static-key or hardcoded-secret endpoints in custom code**
· uploads hygiene · custom code uses the WordPress escaping/nonce/capability APIs · backups
exist off-site.

> That static-key check is not hypothetical: this site shipped a preview bypass on a
> hardcoded key with a week-long cookie. The research flagged the same class independently.

### Security — NEEDS THE OWNER'S DECISION (ask, with a default)
Login protection (2FA / lockout / hidden login URL) · update policy · security plugin or
WAF and who pays · XML-RPC · REST restriction · PHP execution in uploads · account recovery
custody. Seven questions, each with a stated default if the owner says "you decide"
(**R13** — tiered, not a wall).

Renaming the login URL is explicitly **not** a silent default: it is bot-noise reduction,
not a security control, and it is a lockout risk.

### Needs a browser — state, never fake (**R5**)
Viewport sweep at 375/768/1280/1440, console errors, real touch-target sizes, animation
smoothness. Claudpress has no browser. The agent must use markup-level proxies (fixed
widths, unconstrained images, missing labels) and then **list these as outstanding in the
completion report** — never silently pass them. If the client the agent runs in *does* have
a browser, it must use it.

---

## The fix loop — with termination and anti-gaming rules (**R3, R4, R7**)

1. Run `site_readiness` for the objective baseline, then `pre-launch-audit` for the
   judgement pass it cannot do.
2. Fix every Blocker and High.
3. Re-run. Repeat to a maximum of **3 iterations**.
4. Anything still failing after 3 goes to the completion report as an explicit outstanding
   item with the reason — never as a pass.

Hard rules:
- **Severity may never be lowered to make the loop exit.** Mirrors the orchestrator rule:
  you may not make a failing check pass by lowering the bar.
- **An owner-decision item is not a failure and not a pass** — it is `accepted-by-owner`
  only once the owner has actually been asked, with their answer recorded.
- **Evidence, not recall.** The audit must re-fetch rendered HTML and status codes. "I set
  that earlier" is not evidence — the four SEO bugs in 1.9.1 all reported success while
  emitting nothing.
- **Prefer a fresh auditor.** Where the client supports sub-agents, run the audit in one
  with no build context. Self-auditing is the weakest link in this design.
- **Snapshot before fixing.** Record the prior value of every option and file the loop
  intends to change, so a bad automated fix is reversible.

---

## Phase 2 — domain skills become gates

| Skill | Change |
|---|---|
| `site-build-kickoff` | Mandatory closing loop; security intake questions; points at `build-standard` at step 0. |
| `seo-optimization` | Required build phase, not optional: meta, OG/Twitter, schema, sitemap, robots, `llms.txt`, verified in rendered HTML. Never-fake-reviews rule. |
| `web-design-master` | §8 becomes blocking numbers: alt coverage, heading order, contrast, image budget, touch targets. |
| `store-launch-and-deploy` | Commerce gates: tax/rates, stock, reviews, legal wiring, one real test order. |
| `website-building` | Runs the page-level list before handing any page over. |
| `pre-launch-audit` | Add the checks both audits found ad hoc (tax-with-no-rates, stock, registration, file editor, caching, `llms.txt`, heading order, image weight) **and the full security section**. Emit a **machine-readable findings block** — it currently has none, so nothing can consume it in a loop. |
| `site-safety` | Never report done without the audit; snapshot-before-fix. |
| **`security-standard`** | **New skill** — the researched reference, loaded when hardening or auditing security. |

So: **two** new skills (`build-standard`, `security-standard`), not one. Still no
skill-per-domain sprawl.

---

## Phase 3 — `site_readiness` (the exit code)

Read-only MCP tool returning structured pass/fail for the objective half: alt coverage,
meta/OG per page, JSON-LD validity, `href="#"` count, heading order, image weight, security
headers, the automatic security checks, tax sanity, stock config, legal wiring, snippet
errors, `llms.txt` status, sitemap/robots.

Conditional: WooCommerce checks only when WooCommerce is active. Lives in an always-on
read-only capability group so it works with abilities restricted. Ships with tests.

This is what turns "done" from a judgement into an exit code the agent cannot argue with.

---

## Phase 4 — intake, and the beginner promise

Tiered so the one-prompt goal survives (**R13**): secure defaults applied silently; only
the seven questions that change how the owner uses their own site get asked; "you decide"
is a valid answer with a documented default.

The promise, stated at kickoff:

> "I'll build this, then audit my own work, fix what I find, and show you the score. You
> won't have to ask me to check SEO, accessibility, speed or security — that's part of
> building it. Anything only you can decide, I'll list plainly at the end."

**Completion report format** (**R11**) — mandatory, three sections:
1. **Score and what was fixed automatically.**
2. **Needs you** — each with why, and the consequence of skipping it (payment credentials,
   tax position, real testimonials, legal review, DNS, 2FA custody).
3. **Not verified** — browser-dependent checks, with exactly what to click.

---

## Phase 5 — adopting an existing site (**R14**)

Claudpress is often pointed at a site that already exists. Building rules do not apply
cleanly to someone else's work.

Rule: **audit first, report, then ask before changing anything you did not build.** Never
mass-rewrite existing content, never bulk-toggle settings, never "fix" a design decision
uninvited. Present the findings and let the owner choose. Run the compromise-detection
checks (`security-standard.md` §6) *before* starting work — building on a site that is
already backdoored just adds to it.

---

## Delivery — how this actually reaches installed sites (**R2**)

The delivery hole matters more than the content. Three channels, deliberately:

1. **Tier 0 ships with the plugin code.** `operating_protocol()` is PHP — updating the
   plugin updates the obligation for every site immediately. Cannot be disabled or edited
   away.
2. **Tier 1/2 skills need `skill_refresh`.** A version bump alone does **not** deliver:
   pre-1.9.0 rows have no `bundled_hash`, so the first refresh keeps and stamps them, and
   only a second one updates. Owner-edited skills are kept indefinitely by design.
   → The release notes must tell owners to run `skill_refresh`, and the new-site path must
   seed the new skills on activation.
3. **Tier 3 is a tool** — arrives with the plugin, governed by capability toggles.

Consequence worth stating plainly: **anything that must never be missed belongs in Tier 0.**
Everything else is best-effort.

---

## Sequencing

1. **Phase 1** — Tier 0 protocol text + `build-standard` skill. Small, highest leverage.
2. **Phase 2** — 7 skill edits + `security-standard` skill. Low risk, markdown.
3. **Phase 4** — intake + report format. Trivial once 1–2 exist.
4. **Phase 3** — `site_readiness` tool. Most effort; do it once the standard is proven, so
   the tool encodes something validated rather than guessed.
5. **Phase 5** — adoption rules. Fold into `site-safety`.

---

## How we will know it worked (**R10** — no longer circular)

Build a site with a single prompt on a **clean install**. Let the agent run to its own
definition of done. Then audit it with a **fresh agent that has no build context**, against
the fixed rubric in `pre-launch-audit`, and compare.

**Target: 0 Blockers, 0 Highs, score in the 80s, without the user asking for a single
check** — and the completion report honestly listing what needs the owner and what needs a
browser.

Pranknologia scored **56** unprompted. That is the baseline to beat.

---

## Non-goals

Not a skill per quality domain — the failure was never "no skill existed", it was "no skill
was called". Not automating what is genuinely the owner's call: tax position, returns
policy, real testimonials, taking payments live, login-URL renaming, or spending their
money on a WAF. Not enforcing CSP or aggressive `.htaccess` rules by default — the research
is explicit that those cause more outages than they prevent breaches; Report-Only first.
