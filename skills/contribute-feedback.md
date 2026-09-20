---
name: contribute-feedback
title: Write a friction note
description: How to word a note for the Keelhob feedback report, and what must never go in one. Use when feedback_status says a report is due and you are about to show the draft, when the user asks you to add something to a report, or when you want to record that a tool or a playbook let you down in a way no error message captured.
enable_agentic: true
enable_prompt: false
role: procedure
surface: any
requires: site-safety
next_skills: skill-creator
verified_by: pre-launch-audit
---

# Write a friction note

Most of the feedback log fills itself in. The plugin already sees every tool
that errored, every capability that blocked real work, and every time you called
the same tool with near-identical arguments three times in a row. None of that
needs you, costs tokens, or depends on you being honest about your own failures.

This skill is for the one category no machine can see:

> **it did exactly what was asked, and the result was wrong.**

That is the most valuable thing this project could learn, and it produces no
error, no exception and no log line. Only you noticed it.

## When to use

- `feedback_status` returned `due: true` and you are about to show the draft.
- The user asked you to add something to a report.
- Something let you down in a way no error captured: a design rejected three
  times, an instruction you misread twice, a playbook that did not say the thing
  that mattered.

## When NOT to use

Not for a tool that returned an error — that is already recorded automatically,
and writing it again produces two rows for one problem. Not for anything about
the site's content, its business, or the person you are working with. And not as
a place to apologise: a note is a bug report, not a confession.

## Routing examples

- fires: feedback_status says a report is due
- fires: add this to the feedback report
- fires: record that a tool let me down
- skips: write me a blog post
- skips: audit this site before launch

## The shape of a note

One sentence. Under 200 characters. About the **plugin**, never about the site.

The test: could this note be read out at a project meeting by someone who has
never seen this website, and still be actionable? If not, rewrite it.

**Good**

> `elementor_page should return the IDs of sections it creates, so the next edit
> does not have to re-read the page to find them.`

It names the tool, says what is missing, and says why it costs something. A
maintainer can act on it without asking a single question.

**Bad**

> `the client at acme-dental.com wanted the hero to say Book Now`

It names a site, describes the site's content, identifies a third party — and as
a bug report it is useless anyway, because nothing in it says what the plugin
did wrong.

## What never goes in a note

Never write any of these, even though the plugin strips them:

- the site's address, name, or the client's name
- anybody's email address, or any personal detail
- page or product content, prices, or anything commercial
- file paths, IP addresses, keys or tokens
- the user's own words quoted verbatim

Redaction in PHP is the safety net, not the plan. A note that arrives as five
`[removed]` marks tells the project nothing, so write one that needs no
redaction in the first place.

## Showing the draft

When `feedback_status` returns `due: true` it hands you a `show_user` block.

1. Show that block **exactly as written**. Do not summarise it, shorten it, fold
   it behind "details", or describe it in your own words. The user is agreeing
   to text leaving their server; they can only agree to text they have read.
2. Ask: *"Send this to the Keelhob project? Or is there anything you would
   add or change?"*
3. If they add something, call `feedback_status` again with `add_note` set to
   what they said, show the **new** draft in full, and ask again.
4. Call `feedback_send` with `approved: true` only after a clear yes.
5. If they say no, call `feedback_send` with `approved: false`, say thank you,
   and drop it. Do not ask again this session and do not explain why they should
   have said yes.

## If the send comes back throttled

`feedback_send` may answer with `throttled: true`. That is **not** a failure and
must not be reported as one.

The project's endpoint accepts a limited number of reports a day per network
address, and many sites share one address — an agency, a shared host. So it
means *somebody else on this server's address got there first today*. The
report is still good, still saved, and the plugin will offer it again tomorrow.

Say one line, warm and short, and move on:

> "The project's inbox is full for today, so I have kept this and it will offer
> again tomorrow. Nothing lost."

Do not retry in this session. Do not suggest anything is wrong with their site.
Do not apologise at length — it is a queue, not a fault.

You **will** be asked to show the draft again tomorrow, and the person will have
to approve it again. That is deliberate: an approval kept on disk to be replayed
later is the first step toward "remember my answer and stop asking", and this
feature does not do that.

## If they are unsure

Answer the question they actually have, which is almost always *"what leaves my
server?"*. The honest answer is short: the lines they can see, an anonymous id
that is not derived from the address, and the plugin version. No page content,
no address, no keys, nothing about their visitors or customers.

If they are still unsure, that is a no. Treat it as one.

## Related skills

`site-safety` is the baseline this sits under — never let a feedback prompt
interrupt unfinished or unverified work. `skill-creator` is the better answer
when the gap you noticed is a missing playbook rather than a missing feature:
write the skill instead of reporting that it was absent. `pre-launch-audit`
still decides whether the work itself is done; a feedback report is something
you offer after that, never instead of it.
