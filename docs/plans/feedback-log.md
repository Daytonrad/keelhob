# Plan — the feedback log

**Status: BUILT, 2026-09-12. This document is now the record of why, not a
plan.** Two things were decided differently during the build and are recorded at
the bottom under "What changed when it was built" — the rate limit, and the
private inbox.

v2 supersedes v1 after a product-risk review found thirteen risks, one of which
made v1 unbuildable as written. Every decision below was made deliberately;
where a choice was close, the rejected option is named with its reason.

## The problem

Somebody asks the agent to build a hero section. It gets it wrong. They correct
it. It gets it wrong differently. Three rounds later it lands, and they are
tired.

That loop is happening on every install and **none of it reaches us.** One
person shrugs. Twenty people is the most valuable thing this project could know,
and today it evaporates when the window closes.

## What this is NOT

Stated first, because the difference is the whole feature:

- **Not analytics.** No page views, no session recording, no usage tracking.
- **Not error reporting.** Nothing is sent automatically, ever.
- **Not a transcript.** The conversation is never sent, and the user's own words
  are never stored verbatim — only a characterisation of what went wrong.

---

## The privacy problem, stated honestly

`PRIVACY.md:14` says, in writing:

> Keelhob has no analytics, no session recording, no error reporting service,
> no advertising, and no third-party scripts anywhere in the admin.

PostHog was rejected for the plugin on that exact sentence. **This sends more
sensitive data than PostHog would have.** So the bar is higher, not lower:

1. **`PRIVACY.md` changes in the same pull request**, or this does not ship.
2. The design must make the promise *checkable*, not merely stated.

Everything below follows from one idea: **the only version of "nothing sensitive
is sent" that survives somebody checking is "a human read it before it left."**

---

## Part 1 — The local friction log

### 1.1 Why local storage exists at all

v1 assumed one long session. Work does not happen that way: people open a new
chat for each job, so the agent in the fifth chat has no idea what happened in
the first four. Friction spread across a fortnight was invisible.

So friction accumulates **on the site**, and a report bundles what has built up.

### 1.2 Mostly automatic, because most of it needs no agent

`Keelhob_Tool_Registry::call()` is the choke point every tool call passes
through. It can see the important things by itself:

| Recorded automatically | Detected by |
|---|---|
| A tool returned an error | the tool's own `success: false` |
| A capability or policy blocked legitimate work | `Keelhob_Policy::check()` returning a reason |
| The same tool called with near-identical arguments 3+ times | hash of `name + args`, compared within a run |

**Zero tokens. Zero conversation content. Cannot be skipped by an agent that
does not feel like cooperating.** That last property matters more than it looks:
a feature that depends on an LLM choosing to be honest about its own failures is
a feature that reports what the LLM finds flattering.

The third row is the "wrong again" loop expressed as machine fact rather than as
an opinion.

**Rejected:** writing a brief every 2–5 messages. It costs the user's own API
credit on every session for data that is empty most of the time, and it puts a
rolling summary of their conversations in their database. Friction-only logging
stores almost nothing on a good day.

### 1.3 What the agent adds, rarely

The most valuable category produces no error at all: *it did what was asked and
the result looked wrong.* No machine sees that.

So the agent may append a line, and only when it notices something the automatic
path cannot: a design rejected three times, an instruction it misread twice, a
skill that did not say the thing that mattered.

Guidance for writing those lines is a skill (Part 4). The redaction and the
consent are PHP (Parts 1.5, 2), because guidance benefits from being editable
and guarantees must not be.

### 1.4 Shape, retention, and the owner's control

One row per friction event:

```
friction     enum, see below
tool         a real registered tool name, or none
skill        a shipped skill slug, or none
attempts     int
note         <= 200 chars, or empty for automatic entries
source       auto | agent
created_at
```

`friction` values, fixed:

```
repeated_correction    the agent got it wrong more than once in a row
tool_failed            a tool errored or returned something unusable
tool_missing           the job needed something the plugin does not have
wrong_visual_result    it did what was asked and the result looked wrong
docs_gap               the skill or docs did not say the thing that mattered
skill_gap              no skill covered this job
policy_blocked         a capability or policy stopped legitimate work
```

- **Retention: 30 days or 50 entries, whichever comes first.** Long enough to
  catch friction spread across weeks of separate chats, short enough that
  nothing lingers. Tighter than the 90 days on the server, deliberately,
  because this sits on somebody else's site.
- **The owner can read every row and wipe the lot**, from a panel on the
  Settings screen. A log you cannot inspect is a log you cannot trust.
- **Nothing is logged at all when the setting is off.** Not "logged but not
  sent" — not logged. It makes the promise one sentence instead of two.

