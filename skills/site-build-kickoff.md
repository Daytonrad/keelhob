---
name: site-build-kickoff
title: Site build kickoff
description: The FIRST skill to load whenever someone asks to build a WordPress site from scratch — "build me a website", "make my site", "I need a website for my business", or any brand-new-site request with no detailed brief attached. Runs a short menu + intake Q&A instead of requiring a long prompt, saves the answers to memory, then hands off to website-building/elementor-page-building/solace-header-footer/blog-publishing/seo-optimization. Do NOT use for edits to an existing page — only for a new site build.
enable_agentic: true
enable_prompt: true
role: procedure
surface: interactive
requires: build-standard
next_skills: website-building, web-design-master
verified_by: launch-readiness-and-compliance
---

# Site build kickoff

<!-- CONTENTS:START -->
## Contents

- [When to use](#when-to-use)
- [When NOT to use](#when-not-to-use)
- [Step 0 — load the standard, then check memory](#step-0-load-the-standard-then-check-memory)
- [Step 1 — stack menu (ask this first, one question)](#step-1-stack-menu-ask-this-first-one-question)
- [Step 2 — intake batch (ask together, one batch, not one-by-one interrogation)](#step-2-intake-batch-ask-together-one-batch-not-one-by-one-interrogation)
- [Step 3 — save the brief](#step-3-save-the-brief)
- [Step 4 — build order](#step-4-build-order)
- [Step 4.5 — the closing loop (mandatory, not optional)](#step-45-the-closing-loop-mandatory-not-optional)
- [Step 5 — revision loop (how to hand it over)](#step-5-revision-loop-how-to-hand-it-over)
- [Delegating to sub-agents](#delegating-to-sub-agents)
- [Guardrails](#guardrails)
- [Routing examples](#routing-examples)
- [Related skills](#related-skills)
<!-- CONTENTS:END -->

A simple "build me a website" is enough. Never demand a long brief — ask a short menu + a short batch of questions, then build. Keep every reply caveman-brief: short sentences, no filler, plain words. Save state as you go so a fresh session can resume.

## When to use

The very first move on a brand-new site request that arrives with no detailed brief. It runs the short intake that turns "build me a website" into something buildable.

## When NOT to use

Not when a brief, a screenshot or a design already exists — go straight to `website-building`. Not for editing a site that is already up.

## Step 0 — load the standard, then check memory
Load `build-standard` first, before anything else — it is the Definition of Done every
page you build here has to clear, and it routes you to the right skill for each part of
the job. Then `memory_list` / `memory_get` — if a stack choice and brief already exist (a
prior kickoff was interrupted), resume from there instead of asking again.

State the beginner promise to the owner at kickoff, once, in these words:

> "I'll build this, then audit my own work, fix what I find, and show you the score. You
> won't have to ask me to check SEO, accessibility, speed or security — that's part of
> building it. Anything only you can decide, I'll list plainly at the end."

## Step 1 — stack menu (ask this first, one question)
Ask the user to pick a builder/theme stack. Give a one-line reason for each so they can choose blind:

1. **Xpro + Elementor** (default) — free, lightweight theme, Xpro Theme Builder for header/footer. Fastest, no extra install.
2. **Solace + Elementor** — free Solace theme + Solace Extra's Site Builder for header/footer. Good when the user wants a more decorated/starter-template look. Read `solace-header-footer` skill before touching Solace's header/footer — it has two competing header systems and real traps.
3. **Elementor Pro Theme Builder** — only if `elementor_settings action=get` reports `pro_active: true`. Best header/footer flexibility (mega menus, popups).
4. **Custom-coded page** (no builder) — pixel-perfect, not client-editable. Only when the user explicitly wants max fidelity over editability.

If unsure, recommend #1. Install what's picked: `manage_theme`, `manage_plugin`, `elementor_settings action=prepare_for_ai`. Save it: `memory_save key=site.stack value=<choice>`.

## Step 2 — intake batch (ask together, one batch, not one-by-one interrogation)
Ask everything needed to build without guessing:

- **Site name / business name.**
- **Niche / what the business does** — one line is enough, more detail helps.
- **Primary goal** — leads, bookings, sales, portfolio, info only.
- **Pages** — which ones and how many (home, about, services, contact, etc.). If they don't know, propose a sensible default set for their niche and confirm.
- **Blog** — build blog posts now, or skip and do it later? If now: how many, and target keywords/topics if known.
- **Design system** — do they have brand colors/fonts/logo already, or should the agent propose one? If proposing, follow `web-design-master`.
- **Content sources** — where should images/videos come from: their own files (ask them to attach/upload), `image_search` (free stock), `image_generate` (if a key is set), or a reference site/screenshot to match.
- **Reference design** — any site or screenshot to match the style of? If yes, that changes the build to a clone workflow (see Step 3).
- **Hero treatment** — a normal image hero, or a cinematic hero video that scrubs with
  the scroll? Only offer the second when the product is visual and the user has (or can
  make) footage; it changes the build to Mode 3 in `website-building`, and the video and
  its ffmpeg re-encode have to happen outside WordPress.
- **Domain/credentials** — only if going live now; otherwise skip.

**Security — most of it is not a question.** The automatic security floor (file editor,
registration, headers, cookies, debug output, and the rest — see `build-standard`'s
security table) gets applied silently; do not ask about any of it. Only the seven
owner-decision items go in the intake batch, asked together as one line, not as seven
separate questions: login protection, update policy, security plugin/WAF and who pays,
XML-RPC, REST restriction, PHP execution in uploads, and account recovery custody. Full
wording and the default for each lives in `security-standard` §3 (mirrored in
`build-standard`'s table) — "you decide" is a valid answer and applies the stated default.
This keeps the one-prompt goal intact: everything provable gets built silently correct,
and the owner is only asked what genuinely needs their judgment.

Ask for the real assets in this same batch — logo, product photos, hero footage, any
existing copy — and wait for them before building. Building against placeholders and
swapping later doubles the work, because the design ends up shaped around the wrong
picture. If an asset never arrives, say which one is missing and what you used instead.

Don't ask about things covered by the stack choice already (builder, header/footer tool). Don't ask more than this batch — extra questions can wait until they come up mid-build.

## Step 3 — save the brief
`memory_save` the whole intake as a structured brief (e.g. `site.name`, `site.niche`, `site.goal`, `site.pages`, `site.blog`, `site.design`, `site.content_source`, `site.stack`) so it survives context loss and every sub-task can read it back instead of re-asking the user.

## Step 4 — build order
1. Site chrome first: theme active, Elementor prepared, header + footer built and verified live on one page (use the stack's builder — Xpro/Solace/Pro Theme Builder per `elementor-page-building` / `solace-header-footer`).
2. Menus created (`manage_menu`) before any page links to them.
3. Homepage.
4. Remaining pages, one at a time, each checked against the brief and the `web-design-master` quality bar — with `motion-and-interaction` loaded alongside it. A page that does not move reads as a template no matter how well it is typeset, and retrofitting motion afterwards is harder than building it in.
5. Blog posts — only if the brief says "now" (`blog-publishing` skill); otherwise leave for later and say so.
6. `seo-optimization` pass across every new page once content is final.
6b. `launch-readiness-and-compliance` before anything is shown to the public. It asks what the other skills do not: whether the site is allowed to do what it is doing, and whether it reads as paid-for. For a shop this is not optional — a store owes its customers returns terms, delivery terms and honest prices.
7. Clear Elementor cache once, at the end, not per-page.
8. The pages existing is not the same as the build being over — go to Step 4.5 before
   reporting anything as done.

## Step 4.5 — the closing loop (mandatory, not optional)

The build is not finished when it looks right. Run `build-standard`'s fix loop now:
`pre-launch-audit` against your own work, fix every Blocker and High, re-run, up to 3
iterations, anything still failing goes into the report as an outstanding item — never a
silent pass. Do not skip this because the pages look done: a store built this way looked
finished and scored 56/100 — not ready — the first time an independent auditor opened it.

Close every build with the completion report's three sections, in this order: (1) score
and what was fixed automatically, (2) needs you — with why and the consequence of
skipping it, (3) not verified — browser-dependent checks with exactly what to click. This
is what goes to the user next, not "the site is live."

## Step 5 — revision loop (how to hand it over)

Do not present the site section by section and ask "is this right?" after each one. Ask
the user to do **one full pass on a real phone and a real desktop**, collect every note,
and send them together. Then work the whole batch, verify, and hand it back. One batched
round beats fifteen single-note rounds: it costs less of their attention, and notes that
contradict each other surface while they are still cheap to reconcile.

The closing loop in Step 4.5 already ran before this pass — this round is for taste and
judgment calls only, not for the things a fresh audit should have already caught.

## Delegating to sub-agents
If the environment supports spawning sub-agents (e.g. an `Agent`/Task tool is available), don't build every page yourself serially:
- Spawn one cheap sub-agent per independent page/section once chrome + brief + design system are locked, each given: the saved brief, the design tokens, the stack choice, and which single page to build.
- Keep header/footer and the design-system decision on the main thread — never delegate those, everything else depends on them.
- Review each sub-agent's page against the brief and `web-design-master` §8 checklist before marking it done; send it back with precise fixes if it misses.
- If no sub-agent tool is available, build sequentially yourself — don't block on it.

## Guardrails
- Never invent business facts (address, phone, testimonials, stats) — placeholder + flag it if the user hasn't given it.
- Never ship lorem ipsum or grey placeholder images — use `image_search`/`image_generate` or ask for real assets.
- Confirm before overwriting an existing live site's homepage.
- Stay caveman-brief in every message to the user, from the first menu question to the final report.

## Routing examples

Phrases that must reach this skill, and one that must not. `bin/lint-skills.php`
checks them against every other skill's description.

- fires: build me a website
- fires: i need a website for my business
- skips: add a tracking pixel to every page

## Related skills

- **Requires** — build-standard. Load these before you start.
- **Next** — website-building, web-design-master. Consider these when this skill is done.
- **Verified by** — launch-readiness-and-compliance. The work is not finished until this has run and passed.
