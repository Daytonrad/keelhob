---
name: site-clone-verify
title: Score the copy against the original
description: The gate that decides whether a rebuilt page is finished. Puts original and copy side by side at three widths, scores every section on layout, type, colour, imagery, motion and responsive behaviour, and produces one percentage plus a ranked list of what is still different. Then fixes the worst gap and measures again. Refuses "looks about right".
enable_agentic: true
enable_prompt: true
role: gate
surface: workstation
requires: site-clone-capture
next_skills: pre-launch-audit, site-clone-rebuild
---

# Score the copy against the original

<!-- CONTENTS:START -->
## Contents

- [When to use](#when-to-use)
- [When NOT to use](#when-not-to-use)
- [Rule zero — never score from memory](#rule-zero-never-score-from-memory)
- [Step 1 — Put them side by side](#step-1-put-them-side-by-side)
- [Step 2 — Score each section](#step-2-score-each-section)
- [Step 3 — Rank the gaps](#step-3-rank-the-gaps)
- [Step 4 — Fix the worst, measure again](#step-4-fix-the-worst-measure-again)
- [Step 5 — Report](#step-5-report)
- [What the numbers actually mean](#what-the-numbers-actually-mean)
- [Step 6 — Then the real audit](#step-6-then-the-real-audit)
- [Routing examples](#routing-examples)
- [Finish check](#finish-check)
- [Related skills](#related-skills)
<!-- CONTENTS:END -->

"It looks about right" is not a result. An agent that built a page is the worst
possible judge of it, because it is comparing against what it *meant* to build.

This skill produces a number, a ranked list of what is wrong, and a loop that
closes the gaps. It is the difference between claiming 95% and knowing it.

## When to use

- `site-clone-rebuild` has finished a page. Always. It is the declared verifier.
- The owner asks how close the copy is.
- Something feels off and nobody can say what.

## When NOT to use

- The page was built from a brief, not a copy. Use `pre-launch-audit` — there is
  no original to compare against.
- Only tokens were installed. `site-clone-design-system` has its own check, and
  scoring a layout that was never meant to match is meaningless.

## Rule zero — never score from memory

You must look at both pages, in a browser, at the same widths, in this session.
Reading your own build notes and grading them is how a page ships at "97%" with
a missing section.

If you cannot drive a browser, say so and stop. An unmeasured score is worse than
no score, because it gets believed.

## Step 1 — Put them side by side

For each of 1440px, 768px and 390px:

1. Load the original. Full-page screenshot.
2. Load the copy. Full-page screenshot.
3. Keep both. They are the evidence.

Where the original screenshots from `site-clone-capture` are still current, reuse
them rather than re-fetching — but check the original has not changed since.
A site that was redesigned last week makes every gap look like your fault.

## Step 2 — Score each section

Walk `TOPOLOGY.md` top to bottom. For each section, score six things out of 10.
Read real values; do not judge by eye.

| # | What | 10 means | How to check |
|---|---|---|---|
| 1 | **Layout** | Same order, proportions, alignment | Compare bounding boxes. Element widths within ~2%. |
| 2 | **Type** | Same family, size, weight, line-height, tracking | `getComputedStyle` on each text node. Not the screenshot. |
| 3 | **Colour** | Same values in the same roles | Computed `color` and `backgroundColor`. Exact strings. |
| 4 | **Imagery** | Every asset present, right crop, right layer count | Count `<img>` and backgrounds in both. A missing layer is a 5, not a 9. |
| 5 | **Motion** | Same trigger, same duration, same feel | Scroll both. Hover both. Compare against `BEHAVIORS.md`. |
| 6 | **Responsive** | Same layout change at the same breakpoint | Compare at 768 and 390 separately. |

A useful script for #1 to #3 — run it on both pages and diff the output:

```js
// Measured facts for one section, so two pages can be compared as data
// rather than as an impression.
const root = document.querySelector('<section selector>');
const out = [];
for (const el of root.querySelectorAll('*')) {
  const r = el.getBoundingClientRect();
  if (r.width === 0 || r.height === 0) continue;
  const s = getComputedStyle(el);
  out.push({
    tag: el.tagName.toLowerCase(),
    text: (el.textContent || '').trim().slice(0, 40),
    box: [Math.round(r.width), Math.round(r.height)],
    font: [s.fontFamily.split(',')[0], s.fontSize, s.fontWeight, s.lineHeight].join(' '),
    color: s.color,
    bg: s.backgroundColor,
    radius: s.borderRadius,
  });
}
console.log(JSON.stringify(out, null, 1));
```

Section score = the six averaged. Page score = the mean across sections,
**weighted by how much vertical space each section occupies**. A perfect footer
does not make up for a broken hero, and an unweighted average pretends it does.

## Step 3 — Rank the gaps

Write the list worst first. Every entry says the section, what is wrong, the
measured difference, and whether it is fixable here.

```
1. Hero          motion    Original pins and scrubs on scroll; copy fades in.   fixable, hard
2. Feature grid  layout    Cards 380px vs 412px; gap 24px vs 32px.              fixable, easy
3. Testimonials  imagery   Background wash missing — captured as one image.     fixable, easy
4. Hero          imagery   three.js particle field. Static frame in its place.  NOT fixable
```

"Fixable" is a judgement about this stack, not about effort. Be honest — a wrong
"not fixable" hides a bug, and a wrong "fixable" starts an unwinnable loop.

## Step 4 — Fix the worst, measure again

Take the top fixable item. Fix only that. Re-score the section. Repeat.

Stop when any of these is true:

- Every remaining gap is marked not fixable.
- The page score is above 95 and the owner is happy.
- Two consecutive passes move the score by less than 1 point. That is the
  ceiling for this approach, and grinding past it costs more than it returns.

Say which of the three stopped you. "Stopped at 91 because two passes moved it
0.4" is a real answer; "done" is not.

## Step 5 — Report

Give the owner this, in this shape:

```
PAGE:  /about        vs  https://original.example/about

SCORE: 91 / 100

  Layout      9.4     Type        9.8     Colour      10.0
  Imagery     9.1     Motion      6.5     Responsive  9.2

WHAT IS STILL DIFFERENT
  - Hero scroll animation is a fade, not a pinned scrub. GSAP ScrollTrigger
    can get closer; roughly two hours. Say the word.
  - The particle field behind the hero is WebGL. A still frame is in its place.
    This will not clone.

WHAT IS DELIBERATELY BETTER
  - Alt text written for all 14 images (original had none).
  - Body contrast raised 3.1:1 → 4.6:1, so it passes AA.
  - Images served as WebP: 4.1MB → 780KB.

STOPPED BECAUSE: two passes moved the score 0.4 points.
```

The "deliberately better" section is not padding. Without it, every improvement
reads as a failure to match.

## What the numbers actually mean

Be straight with the owner about the range, because "95%" means different things
on different pages:

- **95–99** — a static or lightly-animated page. Normal. Expect it.
- **88–95** — CSS transitions and reveal animations. Also normal.
- **80–90** — GSAP ScrollTrigger with pinning and scrubbing. Good work at this end.
- **60–80** — WebGL, canvas, or a page whose whole identity is a scroll timeline.
  The honest ceiling, and it should have been flagged back in
  `site-clone-capture` Part 1, not discovered here.

If a page is scoring below 80 and nobody warned the owner at the start, that is a
process failure as much as a build one. Say so.

## Step 6 — Then the real audit

A faithful copy is not automatically a good website. When the score is accepted,
hand to `pre-launch-audit`. The original may have shipped with no meta
description, no sitemap and a 4MB hero, and matching it at 99% would faithfully
reproduce every one of those.

## Routing examples

- fires: how close is the copy to the original
- fires: score the rebuilt page against the source
- fires: compare my version side by side and tell me what is off
- fires: what is still different from the original site
- skips: measure the target before we start
- skips: build the next section
- skips: install their colour palette

## Finish check

Copy this into your reply and tick each box as you do it.

- [ ] Both versions open side by side, at the same width — never scored from memory
- [ ] Every section scored, including the ones that look fine
- [ ] Gaps ranked, worst first
- [ ] Worst gap fixed, then measured again rather than assumed fixed
- [ ] Final score taken from a fresh look, not carried over from before the fix

If a fix does not move the score, go back to Step 2 and re-score that section —
the gap may not be where you thought. **After two attempts on the same section,
stop and report it** with what you tried, rather than cycling.

## Related skills

- `site-clone-capture` — supplies BEHAVIORS.md and TOPOLOGY.md, which this scores against.
- `site-clone-rebuild` — where each fixable gap goes back to.
- `pre-launch-audit` — the check that the copy is a good site, not just a faithful one.
- `motion-and-interaction` — judgement on motion gaps worth closing.