### 1.5 What is never stored or sent, enforced in PHP

`Keelhob_Feedback::redact()`, applied on write to the local log AND again
before sending:

| Stripped | Why |
|---|---|
| URLs and bare domains | identifies the site |
| Email addresses | personal data |
| IPv4 / IPv6 | identifies the visitor or the server |
| Absolute file paths | leaks usernames and host layout |
| Hex / base64 runs of 20+ chars | that is the shape of an API key |
| The site's own name and `home_url()` host | the most likely accidental leak |
| Anything following `key`, `token`, `password`, `secret` | belt and braces |

**In PHP, not in a skill.** A skill can be switched off, edited, or replaced by
a well-meaning agent. If redaction removes anything the row records
`redacted: true`, so heavy redaction is visible rather than silent.

---

## Part 2 — Consent

### 2.1 The setting

**Keelhob → Settings → one checkbox, default ON:**

> ☑ **Help improve Keelhob**
> When something goes wrong, note it locally. About once a week the agent will
> offer to send a short, anonymous summary — you see exactly what it says and
> approve it first. No site address, no content, no keys. Switch this off and
> nothing is recorded at all.

Default ON is the owner's decision, recorded here as such. It is defensible
**only because of 2.2 and 2.3.** Default-ON *sending* would not be.

### 2.2 When the agent offers — the rule that decides whether this works

v1 said "at the end of the session". **MCP has no end-of-session event**, so v1
had no trigger at all. And a prompt on every session is a nag, which gets the
setting switched off, which costs more reports than it gains.

The offer happens when **both** are true:

1. The local log holds at least one friction event recorded since the last
   offer, **and**
2. It has been **at least 7 days** since the last offer on this site, whatever
   the answer was.

A quiet fortnight produces no prompt whatsoever. A bad week produces one.

Placement: after the agent has finished the work and is writing its completion
report — the natural pause, and the only one MCP actually exposes.

### 2.3 The approval loop

The checkbox means *"the agent may offer."* It never means *"the agent may
send."*

```
1. Agent shows the complete draft. Every field. Nothing folded away.
2. Agent asks: "Send it? Or is there anything you'd add or change?"
3. If the user adds anything: rewrite, show the new version in full, ask again.
4. Repeat until yes or no.
5. No is final for this session, and resets the 7-day clock.
```

Step 3 is the owner's addition to v1 and is better than the `yes / no / edit`
it replaces. Someone who is nearly comfortable will send a version they shaped.
Someone with no way to shape it just says no.

A worked example:

```
Before we finish — a few things went wrong over the last couple of weeks.
I can send this to the Keelhob project. This is all of it:

  1  wrong_visual_result  elementor_page  x3
     Hero rebuilt three times; the section IDs are not returned, so each
     edit had to re-read the whole page to find what it just made.

  2  tool_failed  upload_media  x2
     Large PNG uploads timed out; no size guidance anywhere in the skill.

  3  docs_gap  none  (skill: web-design-master)
     Nothing said which Elementor global font slot maps to body text.

No site address, no content, no keys. Send it, or anything to add?
```

### 2.4 A rule in PHP, not only in a skill

Operating-protocol **rule 15** covers when to offer, the approval loop, and that
a refusal is final. Same reasoning as rule 14: a skill can be switched off; the
consent rule must not be.

---

## Part 3 — Getting it here

### 3.1 The report

Up to **5 friction events**, each with its own line of **≤200 characters**.

A busy fortnight becomes five short sentences rather than one long paragraph.
Each line stays readable at approval time — and an approval nobody reads is not
consent — and the counting that makes this useful works per problem, not per
report. If more than five events are in the log, the five with the highest
`attempts` go, and the report says how many were left out.

**Rejected:** one 600-character blob (loses which problem was which); unlimited
lines (a 40-line approval prompt is not read, therefore not consent).

### 3.2 The endpoint

`POST /v1/feedback` on the existing `keelhob-api`. No new infrastructure, and
it inherits the throttling and hardening already there.

- **Unauthenticated**, like `/v1/version`. Shipping a key in a GPL plugin does
  not produce a key.
- **Rate limited hard.** 5/hour per IP. This is not a hot path.
- **4 KB body cap.**
- **Always 200**, even for garbage. A site's session must never break because
  this endpoint had an opinion.
- **Server-side redaction runs again**, because a modified client can skip the
  client-side pass.

### 3.3 Counting "twenty installs hit this"

Reuse the anonymous site UUID already sent with the update check.

It is the field that makes the feature work — *one person hit this* and
*twenty-three installs hit this* are different facts — and it is safe for the
same reason the `caps` field is: **that table has no address, domain, IP or
email anywhere in it.** The most anyone can learn, us included, is that some
anonymous UUID had a bad fortnight.

