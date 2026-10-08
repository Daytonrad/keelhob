---
name: motion-and-interaction
title: Motion and interaction
description: How to make a site feel alive rather than static — entrance choreography, scroll-driven animation, easing, stagger, hover and micro-interaction, page transitions, and the performance budget that keeps it all fast. Covers CSS-native motion first, then GSAP and ScrollTrigger, smooth scroll, and where Framer Motion does and does not apply in WordPress. Load this for ANY visual build alongside web-design-master, and whenever the ask is "make it feel premium/expensive/modern/award-winning", "add animations", "animate on scroll", "it looks flat", "bring it to life", or a page has been built and does not move.
enable_agentic: true
enable_prompt: true
role: reference
surface: any
next_skills: web-design-master, seo-audit-and-fix
verified_by: pre-launch-audit
---

# Motion and interaction

<!-- CONTENTS:START -->
## Contents

- [When to use](#when-to-use)
- [When NOT to use](#when-not-to-use)
- [Read this first](#read-this-first)
- [1. The craft — what actually reads as expensive](#1-the-craft-what-actually-reads-as-expensive)
- [2. CSS-native motion — start here](#2-css-native-motion-start-here)
- [3. GSAP — when the job genuinely needs it](#3-gsap-when-the-job-genuinely-needs-it)
- [4. Framer Motion — say plainly when it does not apply](#4-framer-motion-say-plainly-when-it-does-not-apply)
- [5. Smooth scroll — the honest trade](#5-smooth-scroll-the-honest-trade)
- [6. Reduced motion is a design state, not a switch](#6-reduced-motion-is-a-design-state-not-a-switch)
- [7. The performance budget](#7-the-performance-budget)
- [8. Going deeper](#8-going-deeper)
- [Related skills](#related-skills)
- [Routing examples](#routing-examples)
<!-- CONTENTS:END -->

## When to use

A page feels flat, static or generated, or the task names animation, scroll behaviour, hover, transitions or micro-interaction. Read it during a build, not bolted on at the end.

## When NOT to use

Not for layout, colour, type or spacing decisions — those are `web-design-master`. Not a licence to animate everything; motion that costs the performance budget is a defect, not a flourish.

## Read this first

1. **Motion is what separates a commissioned site from a generated one.** A well-typeset page with no motion reads as a template. This is not decoration.
2. **CSS-native first.** Scroll-driven animations, `@starting-style` and view transitions need no library and cannot be broken by a theme update. Most sites need nothing more.
3. **Small distances, fast starts.** The single biggest tell of amateur motion is elements sliding 100px over 800ms. Real motion travels 8–24px and starts fast.
4. **One signature moment per page.** Animating everything is the same as animating nothing.
5. **`prefers-reduced-motion` is not optional**, and it means "a calm alternative", not "no feedback".
6. **Transform and opacity only.** Anything else costs layout, and the SEO skill is measuring you.

---

## 1. The craft — what actually reads as expensive

These are the numbers. Deviating is fine, but deviate deliberately.

### Duration ladder

| What | Duration | Why |
|---|---|---|
| Hover, focus, colour change | 120–180ms | Must feel instant. Over 200ms and the interface feels laggy. |
| Dropdown, tooltip, small reveal | 200–300ms | Long enough to be followed, short enough not to be waited on. |
| Section entering on scroll | 400–600ms | Enough to register as motion rather than a flicker. |
| Hero or statement moment | 800–1200ms | Only once per page. This is the one you are allowed to enjoy. |
| Anything over 1.2s | — | The visitor is now waiting for your website. Do not. |

### Easing — where most AI-built sites give themselves away

`ease`, `ease-in-out` and `linear` are the defaults, and defaults read as
default. Almost all motion in the physical world starts fast and settles slow.

```css
/* Entrances and anything arriving. Fast out of the gate, long settle.
   This one curve does more for "expensive" than any other single change. */
--ease-out-expo: cubic-bezier(0.16, 1, 0.3, 1);

/* A softer, more common alternative. */
--ease-out-quart: cubic-bezier(0.25, 1, 0.5, 1);

/* Exits — the reverse. Slow to leave, then gone. */
--ease-in-quart: cubic-bezier(0.5, 0, 0.75, 0);

/* Playful overshoot. For a confirmation or a toggle, never for a paragraph. */
--ease-spring: cubic-bezier(0.34, 1.56, 0.64, 1);

/* linear is correct for exactly two things: continuous rotation,
   and anything tied to scroll position. Nowhere else. */
```

### Distance and stagger

- **Entrance travel: 8–24px.** Not 60, not 100. Large travel is the clearest sign of a template.
- **Scale: 0.96 → 1**, never 0.8 → 1.
- **Stagger siblings 40–80ms apart.** Below 40ms it reads as one block; above 100ms the visitor is waiting for the last item.
- **Cap the total.** Twelve cards at 80ms is nearly a second before the last one lands. Cap the stagger at ~6 items, then bring the rest in together.

### The three things that make a page feel considered

1. **Entrance choreography.** Elements arrive in a deliberate order — headline, then supporting line, then the button — not all at once, and not one long conveyor belt.
2. **State on everything interactive.** Hover, focus-visible, active, disabled and loading. A button with only a hover colour is unfinished, and `:focus-visible` is the one people skip.
3. **One signature moment.** A hero image that settles as the text arrives, a number that counts, a stat row that reveals in sequence. One. It is memorable *because* it is the only one.

---

## 2. CSS-native motion — start here

No library, no build step, no dependency to keep alive. In WordPress this is
almost always the right answer.

### Scroll-driven animation, with no JavaScript

```css
@keyframes rise {
  from { opacity: 0; transform: translateY(16px); }
  to   { opacity: 1; transform: none; }
}

.reveal {
  animation: rise linear both;
  /* Drive the animation by the element's own path through the viewport
     instead of by time. No observer, no scroll listener, no jank. */
  animation-timeline: view();
  animation-range: entry 10% cover 35%;
}
```

`animation-timeline: scroll()` ties an animation to the scroll container
instead — the right tool for a progress bar or a parallax layer.

Support is good in Chromium and shipping elsewhere. It degrades safely: where
it is unsupported, the animation simply runs once on load. Wrap in
`@supports (animation-timeline: view())` when you want a different fallback.

### `@starting-style` — animating something that has just appeared

The long-standing gap in CSS: you could not transition an element into
existence, because it had no previous state.

```css
.toast {
  opacity: 1;
  transform: translateY(0);
  transition: opacity 250ms var(--ease-out-quart),
              transform 250ms var(--ease-out-quart),
              display 250ms allow-discrete;
}
@starting-style {
  .toast { opacity: 0; transform: translateY(8px); }
}
```

This makes dialogs, popovers and anything toggled with `display` animate
properly with no JavaScript at all.

### View transitions

```css
@view-transition { navigation: auto; }
```

Cross-document view transitions turn a page navigation into a crossfade rather
than a white flash — which is most of what "feels like an app" actually means.
Name a shared element with `view-transition-name` and it will morph between
pages. On a WordPress site this is a couple of lines in a CSS snippet and it
changes the whole feel.

### `@property` — animating things that could not be animated

```css
@property --angle {
  syntax: '<angle>'; inherits: false; initial-value: 0deg;
}
.card {
  background: linear-gradient(var(--angle), #6d5efc, #22d3ee);
  transition: --angle 600ms var(--ease-out-quart);
}
.card:hover { --angle: 180deg; }
```

Gradients, custom values and numbers become animatable. This is how the
"gradient that moves on hover" effect is done properly, instead of by
cross-fading two stacked backgrounds.

---

## 3. GSAP — when the job genuinely needs it

Reach for GSAP when CSS cannot express it: a timeline with several elements in
a precise sequence, pinning a section while its contents change, scrubbing an
animation to scroll position, splitting text into characters, or animating
between two layouts. Not for a fade-in.

GSAP is free, including the plugins that used to be paid (ScrollTrigger,
SplitText, Flip). No licence to buy for commercial work.

### Loading it in WordPress — properly

Never paste a raw `<script>` tag into a template or a block. Enqueue it, and
only on pages that use it, or every page pays for it:

```php
// snippet_save: type=php, location=everywhere
add_action('wp_enqueue_scripts', function () {
    if (!is_page(['home', 'about'])) {   // only where it is used
        return;
    }
    wp_enqueue_script(
        'gsap',
        'https://cdnjs.cloudflare.com/ajax/libs/gsap/3.13.0/gsap.min.js',
        [], '3.13.0', true               // true = in the footer
    );
    wp_enqueue_script(
        'gsap-scrolltrigger',
        'https://cdnjs.cloudflare.com/ajax/libs/gsap/3.13.0/ScrollTrigger.min.js',
        ['gsap'], '3.13.0', true
    );
});
```

Pin the version. "latest" means a third party can change your site.

### The patterns worth knowing

```js
gsap.registerPlugin(ScrollTrigger);

// A timeline: several things in one controlled sequence.
// The "<" and "-=0.3" position parameters are what makes it feel choreographed
// rather than queued.
const tl = gsap.timeline({ defaults: { ease: 'expo.out', duration: 0.8 } });
tl.from('.hero h1', { y: 24, opacity: 0 })
  .from('.hero p',  { y: 16, opacity: 0 }, '-=0.5')   // overlap the previous
  .from('.hero .btn', { y: 12, opacity: 0 }, '<0.1'); // just after it starts

// Scroll-triggered: fires once when the element arrives.
gsap.from('.card', {
  scrollTrigger: { trigger: '.cards', start: 'top 80%' },
  y: 20, opacity: 0, stagger: 0.06, ease: 'expo.out'
});

// Scroll-scrubbed: tied to scroll position, so the visitor drives it.
// scrub: 1 adds a second of catch-up, which is what stops it feeling rigid.
gsap.to('.parallax', {
  scrollTrigger: { trigger: '.section', start: 'top bottom', end: 'bottom top', scrub: 1 },
  y: -80, ease: 'none'          // scrubbed motion is always linear
});
```

**ScrollTrigger's four settings that matter:** `trigger` (what to watch),
`start` / `end` (`'top 80%'` reads as "the top of the trigger meets 80% down
the viewport"), `scrub` (tie to scroll), and `pin` (hold a section still while
its contents change).

**Clean up.** If anything replaces page content without a reload, call
`ScrollTrigger.refresh()` after, or triggers keep firing at stale positions.

**`SplitText`** for per-character or per-line text reveals — the effect behind
most award-site headlines. Reveal by **line** for body copy; per-character on a
paragraph is unreadable and hostile to screen readers.

**`Flip`** for animating between two layouts — a card that expands into a
detail view. It records positions, you change the DOM, it animates the
difference.

---

## 4. Framer Motion — say plainly when it does not apply

**Framer Motion is a React library. A PHP-rendered WordPress theme cannot use
it.** There is no `<motion.div>` in a PHP template, and adding React to a
classic theme to animate a heading is a bad trade nobody should make.

It applies in exactly two places:

- A **headless** front end — Next.js or similar consuming WordPress over REST or GraphQL.
- A **block editor** surface, where the admin UI is genuinely React.

If someone asks for Framer Motion on a normal WordPress site, say this plainly
and offer the equivalent: CSS for most of it, GSAP for the rest. Both do
everything Framer Motion does here. Pretending otherwise wastes an afternoon
and ends with a broken build.

Where it does apply, the ideas transfer: `initial` / `animate` / `exit` is the
same thought as `@starting-style` plus a transition, `whileInView` is
`animation-timeline: view()`, and `layout` is what `Flip` does.

---

## 5. Smooth scroll — the honest trade

Libraries like Lenis interpolate scroll position so the page glides. It is
half of what people mean by "expensive-feeling", and it has real costs:

- It **overrides native scrolling**, so trackpad and phone feel different from every other site.
- It can **fight ScrollTrigger** unless the two are explicitly wired together.
- **Anchor links, in-page search and `scroll-behavior`** all need handling.
- It is another dependency between the visitor and reading the page.

Try `scroll-behavior: smooth` in CSS first — free, native, and enough for
anchor navigation. Add a library only when the design genuinely depends on
scroll feel, and always respect reduced motion.

**Never hijack the scroll wheel** — full-page snap that takes three flicks to
pass, or a horizontal section that traps the pointer. It reads as clever to
the builder and as broken to everyone else.

---

## 6. Reduced motion is a design state, not a switch

```css
@media (prefers-reduced-motion: reduce) {
  *, *::before, *::after {
    animation-duration: 0.01ms !important;
    animation-iteration-count: 1 !important;
    transition-duration: 0.01ms !important;
    scroll-behavior: auto !important;
  }
}
```

That blanket rule is the safety net, not the answer. The good version keeps
the **feedback** and removes the **movement**: a button still changes on hover,
it just does not lift. A section still appears, it fades instead of travelling.

Reduced motion is set by people who get motion sickness or vertigo from
parallax. Removing every trace of interactive feedback makes the site feel
broken for them instead. Keep opacity and colour, drop translation, scale,
parallax and anything continuous.

---

## 7. The performance budget

Motion is one of the most common causes of a failing Core Web Vitals score,
and `seo-audit-and-fix` will measure it.

- **Animate `transform` and `opacity` only.** These are composited. Animating `width`, `height`, `top`, `left` or `margin` forces layout on every frame.
- **`filter` and `box-shadow` are expensive.** Animate a stacked pseudo-element's opacity instead of the shadow itself.
- **`will-change` sparingly** — on the element about to animate, removed after. Leaving it everywhere costs memory and can make things slower.
- **Never animate above the fold on load in a way that delays LCP.** The hero *text* must render immediately. Fading in the headline over 800ms means your Largest Contentful Paint is 800ms worse for nothing.
- **Never animate layout height on scroll.** That is Cumulative Layout Shift, measured directly, and it fails the site.
- **Reserve space for anything that arrives.** Images and embeds get `width` and `height`.
- **Budget:** if scripted animation pushes mobile Performance below 90, cut animation until it does not. The visitor notices a slow page far more than a missing fade.

---

## 8. Going deeper

Public resources worth reading when a job needs more than this file. They are
not bundled with Keelhob and nothing here depends on them — fetch them if
you have network access, and skip them if you do not.

| Resource | What it teaches |
|---|---|
| `github.com/emilkowalski/skills` | Motion craft from an interaction specialist — timing, spring feel, and why a transition reads right or wrong. |
| `github.com/pbakaus/impeccable` | Design vocabulary. Gives an agent the words — vertical rhythm, optical alignment — needed to make a specific critique instead of "make it nicer". |
| `github.com/Leonxlnx/taste-skill` | Aimed squarely at stopping AI tools producing generic layouts. |
| `github.com/greensock/gsap-skills` | GSAP's own guidance, from the people who wrote it. |
| `github.com/bruzethegreat/gsap-master-mcp-server` | A GSAP MCP server, if the owner wants it connected to their client. |
| `github.com/21st-dev/magic-mcp` | Component generation — a source of well-built UI parts to adapt. |

Use them as reference, not as copy-paste. A component pasted in without the
surrounding design system is exactly the mismatch that makes a page look
assembled.

---

## Related skills

- **`web-design-master`** — the layout, type, colour and spacing this sits on top of. Load it **first**; motion on a badly-typeset page just makes the problem move.
- **`code-snippets`** — how to actually ship CSS and JS on a WordPress site, and the safety rules for live code.
- **`seo-audit-and-fix`** — measures whether the motion cost you the performance score. Check after any significant animation work.
- **`website-building`**, **`elementor-page-building`** — the build mechanics that place the markup you are animating.
- **`build-standard`** — the definition of done, and the router for everything else.

## Routing examples

Phrases that must reach this skill, and one that must not. `bin/lint-skills.php`
checks them against every other skill's description.

- fires: make the hero animate on scroll
- fires: the site feels flat and static
- fires: add a hover effect to the cards
- skips: do i need a cookie banner
