---
name: site-clone-capture
title: Measure a page before rebuilding it
description: Records a target page so it can be rebuilt without guessing: full-page screenshots at three widths, computed styles rather than eyeballed ones, the scroll sweep, the hover sweep and the click sweep, which framework and animation library it runs on, and every image, video and font it uses. Writes one spec file per section.
enable_agentic: true
enable_prompt: true
role: procedure
surface: workstation
requires: site-clone
next_skills: site-clone-rebuild, site-clone-design-system
---

# Measure a page before rebuilding it

A website is not a screenshot. It moves. If you extract only static CSS, the copy
looks correct in a picture and feels dead the moment somebody scrolls it — and
that is the failure everybody notices and nobody can name.

This skill's entire output is **written artifacts**. You measure now so a rebuild
later never has to guess, and so a wrong value can be traced to the measurement
rather than argued about.

## When to use

- `site-clone` has agreed the job and you are about to rebuild a page.
- A rebuilt page looks right but feels wrong, and you need to find out what the
  original actually does.
- You need the design tokens only — run Part 1 and Part 2, then stop.

## When NOT to use

- Nothing has agreed permission yet. Go back to `site-clone`.
- You are building from a written brief. Nothing to measure — `site-build-kickoff`.
- The page is behind a login you do not have. Say so and stop; a scrape of a
  login wall is not a capture.

## Where the artifacts go

One folder per page, under the sandbox so nothing lands in the theme:

```
write_sandbox_file  clone/<host>/<page-slug>/TOKENS.md
                    clone/<host>/<page-slug>/BEHAVIORS.md
                    clone/<host>/<page-slug>/TOPOLOGY.md
                    clone/<host>/<page-slug>/sections/<name>.spec.md
                    clone/<host>/<page-slug>/ASSETS.md
```

Write them as you go, not at the end. A capture that dies halfway leaves usable
work if the notes are on disk, and nothing at all if they are in your context.

## Part 1 — What is this thing built with?

Detect before you measure. What the page is built with changes what you can trust
and what you will have to rebuild from scratch rather than translate.

Fetch the page and read its markup:

```
firecrawl_scrape  url: <target>   formats: ["html", "markdown", "screenshot@fullPage"]
```

Then look for these signatures. Record what you find in `TOPOLOGY.md`:

| Look for | Means | Consequence for the rebuild |
|---|---|---|
| `_next/static`, `__NEXT_DATA__` | Next.js | Markup is generated. Copy the rendered output, never the source. |
| `/_nuxt/`, `__NUXT__` | Nuxt | Same. |
| `wix.com`, `wixstatic.com`, `_partials` | **Wix** | Layout is absolutely positioned. Do NOT translate their CSS — rebuild the layout properly in flow. |
| `cdn.shopify.com`, `Shopify.theme` | **Shopify** | Product data is dynamic. The shell copies; the catalogue is a WooCommerce job. |
| `squarespace.com`, `static1.squarespace` | Squarespace | Heavy grid system. Rebuild in flow. |
| `assets.website-files.com`, `wf-` classes | Webflow | Clean CSS. Usually translates well. |
| `framerusercontent.com` | Framer | Motion-heavy by default. Expect animation work. |
| `wp-content/themes/` | WordPress | Check the theme — you may be able to read it directly. |
| `gsap`, `ScrollTrigger` | **GSAP** | Scroll timelines. The expensive part. See Part 4. |
| `framer-motion`, `motion-` data attrs | Framer Motion | Spring physics. Maps to GSAP or CSS, imperfectly. |
| `lenis`, `.lenis` on `<html>` | **Lenis** | Smooth scroll. Miss this and the copy feels wrong in a way nobody can articulate. |
| `locomotive-scroll` | Locomotive | Same, older. |
| `data-aos` | AOS | Simple reveal animations. Easy to reproduce. |
| `<canvas>`, `three.min.js`, `webgl` | **WebGL / canvas** | Does not clone. Say so now, in writing. |

**If you find WebGL or canvas doing something visual, stop and report it before
going further.** The owner needs to decide whether a static replacement is
acceptable while there is still time to change the plan.

## Part 2 — Design tokens, measured not guessed

Never eyeball a value. "Looks like 16px" is wrong when the computed value is
17.6px, and a whole page of near-misses is exactly why a copy looks subtly cheap.

Drive the browser and read `getComputedStyle` directly:

```js
// Run this through your browser tool's evaluate/script action.
// Collects real values, then counts them, so the common ones surface as tokens.
const seen = { color: {}, bg: {}, font: {}, size: {}, weight: {}, radius: {}, shadow: {}, gap: {} };
const bump = (k, v) => { if (v && v !== 'none' && v !== 'normal' && v !== '0px') seen[k][v] = (seen[k][v] || 0) + 1; };
for (const el of document.querySelectorAll('body *')) {
  const r = el.getBoundingClientRect();
  if (r.width === 0 || r.height === 0) continue;      // invisible: not part of the design
  const s = getComputedStyle(el);
  bump('color', s.color);
  bump('bg', s.backgroundColor);
  bump('font', s.fontFamily);
  bump('size', s.fontSize);
  bump('weight', s.fontWeight);
  bump('radius', s.borderRadius);
  bump('shadow', s.boxShadow);
  bump('gap', s.gap);
}
const top = (o, n) => Object.entries(o).sort((a, b) => b[1] - a[1]).slice(0, n);
console.log(JSON.stringify({
  color: top(seen.color, 12), bg: top(seen.bg, 12), font: top(seen.font, 6),
  size: top(seen.size, 14), weight: top(seen.weight, 6),
  radius: top(seen.radius, 8), shadow: top(seen.shadow, 6), gap: top(seen.gap, 8),
}, null, 1));
```

