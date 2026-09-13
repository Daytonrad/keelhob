# Plan — absorb VibeSnip into Claudpress

**Status: PLAN ONLY. Nothing here is built. Do not start until the owner says so.**

VibeSnip is a finished snippets product. Claudpress's Snippets Studio is a
three-tab sketch of the same idea with most of the substance missing. This plan
replaces the sketch with the finished thing, and retires VibeSnip.

Everything below was decided deliberately. Where a choice was close, the
rejected option is named with its reason.

---

## What is actually being moved

The important finding, because it decides the size of this job:

**The two databases are already almost the same table.**

| VibeSnip column | Claudpress column | |
|---|---|:---:|
| `title` | `name` | ✅ |
| `code`, `type`, `location`, `priority` | same | ✅ |
| `status` | `active` | ✅ |
| `tags`, `source`, `ai_prompt`, `ai_model`, `ai_review`, `ai_review_at` | same names | ✅ |
| `error_last` | `last_error` | ✅ |
| `conditions` | `conditions_json` | ⚠️ **different shape — see 3.4** |
| revisions table | `claudpress_snippet_revisions` | ✅ |

Claudpress also carries `target_id`, `purpose`, `impact`, `uid`, `origin_site`
and `origin_uid`, which VibeSnip has no equivalent of.

**So this is a face transplant, not a heart transplant.** The screens move; the
engine stays.

### Why the engine stays — the decision that makes everything else possible

Claudpress's snippet engine is what the AI agent talks to: `snippet_save`,
`snippet_get`, `snippet_list`, `snippet_toggle`, `snippet_delete`, the undo
layer, the post-write front-end check that reloads the home page as a logged-out
visitor, and the fatal-error auto-deactivation. Replacing it with VibeSnip's
would break every one of those, and they are the parts that make an *agent*
safe to point at a live site.

**Rejected:** importing VibeSnip's engine wholesale. It would be a truer "clone"
and it would cost the product its entire reason to exist.

---

## Part 1 — One page, five tabs

The `Snippets` menu item becomes **`VibeSnip`**. `claudpress-studio` keeps its
slug so existing bookmarks and the agent's own links do not break; only the
label and the tab set change.

Tab order is the owner's, and it is deliberately not the current order:

| # | Tab | What changes |
|---|---|---|
| 1 | **All Snippets** | Rebuilt to VibeSnip's list — see 1.1 |
| 2 | **AI Compose** | Rebuilt — see 1.2 |
| 3 | **Add Snippet** | Rebuilt — see 1.3 |
| 4 | **Library** | Built for the first time — see 1.4 |
| 5 | **Import** | Built for the first time — see 1.5 |

Activity does **not** become a tab. Claudpress already has an Activity screen
with a "Snippet activity" section; VibeSnip's log merges into it (Part 4).

### 1.1 All Snippets

What it gains, all of it currently missing:

- **A one-click on/off toggle in every row.** This is the single most-used
  action on a snippets screen and today it is a hover-to-find text link.
- **Sortable columns** — name, priority, last updated.
- **Status filters** — All / Active / Inactive / **Errored**. `last_error`
  already exists and nothing has ever surfaced it, so a snippet that fatalled
  and was auto-switched-off is currently invisible.
- **Priority column**, and **pagination**.
- **Default sort order** as a setting — the owner's call, and it belongs here
  rather than in Settings so it can be changed where it is seen.

Kept from Claudpress: the **Source** column (hand-written vs AI), which VibeSnip
has no equivalent of and which matters more here because an agent writes
snippets unattended.

### 1.2 AI Compose

Today: prompt → plan → code → save. Three things are missing and each one is a
dead end for the user.

- **"What I understood"** — a restatement in the AI's own words. A misreading is
  obvious in a restatement and invisible in a plan.
- **"I had to assume…"** — where a misunderstanding actually hides.
- **"Not quite — let me rephrase"** — today the only way out of a wrong plan is
  reloading the page. The rephrase keeps the earlier wording so the second
  attempt is a correction rather than a fresh start.
- **A Discard button** on the result. Today there is only Save.
- **The result shows what it is**: title, type, where it runs, an explanation,
  and the safety review. Today it is an unlabelled grey box.
