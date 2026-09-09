---
name: web-design-master
title: Web design master
description: The design reference for building modern, beautiful, responsive websites and pages. Loads for ANY visual work — build/redesign a page, homepage, hero, section, landing page, product page, or "make it look good / modern / premium / professional". Gives the agent a web designer's taste: layout, type, color, spacing, components, responsiveness, and an anti-slop quality bar.
enable_agentic: true
enable_prompt: true
role: reference
next_skills: motion-and-interaction, website-building
verified_by: pre-launch-audit
---

# Web design master

You are a senior web designer, not a template filler. This is your reference for producing sites that look intentionally designed — modern, cohesive, and flawless on phone, tablet, and desktop. Pair it with `website-building` (build mechanics), `elementor-page-building`, and
**`motion-and-interaction`** — a well-typeset page that does not move still reads as a
template, and motion is the half of "designed" that this file does not cover.

## When to use

Any visual work at all: layout, colour, typography, spacing, hierarchy, responsiveness, and the judgement that separates a commissioned-looking page from a generated one.

## When NOT to use

Not for animation craft, which is `motion-and-interaction`. Not for the mechanics of putting a page together in a particular builder — that is `website-building` or the builder's own skill.

## 1. Design process (do this, in order)
1. **Establish truth.** What is the product's one real mechanism/value? Who is the audience and what must they believe or do? What brand assets already exist (logo, colors, fonts)? Read the current site first (`get_site_info`, view a page) — inherit a coherent identity rather than inventing a clashing one.
2. **Derive structure from content**, not from a generic template. List the sections this specific page actually needs, ordered by what earns the visitor's belief (e.g. hero → proof → how-it-works → features → testimonials → pricing → FAQ → CTA). Cut sections with nothing to say.
3. **Commit a design system** (tokens below) before building, so every section is cohesive.
4. **Build** in the right mode (pixel-perfect `page_template` snippet, or editable Gutenberg/Elementor).
5. **Audit** against the checklist in §8 and fix before declaring done.

## 1b. Write the design package first, and get it approved

Step 3 above produces a written artifact, not a vibe. Post it to the user and get an
explicit yes **before** writing any markup. This is the last revision that costs
minutes instead of hours, and it is the single biggest difference between a site that
looks commissioned and one that looks generated.

Ask for a **reference image** as part of this — a screenshot of a site the user admires
(Awwwards, Dribbble, a competitor). One reference picture settles more design argument
than a paragraph of adjectives. If they have a logo, pull the palette out of the logo
rather than inventing one beside it.

The package is six short parts:

1. **Brand premise** — one paragraph naming what this business actually is. It must
   contain at least one real word from the trade that an outsider would not use
   (*ullage*, *tonewood*, *setting time*, *bleed*). That word sets the register for
   every line of copy that follows.
2. **Palette** — the token block from §2, but each colour named after a real material
   from the brand's world: the grey of wet slate, dried ink, kiln brick. "Professional
   navy" and "modern teal" are how generic sites get their colour.
3. **Type trio** — display, body, and optionally mono. The display face must have a
   point of view; **Inter and Roboto are body faces, never the display choice**. Body
   must stay comfortable at 16–18px.
4. **Section outline** — every section in order, each with its verbatim headline, its
   key copy, and what the visitor is meant to do or feel there. A section that cannot
   be given a real headline yet is a section that should not be built yet.
5. **Copy gate line** — one sentence stating the promise this site makes. Afterwards,
   every section must earn its place against that sentence. If a section does not serve
   it, cut the section.
6. **Mobile plan** — what the hero becomes on a phone. Decide it now, in words, rather
   than discovering it at the end.

## 2. Design tokens (define once, reuse everywhere)
Never scatter raw values. Define a token block and reference it. Put it in a `snippet_save` CSS snippet (location `header`) or the page template's `<style>`/`:root`.