It also blunts poisoning: the public number counts **distinct site ids**, not
raw reports, so one noisy sender cannot manufacture a trend on its own.

### 3.4 Storage and the deletion route

```
FeedbackReport
  id, siteId, friction, tool, skill, attempts, note,
  redacted, pluginVersion, receivedAt
```

90-day retention, same as check-ins, same document.

**`DELETE /v1/feedback?site_id=…` — the gap v1 had.** Anonymous data cannot
normally honour "delete what you hold about me", because nothing can find it.
Here the site knows its own UUID, so it can ask. A button on the Settings screen
next to the log, and a sentence in PRIVACY.md saying it exists.

### 3.5 Reading it — private inbox, public counts

- **Private:** `GET /v1/feedback/summary`, owner-authenticated, grouped by
  `friction + tool + skill`, newest first. The roadmap writes itself:

  ```
   23  wrong_visual_result   elementor_page      elementor-page-building
   11  tool_missing          none                none
    9  docs_gap              seo_meta            seo-optimization
  ```

- **Public:** a page on keelhob.com showing **only the grouped counts** —
  never a `note`, never a report. Contributors can pick up the top item without
  waiting to be asked, and an outsider can see the project is genuinely used.
  That second effect matters for the reasons in
  `keelhob-vital/FUNDING-AND-SPONSORS.md`.

  **Rejected:** auto-opening a GitHub issue past a threshold. A bad or
  embarrassing report would publish itself before anyone saw it.

### 3.6 Closing the loop

A report that vanishes into a void stops being sent. So the changelog says so:

```
= 1.2.0 =
* Fixes 4 things reported from sites like yours.
```

**No new plumbing.** WordPress already shows `readme.txt`'s changelog on the
update screen. The maintainer writes the number when cutting the release. No
accounts, no tracking who reported what — just an honest count, in the one place
every user already looks.

---

## Part 4 — Teaching the agent

New skill `contribute-feedback` — `role: procedure`, `surface: any`, reached
from `build-standard`. It carries: one line per event, about the plugin and not
about the site, no names, no addresses, and a good and a bad `note` side by side:

> **Good:** `elementor_page should return the IDs of sections it creates, so the
> next edit does not have to re-read the page to find them.`
>
> **Bad:** `the client at acme-dental.com wanted the hero to say Book Now` —
> names a site, describes content, and is useless as a bug report anyway.

Linter-enforced like every other skill.

---

## Part 5 — Documents, in the same pull request

Not afterwards. `test-updater.php` already enforces the equivalent for the
check-in; this gets a sibling.

- **`PRIVACY.md`** — what is recorded locally and for how long, what a report
  contains field by field, that it is approved every time, that nothing is
  recorded when the box is unticked, how to delete what the server holds, and
  the file and line where redaction is enforced.
- **`readme.txt`** — the WordPress-facing disclosure.
- **The admin Guide page** — same wording, because two documents that drift is
  how a promise quietly stops being true.

---

## Part 6 — Tests

- **`test-feedback-redaction.php`** — planted failures. A URL, an email, an IP,
  an absolute path, a fake API key, the site's own domain: none may survive
  `redact()`. This file is what makes the privacy claim checkable.
- **`test-feedback-log.php`** — nothing is written when the setting is off;
  retention prunes at 30 days and at 50 entries; a repeated near-identical tool
  call is detected as one `repeated_correction`, not three.
- **`test-feedback-offer.php`** — no offer with an empty log; no offer within 7
  days of the last one; an offer when both conditions hold; a refusal resets the
  clock.
- **`test-feedback-payload.php`** — enums reject unknown values, notes truncate
  at 200, at most 5 events, the count of omitted events is reported.
- **Protocol** — rule 15 is present.
- **API** — 200 for garbage, stores nothing for garbage, applies its own
  redaction, honours the rate limit, and `DELETE` removes exactly one site's rows.

---

## Risks, and what was done about each