- **Syntax-check failure blocks Save.** Today a snippet whose own syntax check
  failed still offers a Save button. Observed on the rig.

**Recommended addition (mine, not in VibeSnip):** the compose result should
record `ai_prompt` and `ai_model` on the saved row. Both columns already exist
on both sides and nothing writes them, so "why does this snippet exist" has no
answer six months later.

### 1.3 Add Snippet

- **A real code editor.** CodeMirror with line numbers and syntax colours.
  WordPress ships it and it is *already being enqueued on this screen* and going
  unused — the field is a plain `<textarea class="large-text code">`.
- **Code first, settings in a sidebar.** Today the code box sits below five
  metadata fields, roughly 900px down the page: you scroll past everything to
  reach the only thing you came for.
- **The conditional-logic rule builder** — see 3.4 for the migration.
- **Save / Save & activate.** The owner asked me to decide which is primary.

  **"Save" is primary. "Save & activate" is secondary.** VibeSnip has this the
  wrong way round: the blue default button is the one that starts running code
  on a live site. The riskier action must never be the one a tired person
  presses by reflex. VibeSnip's own setting for choosing the primary button is
  **not** carried across — a setting whose wrong value is dangerous is not a
  setting, it is a trap.

**Recommended addition:** keep Claudpress's existing "Ask AI what this does"
button, which VibeSnip also has, and show its report inline rather than in an
alert.

### 1.4 Library

The owner's specification, and it differs from VibeSnip:

- **No sub-tabs.** VibeSnip splits "VibeSnip Library" and "Private Library";
  both go. Private Library is a leftover label from the cancelled paid tier and
  means nothing now.
- **One heading: "Verified snippets."**
- **A search box**, and the existing category grouping.
- Everything installed from it lands **switched off**. Code arriving from
  elsewhere is exactly the code somebody should read before it runs.

**This tab has never worked.** `Claudpress_Studio_Library` calls
`Claudpress_Snippet_Library::all()` — a class that was never written. That is
why the tab hides itself and why the empty-state button "Browse the library"
leads nowhere. VibeSnip has a real library of 13 snippets; they move across.

**Delivery: signed, from GitHub releases.** The same path the skills already
use — a signed file on a release, verified before it is trusted, with the host
removed from the trust chain. The signing infrastructure exists, so this costs
a feed file rather than a system.

**Rejected:** bundling the library in the zip. Simpler, but a new verified
snippet would then wait for a plugin release, which is the thing that stops a
library from growing.

### 1.5 Import

VibeSnip reads **WPCode** and **Code Snippets** JSON exports. This is the single
feature most likely to win someone over, because it removes the reason they
stay where they are.

Everything arrives **inactive**, per snippet, reviewable.

**New, and required by Part 5:** a **VibeSnip importer** that reads
`wp_vibesnip_snippets` directly on a site where both plugins are installed, plus
the export file the final VibeSnip release produces.

---

## Part 2 — Settings

Everything AI already exists in Claudpress: provider, key, endpoint, model,
budget caps. VibeSnip's AI settings **do not move**; the page reads Claudpress's.

| VibeSnip setting | Where it goes |
|---|---|
| AI enable / provider / key / model | Already in Claudpress. Reuse. |
| Which save button is primary | **Dropped** — see 1.3 |
| Default snippet sort order | Moves to **All Snippets** (1.1) |
| Hide donation footer | **Dropped** — see Part 6 |
| Delete all data on uninstall | Already in Claudpress |
| Agent/API access | Already in Claudpress capability groups |
| **Permissions** tab | **Dropped for now** — see below |
| **Debug** tab | Becomes a small Maintenance block — see below |

### Permissions — dropped, deliberately, and this is written down so it is a decision

VibeSnip has a role-by-role matrix for "Manage snippets" and "Use AI features".
Claudpress is `manage_options` everywhere.

Anyone who can reach this plugin can already execute arbitrary PHP through the
agent. A role that may write snippets but may not do *that* is a distinction the
product cannot actually enforce, so offering it would be a security theatre
checkbox — and a new surface on a plugin that hands out code execution.

**Revisit if a real user asks.** There are three installs.

