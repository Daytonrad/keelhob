---
name: site-clone
title: Copy a site into WordPress
description: The way in when somebody hands over a URL and asks to clone, copy, replicate or migrate an existing website into this WordPress site — off Wix, Shopify, Squarespace, Webflow, Framer or a React build. Asks the four questions that decide the whole job: permission, scope, how many pages, which builder. Then routes. Does no copying itself.
enable_agentic: true
enable_prompt: true
role: gate
surface: workstation
next_skills: site-clone-capture, site-clone-design-system, site-clone-rebuild, site-clone-verify
verified_by: site-clone-verify
---

# Copy a site into WordPress

<!-- CONTENTS:START -->
## Contents

- [When to use](#when-to-use)
- [When NOT to use](#when-not-to-use)
- [Step 1 — Permission. Ask, and stop if the answer is no.](#step-1-permission-ask-and-stop-if-the-answer-is-no)
- [Step 2 — What are you copying? Design system, or the site?](#step-2-what-are-you-copying-design-system-or-the-site)
- [Step 3 — How much of it? One page, or all of them?](#step-3-how-much-of-it-one-page-or-all-of-them)
- [Step 4 — Which builder?](#step-4-which-builder)
- [Step 5 — Check the tools exist, before promising anything](#step-5-check-the-tools-exist-before-promising-anything)
- [Step 6 — Route](#step-6-route)
- [What this skill will not pretend](#what-this-skill-will-not-pretend)
- [Routing examples](#routing-examples)
- [Finish check](#finish-check)
- [Related skills](#related-skills)
<!-- CONTENTS:END -->

Somebody has handed you a URL and asked for it. This skill decides **what job you
are actually doing** before a single byte is fetched, because the four possible
jobs behind that one sentence differ by a factor of about fifty in cost.

You do no copying here. You ask, you check the tools exist, you route.

## When to use

- Someone gives you a website address and asks to copy, clone, replicate or
  rebuild it.
- Someone is moving off Wix, Shopify, Squarespace, Webflow, Framer or a hand-built
  React site and wants the same site on WordPress.
- Someone says "make my site look like this one" and pastes a link.
- Someone asks for "just the colours and fonts" from another site.

## When NOT to use

- The design brief is words, not a URL — that is `site-build-kickoff`.
- The site being copied is **this** site (a page duplicate is `create_post` with
  the source content, not a clone pipeline).
- Only one specific component is wanted — a header, a pricing table — and the
  owner can describe it. Describing it is cheaper than measuring it.

## Step 1 — Permission. Ask, and stop if the answer is no.

Ask exactly this, before anything else:

> **Do you own this website, or do you have permission from whoever does?**

Wait for an answer. Do not fetch the URL first.

**If the answer is no, or evasive, stop.** Say plainly: this skill exists to move
a site somebody owns onto WordPress, not to take somebody else's. Then offer the
thing that is always legitimate and usually what they actually wanted:

> "I can't copy that site. What I *can* do is read its design system — the
>  colours, type scale, spacing rhythm and motion feel — and build you an
>  original site with the same quality of craft. Want that instead?"

That offer is not a consolation prize. Layout conventions and design taste are
not protectable the way copy, photography and brand marks are, and "a site that
feels as good as that one" is what most people mean anyway. Route to
`site-clone-design-system`.

**If the answer is yes**, record it and carry on:

```
memory_save  key: site-clone-permission
             value: <url> — owner confirmed permission on <date>
```

That record exists so the next agent in the next session does not have to ask
again, and so there is an answer if anyone ever wonders why this site looks like
that one.

## Step 2 — What are you copying? Design system, or the site?

Ask, and give them these two in these words:

> **A. The design system only.** Colours, fonts, type scale, spacing rhythm,
>    corner radii, shadows, button styles, motion feel. You get their *taste*,
>    applied to your own words and pictures. Fast and cheap — usually one pass.
>
> **B. The site itself.** Every section rebuilt: layout, content, images,
>    animations, the lot. Slow and expensive — a real page is hours, not minutes.

Most people who say "clone it" want **A** and do not know that is a thing they
can ask for. Offer it properly rather than assuming B.

- **A** → `site-clone-design-system`. That skill needs one capture pass, not a
  full one; it says so itself.
- **B** → carry on to Step 3.

## Step 3 — How much of it? One page, or all of them?

This is the money question, and it must be asked out loud rather than decided
for them.

Capturing a page properly means screenshots at three widths, a scroll sweep, a
hover sweep, a click sweep, and computed styles for every element that matters.
That is real time and, if Firecrawl is doing the fetching, real credits.

> **One page at a time (recommended).** Usually the home page first. You see the
> result, correct it, and only then decide whether to spend the same again on
> the next page. Corrections are cheap when there is one page to correct.
>
> **The whole site.** Every page in one run. Only sensible once one page has come
> out right, because a mistake in the approach gets made on every page at once.

Push gently toward one page first. Someone who has seen one page land well makes
a much better decision about the other fourteen.

If they choose the whole site, map it first and **show them the list before
starting**:

```
firecrawl_map  url: <site>          →  every URL the site advertises
```

A site that turns out to have 240 URLs is a conversation, not a task.

## Step 4 — Which builder?

Check what this site actually has before offering choices:

```
get_site_info                       →  active_plugins, active_theme
```

Then pick with them:

| If | Build with | Why |
|---|---|---|
| Elementor is active | **Elementor** | The owner can edit it afterwards without you. Almost always right. |
| Nothing is active, and the target is heavily custom | **A page template snippet** | `snippet_save` with `location: page_template` serves raw HTML/CSS for one page. Pixel-perfect, but only you can edit it. |
| The owner says they want to keep editing it themselves | **Elementor**, always | An unmaintainable perfect copy is worse than an editable good one. |

Say the trade out loud. "Pixel-perfect but you can't edit it" is a real cost and
the owner is the one who pays it.

Record the answer so every later skill builds the same way:

```
memory_save  key: build-mode
             value: elementor | page-template
```

## Step 5 — Check the tools exist, before promising anything

This skill is `surface: workstation` for a reason. It needs two things that do
not live on this WordPress site, and if they are missing you must say so now
rather than four steps in.

**Firecrawl** — fetches pages, discovers URLs, returns HTML and screenshots.

Look for `firecrawl_scrape` in your own tool list.

- **Present** → good, carry on.
- **Missing** → walk the owner through it, in this order:
  1. **The connector** (free, no card): in Claude, open **Settings → Connectors**,
     find **Firecrawl**, click **Connect**, sign in with Google. Come back and it
     will be in your tools.
  2. **An API key** (paid, needed for anything beyond a few pages): sign up at
     `firecrawl.dev`, copy the key, and add it to the MCP server config.

  Say which one they need. The free connector is fine for one page and a design
  system. A whole site will exhaust it, and `firecrawl_map` — the URL discovery
  this skill uses in Step 3 — is not in the free tier at all.

**A browser you can drive** — `chrome-devtools`, Playwright or Puppeteer MCP.

Look for a tool that can navigate, scroll, hover and read computed styles.

- **Present** → good.
- **Missing** → say plainly what is lost: without a browser you can read the
  page's markup but you cannot *watch* it. Every scroll animation, every hover
  state, every sticky-header transition becomes a guess. Offer to continue at
  reduced fidelity, and say the number out loud: a static-only copy of an
  animated site lands around 60%, not 95%.

**If you are not on Claude Code or an IDE at all** — Telegram, Slack, a scheduled
task — stop here. Say which skill it is, say this connection cannot finish it,
and tell the owner to run it from Claude Code connected to this site. Do not
start and improvise around the missing browser.

## Step 6 — Route

With all four answers, hand off:

| Answer | Go to |
|---|---|
| Design system only | `site-clone-capture` (tokens pass), then `site-clone-design-system` |
| One page, whole thing | `site-clone-capture`, then `site-clone-rebuild`, then `site-clone-verify` |
| Whole site | The same three, **once per page**, home page first |

Do not skip `site-clone-verify`. It is this skill's declared verifier, and
operating-protocol rule 13 means you have to report what it said. "It looks
about right" is not a result; that skill produces a number.

## What this skill will not pretend

Say these up front, once, when the job is agreed. An owner who hears them at the
start is fine; one who discovers them at the end feels lied to.

- **WebGL and canvas do not clone.** A three.js hero, a shader background, a
  particle field — the content transfers, the runtime drawing does not.
- **Scroll-choreographed pages are the hard ceiling.** A page where a pinned
  section scrubs a timeline as you scroll can be approximated, not reproduced.
  Expect the 80% end of the range, not the 99% end.
- **Real backends do not come along.** A booking widget, a live search, a logged-in
  area: you copy the shell, not the machinery behind it.
- **Fonts may be licensed.** A commercial font served on their old host does not
  automatically become theirs to serve here. Flag it; do not silently substitute
  and hope nobody notices.

## Routing examples

- fires: clone this website into wordpress
- fires: copy this site for me
- fires: move my wix site to wordpress
- fires: migrate from squarespace to wordpress
- fires: rebuild this page on my site
- fires: make my site look like this link
- skips: write me a blog post about coffee
- skips: my checkout is throwing an error
- skips: add a contact form to the about page

## Finish check

Copy this into your reply and tick each box as you do it.

- [ ] Permission asked and granted. **If the answer was no, stop — there is no step 2**
- [ ] Agreed what is being copied: a design system, or the site
- [ ] Agreed how much: one page, or all of them
- [ ] Each stage handed to its own skill rather than improvised here
- [ ] `site-clone-verify` run, and its result reported

If any stage comes back incomplete, go back to that stage's skill and finish it
before moving on. **After two attempts, stop and report** which stage is stuck.

## Related skills

- `site-clone-capture` — measures the target. Always next, whichever job was chosen.
- `site-clone-design-system` — the cheap path: their taste, your content.
- `site-clone-rebuild` — turns a captured spec into real WordPress pages.
- `site-clone-verify` — scores the copy against the original. This skill's verifier.
- `site-build-kickoff` — the alternative when there is no URL, only a brief.
- `web-design-master` — what "good" means when the target site is not actually good.
