---
name: site-clone-rebuild
title: Turn a captured spec into WordPress
description: Builds an already-measured page as real WordPress — Elementor sections the owner can still edit, or a page-template snippet when pixel accuracy beats editability. Uploads assets into the media library, translates foreign markup into honest structure instead of pasting it, and recreates GSAP, Framer Motion, Lenis and AOS scroll animation with what this site actually has.
enable_agentic: true
enable_prompt: true
role: procedure
surface: workstation
requires: site-clone-capture
next_skills: elementor-page-building, motion-and-interaction, code-snippets, site-clone-verify
verified_by: site-clone-verify
---

# Turn a captured spec into WordPress

The measuring is done. Now build it here — properly, as WordPress, not as a
foreign page smuggled inside a WordPress URL.

Work **one section at a time**. Build it, look at it, fix it, then start the next.
A whole page built blind and reviewed at the end takes longer, because every
mistake compounds into the sections after it.

## When to use

- `site-clone-capture` has produced specs and the owner wants the pages built.
- A section came back wrong from `site-clone-verify` and needs rebuilding.

## When NOT to use

- Nothing has been measured. You will guess, and it will show. Run
  `site-clone-capture`.
- Only the look was wanted — `site-clone-design-system` is far cheaper.
- The page is a straightforward article. `blog-publishing` is the right tool.

## Rule zero — never paste their markup

This is the mistake that ruins the job, and it is tempting because it looks fast.

A Wix page is a stack of absolutely-positioned boxes at fixed coordinates. A
Next.js page is markup a build step generated. A Squarespace page is a grid
system's output. Pasting any of it produces a page that renders once, at one
width, and then breaks the moment anything changes — and it can never be edited.

**Read the spec, understand what the section *is*, and build that.** A hero with
a heading, a paragraph and two buttons is a hero with a heading, a paragraph and
two buttons, whatever coordinates the original used to place them.

The one exception: their **text** is copied verbatim. That is content, and
paraphrasing it is a different kind of error.

## Step 1 — Tokens first, always

If `site-clone-design-system` has not run, run it now. Every section built before
the tokens land hard-codes values that then have to be found and unpicked one at
a time.

```
memory_get   key: build-mode      → elementor | page-template
```

## Step 2 — Assets into the media library

Before building a section, upload what it needs. An image hotlinked from the
original site breaks the day they take it down, and it tells their server about
every one of your visitors.

```
upload_media   url: <original asset url>   title: <descriptive>   alt: <real alt text>
```

While you are there:

- **Write real alt text.** The original's is often empty or `image-3.png`. This
  is one of the twenty things `launch-readiness-and-compliance` checks, and one
  of the few places a copy can honestly be better than its source.
- **Watch for layered compositions.** The spec says whether a visual is one image
  or a background plus a foreground. Get it wrong and the section looks thin.
- **Check for video and Lottie.** If the spec says `<video>`, upload the video.
  Do not build an HTML mock of what the video shows.
- **Convert to WebP** where the original was a large PNG or JPEG. Same picture,
  a fraction of the weight, and the readiness checker looks at page weight.

## Step 3 — Build the section

### Elementor route — the default

```
elementor_settings   action: prepare_for_ai     → read the current structure first
elementor_page       ... build the section
```

Map what the spec describes onto real widgets:

| Spec says | Build with |
|---|---|
| Full-width band with a background | Section, full width, background set on the section |
| Heading + paragraph + buttons | Heading, Text Editor, two Button widgets in a column |
| Card grid | Inner section with equal columns, or a Loop Grid for real posts |
| Sticky header | The theme header, or Xpro's theme builder — **not** a widget with `position: fixed` |
| Accordion / tabs | Elementor's own widget. It is accessible; a hand-rolled one usually is not. |
| Marquee / logo strip | An Xpro addon widget if present, else a small CSS snippet |

Use **global** colours and fonts from the kit. Never type a hex code into a widget
— that is the value hard-coded again, one layer down.

Set responsive values explicitly at tablet and mobile where the spec recorded a
change. Elementor's defaults are a guess and the spec is a measurement.