Counting matters. A colour used 400 times is the body text; a colour used twice
is an accent. The counts are what turn a pile of values into a *system*, and the
system is what you actually want.

Write `TOKENS.md` with, for each: the value, how often it appeared, and where you
saw it. Also record:

- **Fonts** — every family, weight and style actually used. Check `<link>` tags
  for the source. Note whether it is a Google font (free to re-serve) or a
  commercial one (it is not).
- **The page's own scale** — sort the font sizes and look for a ratio. Many sites
  have one; naming it makes the rebuild consistent where the original was sloppy.
- **Container widths** — the max-width of the main content column at each breakpoint.
  This single number does more for "feels the same" than any colour.

## Part 3 — Screenshots at three widths

```
1440px   desktop
768px    tablet
390px    mobile
```

Full page, not viewport. Save each. At each width, note which sections change
layout and roughly where the breakpoint sits. A copy that only works at 1440 is
not a copy.

## Part 4 — The sweeps. This is the part everyone skips.

Four passes, in this order. The order matters: **scroll before you click**.

### 4a. Scroll sweep

Scroll slowly from top to bottom. Do not click anything yet. At each section:

- Does the header change? Record the exact scroll position where it flips, and
  the computed styles **before and after**. Diff them.
- Do elements animate in as they arrive? Record which, what kind (fade-up,
  slide, scale, stagger), and the delay between staggered items.
- Does anything pin and stay put while content moves past it?
- Are there scroll-snap points?
- Does a sidebar or tab indicator change on its own as you scroll?
- Is scrolling itself smooth in a non-native way? Check `<html>` for `.lenis`.

**Why before you click:** the single most expensive mistake in this whole job is
building click-driven tabs when the original switches on scroll. That is not a
CSS fix; it is a rewrite. If a section changes while you are only scrolling, it
is scroll-driven, full stop.

Record for every moving thing: **trigger, before state, after state, duration,
easing.** All four. "The nav shrinks on scroll" is not a specification.

### 4b. Hover sweep

Hover every button, card, link, nav item, image. Record what changes and how
long it takes. A 150ms ease-out and a 400ms spring feel completely different and
both look identical in a screenshot.

### 4c. Click sweep

Now click. Every button, tab, pill, accordion, dropdown, card.

For anything with states — tabs, filters, carousels — click **each one** and
record the content of each state. A tab bar captured in its default state
produces a copy where three of the four tabs are empty.

### 4d. Responsive sweep

Repeat the scroll sweep at 390px. Mobile often has entirely different behaviour:
animations disabled, a drawer instead of a nav bar, a carousel instead of a grid.
Assuming mobile is desktop-reflowed is how a copy breaks on the device most
people will actually use.

Everything from all four passes goes in `BEHAVIORS.md`. That file is the thing
that makes the copy feel alive, and it is the file most likely to be skipped.

## Part 5 — Topology and section specs

List every distinct section top to bottom in `TOPOLOGY.md`: its order, whether it
is in flow or a fixed overlay, and — stated explicitly — its **interaction
model**: static, click-driven, scroll-driven, or time-driven.

Then one spec file per section. Each contains:

```markdown
# <section name>
INTERACTION MODEL: static | click-driven | scroll-driven | time-driven

## Structure
<the DOM shape, in words: what contains what>

## Computed styles
<exact getComputedStyle values — container, then each child that matters>

## States
<every state, with what triggers it and the full before/after>

## Motion
<trigger, before, after, duration, easing — for each animated thing>

## Assets
<every image, video, icon: URL, natural size, how it is cropped>

## Text
<verbatim. Not paraphrased. Not summarised.>

## Responsive
<what changes at 768 and 390>
```

A section whose spec runs past roughly 150 lines is two sections. Split it.

## Part 6 — Asset inventory

List every image, video, icon and font in `ASSETS.md` with its URL and natural
dimensions. Watch for **layered compositions** — a background wash plus a
foreground screenshot is two images, and capturing one of them makes the rebuild
look thin for reasons nobody can place.

Check whether a "graphic" is actually a `<video>` or a Lottie file before
building an elaborate HTML mock of what it shows.

Do not download yet. `site-clone-rebuild` uploads them, so it can name them as it
goes.

## Before you hand off

Every box, or go back and measure more:

- [ ] Framework and animation libraries identified and written down
- [ ] WebGL/canvas found and flagged, or confirmed absent
- [ ] Tokens are computed values with counts, not estimates
- [ ] Screenshots at 1440, 768 and 390
- [ ] Scroll sweep done **before** any clicking
- [ ] Every moving thing has trigger, before, after, duration, easing
- [ ] Every stateful component has every state captured
- [ ] Interaction model written for each section, in those words
- [ ] Text is verbatim
- [ ] Assets listed with dimensions, layered compositions counted correctly

## Routing examples

- fires: measure the target page before we rebuild
- fires: record the computed styles and scroll behaviour
- fires: run the interaction sweep on that page
- fires: what animation library does that site use
- skips: publish the finished page
- skips: score the copy against the original
- skips: set up my payment gateway

## Related skills

- `site-clone` — agreed the job. Come from there, not straight to here.
- `site-clone-rebuild` — consumes every artifact this produces.
- `site-clone-design-system` — needs only Part 1 and Part 2 of this.
- `motion-and-interaction` — what the recorded motion should become on this site.
