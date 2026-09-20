# Funding

Keelhob is free, GPL, and stays that way. This page is the budget: what the
project spends money on, why each line exists, and what sponsorship does and
does not buy.

**[Support on Ko-fi](https://ko-fi.com/keelhob)**

---

## Why this project needs money at all

Keelhob hands an AI agent full control of a website — arbitrary PHP, the
filesystem, the database, the store. Software in that position needs three
things that a spare evening cannot produce: an outside security review by people
who do not already believe the code is fine, infrastructure that stays up
because sites check it for updates, and enough maintainer hours that a security
report gets an answer within a day rather than whenever. For a plugin people
trust with a business, none of that is optional, and all of it costs money.

---

## An independent security review — the priority

A professional third-party audit of `execute_php`, the filesystem tools and the
snippet engine: the three places where a mistake is not a bug but a compromised
site. The findings get published in full, including whatever they say.

A small-scope code audit of this kind — a few thousand lines of PHP, one
reviewer, a written report — runs roughly **$5,000 to $15,000** depending on the
firm and the depth of the scope. That is an estimate from published rates, not a
quote; the real figure and the firm will be named here before anything is
commissioned.

This is the single most valuable thing money can buy this project. Every other
line below is smaller.

---

## Infrastructure

Small, ongoing, and dull:

- The `keelhob.com` domain — about **$15 a year**.
- Hosting for the site and the docs.
- The endpoint that serves version checks and the public install count — a small
  VPS, about **$60 to $120 a year**.

Roughly **$100 to $200 a year** in total. The install count comes from the
update check, which sends four fields and no identifying information at all —
[PRIVACY.md](PRIVACY.md) lists them, and it is switchable off in Settings
without costing you updates. WordPress.org active installs are deliberately not
counted: Keelhob executes arbitrary PHP and SQL, so the directory may never
list it.

---

## Contributor bounties

Money attached to specific labelled issues, so that someone who fixes a real
problem is paid rather than thanked. Bounties go on well-defined work with an
obvious "done" — the code-health list in [ROADMAP.md](ROADMAP.md) is full of it
— and on security findings, which currently have no bounty at all and should.

---

## Maintainer time

Keelhob is built and maintained by one person alongside a full-time job.
Sponsorship turns that into a predictable release cadence rather than whatever
fits into a given month: triage answered on a schedule, releases that land when
they were said to, and the unglamorous work that never wins an argument against
a free weekend.

There is no target attached to this line and no milestone depends on it. The
floor described in [ROADMAP.md](ROADMAP.md) — security fixes, compatibility,
triage, data safety — continues at zero funding.

---

## What sponsors get

If your business runs on WordPress, this problem is yours too: users leaving for
AI builders take your hosting, your themes, your plugins and your agency work
with them.

- Logo and link in the README and on keelhob.com.
- Named in every release while sponsoring.
- Direct input on the roadmap. Not a vote — a conversation, early, before things
  are built.
- Priority on feature issues affecting your stack. Security and data-loss
  reports are triaged ahead of everything regardless of who filed them, and
  that ordering is not for sale.

## What sponsors never get

- A feature behind a paywall.
- A private fork, or a "sponsored edition".
- Anything removed from the free plugin, or held back from it.
- Purchased features, or control over what ships.
- Influence over the free-forever promise.

Every sponsored line of code ships GPL to everybody on the same day. That is not
a concession — it is the only reason anyone should trust this enough for
sponsoring it to be worth doing.

---

## Ways to support that cost nothing

- **Report a bug you actually hit** against a real site. Worth more than most
  donations.
- **Write a skill.** Markdown, no PHP, and the highest-leverage contribution
  there is.
- **Translate.** Every user-facing string goes through `__()`.
- **Star the repository.** It is how anyone finds it; there is no marketing
  budget.
- **Leave the update check on.** It is what produces the public install count,
  and it sends nothing identifying.

---

## Services (entirely optional, and not part of the plugin)

Live one-to-one sessions, taught rather than delivered. You share your screen,
the maintainer walks you through connecting and configuring Keelhob on your
own site while you do it, and you ask whatever you want along the way. A booking
link is emailed after purchase.

| | |
|---|---|
| **Setup & configure session** — $49.99 per site | Installing, connecting your agent, configuring providers, and the safety walkthrough — done live, with you driving, so you can do it again without help. |
| **Build-along session** — from $100 | The above, then building the site or store together in the same way. You bring your own API keys. |

These are teaching sessions, not done-for-you work: nothing is handed over
finished. Requested through keelhob.com. **The plugin behaves identically
whether you buy anything or not**, and it always will — there is no version of
this where paying makes the software better.