### Page-template route — when pixel accuracy wins

Only when the owner has accepted that they will not be able to edit it:

```
snippet_save   snippet_type: php   location: page_template   target_id: <page id>
```

Write real, semantic HTML with the CSS custom properties from the tokens.
Flexbox and grid, never absolute positioning copied from the source. Mobile-first,
with the breakpoints the capture recorded.

## Step 4 — Motion, honestly translated

`BEHAVIORS.md` records what moves. This is where a copy either comes alive or
stays a screenshot.

| Original uses | Build here with | Honest fidelity |
|---|---|---|
| CSS transitions | The same CSS transitions | 100% |
| AOS reveals | Elementor entrance animations, or a small IntersectionObserver snippet | ~95% |
| **Lenis smooth scroll** | Lenis, loaded in a snippet | ~98% — and skipping it is felt immediately |
| GSAP fade / slide / stagger | GSAP via snippet, or CSS + IntersectionObserver | 90–95% |
| **GSAP ScrollTrigger, pinned + scrubbed** | GSAP + ScrollTrigger in a snippet | 75–90%. The hard part. |
| Framer Motion springs | GSAP with an approximated ease | 80–90% — spring physics has no exact CSS equal |
| Framer Motion layout animations | Usually not worth reproducing | Say so |
| **WebGL / canvas / three.js** | A still image or a video of it | Not a clone. Say the word. |

Loading GSAP, when the spec justifies it:

```
snippet_save   name: "GSAP + ScrollTrigger"   snippet_type: php   location: everywhere
```

Enqueue from a CDN with `wp_enqueue_script`, then a second snippet for the
timelines. Two snippets, not one, so the library can stay while a timeline is
rewritten.

Three rules, no exceptions:

1. **Honour `prefers-reduced-motion`.** Wrap every timeline. The original may not;
   that is not a reason to inherit it.
2. **Never animate `top`, `left`, `width` or `height`.** Transform and opacity
   only. The original may be doing it badly on a machine faster than the visitor's.
3. **Do not load GSAP for a fade.** A 70KB library for what a CSS transition does
   is page weight the readiness checker will charge you for, correctly.

Read `motion-and-interaction` before writing timelines. Reproducing the timing of
a site that over-animates is not a success.

## Step 5 — Check the front end after every section

Not at the end. After each one.

```
site_readiness                     → still passing?
```

Then actually look at it — load the page in the browser at 1440 and 390 and
compare against the section screenshot. Memory of what you built is not evidence.

If a snippet fataled it is auto-deactivated and the site stays up, but the
section will silently be missing. Check rather than assume.

## Step 6 — The things a copy should fix, not inherit

The original is not automatically right. Carry these forward better:

- **Alt text** — usually absent. Write it.
- **Heading order** — many designs jump h1 → h4 for looks. Use the right level
  and style it.
- **Colour contrast** — check body text against its background. If the original
  fails, say so and fix it; nobody wants an inaccessible copy for fidelity's sake.
- **Image weight** — serve WebP at sensible dimensions.
- **Focus states** — designers strip them constantly. Put them back.

Note every one of these in your report. They are deliberate differences, and an
owner told about them reads them as care rather than as errors.

## Step 7 — Hand to the verifier

Do not report done. `site-clone-verify` is this skill's declared verifier and
operating-protocol rule 13 means its result has to be in your report. Run it and
say the number. If you did not run it, say that plainly instead.

## Routing examples

- fires: build the captured sections in elementor
- fires: turn the spec into a wordpress page
- fires: recreate their gsap scroll animation here
- fires: rebuild the measured sections as wordpress
- skips: measure the original page first
- skips: extract only the colour palette
- skips: check my site for broken links

## Related skills

- `site-clone-capture` — supplies every spec this consumes. Required.
- `site-clone-verify` — scores the result. This skill's verifier.
- `elementor-page-building` — the widget-level detail for the Elementor route.
- `motion-and-interaction` — the judgement about what should move, and how much.
- `code-snippets` — safe practice for the GSAP and Lenis snippets.