### Debug — two buttons, not a tab

VibeSnip's Debug tab is "Upgrade database tables" and "Reset caches". Both are
genuinely useful when an update half-applies, and neither needs a tab. They
become a **Maintenance** block at the bottom of Claudpress → Connect, beside the
existing health information.

---

## Part 3 — The engine work

### 3.1 Nothing about the stored shape changes

No new table. No renamed column. Claudpress's snippet row already holds every
field these screens need, and `Claudpress_Snippets::save()` is a *merge* update,
so a screen that posts a subset of fields cannot blank the rest.

### 3.2 The MCP tools widen

`snippet_save` gains `tags` and `conditions`; `snippet_list` gains status and
error filters. Two new read/write tools:

- `snippet_library_list` — what verified snippets exist (read-only)
- `snippet_library_install` — install one, switched off

Without these, the agent cannot do what the human on the page can, which is the
gap that makes an agent feel stupid.

### 3.3 The `errored` state becomes real

`last_error` is written on a fatal and then never read. It gains a filter, a
row badge, and a place in the snippet log.

### 3.4 Conditions — the one genuine incompatibility

The two formats are **not** the same, and this is the only part of the merge
that can silently break a live site.

```
Claudpress (today)   {"logged_in":true,"post_types":["page"],"front_page":false}
VibeSnip             {"enabled":true,"relation":"AND","rules":[{type,operator,value}]}
```

VibeSnip's is far richer — user status, user role, device, page type, post type,
URL path, with `is`/`is_not`/`contains`/`starts_with`/`equals` and AND/OR.

**Plan:**

1. Port `VibeSnip_Conditions` as `Claudpress_Snippet_Conditions`.
2. `conditions_pass()` **reads both shapes.** The old flat format keeps working
   for ever — it is on live sites and a snippet that silently stops running (or
   silently starts) is the worst outcome this plan could produce.
3. New rules are written in the rule format. No migration, no rewrite of stored
   rows.
4. A test plants one of each shape and asserts both evaluate correctly.

**This is the highest-risk item in the plan.** It decides whether somebody's
members-only snippet keeps hiding from logged-out visitors.

---

## Part 4 — Activity

VibeSnip's activity log merges into Claudpress → Activity, which already has a
"Snippet activity" section backed by `Claudpress_Snippet_Log`. It gains
VibeSnip's columns where they are missing: the result of the action, and the
auto-rollback reason.

No new screen. No new table.

---

## Part 5 — Retiring VibeSnip

### 5.1 A final VibeSnip release

One last version whose admin notice says, plainly:

> VibeSnip is now part of Claudpress. This plugin will not be updated again.

It ships two things: a **link to Claudpress**, and an **Export** button that
writes every snippet to a JSON file the Claudpress importer reads.

**Why this and not a silent archive.** VibeSnip's updater reaches those sites
already, so this is the only route that actually tells the people using it.
A project whose whole pitch is trustworthiness does not let its users find out
by having something break.

### 5.2 Both plugins installed at once

Two snippet engines both executing PHP on every page load is a real problem:
the same snippet can run twice, and two fatal-error handlers will fight over
which one deactivates it.

Claudpress detects an active VibeSnip and shows an admin notice:

> VibeSnip is installed and is now part of Claudpress. Your snippets can be
> imported here, then VibeSnip can be deactivated.
> **[Import them] [Not now]**

The import is the reader from 1.5. Claudpress **never** deactivates VibeSnip
itself — a plugin that switches off another plugin is a plugin nobody trusts.

### 5.3 The repository

Archived, with a README that points at Claudpress. `vibesnip-vital/` stays as
it is; nothing in this plan touches it.

---

## Part 6 — The donation buttons

Claudpress puts a pink Donate button beside the `<h1>` on **seven** screens.
VibeSnip prints a four-line donation paragraph on every screen. Both are too
much, and the Claudpress one is this project's own doing.

**A button on every screen is not seen more often — it is learned and ignored,
and it makes a free plugin feel like a trial.**

| Keep | Where |
|---|---|
| The Donate menu item | sidebar, one line |
| The real ask | the Donate page |
| One quiet line | bottom of Connect |
| The Plugins-screen row link | outside the plugin's own pages, where people look for links |

