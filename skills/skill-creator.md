---
name: skill-creator
title: Skill creator
description: Create, refine, or improve a Keelhob skill. Use whenever the user asks to "create a skill", "make a skill", "add a skill for X", "teach the site how to Y", or wants to capture a repeatable workflow the AI should follow automatically.
enable_agentic: true
enable_prompt: true
role: procedure
surface: any
next_skills: build-standard
---

# Skill creator

You are writing a Keelhob **skill**: one Markdown document (YAML frontmatter + body) that any AI agent connected to this site loads automatically when its `description` matches a task. Save it with the `skill_save` tool.

## When to use

Someone wants a repeatable workflow captured so the agent follows it automatically next time — "create a skill", "teach the site how to do X every time".

## When NOT to use

Not for a one-off task. And check the router in `build-standard` first: most "we need a skill for this" turns out to be a missing section in a skill that already exists, and two skills covering the same ground split the agent's attention.

## Format

```
---
name: kebab-case-slug
title: Human readable name
description: One line that says WHAT the skill does and WHEN to use it. This is the only field an agent reads to decide whether to load the body — write it for a stranger.
enable_agentic: true   # agent loads it automatically when the description matches
enable_prompt: true    # user can invoke it by name from their client's prompt menu
role: procedure        # gate | procedure | reference
requires:              # load these BEFORE starting (comma separated, optional)
next_skills: skill-a   # consider these when this skill is finished
verified_by: skill-b   # the work is NOT done until these have run
---

# Title

## When to use

## When NOT to use

The instructions the agent should follow.

## Routing examples

- fires: a phrase a user would actually type
- fires: another one
- skips: a phrase that belongs to a different skill

## Related skills
```

**Check it before you save it.** Call `skill_lint` with your draft as `markdown`.
It scores the skill out of 100 and names what is wrong. Call it with no arguments
to check every skill on the site and the links between them — loops, skills
nothing points at, and two skills competing for the same request.

## Rules for a good skill

1. **The description is the trigger.** Include the exact phrases the user actually says. Bad: "Helps with posts." Good: "Bulk-rewrite WooCommerce product descriptions in the brand voice. Use when the user asks to rewrite, fix tone, or generate product copy."
2. **Be concise.** Every word competes for the agent's context. The agent is already a capable WordPress/WooCommerce operator — cut anything it can infer by inspecting the site. Aim for 200–800 words.
3. **Match specificity to fragility.** Multiple valid approaches → write prose. One preferred pattern → show an annotated example. Destructive / irreversible / one-correct-sequence (migrations, price/fee math, bulk deletes) → spell out exact steps and add explicit "do not deviate" wording.
4. **Skills are flat.** Everything lives in the one body — no bundled scripts or file attachments. If a skill wants attachments, it is doing too much; split it.
5. **Say where it sits.** A skill that names nothing to do next leaves the agent with nowhere to go when it finishes, and a skill nothing points at is only ever reached if the user's wording happens to match. Both are silent failures — nothing errors, the work is just worse. Declare `next_skills` and, where something genuinely checks the work, `verified_by`. Then add the new skill to a related skill's `next_skills` so it can be reached at all.
6. **Say when NOT to use it.** Half of routing is staying out of jobs that are not yours. One line naming the skill that should have taken it is enough.
7. **Give a Contents list to anything long.** Past about 120 lines a reader cannot see what is further down before deciding it has read enough. Do not type one — run `php bin/build-skill-contents.php`, which builds it from the skill's own headings. A hand-typed list is correct on the day it is typed and wrong the next time somebody adds a section. The list does not count toward the size cap.
8. **A skill that CHECKS work needs a checklist.** That means a `role: gate`, or a skill another skill names in its `verified_by`. Three parts, and all three are needed:
   - **boxes** the agent copies into its reply and ticks: `- [ ] ...`
   - **a failure branch** — "if X fails, go back to step N". Without it a tick is the only outcome, and ticking is not finishing.
   - **a bound** — "after two attempts, stop and report". An unbounded retry spends the owner's money and nothing in the plugin can cap it.

   This is the rule that keeps getting earned. Real examples from this project: two of three user-enumeration routes closed and the third left open; `site_readiness` run and `pre-launch-audit` never run; a manual written and linked from nowhere. Each was a last step nobody checked.
9. **Do NOT put a checklist in a creative or reference skill.** Anthropic's guidance is that the newer models get *worse* when over-instructed. A checklist in a design or writing skill buys nothing and costs quality. The linter only asks for one where work is actually checked, and that is deliberate.
10. **Match freedom to risk, inside one skill.** The same skill can be loose in one step and exact in another. Writing the launch email: say what good looks like and get out of the way. Taking the backup before a migration: spell out the exact sequence and say not to deviate. Say which kind each step is, in words — there is no frontmatter key for it.

## Process

1. Ask what recurring task the skill should capture (only if unclear).
2. Draft the frontmatter description first — get the trigger right.
3. Write the body: goal, then the steps/conventions, then any guardrails.
4. Call `skill_save` with the full `markdown`.
5. Call `skill_lint` on it and fix what it reports. It runs the same rules the project's own skills are held to, so a clean result means the skill is shaped like the ones that ship.
6. Tell the user it is saved site-wide and will fire automatically when a matching task comes up.

## Related skills

- **`build-standard`** — read its router table before writing a new skill. Most "we need a
  skill for this" turns out to be a section missing from a skill that already exists, and
  a second skill covering the same ground splits the agent's attention.

Two things a new skill must do or it will never be used: give it a `description` naming
the phrases a user would actually type, since that is the only thing that routes a task
to it, and add a `## Related skills` footer so it is part of the tree rather than a leaf.

## Routing examples

Phrases that must reach this skill, and one that must not. `bin/lint-skills.php`
checks them against every other skill's description.

- fires: create a skill for this workflow
- fires: teach the site how to do this every time
- skips: audit my site before it goes live
