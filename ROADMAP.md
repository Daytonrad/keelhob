# Roadmap

What is being built, in what order, keyed to versions. Everything here ships
GPL in the one free plugin.

---

## What Keelhob is for

WordPress made one promise that mattered: anyone could put a real website
online, own it, and change it themselves. AI site builders are taking that
promise back — not by being better, but by being faster to start, and what they
hand you instead is a site you rent rather than own. Keelhob exists to make
the fast path and the ownership path the same path: install one plugin, connect
your agent, say what you want, and get a real database, a real store and real
code on hosting you control.

Working today: 85 tools across content, WooCommerce, SEO, files, PHP execution,
snippets, Elementor, site care, memory and autonomy · 27 expert skills · a
Snippets Studio with a real code editor, conditional logic and revision history ·
cross-session memory · a Telegram front-end with voice, photos and
approve-by-button · staging-to-production bundles · signed skill updates ·
~2,600 unit assertions, 382 browser checks, 30 end-to-end checks through the
live MCP endpoint, and a rig running real WordPress.

---

## Shipped

<!-- keelhob:latest-release 2.1.2 -->
**2.1.2 is the latest published release.** That line is not written by hand: a
test reads the marker above it and fails the build if it stops matching the
version in `keelhob.php`, which is how this file went eight months out of date
once and will not again.

Everything in this section is downloadable today. The full detail is in
[CHANGELOG.md](CHANGELOG.md); this is the shape of it.

- **The one-prompt store, end to end.** Say "build me a shop selling handmade
  candles in the UK", answer a few questions, and get pages, products, images,
  payment, shipping, tax, legal pages and SEO — *verified* done by the readiness
  checker before it says so.
