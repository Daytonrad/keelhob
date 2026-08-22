# Design system brief — claudpress.com

Paste everything below the line into a fresh Claude conversation. It is written
to be handed over cold, so it repeats things you already know.

Before you paste it, check the three bracketed spots at the end — they are the
only decisions this brief cannot make for you.

---

I need a **design system** for the marketing website of a product called
**Claudpress**. Not page designs yet — the system the pages will be built from.

Please ask me anything genuinely ambiguous before you start, then deliver.

## What Claudpress is

A WordPress plugin that turns any WordPress site into an **MCP server**, so an AI
agent — Claude Code, or any MCP client — can run the whole site: write and
publish posts, build pages, create products, configure a WooCommerce store,
change settings, install plugins, write and activate live PHP, edit files, run
SQL, generate images, do SEO, and audit its own work.

The one-line positioning: **"the AI that runs your WordPress site."**

Not "an AI writing assistant". Not a chatbot bolted into wp-admin. The agent has
real administrative control, and that is the product.

## Who it is for

Three audiences, one product. The site needs a door for each.

| Audience | What they want | The line that lands |
|---|---|---|
| Store owners | A WooCommerce store without learning WooCommerce | "Stop building your store. Start describing it." |
| Site builders and bloggers | A real site from a description | "Describe your website. Watch it get built." |
| Freelancers and agencies | Many client sites, less clicking | "Run twenty client sites from one chat." |

They are not developers, mostly. They are competent people who find WordPress
tedious. They are sceptical of AI claims because they have been burned.

## How it makes money

Free core, paid Pro. No third tier, no donations, no lifetime licence.

- **Free:** content, pages, WooCommerce product building, SEO setup, Elementor,
  images, code snippets, PHP and SQL execution, file access, a self-audit tool,
  and 9 of the 15 skills. Unlimited installs. Genuinely capable — the whole
  "build a store in ten minutes" demo runs on the free tier.
- **Pro** ($8.99/mo or $69/yr, 5 sites; $129/yr for 20; $199/yr unlimited):
  new expert skills delivered monthly, a snippet manager UI, a private cloud
  snippet library synced across sites, scheduled autonomous work, order and
  revenue tools, staging-to-production deploy, notifications, ongoing SEO,
  monitoring.
- 14-day Pro trial, no card.
- The customer brings their own AI API key and pays the AI provider directly.

The honest framing we use everywhere: **free to build, pay to run and grow.**

## The competitor

Novamira (novamira.ai), €49/yr. Their free tier already includes PHP execution
and database access. Our free tier is deliberately more generous than theirs and
our Pro is much larger. The site should out-class theirs on craft, not out-shout
it.

## The three things the design has to carry

**1. Power, told calmly.** This plugin can delete a live store's products. The
design must feel like professional tooling that respects that — closer to a
developer platform or a serious SaaS than to a bright consumer AI landing page.
No purple-gradient AI clichés, no glowing orbs, no floating sparkles, no
"✨ AI-powered ✨". If it looks like every other AI launch page, we lose the exact
buyer who is most sceptical.

**2. Trust, structurally.** These people are handing administrative control of a
live site to software. Safety is not a footnote here, it is a feature: code is
saved switched off, PHP is syntax-checked before it can run, a snippet that
crashes is switched back off automatically, every action is logged, changes can
be undone. The system needs a visual language for reassurance that is neither
alarming nor buried.

**3. Proof over promises.** The strongest asset is a screen recording of a site
being built. The system must make video, terminal output, before/after states and
real admin screenshots feel native — not dropped into a generic card.

## What I want you to deliver

A design system document, with real rendered examples, not just descriptions:

1. **Foundations** — colour (light and dark, with the reasoning), a type scale
   with actual font choices and fallbacks, spacing scale, radii, elevation,
   borders, motion timings and easing. Say *why* for each decision.
2. **Tokens** — as CSS custom properties, named for meaning rather than value,
   and structured so a dark theme is a token swap and nothing else.
3. **Components** — buttons (all states), links, form fields, cards, pricing
   table, feature comparison table, code/terminal block, video frame, testimonial,
   badge/pill, callout (info / caution / danger), navigation, footer, FAQ
   accordion, tier indicators for free vs Pro.
4. **Patterns** — hero, feature section, social proof, pricing, three-door
   audience routing, docs page layout, blog post layout.
5. **The rules** — how the three audiences differ visually without becoming three
   different websites. How Pro is marked without looking like a hostage note.
6. **Anti-patterns** — a short, specific list of what this brand must never do,
   so a future contributor cannot drift into generic AI-startup design.
7. **Accessibility** — contrast ratios stated and met, focus states designed on
   purpose, motion honouring `prefers-reduced-motion`, and a real keyboard path.

Build it as a **single self-contained HTML page** that demonstrates every token
and component live, in both themes. I want to look at it, not read about it.

## Constraints

- The site is **WordPress**, running Claudpress itself — that is deliberate,
  and it is a marketing claim we make out loud. The system must be buildable in
  hand-written HTML/CSS inside WordPress templates and snippets. No React, no
  build step, no Tailwind. Plain CSS with custom properties.
- Self-hosted fonts or Google Fonts only.
- Fast. Target sub-2s on a mid-range phone. Design within that, do not decorate
  and then apologise.
- Mobile is designed, not shrunk.

## Tone of voice

Plain, direct, concrete, quietly confident. Short sentences. Never breathless.
We say what the product does and what it cannot do. When we mention risk, we
mention it plainly, because the people who notice we did are the people who buy.

## The bits only I can answer

- **Brand feeling in three words:** [ e.g. precise, calm, capable — change this ]
- **Existing brand assets:** [ logo? colours you already use? or start clean ]
- **A site whose craft you admire:** [ name one or two, and say what you like ]

Start by telling me your reading of the brand in a short paragraph and the
direction you propose. If you see a tension in what I have asked for, name it.
Then build the page.
