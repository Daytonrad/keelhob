---
name: seo-optimization
title: SEO + GEO optimization
description: Required build phase, not an optional pass — meta title/description, Open Graph/Twitter cards, JSON-LD schema, sitemap, robots.txt, and llms.txt for every page, verified in the rendered HTML. Also covers target keywords, GEO/AI answer-engine visibility, redirects, and ranking fixes. Use whenever the task involves SEO, GEO, AI search visibility, ranking, meta tags, schema, keywords, ChatGPT/Perplexity/AI Overviews citations, "make this rank"/"get this cited by AI", or any page-building/site-build-kickoff task reaching its SEO step.
enable_agentic: true
enable_prompt: true
role: gate
surface: any
next_skills: seo-audit-and-fix
verified_by: pre-launch-audit
---

# SEO + GEO optimization

**This skill is one page at a time.** For a whole site — crawling every page,
scoring speed, finding duplicates, connecting Search Console or a paid data
source, and producing a before-and-after report — load **`seo-audit-and-fix`**
instead. That job is expensive and has to be agreed with the owner first; this
one is part of building a page and runs constantly.

This is a **required build phase**, not an optimization you get to later. `build-standard`
lists meta title + description, Open Graph/Twitter cards, JSON-LD schema, sitemap,
robots.txt, and `llms.txt` in its page- and site-level Definition of Done — a page without
these is not done, the same as a page with a broken link.

Two goals, one workflow: rank in classical search (Google/Bing) AND get cited by AI answer engines (ChatGPT, Perplexity, Google AI Overviews, Gemini, Claude). GEO is not a separate discipline bolted on after — AI engines extract and cite pages that are already well-structured, well-sourced, and fresh, so most of the work below serves both goals at once.

## When to use

Every page, every time, as a build phase rather than an afterthought — meta title and description, Open Graph and Twitter cards, JSON-LD schema, sitemap, robots.txt and llms.txt, checked in the rendered HTML.

## When NOT to use

Not for auditing a site that already exists across all its pages — that is `seo-audit-and-fix`. This skill assumes you are building or editing the page in front of you.

## Verify in the rendered HTML, not from a tool's return value

