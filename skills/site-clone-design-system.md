---
name: site-clone-design-system
title: Borrow a site's taste, not its pages
description: Installs another site's colour palette, typography, type scale, spacing rhythm, radii, shadows and motion timing as this site's own global defaults, so pages built here inherit that craft without copying anybody's layout, words or pictures. The fast, always-legitimate answer to "I like their palette and fonts, can we have those".
enable_agentic: true
enable_prompt: true
role: procedure
surface: workstation
requires: site-clone-capture
next_skills: web-design-master, elementor-page-building, motion-and-interaction
---

# Borrow a site's taste, not its pages

<!-- CONTENTS:START -->
## Contents

- [When to use](#when-to-use)
- [When NOT to use](#when-not-to-use)
- [What you are installing, and what you are not](#what-you-are-installing-and-what-you-are-not)
- [Step 1 — Turn measurements into a system](#step-1-turn-measurements-into-a-system)
- [Step 2 — Install them where WordPress will actually use them](#step-2-install-them-where-wordpress-will-actually-use-them)
- [Step 3 — Motion feel](#step-3-motion-feel)
- [Step 4 — Prove it landed](#step-4-prove-it-landed)
- [What to report](#what-to-report)
- [Routing examples](#routing-examples)
- [Related skills](#related-skills)
<!-- CONTENTS:END -->

Somebody pointed at a site and said "like that". Most of the time what they are
responding to is not the layout at all — it is the restraint. One accent colour
instead of five. Type that steps in a real ratio. Space that repeats. Corners
that agree with each other.

None of that is anybody's property, and all of it transfers. This is the version
of "copy that site" that is both cheaper and, nine times out of ten, what they
actually wanted.

## When to use

- Somebody wants another site's palette and type applied to their own content.
- The permission question in `site-clone` came back "no" — this is the honest
  alternative, and it is a good one.
- A full clone is agreed, but the tokens should land first so every rebuilt page
  inherits them instead of hard-coding values section by section.
- This site already exists and its palette and type were never chosen.

## When NOT to use

- The layout, images and words are wanted too. That is `site-clone-rebuild`.
- Nothing has been measured yet. Run `site-clone-capture` Parts 1 and 2 first —
  this skill installs values, it does not discover them.

## What you are installing, and what you are not

**Taking:** colour relationships, type families and the ratio between sizes, the
spacing step, radii, shadow depth and softness, button shape and weight,
transition durations and easing curves, container width.

**Not taking:** their words, their photographs, their logo, their icon set, their
section order, their illustrations. None of that is taste; all of it is theirs.

Say that split out loud when you report. An owner who understands they got the
craft and not the content will not later wonder why their site does not look
identical.

## Step 1 — Turn measurements into a system

`TOKENS.md` has raw values with counts. Raw values are not a system. Cut them
down before installing anything.

**Colour.** Take at most: one background, one surface, one body text, one muted
text, one border, one accent, and one accent-hover. If the capture found nine
blues, they are one blue at different opacities — find the base. A palette that
is merely *copied* is as sloppy as the original; a palette that is *understood*
is better than it.

**Type.** Two families at most — usually one for headings, one for everything
else. Sort the captured sizes and look for the ratio between them. If the steps
are 16 / 20 / 25 / 31 / 39, that is a 1.25 scale and you should install the
scale, not the five numbers. Where the original is inconsistent, snap to the
scale. You are allowed to be tidier than the site you measured.

**Space.** Find the smallest repeating unit — commonly 4px or 8px — and express
every gap and padding as a multiple. This is the single change that most makes a
site feel professional, and it is the one most often skipped.

**Motion.** One duration for small things (hover, roughly 150–200ms), one for
large (section reveals, roughly 400–600ms), and one easing curve used everywhere.
Read them from `BEHAVIORS.md` rather than inventing them.

Sanity-check the result against `web-design-master`. If the measured site is
itself badly built — six typefaces, twelve greys, random spacing — do not
faithfully reproduce bad taste. Take what is good, fix what is not, and say in
your report which calls you made and why.

## Step 2 — Install them where WordPress will actually use them

Check the build mode first:

```
memory_get   key: build-mode
get_site_info                       →  active_plugins
```

### If Elementor is active — Site Settings is the right home

Global colours and fonts set here cascade into every widget, so the owner can
restyle the whole site later from one screen instead of editing forty widgets.

```
elementor_settings  action: prepare_for_ai        → read the current kit
elementor_kit       ... apply the palette and the type scale
```

Set **global** colours and **global** fonts, not per-widget overrides. A widget
override is a hard-coded value wearing a costume, and it defeats the whole point.

### Whichever builder — install CSS custom properties too

Elementor's kit does not reach hand-written snippets, page templates or the block
editor. Custom properties do, and they cost nothing:

```
snippet_save   name: "Design tokens"
               snippet_type: css
               location: header
```

```css
:root {
  --bg: #0b0d0f;
  --surface: #14181c;
  --text: #e8eaed;
  --muted: #9aa3ab;
  --border: #232a31;
  --accent: #c9752b;
  --accent-hover: #e08c3f;

  --font-display: "…", ui-serif, Georgia, serif;
  --font-body: "…", system-ui, -apple-system, sans-serif;

  /* A 1.25 scale from a 16px base. Steps, not arbitrary numbers. */
  --t-xs: 0.8rem;  --t-sm: 1rem;    --t-md: 1.25rem;
  --t-lg: 1.563rem; --t-xl: 1.953rem; --t-2xl: 2.441rem; --t-3xl: 3.052rem;

  /* One 8px unit, multiplied. Never a stray 13px. */
  --s-1: 0.5rem; --s-2: 1rem; --s-3: 1.5rem; --s-4: 2rem;
  --s-6: 3rem;   --s-8: 4rem; --s-12: 6rem;  --s-16: 8rem;

  --radius: 10px;
  --shadow: 0 1px 2px rgb(0 0 0 / .08), 0 8px 24px rgb(0 0 0 / .12);
  --container: 1200px;

  --ease: cubic-bezier(.22, 1, .36, 1);
  --dur-fast: 180ms;
  --dur-slow: 520ms;
}
```

Fill every value from `TOKENS.md`. The block above is a shape to fill, not a
palette to ship.

### Fonts — check the licence before you serve anything

- **Google font** → load it properly with `wp_enqueue_style` in a PHP snippet,
  and add `display=swap`. Never `@import` inside CSS; it blocks rendering and
  the readiness checker will flag it.
- **Commercial font** (Monotype, Hoefler, a foundry licence) → **stop.** The
  original site paying for a licence does not give this site one. Tell the owner
  the name, say a licence is needed, and offer the closest free alternative in
  the meantime. Do not quietly hotlink their font files from the other site.

## Step 3 — Motion feel

Install the durations and easing as variables above, then read
`motion-and-interaction` before applying them. Matching an easing curve is not
the same as matching restraint — a site that animates everything at the right
speed still feels cheap.

Honour `prefers-reduced-motion`. The measured site may not; that is not a reason
to inherit the mistake.

## Step 4 — Prove it landed

Do not report from having written the CSS. Render and read the values back:

1. Load the front page in the browser.
2. Read `getComputedStyle(document.documentElement).getPropertyValue('--accent')`
   and confirm the real value.
3. Screenshot at 1440 and 390.
4. Confirm the heading font is actually loaded, not silently falling back — check
   `document.fonts.check('1em "<family>"')`.

A custom property defined in a stylesheet that never loaded produces a page that
looks completely unchanged and a report that says it worked.

## What to report

- The palette, with each colour's role.
- The type families, the scale ratio, and whether a licence is outstanding.
- The spacing unit.
- Where the tokens live (Elementor kit, CSS variables, or both).
- Any judgement call where you deliberately improved on the measured site.
- Plainly: content and imagery were not taken, and why.

## Routing examples

- fires: copy their typography and spacing rhythm only
- fires: use their colour palette and fonts here
- fires: install the design tokens we measured
- fires: i like their typography can we have that
- skips: copy their home page layout as well
- skips: score how close the copy is
- skips: write the about page copy

## Related skills

- `site-clone-capture` — supplies TOKENS.md. Required.
- `web-design-master` — the judgement about whether the measured taste is any good.
- `motion-and-interaction` — how the borrowed timings should actually be used.
- `elementor-page-building` — building pages once the tokens are in place.