**Remove:** `Claudpress_Donate::button()` from every page heading, and do not
carry VibeSnip's footer across at all.

The docblock at the top of `class-donate.php` argues for the button-on-every-
screen. It is a good argument and it is wrong at seven screens; the file's
comment gets rewritten rather than quietly contradicted.

---

## Part 7 — Tests

- **`test-snippet-conditions.php`** — both stored shapes evaluate correctly;
  a planted old-format row does not change behaviour. This is the file that
  protects live sites.
- **`test-snippet-library.php`** — the feed's signature is verified before
  anything is trusted; an unsigned feed installs nothing; installed items arrive
  inactive.
- **`test-snippet-import.php`** — WPCode, Code Snippets and VibeSnip files each
  import; everything arrives inactive; a malformed file imports nothing and
  errors cleanly.
- **`test-studio-render.php`** — extend: the toggle exists in a row, the filters
  exist, the editor is initialised rather than a bare textarea, Save is primary
  and Save-and-activate is not, and a failed syntax check leaves no Save button.
- **`test-donate.php`** — extend: the heading button appears on **no** screen,
  and the Donate page and the Plugins row link still do.
- **Rig** — the real check. Render every tab, click the toggle, install from the
  library, import a file, and assert against the rendered page rather than the
  source. Grepping HTML has already let an unstyled page ship once.

---

## Order of work

Shipped **all at once**, per the owner. Built in this order because each step
makes the next testable.

1. `Claudpress_Snippet_Conditions` + its tests — the risky part first, alone,
   where a mistake is visible
2. The list: toggle, sort, status filters, priority, pagination
3. The editor: CodeMirror, code-first layout, save buttons, rule builder
4. AI Compose: understanding, assumptions, rephrase, discard, labelled result,
   syntax-check gate
5. `Claudpress_Snippet_Library` + the signed feed + the Library tab
6. The importer + the Import tab, including the VibeSnip reader
7. MCP tools, Activity merge, Maintenance block
8. Menu rename to **VibeSnip**, the dead "Browse the library" button removed
9. The donate reduction
10. VibeSnip's final release and repo archive

---

## Risks, said out loud

| # | Risk | What is done about it |
|---|---|---|
| 1 | **The conditions format change silently alters which snippets run** | 3.4 — both shapes read for ever, planted-failure test. The only item that can hurt an existing site. |
| 2 | A site has both plugins and code runs twice | 5.2 — detect, warn, import. Never deactivate for them. |
| 3 | The signed library feed is one more thing to maintain | It reuses the skills signing path exactly. If that breaks, both break, and both fail closed. |
| 4 | This is a large change landing in one release | Accepted, at the owner's direction. Mitigated by the rig checks in Part 7 — the parts most likely to break are the parts a stub test cannot see. |
| 5 | Removing the donate buttons reduces donations | Probably true in the short term. A free plugin that reads as a trial costs more than it earns. |
| 6 | "VibeSnip" as a page name inside Claudpress confuses people who knew VibeSnip the plugin | This is exactly why 5.1 exists: the retirement notice is what makes the name a continuation rather than a collision. |
| 7 | The agent writes a snippet with conditions it does not understand | 3.2 — the tool schema names the rule vocabulary explicitly rather than accepting free-form JSON. |

---

## Verification

```bash
cd Claudpress
php tests/run.php && composer analyse && php bin/lint-skills.php
bash tests/rig/sync-plugin.sh /c/wp-rig/wordpress
```

Then on the rig, rendered rather than grepped:

1. Every tab loads; the menu says **VibeSnip**.
2. Click the row toggle — the snippet activates, the page says so.
3. Sort by priority; filter by Errored with a deliberately fatalled snippet.
4. The editor shows line numbers; Save is primary.
5. Compose: send a wrong prompt, use **rephrase**, then discard.
6. Install one library snippet — it arrives **off**.
7. Import a WPCode file and a VibeSnip file.
8. A snippet carrying the **old** flat conditions format still runs exactly as
   it did before. Plant it, assert it, then tear it down in the same step.
9. No Donate button beside any `<h1>`; the Donate page still works.
