# The Keelhob skill standard

**GENERATED FILE — do not edit by hand.** Run `php bin/build-skill-router.php`.
Every limit below is read from `includes/class-skill-standard.php`, so this page and
the check that enforces it cannot disagree.

A skill is a Markdown playbook any connected agent loads when its description matches
the task at hand. This page says what one has to contain to be accepted, and why each
rule exists. Run `php bin/lint-skills.php` to check your work — it prints a score out
of 100 and names what is wrong. It is also what a reviewer runs on your pull request.

## Front matter

It is **not YAML.** `Keelhob_Skills::parse_markdown()` reads one key per line with
no nesting, no lists and no wrapped values. A description that runs onto a second line
silently loses everything after the first, and the description is the only thing that
routes a task to your skill — so it fails in total silence.

```
---
name: kebab-case-slug          # must match the filename
title: Human readable name     # how the admin screen lists it
description: One line...       # see below — this is the router
enable_agentic: true
enable_prompt: true
role: procedure                # gate | procedure | reference
surface: any                   # any | interactive | workstation
requires: skill-a, skill-b     # load these BEFORE starting
next_skills: skill-c           # consider these when finished
verified_by: pre-launch-audit  # NOT done until this has run
---
```

### The three roles

| Role | Meaning |
|---|---|
| `gate` | Gets in the way on purpose. Work is not done until it passes. |
| `procedure` | Steps to follow to accomplish a task. The default. |
| `reference` | Background knowledge, read for judgement. No steps. |

### Where a skill can run

`role` says what a skill IS. `surface` says which kind of session can FINISH it, and
it exists because a skill body reads identically down every pipe. Without it, an agent
on Telegram loads a skill whose third step is "open the page in a headless browser and
compare computed styles", gets four steps in, and then either stalls or invents a
result. Both are worse than refusing at step zero and naming the client that can.

| Surface | Meaning |
|---|---|
| `any` | Runs anywhere: Claude Code, an IDE, Telegram, or an unattended task. The default. |
| `interactive` | Needs a person answering questions while it runs. Not an unattended scheduled task. |
| `workstation` | Needs Claude Code or an IDE — it uses tools that live on the caller's machine (a browser, another MCP server). Cannot be completed over Telegram. |

A skill may not `requires`, or be `verified_by`, a skill with a stricter surface than
its own: that is a promise it cannot keep, and the linter refuses it. `next_skills` is
deliberately exempt, because that edge is advice for afterwards rather than a
prerequisite — "and when you are next at your machine, do this too" is a useful thing
for a chat-safe skill to be able to say.

### The three edges

This is what makes a skill part of an ecosystem instead of an island. Before these
existed, one skill could only point at another in prose — so nothing could detect a
loop, price a chain, or build a router, and four of the shipped skills were dead ends
nobody had noticed. A skill must declare at least one of `next_skills` or
`verified_by`, and at least one other skill must point at it.

`verified_by` is the important one. Finishing the steps in a skill is not the same as
the work being right, and operating-protocol rule 13 makes an unrun verifier something
the agent has to declare rather than something it can quietly skip.

## The description is the router

It is the only text a model sees when deciding whether to load your skill. Write it
for a stranger, and put in the words a user would actually type — not the words you
would use to categorise it.

- Between **120** and **700** characters.
- One line. No wrapping.
- Say WHEN to load it, not only what it is.

The whole catalogue is capped at **16 KB**, because every connected session pays
for it before any work happens. If the set outgrows that, someone has to earn the
space by tightening a description or merging two skills.

## Required sections

In this order:

1. `## When to use`
2. `## When NOT to use`
3. `## Routing examples`
4. `## Related skills`

`When NOT to use` is the one people leave out, and it is half of routing. A skill
firing on the wrong job costs as much as one that never fires at all.

`Routing examples` is a machine-read fixture, not prose. At least **two** `fires:`
phrases and at least one `skips:`:

```
## Routing examples

- fires: my site is slow on google
- fires: fix my seo
- skips: write me a blog post
```

The linter checks each `fires:` phrase reaches your skill and not another, and that
each `skips:` phrase does not. It scores by shared significant words — it is not a
model and does not pretend to be. What it reliably catches is a description that does
not contain the words your users would type, which is what makes a good skill
unreachable.

## Length

Body lines, excluding the front matter.

| Role | Minimum | Maximum |
|---|---|---|
| `procedure`, `gate` | 40 | 400 |
| `reference` | 40 | 600 |

Below the floor it is a paragraph, and a paragraph belongs inside a skill that already
exists rather than competing with one. Above the ceiling, split it — or say plainly
that it is a reference, which is read for judgement rather than followed step by step.

Following a skill and everything it `requires` may not exceed **60 KB** of context.
If you are over, move something from `requires` to `next_skills`: one is an obligation,
the other is advice.

## What the linter also checks

- **Loops.** A verifies B and B verifies A means "am I done yet" never terminates.
  `requires` and `verified_by` are each checked separately. `next_skills` may form a
  ring, because it is advice rather than an obligation.
- **Orphans.** Nothing points at your skill, so it is only ever reached if a user's own
  wording happens to match. A skill nobody can reach does not exist.
- **Clashes.** Two descriptions more than 30% alike are competing for the same
  request, and the agent picks one at random. This is how a skill pack rots as it grows.

## Before you open a pull request

```bash
php bin/lint-skills.php              # must pass; aim for 100
php bin/build-skill-router.php       # regenerate the catalogue and the tree table
php tests/run.php
```

Commit whatever the generator changed. You do **not** need to update a router row, a
catalogue entry, a count in the zip builder, or a number in a test — those were four
separate chores that made accepting a contributed skill a job in itself, and they are
all derived now.

## On a live site

The same rules run inside the plugin. Call the `skill_lint` tool to check a draft
before saving it, or to check every skill and the links between them. `skill_save`
reports the same findings as warnings — it never refuses. Enforcing a standard is this
repository's job; on someone else's site, telling them is.
