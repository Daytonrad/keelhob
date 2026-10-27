---
name: confirm-before-building
title: Agree it before you build it
description: The gate in front of every build. Loads automatically before anything new is created on this site and makes you state your reading of the request, ask the questions that would change what you build, and name the business risks in your own plan before the first write. Use when you are about to treat a long prompt as if it were agreement, when you are not sure you understood what the owner asked for, when you want your plan checked for risk before building it, or when nobody is available to answer and you were about to guess anyway. It does not run the intake itself — site-build-kickoff owns that for a new site. Not for edits or fixes to something that already exists.
enable_agentic: true
enable_prompt: true
role: gate
next_skills: site-build-kickoff, website-building, blog-publishing
verified_by: build-standard
---

# Agree it before you build it

<!-- CONTENTS:START -->
## Contents

- [When to use](#when-to-use)
- [When NOT to use](#when-not-to-use)
- [The one mistake this exists to stop](#the-one-mistake-this-exists-to-stop)
- [1. Say your reading back, in a few lines](#1-say-your-reading-back-in-a-few-lines)
- [2. Ask only what would change the build](#2-ask-only-what-would-change-the-build)
- [3. Do not start until one of these is true](#3-do-not-start-until-one-of-these-is-true)
- [4. Then review your own plan for business risk](#4-then-review-your-own-plan-for-business-risk)
- [5. If nobody is answering, stop](#5-if-nobody-is-answering-stop)
- [Finish check](#finish-check)
- [Related skills](#related-skills)
- [Routing examples](#routing-examples)
<!-- CONTENTS:END -->

Operating protocol rules 7a, 7b and 7c, with the detail of how to do them. The
rules are in the plugin and cannot be switched off. This skill is how you obey
them well.

## When to use

Anything built from scratch: a whole site, a page, a header or footer, a blog
post, a cloned design, a design system, a scroll-driven page.

## When NOT to use

Not for an edit, a fix, a report or an audit of something that already exists.
A one-line change does not get an interview.

## The one mistake this exists to stop

**A long prompt is not a brief. It is your reading of a brief.**

This has actually happened on a real site, twice. A detailed request arrived,
the agent decided it understood everything, skipped the intake, and built a
whole site against its own interpretation. Nothing errored. The work was simply
not the work that was wanted, and all of it had to be read before any of it
could be trusted.

The skills invited it: `site-build-kickoff` says to go straight to
`website-building` when "a brief already exists", and a long prompt looks
exactly like one. So the test is not *"do I have enough detail?"* — detail is
easy to mistake for agreement. The test is:

> **Has the owner agreed with MY reading of it?**

If they have not, you do not have a brief. You have a guess that reads well.

## 1. Say your reading back, in a few lines

Before the first write. Short — this is not a document.

- what you think they want, in one or two sentences
- what you are about to build, as a list of the actual things (pages, sections)
- what you are deliberately NOT building

Getting this wrong out loud costs four lines. Getting it wrong silently costs
the whole build.

## 2. Ask only what would change the build

**One batch. Never one question at a time.** Interrogating an owner is its own
failure, and `site-build-kickoff` says so.

Ask a question only if a different answer would make you build something
different. Good: *"is this for selling, or for being found?"*, *"who is the
customer?"*, *"is there a brand to match, or am I choosing?"* Bad: anything you
could read off the site, infer safely, or decide yourself and mention later.

If a skill already owns this intake, **run that skill's intake instead of
inventing your own** — `site-build-kickoff` for a new site. Run it even when you
believe you already have a brief. That belief is the bug.

## 3. Do not start until one of these is true

- they answered, or
- they said to go ahead, or
- you would stake the whole job on your reading being right.

"Probably right" is not on that list.

## 4. Then review your own plan for business risk

Rule 7b. Once they have agreed, read the plan back against what they asked for
and look for what is wrong for their **business**, not what is wrong technically.

- a page that sells the wrong thing, or nothing
- a tone aimed at the wrong customer
- no way for a real visitor to get in touch or buy
- a claim, number or testimonial they cannot stand behind
- something expensive to undo later: a URL structure, a domain, a payment setup
- the thing they will notice is missing on the day they show someone

**List these before the first write, not in the summary afterwards.** A plan
that is technically perfect and aimed at the wrong audience is still a wasted
build.

## 5. If nobody is answering, stop

Rule 7c. On Telegram or Slack, in a scheduled task, or running unattended, you
cannot agree anything with anybody.

Do **not** build the smallest safe version. Do **not** guess. Say which parts
you do not know, say plainly that this connection cannot settle them, and leave
the work undone.

An unapproved site built while the owner was asleep is worse than no site: they
now have to read all of it before they can trust any of it.

## Finish check

Copy these and tick them before the first write. Do not report a build as
started with an unticked box.

- [ ] I said my reading back, and named what I am not building
- [ ] I asked the build-changing questions, in ONE batch
- [ ] they answered, or said go ahead, or I would stake the job on my reading
- [ ] if a skill owns this intake, I ran it — even though I thought I had a brief
- [ ] I listed the business risks in my own plan, before writing anything
- [ ] if nobody could answer, I stopped and said what I did not know

If a box cannot be ticked, you are not ready to build. Go back to step 1.
After two rounds of questions with no clear answer, stop and report what is
still undecided — do not keep asking, and do not quietly start.

## Related skills

- **`site-build-kickoff`** — owns the intake for a brand-new site. This gate
  sends you there rather than replacing it.
- **`build-standard`** — the Definition of Done at the other end of the build.
- **`site-safety`** — the same idea for changes: reversible, and verified after.

## Routing examples

Phrases that must reach this skill, and one that must not. `bin/lint-skills.php`
checks them against every other skill's description. None of them is a build
request: this gate is reached because a build skill requires it, exactly as
`site-safety` is, so competing with `site-build-kickoff` for "build me a
website" would send the work to the wrong place.

- fires: are you sure you understood what I asked for
- fires: check your plan for risk before you build it
- skips: fix the padding on the about page