| # | Risk | Resolution |
|---|---|---|
| 1 | v1 had no trigger; MCP has no session end | 2.2 — friction present AND 7 days elapsed |
| 2 | Prompting too often gets the setting switched off | 2.2 — at most one offer a week |
| 3 | An LLM grading its own work will confabulate | 1.2 — the bulk is machine fact; agent notes are the minority and the user approves them |
| 4 | Only friction is ever reported, so what works looks unimportant | **Accepted, not fixed.** Read it as a bug queue, never as a satisfaction metric. |
| 5 | The user never learns their report mattered | 3.6 — a line in the changelog |
| 6 | Private reports fight the open-source model | 3.5 — public aggregate counts |
| 7 | 280 chars too tight for multi-session reports | 3.1 — 5 events × 200 chars |
| 8 | A user may type a client's name during the approval loop | **Accepted.** Redaction catches addresses and keys, not "Acme Dental". It is volunteered, in front of them, and the consent text says the note is sent as written. |
| 9 | Anonymous data cannot honour a deletion request | 3.4 — `DELETE /v1/feedback` using the site's own UUID |
| 10 | An open endpoint can be flooded to skew the roadmap | 3.3 — public counts are distinct site ids; 3.2 — rate limit |
| 11 | "This plugin phones home" does real reputational damage | Be louder about it than a critic would be: default-visible setting, approval every time, PRIVACY.md specific enough to check |
| 12 | The send fails and nobody notices | Drop it silently. No retry, no queue. A failed report is worth less than a session interrupted by an error about a report. |
| 13 | Who owns a suggestion that becomes a feature | One line in the consent text: sending permits use under the project's licence |

**Still true and worth repeating:** this converts "no analytics, full stop" into
"no analytics, and one thing you approve roughly monthly". That is a real change
to a promise people chose this plugin for, and some of them will dislike it.
If anyone ever proposes *"remember my answer and stop asking"*, that is the
moment this becomes telemetry, and the answer is no.

## Order of work

1. `Keelhob_Feedback::redact()` + planted-failure tests — the guarantee first
2. The local log: automatic capture in `Tool_Registry::call()`, retention, the
   owner's panel and Clear button
3. Setting, admin copy, protocol rule 15, the approval loop
4. Payload builder, validation, tests
5. `contribute-feedback` skill, linter green
6. API: migration, `POST`, `DELETE`, rate limit, tests
7. PRIVACY.md, readme.txt, Guide — same pull request
8. Private summary endpoint, then the public counts page

## Verification

```bash
php tests/run.php && composer analyse && php bin/lint-skills.php
```

Then on a real session: force three failing tool calls, confirm one
`repeated_correction` row appears and not three. Confirm no offer arrives on day
one. Wind the clock, trigger the offer, and check the draft shows every field.
Answer **no** — confirm nothing was sent and the clock reset. Then answer
**yes** with a note deliberately containing a URL, an email and a fake key, and
confirm what arrives has none of them. Finally untick the setting, cause more
friction, and confirm the local log stays empty.


---

## What changed when it was built

Two deviations, both deliberate, both recorded here rather than quietly absorbed.

### The rate limit is 30 a DAY, not 5 an hour

Section 3.2 said 5 per hour per IP, on the reasoning that a site sends at most
one report a week.

That reasoning assumed one site per address. It is false for exactly the callers
this project most wants to hear from: agencies and shared hosts put dozens of
WordPress installs behind one outbound IP. At five, the fourth agency site's
report is dropped — and dropped *silently*, because the plugin deliberately
never shows the user an error about a report.

It went to 30 an hour first, which fixed the burst — and then to **30 a day**,
because 30 an hour permits 720 a day from one address, which is a generous
budget for anyone who wants to steer the roadmap. 30 a day absorbs a whole
afternoon's burst from a large agency and caps abuse at roughly a twentieth of
the hourly version.

**A per-day limit is only safe because a throttle is now a delay rather than a
loss.** Section 3.2 said "always 200, drop on failure", and under that rule a
daily limit would quietly silence the biggest callers. So the plugin reads 429
specifically: the rows stay in the local log, the site's weekly offer is NOT
spent, a 24-hour hold is set, and the agent is told it is a queue and not a
fault on the owner's site. The person approves again tomorrow — deliberately, so
that no approval is ever stored to be replayed later.

Found by the HTTP test, not by review: the suite tripped the limit while posting
its own garbage cases.

### The private inbox is not built

Section 3.5 described two readers: a private, owner-authenticated summary with
notes, and a public page of grouped counts.

**The public half is built.** `GET /v1/feedback/summary` returns counts of
distinct sites per `friction + tool + skill`, and never a note.

**The private half is not**, because this service has no authentication at all
and that is on purpose — the two guards that once provided it read
`SUPABASE_SERVICE_KEY`, a credential that bypasses row-level security, and they
were deleted along with the paid tier. Adding authentication back to reach the
notes is a real decision about what secret lives in that environment, not a
detail to be slipped in at the end of a build.

Until it is made, notes are in the table and reachable directly from the
database. That is honest, and it is less useful than the plan implied.

### One thing the build confirmed rather than changed

`POST` answering 200 for every input is not automatic: Nest returns 201 for a
POST by default, and the route needed `@HttpCode(200)`. The HTTP test caught it.
A unit test calling the controller directly would not have — which is the same
lesson the Ko-fi urlencoded parser taught this project once already.
