---
name: site-safety
title: Site safety
description: Baseline safety and verification rules for making changes on this WordPress site. Loads automatically for any create/update/delete/install/PHP/SQL operation to keep changes reversible and verified. Use when a change needs to be undoable, when you must verify the front end still loads and did not break after an edit, when something you just did took the site down, or before touching anything you did not build.
enable_agentic: true
enable_prompt: false
role: gate
next_skills: security-standard, code-snippets
verified_by: pre-launch-audit
---

# Site safety

You have full control of a real WordPress site. Move fast, but never leave it broken.

## When to use

Any create, update, delete, install, PHP or SQL operation on a live site. It is the baseline that keeps a change reversible and makes you prove it worked.

## When NOT to use

Not a security audit — that is `security-standard`. This is about not breaking the site you are working on, not about defending it from an attacker.

## Never report done without the audit

This skill loads automatically on every create/update/delete/install/PHP/SQL operation, so
this is the right place to say it plainly: per the operating protocol and `build-standard`,
any create, update, or build that ends with "done" or "finished" requires the fix loop to
have actually run — `pre-launch-audit` (or `site_readiness` where available) against your
own work, every Blocker and every High resolved, up to the 3-iteration cap in
`build-standard`. Writing the content and reporting success without running that loop is
not done, no matter how finished it looks.

## Before changing anything
- Understand current state first: read the record (`get_post`, `wc_order action=get`, `manage_option action=get`) before you modify it.
- For anything destructive or irreversible — deleting content, refunds, bulk price/stock changes, deleting/replacing files, running `DELETE`/`UPDATE` SQL without a tight `WHERE`, changing base country/currency, deactivating a plugin the site depends on — state exactly what you will do and get explicit confirmation.

## Prefer reversible paths
- Trash, don't force-delete (`delete_post` without `force`) unless asked.
- When editing files or theme code, prefer a child theme or a `write_sandbox_file` snippet over editing core/parent files. Sandbox files that fatal are auto-quarantined, so the site stays up.
- For risky `execute_php`, first run a read-only probe (return the data you'd change) before the write.

## Snapshot before fixing

Snippets are reversible (`snippet_toggle`/`snippet_delete`) and content has `/undo` —
option changes and file changes are not. A bad automated fix currently has no undo, so
build the undo path yourself before you need it:

- Before `manage_option action=set`, first run `manage_option action=get` on the same
  option and record what it returned — in the summary you give the owner, and in
  `memory_save` if it's a setting a future session might touch again.
- Before `write_file`/`edit_file` changes an existing file, `read_file` it first and keep
  the prior content on hand (in the conversation, or noted in the summary) until you've
  verified the new version actually works.
- This applies most to the fix loop in `build-standard`: every option or file it changes
  needs its prior value on record before the change lands, not discovered after something
  breaks.

## GOTCHA: full-file rewrites of live .php files
`write_file`/`edit_file` content is syntax-checked before it touches disk (missing/mangled `<?php` tag, unbalanced braces, stray tokens — all rejected, nothing written). That catches broken syntax, but it can't catch broken *content that still parses* — and a live plugin/theme .php file is `require`d on every request with no crash recovery, unlike sandbox files.
- **Prefer `edit_file` with a small, targeted `old_string`/`new_string` over `write_file` with the whole file retyped.** A full rewrite of a large file is exactly how this class of incident happens: a wrong character transcribed once near the top (e.g. `<` coming out as `&lt;` while composing the write) corrupts the entire file, and the more content you retype, the more surface area for that slip.
- If you must rewrite a whole live .php file, read it back afterward and confirm it matches what you intended before considering the change done — don't infer success from the tool call alone.
- This applies double to anything in `includes/` — those files run unconditionally on every single request site-wide, so a bad one takes the whole site down, not just one feature.

## GOTCHA: never split one interdependent change across two live writes
`includes/` files are `require`d on every request. If a change to one file only
makes sense once a second change (in the same file, or a related one) has also
landed — e.g. registering a tool whose callback method doesn't exist yet — do
**not** apply them as two separate `write_file`/`edit_file` calls. The instant
the first one lands, every subsequent request (including your own next tool
call, since it also has to load this plugin) can fatal. You lose the only
channel you had to apply the second half of the fix, and recovery needs
server/SSH access outside Claudpress entirely.
- Compose the whole change locally/mentally first, then land it in **one**
  write per file (a single `write_file` with the complete correct content, or
  one `edit_file` covering the whole interdependent hunk) — never a "part one,
  then part two" sequence against a live site.
- If a change is genuinely too large for one edit, land it in an order where
  every intermediate state still parses **and still runs**: define new methods
  *before* the line that registers them as a callback, not after.
- If you do land the site in this state anyway: MCP access is gone (loading
  the plugin to serve the MCP request also fatals), so the only way back in is
  outside Claudpress — SSH/hosting file manager/`docker exec` to fix the file
  directly, then resume.

## Verify after
- After a change, read one affected record back and confirm the new value. Report what actually changed, with IDs and links.
- If a tool returns `success: false`, surface the exact error and adjust — don't retry the same call blindly.

## Scope
- Keep the filesystem within the configured base; don't try to escape it.
- Don't touch other admins' users, unrelated plugins, or production credentials unless that's the task.

## Adopting an existing site

Claudpress is routinely pointed at a site somebody else already built. Building and fixing
rules do not apply cleanly to someone else's work — you didn't make the decisions on this
site, so you don't get to unilaterally change them.

- **Audit first, report, then ask** before changing anything you did not build yourself.
- Never mass-rewrite existing content, never bulk-toggle settings, never "fix" a design
  decision nobody asked you to touch — even one you'd have made differently.
- Present the findings and let the owner choose what happens next.
- Run the compromise-detection checks in `security-standard` §5 **before** starting any
  other work on the site. Building on a site that is already backdoored just adds a
  second layer to clean up later. If you find signs of compromise, stop and tell the
  owner — don't silently clean it up as though it were routine maintenance.

## Remember
- Save durable decisions and conventions with `memory_save` so future sessions don't repeat mistakes or re-ask.

## Routing examples

Phrases that must reach this skill, and one that must not. `bin/lint-skills.php`
checks them against every other skill's description.

- fires: make sure this change is reversible
- fires: verify the change did not break the front end
- skips: write a blog post about padel courts

## Related skills

- **Next** — security-standard, code-snippets. Consider these when this skill is done.
- **Verified by** — pre-launch-audit. The work is not finished until this has run and passed.