- **The manual**, written for someone who has never touched MCP, at
  [keelhob.com/docs](https://keelhob.com/docs) and in the zip as
  [DOCUMENTATION.md](DOCUMENTATION.md).
- **The site-clone skill family** — copy a site built on Wix, Shopify or React
  into WordPress and Elementor, with a permission gate that stops the run if the
  answer is no.
- **A Snippets Studio that reads as an editor**: two columns, syntax colouring,
  conditional logic with ALL/ANY rules, and revisions with one-click restore.
- **Feedback you can send from inside the plugin**, and a private summary the
  maintainer can read.
- **Agent-written abilities, site care, ratings and a run log** (2.0.0), plus a
  rebuilt admin on its own design system.
- **OAuth 2.1 with dynamic client registration** (2.1.0), off by default.
- **Provider choice.** Anthropic, OpenAI and Google, for text, images and
  speech-to-text alike — `class-provider-*.php`, `class-image-provider-*.php`
  and `class-transcribe-provider-*.php`. Bring your own key. Local models
  through Ollama are the one piece still outstanding, and they are in 2.4.
- **Signed releases.** The zip is built from a clean checkout of the tagged
  commit, and the two skill-feed assets carry detached Ed25519 signatures that
  every installed site verifies before it trusts a skill update.
- **The demo.** One sitting, 69 minutes, cut to three and a half, on
  [keelhob.com](https://keelhob.com) and at the top of the README. This used to
  be the one thing blocking a release. It is recorded.

## Now (2.2) — the holes in the product

Being built. These are not new ideas; each one is a gap somebody can hit today.

- **Onboarding wizard.** Activation to a connected agent without reading
  anything first.
- **Give the owner eyes on Telegram.** There is no screenshot ability anywhere
  in the plugin today, and `class-telegram-api.php` has no `sendPhoto` —
  pictures go in and never come out. So an agent building a site from a Telegram
  chat sends words about a page nobody can see, and the result is structurally
  correct and visually unproven. The standing rule in this project is *never
  claim a page works from reading its HTML*, and over Telegram reading the HTML
  is all the agent has. Fix: `sendPhoto`, plus a hosted screenshot service —
  hosted rather than headless Chrome, because the people who most need to build
  from a phone are on shared hosting that will not run a browser.
- **`elementor_page` has no partial update.** Changing one section means
  sending the whole page back, so a long page is rewritten to edit a heading.
- **The Snippets admin form fails silently if the row has gone.** The window is
  small — the row has to be deleted between the page rendering and the save —
  but a save that reports nothing is the wrong failure.
- **An unknown `source` is silently relabelled "Hand-written".** A snippet whose
  origin the plugin does not recognise should say so, not claim a human wrote
  it.

## Next (2.3, 2.4, 2.5)

Planned and ordered.

### 2.3 — trust

- **An independent security review** of `execute_php`, the filesystem tools and
  the snippet engine, published in full including whatever it finds.
- **The tool-level build lock.** Today "agree what you are building before you
  build it" is an *instruction*, written in the strongest place available — and
  a model that ignores its instructions can still write. The hard version gives
  the plugin a session, a recorded agreement, and a `create_post` and
  `snippet_save` that refuse the first creation of a session until that
  agreement exists. It cannot be talked past. It is days of work and it changes
  how the plugin feels for everyone, which is why it waits for a version of its
  own rather than being bolted on.
- **A permission model finer than capability groups**: per-tool approval, and an
  audit-and-approve queue for destructive actions.
- **Automatic rollback.** A full site snapshot before any agent run, one click
  back.
- **A hardening guide** for people running this on production anyway. They
  exist. Better they do it well.

### 2.4 — reach

- **Local models through Ollama.** The rest of provider choice shipped; this is
  the part that matters to people who will not send their site to anyone's
  cloud, and it is the only piece left.
- **Real translations.** `load_plugin_textdomain()` has been called since 1.0
  and `languages/` is empty, so nothing is actually translatable. A `.pot` file
  first, then the first language.
- **A skill gallery** — browse and install community skills from the admin.
- **Two-way Slack chat, if people ask for it.** The design is finished and the
  decisions are made: shipped to everyone but **off by default**, beside
  Telegram rather than instead of it, Block Kit buttons for approve and cancel,
  text only in v1. It is not waiting on a plan. It is waiting on evidence that
  anyone wants it, because a second chat surface is a second public door to
  maintain forever. **If you want this, say so in an issue** — that is the
  whole gate, and one real request moves it.

### 2.5 — depth

Keelhob stops being a tool that builds sites and becomes one that builds
tools.

- **The Plugin Factory skill.** Ask for a plugin and get one. Ask it to read a
  plugin you already have and get your own version of it, or the one feature you
  actually wanted without the other forty. Same for snippets: describe the
  behaviour, get working code, installed and switched off until you have read
  it.
- **Multi-agent builds.** Several agents on one site in parallel, each on a
  different section, without standing on each other.
- **A background job queue**, so a long build survives a closed laptop.
- **Undo across a whole session**, not one action.

## Later

Direction, not commitments. These are the things that come after the list
above, in no fixed order and with no date attached. Some will change shape and
some will be dropped.

- **Block themes and full site editing**, not just Elementor.
- **Migrate in**: Shopify, Wix, Squarespace, static sites.
- **Vertical store skills** — dropshipping, digital goods, bookings,
  subscriptions — each one an expert rather than a generalist.
- **Agency mode**: one agent, many sites, shared skills and brand memory.
- **A visual builder loop**: the agent looks at what it made, in a browser, and
  fixes what looks wrong.
- **Accessibility and Core Web Vitals** enforced as part of the definition of
  done, not offered as an afterthought.
- **Public build sessions** — watch real sites get built, ask anything.
- **Written governance**: how decisions get made, how maintainers are chosen,
  how someone disagrees — and more than one maintainer, which is the only real
  measure of a project surviving its author.
- **A serious proposal to the WordPress project.** Not a plugin that happens to
  exist near WordPress, but an answer to the question the ecosystem is currently
  losing.

---

## Always

Not tied to a version. These happen in every release, including the ones with
nothing else in them.

- **Security fixes**, prioritised above all other work.
- **Compatibility** with new WordPress, PHP and WooCommerce releases.
- **Bug triage within 72 hours**, publicly, every time. Every report gets read.
- **A patch cadence you can rely on** rather than hope for.
- **Documentation corrections.**
- **Data safety.** Nothing ships that can lose somebody's work.

This is the floor. It does not move.

### Code health

Unglamorous, and the reason the project is still moving. Contributors are
welcome here especially — these are well-defined problems with an obvious
"done".

- ~~**An autoloader**, replacing the manual `require_once` lines in
  `keelhob.php`.~~ Done in 1.0.1.
- ~~**Split `class-admin.php`.**~~ Done in 1.0.1, along with
  `class-providers.php` and `class-telegram.php`. The largest file in
  `includes/` is now 818 lines, down from 2,440.
- ~~**Static analysis in CI**~~ Done in 1.0.1 — PHPStan at level 1 with a
  baseline. **Raising the level** is the open work, and it is a genuinely good
  contribution: pick one rule class, fix what it finds, remove those baseline
  entries.
- **Coverage targets** for the tool layer, which is thinner than the decision
  layer deserves.
- **PHP 8.4 and 8.5 readiness**, ahead of hosts moving.
- **Rig checks in CI**, so the live suite is not something only one machine
  runs.
- **`readme.txt` and `README.md` generated from one source**, so they cannot
  drift.
- **An i18n audit** — every user-facing string through `__()`, no exceptions.

---

## Not planned

Saying no in public is worth more than a longer list.

- **A paid tier, ever.** Not a "pro" version, not an "enterprise" edition, not a
  hosted version with the good features. Everything that was once going to be
  sold — payments, orders and reports, shipping, tax, user management, updates,
  email, deploy-to-production, the Snippets Studio and six of the fifteen
  skills — is in the repository, GPL, and stays there.
- **Telemetry beyond the update check.** No analytics, no session recording, no
  error reporting service, no third-party scripts in the admin.
- **Selling or sharing user data.** There is none to sell — the update check
  carries no identifying information at all. [PRIVACY.md](PRIVACY.md) lists
  every field.
- **Ads or upsells in the admin.** One donate button, which never blocks
  anything.
- **Removing the safety switch**, the capability groups, or the warnings — no
  matter how much friction they add to a demo.

---

## How to influence this

Open an issue. That is the whole mechanism, and it works: contributors decide a
large share of what actually gets built, because what gets built is what someone
turns up and builds.

The single most valuable contribution needs no PHP at all. **A skill is a
markdown file.** It teaches the agent how to do something properly: the order
things must happen in, the setting everyone forgets, the thing that silently
breaks checkout. If you know how to do a job well in WordPress, that knowledge
is a pull request.

Also wanted, and all credited by name in the release notes:

- Bug reports against real sites.
- Translations.
- Documentation.
- Code — `good first issue` is used honestly here.
- Design. The admin works, and a designer would improve it in an afternoon.

[CONTRIBUTING.md](CONTRIBUTING.md) has the setup, which is: clone it and run
`php tests/run.php`. No Docker, no database, no build step.

**Maintainers are recruited from contributors.** If you send good work more than
once, you will be asked.

What this roadmap costs to deliver, and what sponsorship pays for, is in
[FUNDING.md](FUNDING.md).

---

*This document is the plan of record. If something is being built and is not
here, that is a bug in this file — open an issue.*