A `seo_*` tool reporting success is not evidence the tag exists. Release 1.9.1 shipped
four silent SEO head-injection bugs — every one of them returned success while emitting
nothing into `<head>` (see the git log commit "Fix four silent SEO head-injection bugs
found by a live audit"). Recall is not verification; the fix could have regressed, a
caching layer could be serving a stale head, or a theme/plugin could be stripping the tag.

After every `seo_meta`, `seo_schema`, `seo_sitemap`, or `seo_redirect` call that is meant
to change what a visitor's browser receives, fetch the actual page and grep for the tag:

```php
$html = wp_remote_retrieve_body( wp_remote_get( $url ) );
// then grep $html for <title>, meta[name=description], og:*, application/ld+json, etc.
```

Run this through `execute_php`. Do not report a meta tag, schema block, or crawler file as
present until you have seen it in the fetched body — never from memory of having set it.

The `seo_*` tools write to whatever SEO plugin is active (Yoast, Rank Math, AIOSEO, SEOPress) and fall back to Keelhob's own head injection when none is present — so your changes apply either way.

## Before writing anything
If the site has brand/voice reference material (check `memory_get` for a saved brand brief, voice guide, or fact sheet), read it first. An AI search engine isn't matching keywords — it's deciding *who to recommend* — so generic copy that could belong to any site is a GEO liability, not just a style problem. Avoid AI-tell phrasing ("unlock," "leverage," "seamless," "in today's fast-paced world"); lead with a direct answer, not a preamble ("In this article we'll explore...").

## Workflow for a single URL
1. `seo_analyze post_id=… focus_keyword=…` — returns two scores: a classical `score` (title/meta length, keyword placement/density, word count, headings, links, alt coverage) and a `geo_score` (answer-first opening, FAQ-style headings, list/table structuring, schema presence, author bio, content freshness, outbound citations). Read the `checks` array — each failing check has a `note` explaining why it matters to AI engines, not just Google.
2. Fix failing classical checks, in priority order:
   - **SEO title** (`seo_meta action=set title=…`): 30–60 chars, keyword near the front, one clear promise. Do not stuff.
   - **Meta description** (`description=…`): 120–160 chars, includes the keyword naturally, ends with a reason to click.
   - **Keyword placement**: focus keyword in title, URL slug, first paragraph, and at least one H2.
   - **Density**: keep 0.5–2.5%. If higher, rewrite for readability, don't delete mechanically.
   - **Images**: every content image needs descriptive `alt` text.
   - **Internal links**: add at least one relevant internal link.
3. Fix failing GEO checks:
   - **Answer-first opening**: state the direct answer/definition in the first 1–2 sentences, before any throat-clearing.
   - **FAQ-style headings**: phrase 2–4 H2/H3s as real questions ("How much does X cost?") answered in 2–4 sentences directly underneath — this is what gets lifted verbatim as an AI citation.
   - **List/table structuring**: convert dense paragraphs of comparable items into a `<ul>`/`<ol>`/table where it fits naturally.
   - **Outbound citations**: link 1–2 authoritative external sources (not competitors) to signal the page is a sourced primary reference, not a rehash.
   - **Freshness**: update stale posts (>6 months) with a current date-stamped revision rather than leaving them static — AI engines deprioritize stale sources.
   - **Author bio**: make sure the WordPress user publishing has a filled-in bio (`manage_user`) — it feeds the `Person` author schema below and is a real E-E-A-T signal.
4. Re-run `seo_analyze` and report the before/after for both scores.

## Structured data
`seo_schema action=set` two ways:
- **Hand-written**: pass `schema={...}` (or an array of objects — Keelhob combines them into one `@graph` so a page can carry `Article` + `FAQPage` + `BreadcrumbList` together). Match the type to the page: `Article`/`BlogPosting` for posts, `Product` (+ `offers`, `aggregateRating`) for products, `LocalBusiness`/`Organization` site-wide, `BreadcrumbList` for navigation.
- **Generated**: pass `generate=article|faq|howto|local_business|person` with `data={...}` and Keelhob builds valid JSON-LD for you — `generate=article` automatically pulls the post author into a `Person` sub-schema (name, bio, author URL), which is exactly the attribution data AI engines use to decide whose content to trust and cite. `generate=faq` takes `data={"items":[{"question":"...","answer":"..."}]}` and is the fastest way to make FAQ-style headings machine-citable, not just human-readable.

Always use real values from the page — never placeholder text.

**Never fake reviews.** Never emit `aggregateRating` or `review` schema unless real
reviews exist behind it. A store with no orders has no reviews to schema — leave the
property out entirely rather than inventing a rating. This is not just a content-honesty
rule: fabricated review markup is a violation of Google's structured-data policy and can
get the whole site's rich results suppressed, on top of being a lie to the visitor.

## Titles that win clicks (and citations)
Front-load the keyword, add a differentiator (year, number, benefit, location), stay under 60 chars so it doesn't truncate. Every page needs a *unique* title and description — never duplicate across pages.

## GEO infrastructure (site-wide, do this once per site)
- **llms.txt**: `seo_sitemap action=get_llms_txt` to check if one exists; `action=set_llms_txt content=…` to write it. This is the file AI engines read first to understand what a site is — format per llmstxt.org: an H1 site name, a one-line blockquote summary, then H2 sections with links to the pages worth knowing about (docs, key products/services, about/contact). Served automatically at `/llms.txt`.
- **AI crawler access**: `seo_sitemap action=get_ai_crawlers` to see current allow/disallow state for GPTBot, ClaudeBot, PerplexityBot, Google-Extended, CCBot, Applebot-Extended, Bytespider (all allowed by default). Never disallow one without the user explicitly asking to opt out of a specific AI engine — blocking these is invisible-to-AI-search by definition.
- **JS-rendered content**: if pricing, specs, or other commercial content only appears after client-side JS runs, AI crawlers (and many SEO crawlers) can't read it. Flag this to the user — it needs to render in the initial server HTML.

## Technical
- Redirects: when a slug changes, add a 301 with `seo_redirect action=add from=/old to=/new` so link equity and users survive.
- Sitemap: `seo_sitemap action=get_sitemap` for the URL; `action=ping` after a big content change (pings Bing only — Google retired sitemap ping in 2023, use Search Console for Google).
- robots.txt: `seo_sitemap action=set_robots` for extra rules — never block resources search engines or AI crawlers need to render/read the page.
- **Sitemap, robots.txt, and `llms.txt` must each return a real 200.** Fetch all three URLs
  directly and check the status code, not just that a body came back — a 404 page served
  with a 200 status is not "serving," and a sitemap/robots/llms.txt URL that 404s is
  invisible to crawlers even though it "looks fine" if you only glance at content.

## Speed is an SEO check, not a separate concern

A page can carry perfect meta and still not rank, because Google measures what
the visitor experiences. Judge every page against these before calling it done:

| Metric | Pass | The usual cause when it fails |
|---|---|---|
| Largest Contentful Paint | ≤ 2.5s | The hero image — unsized, uncompressed, or lazy-loaded when it should not be |
| Interaction to Next Paint | ≤ 200ms | Too much JavaScript running on the main thread |
| Cumulative Layout Shift | ≤ 0.1 | Images and embeds with no `width`/`height`; webfonts without `font-display: swap` |
| Lighthouse Performance | ≥ 90 on mobile | Work the opportunities list top-down |

Measure it rather than guessing: Google PageSpeed Insights is a public endpoint
needing no key at low volume, callable through `execute_php` + `wp_remote_get`
from any client — including Telegram, where there is no browser to run
Lighthouse in. `seo-audit-and-fix` has the full method.

## Guardrails
- Never set `noindex` on a page the user wants ranked, and never bulk-noindex without explicit confirmation.
- Never disallow an AI crawler without the user explicitly asking to.
- Write, don't overwrite blindly: read current SEO meta before replacing it, and keep the human's wording where it already works.
- Don't fabricate stats, testimonials, or credentials to game E-E-A-T signals — use real data only; ask the user for anything you don't have.
- If publishing content in bulk (e.g. via `create_post` in a loop), throttle it — sudden volume spikes read as spam to Google, not just AI engines.
- Save the site's keyword targets, brand voice, and title formula to `memory_save` so future content stays on-strategy.

## Routing examples

Phrases that must reach this skill, and one that must not. `bin/lint-skills.php`
checks them against every other skill's description.

- fires: set the meta title and description for this page
- fires: add json-ld schema to the product pages
- skips: audit the seo of my whole website

## Related skills

- **Next** — seo-audit-and-fix. Consider these when this skill is done.
- **Verified by** — pre-launch-audit. The work is not finished until this has run and passed.
