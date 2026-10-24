# Build notes

Decisions taken during a build when the owner could not be asked. Each one is
the safer option, recorded here so it can be reopened deliberately rather than
discovered.

## Track 5 — the scroll-craft sub-skill (2026-10-24)

1. **The engine is not vendored into this repo.** Upstream
   (`github.com/nateherkai/scroll-craft`, shipped as the `nateherk-design`
   Claude Code plugin) declares `"license": "MIT"` in `.claude-plugin/plugin.json`
   — and that is the only place it is declared. There is no `LICENSE` or
   `COPYING` file in the distribution and no copyright line or author header in
   `engine/scrollcraft.js` or `engine/scrollcraft.css`. MIT is GPL-compatible, so
   a copy would be permitted in principle, but MIT's single condition is that the
   copyright notice travels with the copy, and there is no notice to carry. So
   `skills/scroll-craft.md` tells the agent how to obtain and install the engine
   instead of shipping one. **Reopen this** if upstream adds a LICENSE naming a
   copyright holder.

2. **Surface is `workstation`, not `any`.** Step 9 is a screenshot strip driven
   from a real browser on the caller's machine. A Telegram session cannot finish
   it, and the skill standard exists so that is refused at step zero rather than
   four steps in.

3. **`requires: web-design-master, code-snippets`, `verified_by: pre-launch-audit`.**
   Both requirements are `surface: any`, which is laxer than `workstation`, so the
   standard's surface rule holds. The parent edge is `next_skills` on
   `web-design-master`, which the standard exempts from that rule on purpose.

4. **Wired into `web-design-master` only, not also `website-building`.** PLAN.md
   §4 names one parent. `website-building`'s description already mentions a
   scroll-scrubbed hero, and adding a second pointer risked the linter's 30%
   description-clash rule for no routing benefit.

5. **The mount call lives in the page template, after `wp_footer()`.** The engine
   exposes `ScrollCraft.mount()` and does not boot itself. The alternative was a
   second `js` footer snippet with a higher priority; the template is fewer moving
   parts and keeps the mount next to the markup it mounts. The second snippet is
   still the right answer if the template is not the agent's to edit.

6. **Theme tokens go in the template's own `<style>`, not in the engine CSS
   snippet.** Editing the engine's token block is how the next engine upgrade
   silently reverts a brand.

7. **Build the template against a draft page first.** Not in PLAN.md. A bad render
   on a draft is a page nobody can reach; on a published page it is an outage.

### Two things PLAN.md §4 did not cover, and what the skill says instead

- **A `page_template` that throws takes the page down.** The skill references the
  guards that already exist in `includes/class-snippets.php` rather than inventing
  advice: `lint_php()` parse-checks a `page_template` body as raw markup and forces
  `active = 0` on failure; `run_php_template()` catches `Throwable` and
  `on_shutdown()` catches the fatals a catch cannot see, both ending in
  `deactivate_with_error()`; `verify_front_end()` probes the front end as a
  logged-out visitor with a control re-probe before blaming the snippet; and
  `define('KEELHOB_SNIPPETS_OFF', true)` is the last resort.
  **The gap worth naming:** `verify_front_end()` probes the HOME page, so for a
  `page_template` snippet scoped to one page the automatic check never visits the
  page the template serves. The skill makes loading the target page the agent's
  own job.
- **A footer `js` snippet runs on every page.** Scoping is `conditions` (an array,
  `relation` AND/OR, `rules` of `type`/`operator`/`value`) with a single
  `post_id is <ID>` rule. `page_template` ignores conditions entirely —
  `maybe_page_template()` selects on `active`, `location` and `target_id` alone —
  so `target_id` is its scoping.

### Found by running it on the rig, not by reading it

- **`conditions`, not `conditions_json`.** `Keelhob_Snippets::save()` only reads
  the `conditions` key. A `conditions_json` string is accepted, ignored, and
  leaves the snippet running site-wide with nothing in the response to say so.
  Confirmed both ways: with the wrong key the engine appeared on the home page;
  with the right key it appeared on the target page only.
- **The engine fails silently without `ScrollCraft.mount()`.** The page renders,
  the CSS applies, every cue sits at `opacity: 0`, `--sc-p` is empty, the act
  height is never set, nothing moves, and the console is clean. That is the first
  failure anyone will hit and it looks like a broken engine rather than a missing
  line.
- **A `page_template` that prints its own `<html>` must call `wp_head()` and
  `wp_footer()`** or it gets neither the CSS nor the JS snippet, because Keelhob
  outputs them on those two hooks at priority 20.