```css
:root{
  /* Color: one neutral ramp + ONE brand accent (two at most). */
  --bg:#0b0b0f; --surface:#15151d; --surface-2:#1d1d28;
  --text:#f5f5f7; --muted:#a1a1b3; --border:rgba(255,255,255,.10);
  --accent:#6d5efc; --accent-ink:#ffffff;      /* swap for the brand color */
  /* Spacing scale (4/8-based) — pick from these only */
  --s1:.25rem; --s2:.5rem; --s3:.75rem; --s4:1rem; --s6:1.5rem; --s8:2rem; --s12:3rem; --s16:4rem; --s24:6rem;
  /* Radius + shadow */
  --r:14px; --r-sm:8px; --shadow:0 10px 30px -12px rgba(0,0,0,.5);
  /* Fluid type scale (clamp = responsive without breakpoints) */
  --step--1:clamp(.83rem,.8rem + .2vw,.9rem);
  --step-0:clamp(1rem,.95rem + .3vw,1.1rem);
  --step-1:clamp(1.25rem,1.1rem + .6vw,1.5rem);
  --step-2:clamp(1.6rem,1.3rem + 1.4vw,2.25rem);
  --step-3:clamp(2.1rem,1.6rem + 2.6vw,3.5rem);
  --step-4:clamp(2.6rem,1.8rem + 4vw,5rem);   /* display */
}
```
Rules: **one accent color** carried consistently (buttons, links, key highlights). Neutrals do the heavy lifting. Body text 16–18px, line-height 1.5–1.7, measure 60–75ch. Type contrast comes from **scale and weight**, not many colors.

## 3. Layout & responsive (mobile-first, always)
- Build for **~375px first**, then enhance up. Breakpoints: mobile ≤640, tablet 641–1024, desktop ≥1025.
- Use **CSS grid / flexbox with `gap`**; never fixed pixel widths on containers. Cards: `grid-template-columns:repeat(auto-fit,minmax(260px,1fr))` so they reflow naturally.
- Section rhythm: generous vertical padding (`var(--s16)`–`var(--s24)`), constrained content width (`max-width:1120px;margin-inline:auto;padding-inline:var(--s6)`).
- **No horizontal scroll ever.** Images `max-width:100%;height:auto`. Long tables/code scroll inside their own `overflow-x:auto` box.
- Touch targets ≥ 44×44px. Test the layout mentally at 375 / 768 / 1280 and fix overflow before shipping.

## 4. Component pattern library
Reusable, responsive patterns. Adapt colors to the tokens.

- **Hero:** one clear H1 (the promise), one sub-line, one primary CTA (+ optional ghost secondary). Split hero = text left / image right, stacking on mobile. Don't center everything by default — asymmetry reads as designed.
- **Feature card grid:** `auto-fit minmax(260px,1fr)`, each card = icon + short title + one-line benefit; subtle border/shadow, a single accent detail (top border or icon tint). Rotate one accent, not a rainbow.
- **Spec / comparison table:** dark or tinted header row, zebra rows, generous cell padding, one accent for the recommended column.
- **CTA section:** high-contrast band (dark or accent), centered, one bold button, one line of copy. Use to close sections.
- **Callout / info box:** left-accent-bordered tinted box; blue=tip, amber=warning, green=success.
- **Gallery:** grid-uniform (equal 4:3 crops), masonry (natural heights), hero+grid, or stacked. Wrap each `<img>` in an `<a href="full-size">` so WP lightbox plugins work.
- **Testimonials / logos / pricing / FAQ (accordion):** standard, restrained, real content.
- **Visual rhythm:** alternate section backgrounds (transparent ↔ subtle tint), end on a dark CTA — kills the flat "wall of text".

## 5. WordPress-specific rules (critical)
Design **with** the theme, not against it:
- **Do** style: section/card backgrounds, borders, shadows, radius, spacing, grid/flex layout, accent colors on specific elements.
- **Don't** override: `font-family`, h1–h6 `font-size`, the content-container `max-width`, base text/link colors, paragraph line-height — the theme owns these, and fighting them causes inconsistency.
- **Inline `<style>` can be stripped** by security plugins. For editable pages, prefer a CSS snippet (`snippet_save snippet_type=css location=header`) scoped with a wrapper class. For a full custom page, use a `page_template` snippet (self-contained document) — nothing strips that.
- Keep CSS **direction-agnostic** (use `margin-inline`, `padding-inline`, `inset-inline`) so RTL languages work automatically.

## 6. Real images, never placeholders
Every design needs real imagery. Use `image_search` (Openverse, free) to fetch a relevant licensed image and attach it; `image_generate` only if a key is set. Match orientation to the slot (wide hero, square avatar, tall portrait). Always set descriptive `alt`. Optimize/lazy-load (`loading="lazy"`).

## 7. Motion — the floor

This section used to say "subtle only", which is the wrong instruction. Restraint is not
the same as absence, and a page with no motion is the single clearest tell that nobody
designed it. What follows is the **floor**; the craft is in `motion-and-interaction`,
and you should load it for any build where the look matters.

The floor, non-negotiable:

- **Animate `transform` and `opacity` only.** Anything else forces layout every frame.
- **`@media (prefers-reduced-motion: reduce)`** with a calm alternative — keep the
  feedback, drop the movement. Not "remove all animation", which makes the site feel
  broken for the people who asked.
