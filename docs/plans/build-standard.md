# Plan — make "done" mean done

## Why this exists

Pranknologia was built with this plugin. It looks good. Then an independent audit scored
it **56/100 — not ready**, and a second one **62/100 — conditionally ready**, across
**2 blockers, 7 high, 9 medium and 6 low** findings.

None of those findings were exotic. They were:

- no meta description or Open Graph tag on any page
- no product structured data
- 632 of 647 images with no alt text
- broken heading order and no skip link on every page
- 122 MB of unoptimised images, the logo alone 867 KB
- fabricated social proof ("5,000+ people pranked") on a store with zero orders
- a Contact FAQ promising "30 days, no questions asked" while the refund policy said
  damaged-only
- no Terms page at all, and a Privacy Policy left as a draft
- every 404 silently redirecting to the homepage
- no security headers, versions advertised
- newsletter signups going nowhere with no consent record
- taxes switched on with zero tax rates configured

Every one of these is something a competent builder does **as part of building**. The
agent didn't skip them out of incompetence — it skipped them because **nothing in the
skill set said they were part of the job.** The skills describe how to *build*. Not one
of them defines what *finished* means, and the audit skill is opt-in and read-only.

So the owner became the QA department. They had to know to ask "is my checkout tested?",
"do I have a privacy policy?", "why do my links look bare on WhatsApp?" — which is
exactly backwards, and impossible for the beginner this plugin is meant to serve.

**The fix is not more skills. It is a contract, a gate, and a loop.**

---

## The four structural gaps

| # | Gap | Consequence |
|---|---|---|
| 1 | **No definition of done.** 13 skills, none states completion criteria. | The agent stops when the page looks right. |
| 2 | **The audit is opt-in and read-only.** Nothing says run it, and nothing says fix what it finds. | Quality is discovered by the user, months later. |
| 3 | **No routing map.** Skills are offered as a flat list of descriptions; the agent picks reactively. | SEO, a11y and performance skills exist and are never called. |
| 4 | **Nothing is machine-checkable.** Every standard is prose. | "Done" drifts with the model's mood. |

---

## The shape of the fix

```
        ┌───────────────────────────────────────────────┐
        │  build-standard  (NEW)                        │
        │  the contract: what DONE means + skill router │
        └───────────────────────────────────────────────┘
                 ▲ referenced by every build skill
                 │
  kickoff ─► build ─► SEO ─► a11y/perf ─► commerce ─► legal
                 │
                 ▼
        ┌───────────────────────────────────────────────┐
        │  MANDATORY CLOSING LOOP                       │
        │  pre-launch-audit ─► fix ─► re-audit          │
        │  repeat until 0 Blockers and 0 Highs          │
        │  only then may the agent say "done"           │
        └───────────────────────────────────────────────┘
```

---

## Phase 1 — `build-standard` (new skill, the only new one)

One short, blunt, non-negotiable document. Everything else points at it.

**A. The Definition of Done.** A site or page is not done until every line is true and
*verified by inspection, not assumed*:

- Every content image has meaningful alt text. Decorative images have `alt=""`.
- One `<h1>` per page; heading levels never skip; a skip link exists.
- Every page has a title and a meta description; every shareable page has Open Graph tags.
- Products carry Product JSON-LD — and **never** `aggregateRating`/`review` markup unless
  real reviews exist.
- No page weighs more than it needs to: images sized, compressed, lazy-loaded below the
  fold. State an explicit budget.
- No horizontal scroll at 375px. Touch targets ≥ 44px.
- Colour contrast ≥ 4.5:1 for body text.
- Every link resolves. No `href="#"`, no "coming soon", no lorem, no placeholder image.
- Every factual claim on the site is true and consistent with every other page.
- Legal pages exist, are published, are linked, and are wired to the relevant settings.
- Nothing contradicts anything: FAQ vs policy vs actual configuration must agree.
- The console is clean and no request 404s.

**B. Content integrity — a hard rule, not a preference.** Never write a number, review,
testimonial, customer count, view count or credential the site cannot substantiate. A
store with zero orders may not claim customers. This is the rule that "5,000+ people
pranked" broke, and it carries real legal exposure under the FTC's 2024 rule and the UK
DMCC Act.

**C. The skill router.** A plain table the agent reads once: *task → skill to load*.
Building a page, styling it, an Elementor build, a store, a blog post, SEO, launching,
auditing, writing code. This closes gap 3 — the agent stops having to guess what exists.

