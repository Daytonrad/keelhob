---
name: seo-audit-and-fix
title: SEO audit and fix
description: Audit a whole website's SEO across five layers and then fix what it finds — on-page, technical/Core Web Vitals, AI-overview readiness, duplicate/doorway pages, and local/Google Business Profile — producing a before-and-after report the owner can actually look at. Use for "audit my SEO", "why am I not ranking", "check my whole site", "improve my Lighthouse score", "my traffic dropped", "make my site load faster", "get me into AI Overviews", "score my site", or auditing a prospect's site to win work. This is the WHOLE-SITE, expensive, consent-gated job; for one page use seo-optimization instead. Never start it without agreeing scope and cost with the owner first.
enable_agentic: true
enable_prompt: true
role: procedure
surface: any
next_skills: seo-optimization, motion-and-interaction
verified_by: pre-launch-audit
---

# SEO audit and fix

<!-- CONTENTS:START -->
## Contents

- [When to use](#when-to-use)
- [When NOT to use](#when-not-to-use)
- [Read this first](#read-this-first)
- [1. Before you run anything — the cost gate](#1-before-you-run-anything-the-cost-gate)
- [2. What is connected? Ask, then guide](#2-what-is-connected-ask-then-guide)
- [3. The free path — this must always work](#3-the-free-path-this-must-always-work)
- [4. Layer 1 — on-page](#4-layer-1-on-page)
- [5. Layer 2 — technical and Core Web Vitals](#5-layer-2-technical-and-core-web-vitals)
- [6. Layer 3 — AI overviews and answer engines](#6-layer-3-ai-overviews-and-answer-engines)
- [7. Layer 4 — duplicate and doorway pages](#7-layer-4-duplicate-and-doorway-pages)
- [8. Layer 5 — local and Google Business Profile](#8-layer-5-local-and-google-business-profile)
- [9. The before-and-after report page](#9-the-before-and-after-report-page)
- [10. One pass is never enough](#10-one-pass-is-never-enough)
- [11. Auditing a site you do not own](#11-auditing-a-site-you-do-not-own)
- [12. Evidence discipline](#12-evidence-discipline)
- [Related skills](#related-skills)
- [Routing examples](#routing-examples)
<!-- CONTENTS:END -->

## When to use

A whole site's search performance is the subject: rankings dropped, traffic fell, Core Web Vitals are poor, or the owner wants an audit across every page. Expensive, so the cost gate comes first.

## When NOT to use

Not for one page. Setting a meta title, adding schema, or writing a description for a single page is `seo-optimization`, which is far cheaper to load and runs constantly during a build.

## Read this first

1. **Agree before you spend.** This is the most expensive thing in the plugin. Count the pages, say what it will cost and how long it will take, wait for a yes. (Operating protocol rule 12.)
2. **Ask what they have connected** before assuming anything. Search Console, SEMrush, DataForSEO, Rank Math — each changes what you can see. Never guess.
3. **The audit must work with none of them.** The free path is not a downgrade you apologise for; it is the path most owners will take.
4. **Build the report page early**, before you fix anything, so a "before" exists. Without it, "I improved your site" is a claim with no evidence.
5. **One pass is never enough.** Fixes conflict. Re-run and re-measure until the number stops moving.
6. **Report the real number.** Never the hoped-for one.

Everything below is the detail behind those six.

---

## 1. Before you run anything — the cost gate

An owner types "check my SEO" meaning thirty seconds of attention. Taken
literally that is an hour of wall clock, a few dollars of API credit and
hundreds of tool calls. Those are not the same request, and the gap between
them is the owner's money.

**Say this before you start, filled in with real numbers:**

- How many pages you will crawl. Get it from the sitemap (`seo_sitemap action=get_sitemap`) or `list_posts`, do not estimate.
- Which of the five layers you will run.
- Whether you will fix as you go, or only report.
- Roughly how long. A ten-page site is minutes; a thousand-page site is hours.
- What it will cost, if any paid tool is in play, and that you cannot see or cap that spend — only their provider account can.

Then stop and wait. A number you are unsure of is still worth saying; write
"roughly", not nothing. Silence is what makes a bill a surprise.

**Offer a cheaper shape when the site is big.** Auditing the ten pages that
actually get traffic beats auditing four hundred that do not. Search Console,
if they have it, tells you which ten.

**Report-only is a legitimate answer.** Some owners want the list and will do
the work themselves, or hand it to a developer. Ask which they want rather
than deciding for them — "report only" costs a fraction of "report and fix".

---

## 2. What is connected? Ask, then guide

Ask the owner which of these they have, in one message, and say plainly that
**none of them are required**. Do not make someone feel they cannot start.

| Source | Cost | What it adds that nothing else can |
|---|---|---|
| **Google Search Console** | Free | What people actually searched to reach this site, what impressions it already earns, and which pages Google refuses to index. Irreplaceable, and the first one to ask for. |
| **Rank Math** (WordPress plugin) | Free | Its own per-page scoring, a broken-link crawl of the whole site, and internal-link data. If the site is WordPress and has no SEO plugin, suggesting the free Rank Math is a real recommendation, not an upsell. |
| **DataForSEO** | Paid, ~$1 trial credit | Real ranking positions, competitor gaps, keyword volume and backlinks. The cheap way into professional data. |
| **SEMrush** | Paid, free trial | A hosted site audit with a health score, plus keyword and competitor research. |

### Guiding them through connecting one

These live on the **owner's AI client**, not in WordPress. Keelhob cannot
install them, and neither can you — say so rather than pretending to try.

For a custom connector (this is the DataForSEO path):

1. In their Claude client: **Settings → Connectors → Add → Add custom connector**.
2. Name it, and paste the remote server URL. DataForSEO's is `https://mcp.dataforseo.com/mcp`. Leave the OAuth client fields blank.
3. Add, then click **Connect** — adding it does not authorise it. An OAuth screen asks to approve `api` and `profile` scopes.
4. Set the connector's tools to **Always allow**, or it will interrupt to ask permission on every single call during a long audit.

**Two things that waste people's afternoons, so say them up front:**

- **A new connector only applies to conversations started after it is added.** If they connect something mid-audit, the current session cannot see it. Finish, then start a fresh conversation.
- **A managed or corporate account may not show the custom-connector option at all.** That is an administrator setting, not something they did wrong. A personal account is the workaround.

Search Console has no connector in many setups. The export path works
everywhere: in Search Console, export the account as CSV, unzip it, and hand
the folder to the agent. Old data is still data.

**Be honest about money.** DataForSEO gives about $1 of trial credit and a
small audit fits inside it. SEMrush trials are about a week. Keelhob takes
no cut, has no affiliate link, and gains nothing from either choice.

---

## 3. The free path — this must always work

Assume the owner has nothing connected and is talking to you over Telegram
with no browser. The audit still runs. Everything here is inside the plugin or
is a public endpoint.

| Job | How, with no paid tool |
|---|---|
| Get the page list | `seo_sitemap action=get_sitemap`, then fetch it. Fall back to `list_posts` for every public post type. |
| Per-page SEO score | `seo_analyze post_id=… focus_keyword=…` — returns a classical score and a `geo_score`, each with a `checks` array explaining every failure. |
| Whole-site readiness | `site_readiness` — the plugin's own graded audit. Run it first; it is free, instant, and finds blockers before you spend anything. |
| The rendered HTML of any page | `execute_php` running `wp_remote_get()`, then parse the body. This is how you check what a visitor actually receives, which is the only thing that counts. |
| Performance and Core Web Vitals | **Google PageSpeed Insights**, a public endpoint needing no key at low volume: `https://www.googleapis.com/pagespeedonline/v5/runPagespeed?url=…&strategy=mobile`. Call it through `execute_php` + `wp_remote_get`. It returns the same four Lighthouse categories — Performance, Accessibility, Best Practices, SEO — plus field data from real Chrome users when Google has enough of it. |
| Broken links | Crawl the page list, collect every `href`, and `wp_remote_head()` each one. Slow but complete. |
| Indexing | `seo_sitemap` for what you claim exists; fetch `robots.txt`; check for stray `noindex` in the rendered head. |

**About Lighthouse specifically.** It needs a real Chrome. The plugin is PHP on
a web server and cannot run one. If the agent has a browser it can run
Lighthouse directly and that is the better measurement — same engine, real
device emulation, and a full trace. If it does not, PageSpeed Insights runs
the identical audit on Google's hardware and returns the same scores. Use PSI
as the default and Lighthouse as the upgrade. **Never tell the owner you
cannot measure performance.** You can, from anywhere.

Mobile first. Google ranks on the mobile score, and it is always the worse of
the two.

---

## 4. Layer 1 — on-page

This is the layer `seo-optimization` covers in depth for a single page. Load
that skill for the fix detail; here you are running it across every page and
scoring the site as a whole.

Per page, check and fix in this order — earlier items move the number more:

- **Exactly one `<h1>`**, containing the page's primary term. Zero H1s is common on home pages built as a stack of sections, and it is the single most-missed fix.
- **Title** 30–60 characters, term near the front, unique across the whole site.
- **Meta description** 120–155 characters, unique. A page with none lets Google invent the grey text under the link, and it picks badly.
- **Heading order** that does not skip levels.
- **The term appears** in the title, the slug, the first paragraph and at least one H2 — without stuffing.
- **Every content image has real `alt` text.**
- **At least one internal link in, and one out.** A page nothing links to is a page Google barely counts.
- **URL** short, lowercase, hyphenated, no dates or stop words.

**Duplicate titles and descriptions across pages are a site-level failure**,
not a per-page one. Collect them all before fixing any, because the fix is
usually to differentiate a set, not to rewrite one.

---

## 5. Layer 2 — technical and Core Web Vitals

Two questions: can Google reach the page at all, and how fast is it for a
person on a phone.

**Targets — pass or fail, no partial credit:**

| Metric | Good | Fix when it fails |
|---|---|---|
| Largest Contentful Paint | ≤ 2.5s | Almost always the hero image. Convert to WebP/AVIF, size it to what is displayed, `fetchpriority="high"`, and never lazy-load it. |
| Interaction to Next Paint | ≤ 200ms | Too much JavaScript on the main thread. Defer what is not needed to render. |
| Cumulative Layout Shift | ≤ 0.1 | Images and embeds with no `width`/`height`, and webfonts loading without `font-display: swap`. |
| Lighthouse Performance | ≥ 90 mobile | Work the PSI opportunities list top-down; the top item is usually worth more than the rest combined. |
| Accessibility | 100 | Contrast, form labels, focus order. Cheap points, and it is the right thing anyway. |

**The heaviest wins on a typical WordPress site, in order:**

1. **Images.** Usually most of the page weight. Resize to display size, convert to WebP, lazy-load everything except the hero.
2. **Plugins loading assets on every page.** A slider plugin shipping 400KB to a page with no slider. Dequeue it where it is not used, with a snippet.
3. **Render-blocking CSS and JS in `<head>`.** Defer, or inline only the small amount needed for the first screen.
4. **No caching or compression** at the server. Often not yours to fix — name it and say who can.

Also in this layer: HTTPS everywhere with no mixed content, no insecure
`http://` links left in copy, a real `robots.txt`, a sitemap returning **200**,
correct canonicals, and no stray staging or test page indexed. A page titled
"Test" submitted to Google is more common than it sounds — look for it.

**Never fix speed by breaking the site.** Read the front-end check contract in
the operating protocol (rule 6c). Anything you dequeue, verify the page still
renders afterwards.

---

## 6. Layer 3 — AI overviews and answer engines

Being quoted in Google's AI Overview, ChatGPT or Perplexity is a different
game from ranking, and it is winnable on sites that cannot yet rank at all.

What actually gets lifted, in order of how reliably:

- **A direct answer in the first 40–60 words** under a heading, before any preamble. "In this article we will explore" is a guarantee of being skipped.
- **Questions as headings**, phrased the way a person types them, answered immediately underneath in two to four sentences.
- **Tables and lists** for anything comparable — prices, specifications, steps. Structure is what makes a passage liftable.
- **An FAQ block** at the end, marked up as `FAQPage` schema.
- **Real citations out** to authoritative sources, which signals a sourced page rather than a rewrite.
- **A named author with a real bio**, feeding `Person` schema.
- **`llms.txt`** at the site root — see `seo-optimization` for the format.

**Test it, do not assume it.** Ask ChatGPT or Perplexity the question this
page should win — "who is the best plumber in Austin" — and see who gets named
and why. If rivals are quoted and this site is not, the answer usually names
the reason: they answer in forty words, or they show prices on the page.
Record what you found; it is the most persuasive evidence in the report.

---

## 7. Layer 4 — duplicate and doorway pages

A business with pages for "plumbing in Los Angeles", "plumbing in San
Francisco" and "plumbing in San Diego" that differ only by the city name is
building doorway pages. Three is invisible. Forty is spam, and Google treats
it as spam.

**Find them:** group pages by template and compare body text. Near-identical
copy with a swapped noun is the signal.

**The fix is not deletion.** A local page can be legitimate — it just has to
be true. Give each one something only true there: a real local photo, real
neighbourhood names, a review from that area, an FAQ about that place's rules
or hours. Where there is nothing real to say, merge the page into a parent and
301 the old URL (`seo_redirect action=add`).

Also in this layer: two of your own pages targeting the same term, which makes
them compete with each other. Say it carefully — overlapping keywords are
*likely* competition, not proof of it.

---

## 8. Layer 5 — local and Google Business Profile

Only for businesses with a location or a service area. Skip it entirely
otherwise, and say you skipped it.

Three things decide the map pack: how complete the profile is, reviews against
rivals, and whether the name, address and phone match **everywhere** the
business is listed.

There is no practical API here — Google's takes weeks of manual review. So
this is copy-and-paste work, and it takes the owner about five minutes:

1. Ask them to paste their current profile — business information, products, services.
2. Generate improved text for each field: complete categories, service descriptions using the terms people search, hours, service areas.
3. Hand it back as clearly labelled blocks they can paste into each field.
4. Check the site's own NAP matches the profile exactly. A phone number in a different format on the footer is a real inconsistency.

---

## 9. The before-and-after report page

**Build this before you fix anything.** A "before" that is reconstructed
afterwards is not a before.

Why a page and not a chat message: an owner on Telegram, Slack or a phone
cannot see a terminal. A page is a link they can open, keep, and send to
somebody else.

**How to build it:**

- `create_post` a WordPress **page** with an unguessable slug — `seo-report-` plus 12 random characters. Not `/seo-report`.
- Set it `noindex` (`seo_meta`) and keep it out of the sitemap. It is for one person, not for Google.
- It must return **status 200**. Never 4xx or 5xx from the front end of a live site — operating protocol rule 6a exists because a `503` there has taken a real site off the internet.
- Content: the scores before, every finding grouped by layer with its severity, what you propose to do, and — after the fix pass — the same scores again beside the first set.
- Every finding cites its evidence: which tool returned it, which URL, when.

**Put nothing sensitive on it.** No API keys, no account emails, no Search
Console property names, no tokens. Assume the URL will eventually be shared.

**Never delete it on your own, and never nag about it.** When the owner says
they are happy with the result, ask **once**:

> Do you want me to delete the report page, or keep it? Keeping it is useful as a record of where the site started.

Then do what they say. If they do not answer, leave it. An unanswered question
is not permission to delete something.

---

## 10. One pass is never enough

The most repeated warning from everyone who does this seriously: the first run
will not be perfect, because fixes conflict. Compressing an image changes the
layout score. Deferring a script breaks a widget. Adding schema changes the
head that something else was counting on.

So: fix, re-measure, fix again. Keep going until the number stops moving, not
until you have worked the list once. Tell the owner this in advance so a
second pass reads as the method rather than as a failure.

**Re-measure, do not remember.** A tool returning success is not evidence.
Fetch the page and look.

---

## 11. Auditing a site you do not own

Perfectly legitimate, and a good way to win work — everything you read is
already public.

**But it is read-only, absolutely.** Produce the report and stop. Do not fix,
do not submit anything, do not touch their Search Console, do not create a
page on their site. You have no permission, and "it was an improvement" is not
a defence.

Say plainly in the report that these are estimates from outside, without their
analytics.

---

## 12. Evidence discipline

The rule that makes a report trustworthy: **flags are prompts, not verdicts.**

- Every finding names the tool call, URL and time it came from. A finding you cannot source, you delete.
- Say which findings are certain ("this page returns 404") and which are patterns worth a human look ("these four pages share an opening paragraph").
- Never lower a severity to make a score look better. Operating protocol rule 9.
- Never invent a metric. If you did not measure it, it does not go in the report.
- Where a fix needs a decision only the owner can make — a page merged, a service dropped — list it as theirs and leave it.

Save what you learned to `memory_save`: the audit date, the scores, the target
keywords, and what the owner declined. The next session should resume rather
than re-ask.

---

## Related skills

- **`seo-optimization`** — the per-page work. Load it for the fix detail behind Layer 1 and Layer 3; this skill is the whole-site pass around it.
- **`build-standard`** — the definition of done, and the router for everything else.
- **`launch-readiness-and-compliance`** — a fast page that is illegal is still not ready. Analytics that fire before consent is a real finding, and it belongs there.
- **`motion-and-interaction`** — animation is a common cause of a failing performance and layout-shift score; that skill carries the budget.
- **`website-building`**, **`web-design-master`** — when the fix is that the page is wrong, not that the tag is missing.

## Routing examples

Phrases that must reach this skill, and one that must not. `bin/lint-skills.php`
checks them against every other skill's description.

- fires: audit the seo of my whole website
- fires: my rankings dropped find out why
- fires: check my core web vitals
- skips: do i need a cookie banner
