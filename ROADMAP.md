# Claudpress Roadmap

**Free forever. Funded by the people who use it. This document says exactly what
that funding buys.**

---

## The promise

WordPress made one promise that mattered: *anyone* could put a real website
online, own it, and change it themselves. For twenty years that promise was the
reason WordPress ran a third of the web.

AI site builders are taking that promise away — not by being better, but by
being *faster to start*. Ten minutes and a prompt versus an afternoon and a
theme. People are leaving, and the ones who leave do not come back, because what
they get instead is a site they rent rather than own.

Claudpress hands the promise back, with the part the AI builders cannot give
you: a real database, a real store, real code, on hosting you control, under a
licence nobody can revoke.

**Install one plugin. Connect your agent. Say what you want. Watch it get
built.**

And it is free. Not free-with-a-paid-tier, not free-for-now, not
free-until-we-raise. Every feature is in the repository, GPL, forever.

---

## Where this actually stands

**Version 2.0.0 — everything that was going to be sold is now free.**

A paid tier was designed and built: payments, orders and reports, shipping, tax,
user management, updates, email and notifications, deploy-to-production, the
Snippets Studio, and six of the fifteen expert skills. Two zips, a licence gate,
a build system that policed the boundary between them.

It was given away instead, because a plugin that hands people back a promise
should not immediately take part of it away again.

Working today: 68 tools across content, WooCommerce, SEO, files, PHP execution,
snippets, Elementor, memory and autonomy · 15 expert skills · a Snippets Studio
with AI compose and revision history · cross-session memory · a Telegram
front-end with voice, photos and approve-by-button · staging-to-production
bundles · signed skill updates · ~1,500 unit assertions and a live rig against
real WordPress and WooCommerce.

---

## How the funding works, stated plainly

This is built in evenings, around a full-time job. That is the constraint that
governs everything below, and pretending otherwise would make this document
worthless.

So the roadmap is **achievement-gated**: each milestone names what it unlocks,
and the milestone is real support from real people. Not a wishlist. A contract.

