---
name: elementor-page-building
title: Elementor page building
description: Build or edit fully editable pages, headers, and footers with Elementor (free or Pro, incl. Xpro Addons). Use whenever the task is to build/edit an Elementor page, header, footer, or template, or the site's builder is Elementor and the user wants client-editable pages.
enable_agentic: true
enable_prompt: true
role: procedure
surface: any
requires: confirm-before-building, web-design-master
next_skills: motion-and-interaction, seo-optimization
verified_by: pre-launch-audit
---

# Elementor page building

Build **client-editable** pages with Elementor via the `elementor_page`, `elementor_settings`, and `elementor_kit` tools. (For maximum-fidelity pages the client won't edit, prefer a custom-coded page template — see the `website-building` skill.)

## When to use

The builder is Elementor and the task touches an Elementor page, header, footer or template — building one, rebuilding a homepage or landing page in it, editing one, or making one editable by the owner afterwards.

## When NOT to use

Not when the site's builder is something else. On a Solace theme the chrome belongs to `solace-header-footer`; a page built in code belongs to `website-building`.

**Before the first write.** `confirm-before-building` is required above. An Elementor layout built against the wrong reading of the request is a layout the owner has to read section by section before trusting any of it. Say your reading back, ask what would change the build in one batch, and name the business risks in your plan.

## One-time prep (do this first)
1. Confirm Elementor is active (`get_site_info`). If not: `manage_plugin action=install plugin=elementor` then `action=activate plugin=elementor/elementor.php`.
2. `elementor_settings action=prepare_for_ai` — this enables **Container (flexbox)** and disables the **Atomic editor**. This is essential: build with flexbox Containers, never the deprecated Section/Column widgets, and the Atomic/v4 editor confuses programmatic builds.
3. For custom headers/footers on Elementor **free**, use **Xpro Theme Builder** (already available on this site), or if the active theme is **Solace**, use the `solace-header-footer` skill instead — it has its own Site Builder with real traps. Elementor Pro's Theme Builder also works if `elementor_settings` reports `pro_active: true`.

## The data model
Elementor stores a page as `_elementor_data`: a JSON tree of elements. Each element is:

```json
{ "id": "a1b2c3d", "elType": "container", "settings": { ... }, "elements": [ ... ] }
```

- `elType`: `container` (flexbox — your default building block), or `widget` (with `"widgetType": "heading" | "text-editor" | "button" | "image" | ...`).
- `id`: a unique 7-char hex-ish string per element — generate one for every element.
- `settings`: element options (typography, spacing, colors, flex direction, etc.).
- Nest children in `elements`.

Read an existing page first with `elementor_page action=get` to learn the exact settings keys the current theme/widgets use, then mirror that shape. When unsure of a widget's settings, build a minimal version in the editor mentally, or inspect another page.

## Building
- `elementor_page action=create title="…" data=[…]` for a new page, or `action=set post_id=… data=[…]` to replace a page's tree.
- Structure top-down: a `container` per section (hero, features, testimonials, CTA, footer), each with flex settings and child widgets.
- After writing, Elementor CSS is regenerated automatically. Give the user the `edit_link` so they can fine-tune visually.

## Global design (brand kit)
Apply site-wide colors/fonts once with `elementor_kit action=set` (merges into the active kit's `_elementor_page_settings`: `system_colors`, `custom_colors`, `system_typography`). Every Elementor page inherits it.

- **GOTCHA: the kit's global link color beats a bare class selector.** The kit emits `.elementor a { color: … }`, which outranks a plain class like `.kh-btn` — a copper button can end up with teal underlined text inside it. Prefix such selectors with the parent: `.elementor a.kh-btn`, not `.kh-btn`.

- **GOTCHA: `__globals__` beats the literal value, so raw `_elementor_data` lies.** A widget can hold `settings.title_color: "#8A8F93"` *and* `settings.__globals__.title_color: "globals/colors?id=cpmuted"`. The global wins; the hex is dead weight. Read the JSON and a fully tokenised site looks like it has no tokens at all — which sends you off editing hexes that change nothing.
  - To learn a page's **real** colours, read the generated `uploads/elementor/css/post-<id>.css`, or read the computed style in a browser.
  - To **change** a colour properly, set `__globals__[key]`, and update the literal to match so the fallback is not stale.
  - To **count** how hard-coded a site really is, walk the tree and count only settings whose `__globals__[key]` is empty. On one real site that turned an apparent 600 stray hexes into 311 genuine ones.

- **GOTCHA: Elementor's global colours are declared on `body`, not `:root`.** They are emitted on the `.elementor-kit-<id>` class, which WordPress puts on `<body>`. Any custom property of your own that reads one must be declared on `body` too. Written as `:root{--brand-bg:var(--e-global-color-accent,#0E0B09)}` the Elementor variable is out of scope, so it silently takes the fallback for ever — and **it looks perfect** as long as the fallback matches. Change the palette a year later and half the site does not move.
  - **Prove the bridge, never assume it.** In the browser: `document.body.style.setProperty('--e-global-color-accent','#FF00FF')`, read your own property back, confirm it followed, then `removeProperty`. Nothing on the server is touched, so there is nothing to undo. One call turns a bug that hides for a year into one you catch in a second.

- **GOTCHA: the kit holds colours of its own, and no page sweep will find them.** `_elementor_page_settings` on the active kit (`get_option('elementor_active_kit')`) carries `body_background_color`, `link_normal_color` and `link_hover_color` as plain values, outside every page's `_elementor_data`. Sweep all the pages clean and the background stubbornly stays the old colour. They accept `__globals__` entries like any widget. **Check the kit last, every time.**

- **Writing `_elementor_data` by hand: use `$wpdb->update()`, never `update_post_meta()`.** `update_post_meta()` runs `wp_unslash()` internally and eats the JSON's own `\"` escapes — the write reports success and the page renders blank. See the meta-writer GOTCHA in `site-safety`. Prefer the `elementor_page` tool, which handles this; drop to raw SQL only for a bulk rewrite it cannot express.

## Widget settings — read `get_controls()` first
Wrong setting keys are written, saved, and reported successful, then do nothing — Elementor validates none of it. Before setting a widget's `settings`, read an existing instance of that widget with `action=get` (or check the widget's controls) rather than guessing names from the label in the editor UI. Real example from a live build: the `xpro-horizontal-menu` widget's controls are `nav_menu` and `menu_text_color` — the obvious guesses `menu` and `menu_color` are accepted and produce a menu with default dark-on-light colors on a near-black header, with nothing in the response to say so.

## Containers: a "grid" of full-width children is not a grid
A container child with no explicit width is **100% wide**, so setting `flex_direction: row` and `flex_wrap: wrap` on the parent produces stacked full-width blocks, not columns. It looks deliberate, so nobody notices — on one real build the capability grid, the docs answers, the pricing cards and two sponsor lists were *all* stacking and every one of them looked fine.

Set `width` as a percentage on each **child**, with `width_tablet` and `width_mobile` back to `100%` so it stacks on a phone. There is no parent-level control that does this, which is the trap: the parent reads correct.

Make the percentage **under** `100/N`. Elementor adds the flex gap on top of the width, so two children at `50%` with a `64px` gap wrap to one per row. Use roughly **47%** for two columns, **30.5%** for three, **22%** for four.

## Page title
Disable a page's title through Elementor's own `hide_title` page setting, not with CSS. Hiding an `<h1>` with CSS leaves it in the rendered document ahead of your real headline — two H1s on the page, and the wrong one first.

## Xpro Theme Builder (headers & footers)
Xpro Theme Builder layouts are their own post type (`xpro-themer`) with display-rule meta. Three of its settings fail silently if you get them wrong, because Xpro publishes the layout either way — it just never finds or renders it.

- **The template-type meta must match the SETTING name, not the label shown in the editor.** `xpro_theme_builder_template_type` must be stored as `type_header` or `type_footer` — not `header` or `footer`. Xpro looks it up with `Xpro_Theme_Builder_Main::get_settings('type_header')`, which compares the stored meta against that exact string. A layout stored as `header` is published, has a valid display rule, and is never found by anything. This is the single most expensive trap in Xpro — if a header/footer layout "does nothing," check this meta value first.
- **`xpro_theme_builder_target_include_locations` must be `['basic-global']`** for a layout meant to appear site-wide.
- **Xpro needs a classic theme.** It hooks into `get_header()`/`get_footer()` — a block theme (Twenty Twenty-Five, and every default WordPress theme since Twenty Twenty-Two) never calls either function, so a correctly-built header/footer layout simply never renders. Check the active theme with `get_site_info` before building one; **Hello Elementor** is the safe choice.
- **`xpro-site-logo` can render nothing even with correct settings and a custom logo set.** If it does, use Elementor's core `image` widget instead — it works reliably. The rule in general: the *layout* has to be an Xpro Theme Builder layout, but the widgets placed inside it can be any Elementor widget, and a core widget is often more predictable than an Xpro one.
- **Xpro renders the FIRST published layout matching a template type**, not the most recent or most specific. A duplicate header layout isn't just untidy — it's a coin toss which one actually shows up. When rebuilding a header or footer, update the existing `xpro-themer` post in place rather than creating a new one.

## Guardrails
- Always `action=get` before `action=set` so you don't wipe a page you meant to extend.
- Keep element `id`s unique; duplicate ids break the editor.
- **Verify by rendering, never by reading the JSON back.** A malformed-JSON check alone isn't enough — every trap above produces a tree that looks completely correct in `_elementor_data`. Load the page and check the actual computed result (does the menu have the right colors? does the header appear at all? is there one H1?).
- Save the site's chosen builder and any recurring section patterns with `memory_save`.

## Routing examples

Phrases that must reach this skill, and one that must not. `bin/lint-skills.php`
checks them against every other skill's description.

- fires: rebuild the homepage in elementor
- fires: edit this elementor template
- skips: write a blog post about padel courts

## Related skills

- **Requires** — web-design-master. Load these before you start.
- **Next** — motion-and-interaction, seo-optimization. Consider these when this skill is done.
- **Verified by** — pre-launch-audit. The work is not finished until this has run and passed.
