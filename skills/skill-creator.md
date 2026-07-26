---
name: skill-creator
description: Create, refine, or improve a Claudpress skill. Use whenever the user asks to "create a skill", "make a skill", "add a skill for X", "teach the site how to Y", or wants to capture a repeatable workflow the AI should follow automatically.
enable_agentic: true
enable_prompt: true
---

# Skill creator

You are writing a Claudpress **skill**: one Markdown document (YAML frontmatter + body) that any AI agent connected to this site loads automatically when its `description` matches a task. Save it with the `skill_save` tool.

## Format

```
---
name: kebab-case-slug
description: One line that says WHAT the skill does and WHEN to use it. This is the only field an agent reads to decide whether to load the body — write it for a stranger.
enable_agentic: true   # agent loads it automatically when the description matches
enable_prompt: true    # user can invoke it by name from their client's prompt menu
---

# Title

The instructions the agent should follow.
```

## Rules for a good skill

1. **The description is the trigger.** Include the exact phrases the user actually says. Bad: "Helps with posts." Good: "Bulk-rewrite WooCommerce product descriptions in the brand voice. Use when the user asks to rewrite, fix tone, or generate product copy."
2. **Be concise.** Every word competes for the agent's context. The agent is already a capable WordPress/WooCommerce operator — cut anything it can infer by inspecting the site. Aim for 200–800 words.
3. **Match specificity to fragility.** Multiple valid approaches → write prose. One preferred pattern → show an annotated example. Destructive / irreversible / one-correct-sequence (migrations, price/fee math, bulk deletes) → spell out exact steps and add explicit "do not deviate" wording.
4. **Skills are flat.** Everything lives in the one body — no bundled scripts or file attachments. If a skill wants attachments, it is doing too much; split it.

## Process

1. Ask what recurring task the skill should capture (only if unclear).
2. Draft the frontmatter description first — get the trigger right.
3. Write the body: goal, then the steps/conventions, then any guardrails.
4. Call `skill_save` with the full `markdown`.
5. Tell the user it is saved site-wide and will fire automatically when a matching task comes up.
