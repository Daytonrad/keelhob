---
name: scroll-craft
title: Scroll-craft pages
description: Install the scrollcraft runtime on a WordPress page so the scroll wheel drives the visuals — planes that travel at different rates, a stage that pins while the reader reads, a clip that scrubs frame by frame. Three snippets and a custom page template. Load it when the owner asks for a scrollytelling page, a pinned or layered hero, an Apple-style scroll experience, "scrollcraft", or a page that feels like a film rather than a document. It does not work inside Elementor, and this skill says so before anything is built.
enable_agentic: true
enable_prompt: true
role: procedure
surface: workstation
requires: web-design-master, code-snippets
next_skills: pre-launch-audit
verified_by: pre-launch-audit
---

# Scroll-craft pages

<!-- CONTENTS:START -->
## Contents

- [When to use](#when-to-use)
- [When NOT to use](#when-not-to-use)
- [1. Ask the branch question first](#1-ask-the-branch-question-first)
- [2. Get the engine. Do not expect it in this repo](#2-get-the-engine-do-not-expect-it-in-this-repo)
- [3. Install it as three snippets](#3-install-it-as-three-snippets)
- [4. Scope the engine to the one page](#4-scope-the-engine-to-the-one-page)
- [5. A `page_template` that throws takes the page down](#5-a-pagetemplate-that-throws-takes-the-page-down)
- [6. The authoring model](#6-the-authoring-model)
- [7. Assets come from Keelhob, not kie.ai](#7-assets-come-from-keelhob-not-kieai)
- [8. Reduced motion is not optional](#8-reduced-motion-is-not-optional)
- [9. Verify in a real browser](#9-verify-in-a-real-browser)
- [Related skills](#related-skills)
- [Routing examples](#routing-examples)
<!-- CONTENTS:END -->

Scroll is the only input every visitor already knows. This skill wires a
scroll-driven runtime onto one WordPress page: you author real semantic HTML,
mark it with `data-sc-*` attributes, and the engine drives all of it from a
single scroll value on one `requestAnimationFrame` loop. No GSAP, no Lenis, no
build step, no npm.

It is a **treatment applied to one page**, not a way of building a site. The
site is still built with `website-building` and `web-design-master`.

## When to use

The owner wants the scroll on one page to drive the visuals: images that travel
at different speeds, a section that holds still while copy assembles, a video
that scrubs with the wheel, a background that drifts colour. They said
"scrollytelling", "scrollcraft", "pinned hero", "layered hero", "Apple-style",
or "make this page feel like an experience".

## When NOT to use

Not for ordinary page motion — reveals on entry, hover states, a sticky header.
That is `motion-and-interaction`, it works everywhere including Elementor, and
it is the right answer for nine pages in ten. Not for the page's typography,
palette or layout, which is `web-design-master`. And not at all on a page the
owner needs to keep editing in Elementor: see §1.

## 1. Ask the branch question first

Two routes exist and only one of them works. **Ask. Never assume**, and never
pick the route because it is the one you already started.

> Do you want the scroll on this page to drive the visuals — images that move at
> different speeds, sections that hold while you read, a video that scrubs as you
> scroll? It needs a custom page template rather than Elementor, so afterwards
> this page is edited as code and not in the builder.

- **Page-template route — supported.** A `page_template` snippet owns the whole
  document. The engine owns the page. This is the one that works.
- **Elementor route — not supported.** Say so plainly and offer the template
  route instead.

**Why Elementor is a refusal and not a challenge.** Pinning is
`position: sticky`, and sticky stops working the moment any ancestor has
`overflow: hidden` or a constrained height. Elementor sets both on its own
section and container wrappers. You would be fighting the builder on every
section, you would win by overriding its wrappers, and the next Elementor
release would break it again. There is nothing to debug here, so do not spend
the owner's money trying.

If the owner wants both — a builder-editable page *and* scroll-driven visuals —
the honest answer is two pages, or the template route plus the warning that the
builder is no longer the way to edit it. Say which you did.

## 2. Get the engine. Do not expect it in this repo

**The engine is not vendored into Keelhob, on purpose.** It is
`scrollcraft.js` + `scrollcraft.css` from the `nateherk-design` Claude Code
plugin (`github.com/nateherkai/scroll-craft`), whose `plugin.json` declares
`"license": "MIT"`.

MIT is compatible with this plugin's GPL, so a copy would be permitted in
principle — but MIT's single condition is that the copyright notice and
permission text travel with the copy, and **the distributed engine files carry
neither**: no `LICENSE` file, no copyright line, no author header. There is no
notice to carry, so a copy here could not satisfy the one term it is given
under. Keelhob therefore points at the engine instead of shipping it. If
upstream later ships a `LICENSE` with a copyright holder, vendoring becomes a
decision somebody can actually make.

So the agent obtains it, every time:

1. If the caller has the `nateherk-design` plugin installed, the files are at
   `<plugins cache>/nateherk/nateherk-design/<version>/skills/scroll-craft/engine/`.
2. Otherwise clone or download `github.com/nateherkai/scroll-craft` and read
   `skills/scroll-craft/engine/`.
3. If neither is available, **stop and say so.** Do not reconstruct the engine
   from this document — it is 1,283 lines of JS and 432 of CSS, and an
   approximation of it is worse than no page.

**Never edit the engine.** It is the mechanism. Brand it by overriding tokens
(`--sc-canvas`, `--sc-surface`, `--sc-ink`, `--sc-ink-soft`, `--sc-accent`,
`--sc-accent-ink`, plus the two font variables) and write your own markup.
Bespoke behaviour is bespoke JS in the page, driven off the `--sc-p` the engine
publishes.

## 3. Install it as three snippets

Keelhob ships all three pieces with no new plugin code. Exact field values,
because each one has been wrong at least once:

| Piece | `snippet_type` | `location` | Also |
|---|---|---|---|
| `scrollcraft.css` | `css` | `header` | `conditions` — see §4 |
| `scrollcraft.js` | `js` | `footer` | `conditions` — see §4 |
| your marked-up page | `php` | `page_template` | `target_id` = the page's post ID |

Fill `name`, `description`, `purpose`, `impact` and `tags` on all three, as
`code-snippets` requires. Three unexplained snippets totalling 60 KB is exactly
the page of code an owner is afraid to switch off.

Then three things that are not optional, and each one fails **silently** if you
skip it:

**The template must call `wp_head()` and `wp_footer()`.** Keelhob outputs header
and footer snippets on those two hooks. A `page_template` snippet that prints its
own `<html>` and omits them gets neither the engine CSS nor the engine JS — and
no error, anywhere.

**The engine does not boot itself.** It exposes `ScrollCraft.mount()` and waits.
Put the mount call in the template, after `wp_footer()`:

```html
<?php wp_footer(); ?>
<script>ScrollCraft.mount(document.body);</script>
```

Without it the page renders, the CSS applies, every cue sits at `opacity: 0`,
nothing moves, and **the console is clean**. Verified on the rig: that is the
failure you will hit first, and it looks like a broken engine rather than a
missing line.

**Theme tokens go in the template, not in the engine CSS snippet.** Put your
`:root` override in the template's own `<style>`, or in a second `css` snippet
with a higher `priority` so it lands after the engine's. Editing the engine's
token block is how the next upgrade silently reverts the brand.

## 4. Scope the engine to the one page

A `footer` js snippet runs on **every page of the site**. 60 KB of
scroll runtime on the checkout is a real cost, and the CSS is worse — it
restyles `[data-sc-*]` globally and sets page-level tokens.

`includes/class-snippet-conditions.php` is the rules engine. Pass **`conditions`**
on `snippet_save` — an array, with ALL/ANY semantics:

```json
{ "enabled": true,
  "relation": "AND",
  "rules": [ { "type": "post_id", "operator": "is", "value": "412" } ] }
```

`relation` is `AND` (all rules) or `OR` (any rule). Rule `type` is one of
`user_status`, `user_role`, `page_type`, `post_type`, `post_id`, `url_path`,
`device`; `operator` is `is` / `is_not`, or `contains` / `equals` /
`starts_with` for `url_path`.

Two traps, both found by running it:

- **The key is `conditions`, not `conditions_json`.** `save()` only reads
  `conditions` and re-encodes it. Passing `conditions_json` is accepted,
  ignored, and leaves the snippet running site-wide with nothing to show you
  it did. Read the row back and check `conditions_json` is populated.
- **`page_template` ignores conditions entirely.** `maybe_page_template()`
  selects on `active`, `location` and `target_id` alone. `target_id` *is* its
  scoping, so set it and do not try to narrow it further with rules that will
  never be read.

After saving, prove it: load the page and one other page, and confirm the engine
`<script>` is present on the first and absent on the second.

## 5. A `page_template` that throws takes the page down

This location renders the whole response and then `exit`s. A fatal halfway
through leaves a half-written document, so treat it as the highest-risk snippet
type Keelhob has. **Do not invent guards — use the ones that exist**, in
`includes/class-snippets.php`:

- **It is parse-checked before it is stored.** `lint_php()` lints a
  `page_template` body as raw markup with `<?php` islands, as-is. A failure
  stores the code but **forces `active = 0`** and sets `last_error`, and the
  response carries `lint_error`. Read the response. "Saved" and "live" are not
  the same word here.
- **Fatals at runtime deactivate that exact snippet.** `run_php_template()`
  catches `Throwable`, and `on_shutdown()` picks up the ones a catch cannot see
  via `register_shutdown_function()` + `error_get_last()`. Both end in
  `deactivate_with_error()`, which switches the snippet off, records the message
  in the snippet log, and fires `keelhob_snippet_auto_deactivated` so the owner
  is told. The next request serves the theme's template instead of a broken page.
- **Activation triggers a real front-end request.** `verify_front_end()` probes
  the site as a logged-out visitor, and on a failure re-probes with the snippet
  off as a control before blaming it. An inconclusive probe deactivates nothing.
- **`verify_front_end()` probes the HOME page, not your page.** For a
  `page_template` snippet targeting one page, the automatic check does not visit
  the page the template serves. So **you load the target page yourself** — §9 —
  and you are the only check that covers it.
- **The last resort is `define('KEELHOB_SNIPPETS_OFF', true);`** in
  `wp-config.php`. Tell the owner the line exists before you activate the
  template. A kill switch nobody has been told about is not a kill switch.

Two rules of your own on top: build the template against a **draft** page first,
so a bad render is a page nobody can reach; and never return a 4xx or 5xx from
it, which is the one failure the crash guard cannot catch.

## 6. The authoring model

Author real HTML — real `<h1>`, real `<p>`, real links, real reading order — and
mark it up. The engine reads the attributes and drives them. It never generates
DOM, which is why two pages built on it do not look like each other.

**Acts** are the unit of scroll time. `data-sc-act` is `scrub`, `pin`, `pan` or
`flow`; `data-sc-span` is how many viewport-heights of scroll the act owns
(default 1.5). The engine sets the outer height and sticks the first
`.sc-stage` child. Every act publishes its normalised progress as `--sc-p` on
itself, so your own CSS and your own JS can read it.

```html
<section data-sc-act="pin" data-sc-span="3">
  <div class="sc-stage">
    <img src="..." alt="" data-sc-parallax="-0.3">
    <h2 data-sc-cue="0.2 0.8" data-sc-kinetic="lines">Copy that assembles</h2>
  </div>
</section>
```

What progress drives:

| Attribute | On | Effect |
|---|---|---|
| `data-sc-parallax="-0.2"` | anything | translateY by rate × progress × viewport. Negative recedes |
| `data-sc-cue="0.1 0.5"` | anything | opacity and rise keyed to progress; one value = enter and hold |
| `data-sc-kinetic="lines"` | text | splits into lines / words / chars and staggers the reveal |
| `data-sc-reveal="up"` | anything | `clip-path` wipe: up, down, left, right, iris |
| `data-sc-scrub` | `<video>` | progress scrubs `currentTime` |
| `data-sc-sequence="a/{i}.webp:120:1"` | `<canvas>` | progress scrubs an image sequence |
| `data-sc-pan="0.6"` | a wide rail | horizontal travel inside a `pan` act |
| `data-sc-count="0 4200"` | anything | a number blooms across the cue window |
| `data-sc-drift="#07090c"` | an act | the page background interpolates toward this colour |
| `data-sc-in` | a flow section | reveals once on entry via IntersectionObserver |

`data-sc-tilt`, `data-sc-magnet` and `data-sc-spotlight` follow the pointer. All
three are gated to `(hover: hover) and (pointer: fine)`, so touch never fires
them.

Two composition rules worth keeping, because they are what stops every page
built this way looking identical: **no device family twice in a row**, and **at
most two `scrub` acts** — video is the heaviest thing on the page and the third
clip has stopped being a surprise.

## 7. Assets come from Keelhob, not kie.ai

The upstream skill generates photoreal stills and clips through kie.ai. **Do not
use that path from here.** kie.ai serves finished jobs from CDN hosts that are
blocked on this network, so the job runs, the money is spent, and the result
cannot be downloaded.

Use Keelhob's own tools:

- **`image_search`** — Openverse, free, licensed. The default for anything
  real-world.
- **`image_generate`** — only when a provider key is set.
- **`upload_media`** — for the owner's own photographs and footage, which beat
  generated assets every time and cost nothing.

One style decision applies regardless of where the pixels come from: write the
style description **once** and reuse it verbatim for every image, so six
separate assets read as one shoot. And look at every asset before you place it.

A scrub clip needs a dense GOP, because seeking walks back to the previous
keyframe; a normal web encode plays perfectly and scrubs like mud. If you cannot
re-encode, use `data-sc-sequence` over a WebP image sequence instead, which has
no seek cost at all.

## 8. Reduced motion is not optional

The engine already honours `prefers-reduced-motion: reduce`, and its behaviour
is **fewer and gentler, not zero**: cues still fade, so comprehension survives;
translation collapses; scrub clips are never fetched and the poster holds;
pointer devices go inert. Confirmed on the rig — under `reduce` the parallax
transform stayed at `none` across the whole act while the cue opacity still rose
from 0 to 0.189.

That the engine honours it is not the end of your job. **Verify the static
composition still reads**, because a WordPress site has visitors a one-off
landing page does not, and a meaningful share of them have the setting on.

Under reduced motion, check: every poster frame is a composition somebody would
have chosen, not an arbitrary first frame; no act is blank, because its only
content was the movement; the copy order still makes the argument without the
staggering; and nothing that was only legible against a moving plane has become
unreadable against a still one. If an act fails that, give it a reduced-motion
state of its own in the template's CSS. Do not fix it by weakening the media
query.

## 9. Verify in a real browser

**UI is never claimed to work from reading HTML.** This is a house rule, and a
scroll page is the case it was written for: the page has no single state, every
scroll position is a different frame, and the failures live between the two
positions you happened to look at.

Keep the screenshot strip. Walk each act at six positions or more and capture
each one, in three passes: desktop, a 390×844 phone, and reduced motion. The
upstream skill ships `scripts/shoot.mjs` for exactly this; drive it or a
playwright-core script of your own against the live WordPress URL, not a local
copy of the markup — the point is to test what Keelhob actually serves.

At each position, assert rather than eyeball:

1. The act's `--sc-p` is populated and **advances** between two scroll
   positions. Empty or frozen means the mount never ran — go back to §3.
2. A `data-sc-parallax` element's computed `transform` **changes** between two
   positions.
3. A `data-sc-cue` element's computed opacity reaches full, not 0.6, by the end
   of its window.
4. Zero console errors and zero page errors.
5. Under `reduce`: transforms stay at `none` and the cue opacity still moves.
6. The engine's `<script>` is absent from a second, unrelated page (§4).

If any of the six fails, fix the cause and shoot it again. **After two attempts,
stop and report** what is failing and what you have ruled out — a third round of
the same change is spending the owner's money to learn nothing.

Then do the part no harness can: **read the contact sheet.** It proves a clip
advances; it cannot tell you the composition is good or the page means anything.
Tab through for focus order while you are there.

And say what a green run does not cover: **a real phone.** Headless Chrome
cannot reproduce an iPhone's video decoder, its autoplay policy, Low Power Mode
or touch scrolling. A build can pass four green rounds with the hero clip frozen
on the actual device. Report what you verified and what you did not.

## Related skills

- **`web-design-master`** — required. The type, colour, spacing and quality bar.
  This skill is a treatment; that one decides whether the page is any good.
- **`code-snippets`** — required. How `snippet_save` behaves: merge-on-update,
  revisions, reading `replaced_bytes` before you overwrite work you have not read.
- **`motion-and-interaction`** — the right answer for ordinary page motion, and
  the one that works inside Elementor.
- **`pre-launch-audit`** — verified by. The page is not done until it has run.

## Routing examples

Phrases that must reach this skill, and one that must not. `bin/lint-skills.php`
checks them against every other skill's description.

- fires: build me a scrollytelling page
- fires: i want a pinned layered hero that moves as you scroll
- fires: use scrollcraft on this page
- skips: add a tracking pixel to every page
