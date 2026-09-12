# Plan — the feedback log

**Status: PLAN ONLY. Nothing here is built. Do not start until the owner says so.**

## The problem

Somebody asks the agent to build a hero section. It gets it wrong. They correct
it. It gets it wrong differently. Three rounds later it lands, and the user is
tired.

That exact loop is happening on every install, and **none of it reaches us.**
If one person hits it, they shrug. If twenty people hit it, that is the single
most valuable piece of information this project could have — and today it
evaporates the moment the session closes.

The fix is not analytics. It is letting the agent, which just watched the whole
thing, write down what went wrong and offer to send it.

## What this is NOT

Stated first, because the difference is the entire feature:

- **Not analytics.** No page views, no session recording, no usage tracking, no
  "time spent", no funnels.
- **Not error reporting.** No stack traces, no automatic anything.
- **Not a transcript.** The conversation is never sent. A *conclusion about* the
  conversation is, and only after a human has read it.

---

## The privacy problem, stated honestly

`PRIVACY.md:14` says, in writing:

> Claudpress has no analytics, no session recording, no error reporting service,
> no advertising, and no third-party scripts anywhere in the admin.

Three days before this plan was written, PostHog was rejected for the plugin on
exactly that sentence. **This feature sends more sensitive data than PostHog
would have** — PostHog sends page views; this sends text derived from a private
conversation about somebody's business.

So the bar is higher, not lower. Two consequences:

1. **`PRIVACY.md` changes in the same pull request**, or this does not ship.
2. The design has to make the promise *checkable*, not merely stated.

The design below is built around one idea: **the only version of "nothing
sensitive is sent" that survives somebody checking is "a human read it before it
left."**

---

## Part 1 — The report

### 1.1 Structured, not prose

The single biggest safety lever. A dropdown cannot leak a password.

| Field | Type | Example |
|---|---|---|
| `friction` | enum | `repeated_correction` |
| `tool` | enum — a real registered tool name, or `none` | `elementor_page` |
| `skill` | enum — a shipped skill slug, or `none` | `web-design-master` |
| `attempts` | int, 1–20 | `3` |
| `resolved` | bool | `true` |
| `phase` | enum | `build` |
| `suggestion` | **free text, 280 chars** | see below |

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

Everything except `suggestion` is an enum or a number. **Six of the seven fields
are structurally incapable of leaking anything.**

### 1.2 The one free-text field

`suggestion` is where the value is, so it cannot be removed — but it is fenced:

- **280 characters.** Hard cap, enforced server-side too.
- **One sentence, about the plugin, not about the site.** Rule, enforced by the
  skill that writes it and by review of what arrives.
- Good: *"elementor_page should return the IDs of sections it created, so the
  next edit does not have to re-read the whole page."*
- Bad: *"the client at acme-dental.com wanted the hero to say Book Now"* —
  names a site, describes content, useless as a bug report anyway.

### 1.3 What is never sent, and how that is enforced

Not a promise in a document. A function, in PHP, that the agent cannot switch off.

`Claudpress_Feedback::redact()` strips, before anything leaves:

| Stripped | Why |
|---|---|
| URLs and bare domains | identifies the site |
| Email addresses | personal data |
| IPv4 / IPv6 | identifies the visitor or server |
| Absolute file paths | leaks usernames and host layout |
| Long hex / base64 runs (20+ chars) | that is what an API key looks like |
| The site's own name and `home_url()` host | the most likely accidental leak |
| Anything after `key`, `token`, `password`, `secret` | belt and braces |

**In PHP, not in the skill.** A skill can be switched off, edited, or replaced
by a well-meaning agent. Rules 9–14 of the operating protocol live in PHP for
this reason and so does this.

If redaction removes anything, the report says so (`redacted: true`) — so a
pattern of heavy redaction is visible rather than silent.

---

## Part 2 — Consent

### 2.1 The setting

**Claudpress → Settings → one checkbox, default ON:**

> ☑ **Help improve Claudpress**
> At the end of a session, let the agent offer to send one short, anonymous note
> about anything that went wrong. You see exactly what it says before it goes.
> No site address, no content, no keys — ever.

Default ON is the owner's decision and is recorded here as such. It is
defensible **only because of 2.2.** Default-ON *sending* would not be.

### 2.2 The send is always confirmed — non-negotiable

The checkbox means *"the agent may offer."* It never means *"the agent may
send."*

The agent must print the exact payload, then ask. Something like:

```
Before we finish — I got the hero section wrong twice before it landed, because
elementor_page does not return the IDs of what it created.

I can send this to the Claudpress project. This is all of it:

  friction    repeated_correction
  tool        elementor_page
  skill       elementor-page-building
  attempts    3
  resolved    yes
  phase       build
  suggestion  elementor_page should return the section IDs it creates, so the
              next edit does not have to re-read the page to find them.

No site address, no content, no keys. Send it? (yes / no / edit)
```

**`edit` matters.** Someone who is nearly comfortable will send a version they
changed. Someone with no edit option just says no.

### 2.3 A rule in PHP, not only in a skill

