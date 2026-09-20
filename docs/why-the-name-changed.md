# Why the name changed

This project was called **Claudpress** from its first commit until version 2.0.0.
It is now called **Keelhob**. Nothing about what it does changed. Only the name did.

This page exists so nobody has to guess why, and so the decision is on the record
rather than in somebody's memory.

## The short version

"Claudpress" contained **Claud**, which is one letter away from **Claude**.

Claude is a registered trademark of Anthropic, PBC. Claude is also the AI client
this plugin is most often pointed at, which is exactly the kind of overlap that
makes a name look like an official product when it is not.

We changed it before being asked to, rather than after.

## What made this urgent rather than theoretical

In January 2026 an open-source AI project called **Clawdbot** received a
trademark notice from Anthropic's legal team. It renamed to Moltbot, then to
OpenClaw, in the space of three days.

Two details from that episode matter here:

1. **"Clawd" is spelled nothing like "Claude"** and it was still too close.
   "Claud" is closer.
2. When the author asked whether shortening it to **"Clawbot"** would be
   acceptable, the answer was no.

Anthropic asked politely and the project complied. Nobody went to court. But the
pattern was clear, and this project's name was a nearer match than the one that
got the letter.

For completeness, because the distinction is genuinely useful: the eventual name
**OpenClaw** contains "Claw" and has stood since 30 January 2026 without
objection. The problem was never the animal. It was the sound of *Claude*.

## Why now and not later

At the time of the rename this repository was private, which meant release
assets could not be downloaded by anyone outside the project. The install base
was three sites, all owned by the maintainer.

That made this the cheapest possible moment. Six months later, the same change
would have meant breaking other people's live sites, renaming a database they
depend on, and invalidating every MCP client configuration in the wild.

A rename is a small job with a short deadline that nobody tells you about.

## What Keelhob means

A **keel** is the spine of a ship. It is the first timber laid down and
everything else is built onto it. There is also an English phrase, *on an even
keel*, meaning steady and under control.

A **hob** is two things. It is the warm shelf beside a fireplace. It is also, in
English folklore, a household spirit that does the chores at night while the
family sleeps and asks for nothing but a bowl of milk.

A foundation, and something helpful that works while you are not watching. That
is the whole product in one word.

## What actually changed, mechanically

| Thing | Before | After |
|---|---|---|
| Plugin folder and main file | `claudpress/claudpress.php` | `keelhob/keelhob.php` |
| Class prefix | `Claudpress_` | `Keelhob_` |
| Constants | `CLAUDPRESS_*` | `KEELHOB_*` |
| Text domain | `claudpress` | `keelhob` |
| MCP route | `/wp-json/claudpress/v1/mcp` | `/wp-json/keelhob/v1/mcp` |
| Database tables | `wp_claudpress_*` | `wp_keelhob_*` |
| Options, transients, post meta | `claudpress_*`, `_claudpress_*` | `keelhob_*`, `_keelhob_*` |
| Internal short prefix | `cp_`, `cp-`, `CP_` | `kh_`, `kh-`, `KH_` |

4,597 occurrences across 208 files, plus ten tables and three filenames.

**Every MCP client has to be reconnected.** The route changed, so an existing
configuration will get a 404. Copy the new command from the Connect screen.

## How we know nothing was missed

Not by searching carefully. A careful search is a promise to remember, and this
project has already been caught out three times by a number or a name that a
person was supposed to keep up to date.

Instead, `tests/unit/test-no-legacy-brand.php` scans every text file in the
project for the old name and fails the build if it finds one, printing the exact
file and line. CI runs it on every push.

Exactly two files are allowed to contain the old name, and both have to:

- **this document**, which cannot explain the change without naming what changed
- **`includes/class-legacy-adopt.php`**, which has to say where the old data is
  in order to move it

The old name appears on two lines of that second file and nowhere else in the
codebase.

## If you are upgrading an existing site

Keelhob adopts your data automatically: tables are renamed in place, settings
and connection token are carried across, and the SEO fields on your posts are
updated. Nothing is copied or rebuilt, so nothing is lost.

**Do it in this order:**

1. **Deactivate Claudpress.** Do not delete it yet.
2. Install and activate Keelhob.
3. Check your snippets, skills, memory and scheduled tasks are all present.
4. Reconnect your MCP client using the new command on the Connect screen.
5. Once you are satisfied, delete Claudpress.

Keelhob **refuses to touch anything while Claudpress is still active.** Renaming
tables out from under a running plugin does not produce a warning, it produces a
database error on every request, so the adoption step declines and waits.

If you skipped step 1, no harm is done. Deactivate the old plugin and load any
admin page.
