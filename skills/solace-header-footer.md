---
name: solace-header-footer
title: Solace header & footer
description: Build or edit the header/footer of a site running the Solace theme. Use whenever the active theme is Solace (or Solace Extra) and the task touches the header, footer, mega menu, or site chrome. Distilled from a full Solace Site Builder + Customizer reference — read before writing anything, this theme has real traps.
enable_agentic: true
enable_prompt: true
role: procedure
requires: web-design-master
next_skills: website-building, motion-and-interaction
verified_by: pre-launch-audit
---

# Solace header & footer

Solace ships **two independent header/footer systems**. Whichever is active fully overrides the other — writing to the inactive one does nothing, silently.

1. **Customizer / Header-Footer Grid (HFG)** — plain `theme_mod` values (JSON strings + PHP arrays), sitewide only, ships with base Solace. No Claudpress tool writes these directly; use `execute_php` (`set_theme_mod`/`get_theme_mod`) if this system is the one live.
2. **Site Builder** (needs the free **Solace Extra** plugin) — built with Elementor, stored as `solace-sitebuilder` posts with per-page display conditions. Fully readable/writable with the normal `elementor_page` tool once you have the post ID.

## When to use

The active theme is Solace or Solace Extra and the task touches the header, footer, mega menu or site chrome.

## When NOT to use

Not on any other theme. On Elementor the same job is `elementor-page-building`; elsewhere it is `website-building`.

## Step 0 — detect which one is live (every time, before touching anything)
1. Query `solace-sitebuilder` posts (`list_posts post_type=solace-sitebuilder`) and check each one's `_solace_template` (header/footer), `_solace_{header|footer}_status` (enabled?), and `_solace_{header|footer}_conditions` (which pages it targets).
2. Cross-check the live front-end HTML for `id="header-grid"` / `id="footer-grid"` — **strip `<style>` blocks first**, Solace emits matching CSS class names either way, so raw string-matching on classes lies.
3. Most real sites arrive with **Site Builder already active** (from a starter template) — assume that until you've confirmed otherwise, not the reverse.

## Building with the Site Builder (the common case)
- It's an Elementor page under the hood: `elementor_page action=get post_id=<solace-sitebuilder id>` to read, `action=set` to write, exactly like any Elementor page.
- Set the enable flag and conditions via postmeta (`execute_php` if no dedicated field is exposed): `_solace_header_status` / `_solace_footer_status` = enabled, `_solace_header_conditions` / `_solace_footer_conditions` = where it should show (e.g. entire site).
- Build header/footer **first**, before converting page bodies, and verify it live on one real page before doing the rest of the site — a wrong header caught after ten pages wastes the run.

## Three mistakes that break sites
1. **Writing to the wrong system.** Always run Step 0 first.
2. **The doubled-prefix trap.** Some component setting keys repeat their prefix (e.g. `header_social_header_social_setting`, not `header_social_setting`) — copy exact keys from a real export/read rather than guessing the pattern.
3. **Global background mode voids row backgrounds.** If a global header/footer background mode is set, individual row background settings are silently ignored — set the background at the level that's actually rendering.

## Verifying
- After any header/footer write, load the live page and confirm the change actually rendered (not just that the save succeeded) — both systems fail silently on a bad key.
- Clear Elementor's render cache once after all header/footer + page writes are done, not per-write.
- `manage_menu` menus referenced by the header must exist before the header widget points at them.

## When NOT to use this skill
Any other theme, or Solace's page bodies (use `elementor-page-building` for those) — this skill is header/footer/site-chrome only.

## Guardrails
- Don't disable/hide the currently-live header/footer system to "clean up" the other one without asking — the site depends on it.
- Save which system is active for this site with `memory_save key=site.solace_header_system` so future sessions skip re-detecting.

## Routing examples

Phrases that must reach this skill, and one that must not. `bin/lint-skills.php`
checks them against every other skill's description.

- fires: edit the header on my solace theme
- fires: set up the mega menu in solace
- skips: write a blog post about padel courts

## Related skills

- **Requires** — web-design-master. Load these before you start.
- **Next** — website-building, motion-and-interaction. Consider these when this skill is done.
- **Verified by** — pre-launch-audit. The work is not finished until this has run and passed.