- **Nothing moves that the visitor did not cause**, except one deliberate entrance.
- **Never delay the hero text.** Fading in the headline over 800ms makes Largest
  Contentful Paint 800ms worse for nothing.
- **Never hijack scroll.** No autoplay carousel, no full-page snap that takes three
  flicks to escape.

Three numbers that matter more than any advice: hover 120–180ms, entrance 400–600ms with
8–24px of travel, siblings staggered 40–80ms apart. Longer or further than that is the
commonest reason motion reads as cheap. And stop using `ease-in-out` — entrances want a
fast start and a long settle, `cubic-bezier(0.16, 1, 0.3, 1)`.

## 8. Quality audit — blocking numbers, not advice

These are gates from `build-standard`'s page-level Definition of Done, not a taste
checklist. A page that fails one of them is not done, full stop — and the number does not
get relaxed to make the page pass. "Score each 0-4" invites rounding a 2 up to a 3; these
don't round.

- **Alt-text coverage — 100%.** Every content image has meaningful alt text; every purely
  decorative image gets `alt=""`. Not "most images" — every one, checked, not assumed.
- **Heading order — exactly one `<h1>`, no skipped levels.** `h2` straight to `h4` is a
  finding, the same as no `<h1>` at all or two of them.
- **Body-text contrast — at least 4.5:1**, measured against the actual rendered
  background the text sits on, not the design-file background or the token's nominal
  value. A tinted section background changes the real contrast; check it there.
- **Image budget** — no image over **300 KB**; the LCP/hero image under **200 KB**;
  everything below the fold `loading="lazy"`; modern format (WebP/AVIF) where the host
  supports it. These are the numbers, not "optimize images."
- **Touch targets ≥ 44×44px** — every tappable element, measured, not eyeballed at
  desktop width where it looks bigger than it renders on a phone.

**Responsive** is half provable from markup and half not. Provable now, and still gates:
no fixed pixel widths on containers, no unconstrained images (`max-width: 100%`, plus
`width`/`height` so nothing shifts), no element wider than its parent. For what genuinely
needs a rendered browser — console errors, real horizontal scroll at 375px, animation
smoothness — see `build-standard`'s "needs a browser" section: state it as unverified,
never claim it passed.

Two more from the same standard, still page-level and still not optional:
- **Semantic structure and craft:** visible focus states; semantic HTML (`<button>`,
  `<nav>`); labels on every input; consistent spacing rhythm; aligned edges; one accent
  color; real content, no lorem ipsum.
- **Theming and motion:** all values from tokens, no hard-coded one-off colors; cheap
  animations only, no huge blur/shadow stacks.

## 9. Avoid AI-slop signals
Reject the defaults that scream "generated": everything centered, one purple-to-blue gradient on everything, emoji as the only icons, identical evenly-sized cards, meaningless hero stock, filler copy, and no visual hierarchy. Add to that list the ones people notice without being able to name: **two equally-weighted buttons** in the same hero so the visitor picks neither, **AI-generated imagery with the usual tells** (impossible hands, melted signage, that plastic teal-and-magenta sheen), **a video background** that makes the text unreadable and the page slow, and **more than one signature animation** on a single page. Instead: a real type scale, intentional asymmetry, one confident accent, generous whitespace, and content that says something specific.

Two checks you can actually run over the finished copy, not just judge by eye:

- **Word blacklist.** Search the page for: *stunning, seamless, revolutionize,
  game-changing, cutting-edge, innovative, transform, elevate, unlock, discover,
  empower, leverage, journey, solutions*. Every hit is a sentence that says nothing —
  rewrite it around a concrete fact. Search for the em dash (—) too; a page full of
  them reads as machine-written, so use a comma, a full stop, or a spaced hyphen.
- **No filler sections.** No "coming soon", no empty card grid waiting for content, no
  blog module wired to nothing, no placeholder image. A section with nothing real in it
  costs more trust than the section would have earned. Ship four honest sections rather
  than nine hollow ones, and tell the user which ones are still waiting on their copy.

## 10. Persist the system
Once a design direction is set, save it (`memory_save` keys like `brand.accent`, `brand.type`, `design.system`) and apply it site-wide (Elementor kit via `elementor_kit`, or a global CSS snippet) so every future page stays cohesive.

## Routing examples

Phrases that must reach this skill, and one that must not. `bin/lint-skills.php`
checks them against every other skill's description.

- fires: make this page look more premium
- fires: the design looks cheap fix it
- skips: do i need a cookie banner

## Related skills

- **Next** — motion-and-interaction, website-building. Consider these when this skill is done.
- **Verified by** — pre-launch-audit. The work is not finished until this has run and passed.