The operating protocol gains a rule (15) covering when to offer, what may go in,
and that a refusal is final for the session. Same reasoning as rule 14: a skill
can be switched off; the consent rule must not be.

---

## Part 3 — Getting it here

### 3.1 The endpoint

`POST /v1/feedback` on the existing `claudpress-api`. No new infrastructure, and
it reuses the throttling and the hardening already there.

- **Unauthenticated**, like `/v1/version`. Requiring a key would mean shipping a
  key in a GPL plugin, which is not a key.
- **Rate limited** hard — this is not a hot path. 5/hour per IP.
- **2 KB body cap.** A real report is ~300 bytes.
- **Always 200**, even on garbage. A site's session must never break because
  our endpoint had an opinion.
- **Server-side redaction runs again.** The client's redaction can be bypassed by
  a modified client; ours cannot.

### 3.2 Counting "twenty people hit this"

Reuse the anonymous site UUID already sent with the update check.

This is the field that makes the whole feature work — *one person hit this* and
*twenty-three installs hit this* are completely different facts — and it is safe
for the same reason the `caps` field is: **that table has no address, no domain,
no IP and no email anywhere in it.** The most anyone can learn, including us, is
that some anonymous UUID had a bad time.

### 3.3 Storage

New Prisma model, next to `SiteCheckin` and `Donation`:

```
FeedbackReport
  id, siteId, friction, tool, skill, attempts, resolved, phase,
  suggestion, redacted, pluginVersion, receivedAt
```

**Same 90-day retention as check-ins**, same reason, same document. One
migration, additive.

### 3.4 Reading it

`GET /v1/feedback/summary`, owner-authenticated, grouping by
`friction + tool + skill` with counts, newest first. The roadmap then writes
itself:

```
 23  wrong_visual_result   elementor_page      elementor-page-building
 11  tool_missing          none                none
  9  docs_gap              seo_meta            seo-optimization
```

---

## Part 4 — Teaching the agent to write one

A new skill, `contribute-feedback`:

- `role: procedure`, `surface: any`
- Reached from `build-standard`
- Carries the rules: one sentence, about the plugin not the site, no names, no
  URLs, what a good and a bad `suggestion` look like side by side
- Linter-enforced like every other skill

**The writing guidance is a skill. The redaction and the consent are PHP.** The
split is deliberate: guidance benefits from being editable, guarantees must not
be.

---

## Part 5 — Documents that change in the same pull request

Not afterwards. The test that exists for exactly this reason (`test-updater.php`
already enforces the equivalent for the check-in) gets a sibling.

- **`PRIVACY.md`** — a new section: what a report contains, the full field list,
  that it is confirmed every time, that it can be switched off, the retention,
  and the file and line where redaction is enforced.
- **`readme.txt`** — the WordPress-facing disclosure.
- **The admin Guide page** — same wording as PRIVACY.md, because two documents
  that drift is how a promise quietly stops being true.

---

## Part 6 — Tests

- **`test-feedback-redaction.php`** — planted failures. A URL, an email, an IP,
  an absolute path, a fake API key, the site's own domain: each must not survive
  `redact()`. This is the file that makes the privacy claim checkable.
- **`test-feedback-payload.php`** — enums reject unknown values; `suggestion` is
  truncated at 280; a report with no `friction` is refused.
- **`test-feedback-consent.php`** — the payload is never built when the setting
  is off; rule 15 is present in the protocol.
- **API** — `/v1/feedback` returns 200 for garbage, stores nothing for garbage,
  applies its own redaction, and enforces the rate limit.

---

## Risks, said out loud

- **It weakens a promise people chose this plugin for.** Mitigated by
  confirm-every-time and by PRIVACY.md being specific, but the honest summary is
  that "no analytics, full stop" becomes "no analytics, and one thing you
  approve each time". That is a real change and some people will dislike it.
- **Default ON will be criticised.** It is defensible only while sending stays
  confirmed. If anyone ever proposes "remember my answer and stop asking", that
  is the moment this becomes telemetry, and the answer is no.
- **The agent judges its own performance.** Reports will skew toward things the
  agent noticed, not things the user felt. Useful, not representative — do not
  read it as a satisfaction metric.
- **Low volume at first.** With 3 installs this returns nothing. It is worth
  building *before* growth, so the data starts accumulating from the first real
  user rather than from whenever somebody remembers.

## Order of work

1. `Claudpress_Feedback::redact()` + its planted-failure tests — the guarantee first
2. Payload builder + validation + tests
3. Setting, admin copy, protocol rule 15
4. `contribute-feedback` skill, linter green
5. API: migration, endpoint, tests
6. PRIVACY.md, readme.txt, Guide — same pull request
7. Summary endpoint

## Verification

```bash
php tests/run.php && composer analyse && php bin/lint-skills.php
```

Then, on a real session: trigger a report, confirm the preview shows every
field, answer **no** and confirm nothing was sent. Then answer **yes** with a
deliberately dirty `suggestion` containing a URL, an email and a fake key — and
confirm what arrives has none of them.