**[♥ Support on Ko-fi](https://ko-fi.com/claudpress)**

### How the numbers are measured, and why you can trust them

| Number | Where it comes from |
|---|---|
| **Sites running now** | Distinct anonymous IDs that checked for an update in the last 7 days. Not total installs — that only ever goes up and flatters. |
| **Downloads** | GitHub release asset download counts. |
| **Monthly support** | Recurring Ko-fi support, net of fees. |

The site count comes from the update check, which sends four fields and no
identifying information at all — [PRIVACY.md](PRIVACY.md) lists them and the
code is right there to check it against. It is switchable off in Settings, and
switching it off does not cost you updates.

Deliberately **not** counted: WordPress.org active installs. Claudpress executes
arbitrary PHP and SQL, so the directory may never list it. Building the roadmap
on a number that might not exist would be dishonest.

---

## The milestones

Each row is cumulative. Everything from earlier milestones keeps happening.

### 🌱 M1 · Proof — *100 sites running · $50/month*

**Someone other than me is using this.**

- Bug triage within 72 hours, publicly, every time.
- A patch release cadence you can rely on rather than hope for.
- Real installation docs, with screenshots, for people who have never touched
  MCP.
- A 3-minute video of a site being built from one prompt, start to finish, no
  cuts.

### 🚀 M2 · Momentum — *500 sites · $250/month*

**The ten-minute build stops being a claim and becomes a guarantee.**

- **The one-prompt store, polished end to end.** Say "build me a shop selling
  handmade candles in the UK" and answer a few questions. Pages, products,
  images, payment, shipping, tax, legal pages, SEO — done, and *verified* done
  by the readiness checker before it says so.
- Onboarding wizard: from activation to a connected agent without reading
  anything.
- A skill gallery — browse and install community skills from the admin.
- Proper i18n, and the first translations.

### 🛡️ M3 · Trust — *1,000 sites · $500/month*

**Making it safe to recommend to someone else.**

- **An independent security review** of `execute_php`, the filesystem tools and
  the snippet engine, published in full including whatever it finds.
- Signed releases, with the signing process documented and reproducible.
- A permission model finer than capability groups: per-tool approval, an
  audit-and-approve queue for destructive actions.
- Automatic rollback: a full site snapshot before any agent run, one click back.
- A hardening guide for people running this on production anyway. They exist.
  Better they do it well.

### 🏭 M4 · Depth — *2,500 sites · $1,000/month*

**Claudpress stops being a tool that builds sites and becomes one that builds
tools.**

- **The Plugin Factory skill.** Ask for a plugin and get one. Ask it to read a
  plugin you already have, and get your own version of it, or the one feature
  you actually wanted without the other forty. Same for snippets: describe the
  behaviour, get working code, installed and switched off until you have read
  it.
- Multi-agent builds: several agents on one site in parallel, each on a
  different section, without standing on each other.
- Background job queue, so a long build survives a closed laptop.
- Undo across a whole session, not one action.

### ⏳ M5 · Part-time — *5,000 sites · $2,500/month*

**I drop to part-time at my job.**

The first milestone that changes how much of my life this gets. Roughly: weekly
releases instead of monthly, and features stop waiting for a free weekend.

- Block themes and full site editing, not just Elementor.
- Migrate in: Shopify, Wix, Squarespace, static sites.
- Vertical store skills — dropshipping, digital goods, bookings, subscriptions,
  each one an expert rather than a generalist.
- Provider choice everywhere: OpenAI, Gemini, and local models through Ollama,
  for people who will not send their site to anyone's cloud.

### 🔥 M6 · Full-time — *10,000 sites · $5,000/month*

**I quit my job and work on Claudpress full-time.**

This is the one that matters. Everything above is what fits around employment.
This is what happens when it does not have to.

- Daily builds. Issues answered the same day.
- Public office hours — watch real sites get built, ask anything.
- Agency mode: one agent, many sites, shared skills and brand memory.
- A visual builder loop: the agent looks at what it made, in a browser, and
  fixes what looks wrong.
- Accessibility and Core Web Vitals enforced as part of the definition of done,
  not offered as an afterthought.

### 🏛️ M7 · Team — *25,000 sites · $10,000/month*

**It stops depending on one person, which is the only real measure of success.**

- Paid maintainers. A bus factor above one.
- Written governance: how decisions get made, how maintainers are chosen, how
  someone disagrees.
- A funded security bounty.
- A serious proposal to the WordPress project. Not a plugin that happens to
  exist near WordPress — a genuine answer to the question the whole ecosystem is
  currently losing.

---

## The always-on lane

Never gated behind anything. These happen at every milestone including none:

- **Security fixes**, prioritised above all other work, always.
- **Compatibility** with new WordPress, PHP and WooCommerce releases.
- **Bug triage.** Every report gets read.
- **Documentation** corrections.
- **Data safety.** Nothing ships that can lose somebody's work.

If support went to zero tomorrow, this lane continues. It is the floor.

---

## Code health

Unglamorous, and the reason the project is still moving. Contributors welcome
here especially — these are well-defined problems with obvious "done".

- **An autoloader**, replacing 40+ manual `require_once` lines in `claudpress.php`.
- **Split `class-admin.php`.** It is a 2,000-line god class rendering four
  screens.
- **Static analysis** in CI — PHPStan or Psalm, starting permissive, tightening
  each release.
- **Coverage targets** for the tool layer, which is thinner than the decision
  layer deserves.
- **PHP 8.4 and 8.5 readiness**, ahead of hosts moving.
- **Rig checks in CI**, so the live suite is not something only one machine runs.
- **`readme.txt` and `README.md` generated from one source**, so they cannot
  drift.
- **i18n audit** — every user-facing string through `__()`, no exceptions.

---

## Contributing — the door is genuinely open

The single most valuable contribution to this project needs no PHP at all.

**A skill is a markdown file.** It teaches the agent how to do something
properly: the order things must happen in, the setting everyone forgets, the
thing that silently breaks checkout. If you know how to do a job well in
WordPress, that knowledge is a pull request.

Also wanted, and all credited by name in the release notes:

- Bug reports against real sites. Worth more than most donations.
- Translations.
- Documentation.
- Code — `good first issue` is used honestly here.
- Design. The admin works, and a designer would improve it in an afternoon.

[CONTRIBUTING.md](CONTRIBUTING.md) has the setup, which is: clone it and run
`php tests/run.php`. No Docker, no database, no build step.

**Maintainers are recruited from contributors.** If you send good work more than
once, you will be asked.

---

## For companies

If your business runs on WordPress, the thing described at the top of this
document is your problem too. Users leaving for AI builders are leaving your
hosting, your themes, your plugins and your agency work with them.

Sponsors get:

- Logo and link in this README and on claudpress.com.
- Named in every release while sponsoring.
- Direct input on the roadmap. Not a vote — a conversation, early, before things
  are built.
- Priority on issues affecting your stack.

What sponsors do **not** get, ever: a feature behind a paywall, a private fork, a
"sponsored edition", or influence over the free-forever promise. Every sponsored
line of code ships GPL to everybody on the same day. That is not a concession —
it is the only reason anyone will trust this enough for it to be worth
sponsoring.

**[Sponsor via Ko-fi](https://ko-fi.com/claudpress)** · or open an issue and say
hello.

---

## Services (entirely optional, and not part of the plugin)

The plugin is free and always will be. If you would rather not do the setup
yourself:

| | |
|---|---|
| **Setup & Configure** — $49.99 per site | Install, connect your agent, configure providers, safety walkthrough, and a handover so you can drive it. |
| **Done-for-you Build** — from $100 | The above, plus we build the site or store. You bring your own API keys. |

Requested through claudpress.com. **The plugin behaves identically whether you
buy anything or not**, and it always will — there is no version of this where
paying makes the software better.

---

## Things deliberately not on this roadmap

Saying no in public is worth more than a longer list.

- **A paid tier, ever.** Not a "pro" version, not an "enterprise" edition, not a
  hosted version with the good features. The whole point was giving that up.
- **Telemetry beyond the update check.** No analytics, no session recording, no
  error reporting service, no third-party scripts in the admin.
- **Selling or sharing user data.** There is none to sell — the check-in carries
  no identifying information at all.
- **Ads or upsells in the admin.** One donate button, which never blocks
  anything.
- **Removing the safety switch**, the capability groups, or the warnings — no
  matter how much friction they add to a demo.

---

*This document is the plan of record. If something is being built and is not
here, that is a bug in this file — open an issue.*