**D. The closing sequence.** Written as an ordered, non-skippable list, ending with:
> You may not report a build as finished until `pre-launch-audit` has been run against it
> and every Blocker and High has been fixed or explicitly accepted by the owner in
> writing. "It looks right" is not done.

---

## Phase 2 — turn existing skills from advice into gates

No new skills. Each of these already covers its domain; each needs its floor made
blocking and its gaps filled from the audit findings.

| Skill | Change |
|---|---|
| `site-build-kickoff` | Add the mandatory closing loop. Make the audit a build phase, not a suggestion. Point at `build-standard` in step 0. |
| `seo-optimization` | Promote from optional pass to **required build phase**. Concrete checklist: meta description, OG/Twitter, Product JSON-LD, Organization schema, sitemap, robots, `llms.txt`. Add the never-fake-reviews rule. |
| `web-design-master` | Promote §8's audit from "score it" to **blocking gates with numbers**: 100% alt coverage on content images, heading order, skip link, contrast, 375px, touch targets, image weight budget. |
| `store-launch-and-deploy` | Add commerce-correctness gates: **tax enabled must have rates** (or be off), stock policy decided explicitly, review policy decided, short descriptions present, legal pages linked and wired, **one real test order placed and refunded**. |
| `website-building` | On finishing any page, run the page-level subset of the Definition of Done before handing it over. |
| `pre-launch-audit` | Add the checks both audits had to discover ad hoc: tax-enabled-with-no-rates, stock management, public registration, file editor enabled, caching, `llms.txt`, heading order, image weight. Emit a **machine-readable findings block** so a fix loop can consume it. |
| `site-safety` | Add: never report done without the audit. Keeps the safety skill as the single place "how to behave" lives. |

---

## Phase 3 — make it machine-checkable (`site_readiness` tool)

Prose standards drift. The durable fix is code.

A new read-only MCP tool, `site_readiness`, that runs the objective half of the Definition
of Done and returns structured pass/fail — alt-text coverage, meta/OG presence per page,
JSON-LD validity, `href="#"` count, heading order, image weight, security headers, tax
config sanity, stock config, legal pages wired, snippet errors, console-visible 404s.

Why this matters more than the prose: the agent can call it in a loop and cannot argue
with it. It turns "done" from a judgement into an exit code. It is also the natural
backbone for the closing loop in Phase 1D — *run it, fix what fails, run it again.*

Sits in the `seo`/`site_admin` capability group as read-only, so it works even with
abilities restricted.

---

## Phase 4 — the beginner path

The goal the owner actually stated: **one prompt, and it comes back finished.**

`site-build-kickoff` already asks the right intake questions. What it lacks is the promise
at the end. Add an explicit contract to the user at kickoff:

> "I'll build this, then audit my own work, fix what the audit finds, and show you the
> score. You won't need to ask me to check SEO, accessibility, speed or security — that's
> part of building it."

And a final report format: the readiness score, what was fixed automatically, what needs
a human decision (tax position, real testimonials, payment credentials), and what was
deliberately deferred. So the user's next step is always obvious.

---

## Sequencing and risk

1. **Phase 1** — one new skill file. No risk. Highest leverage: it is the thing that was
   missing.
2. **Phase 2** — edits to 7 existing skills. Low risk; they are markdown playbooks.
   Ship with a version bump so `skill_refresh` carries them to installed sites.
3. **Phase 3** — new tool, needs code, tests, and a capability-group entry. Medium effort.
   Do it after 1 and 2 prove the standard is right; the tool should encode a standard we
   have already validated, not the other way round.
4. **Phase 4** — copy changes to kickoff, trivial once 1–3 exist.

**Deliberate non-goals.** Not adding a skill per quality domain — that multiplies surface
without changing behaviour, since the failure was never "no skill existed", it was "no
skill was called". Not automating decisions that are genuinely the owner's: tax position,
returns policy, real testimonials, whether to take a payment live.

---

## How we will know it worked

Build a throwaway site with a single prompt on a clean install, let the agent run to its
own definition of done, then run `pre-launch-audit` independently. **Target: no Blockers,
no Highs, and a score in the 80s without the user having asked for a single check.**

Pranknologia scored 56 unprompted. That is the baseline this plan has to beat.
