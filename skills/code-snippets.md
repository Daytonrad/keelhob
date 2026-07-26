---
name: code-snippets
description: Add custom functionality or behavior to the site by writing live code snippets (PHP, CSS, JS, HTML) — hooks, filters, shortcodes, tracking pixels, tweaks, fixes. Use when the task is to change how the site works or looks via code rather than content, or the user says "add code", "add a snippet", "add a hook/filter", "add tracking", "custom function".
enable_agentic: true
enable_prompt: true
---

# Code snippets

Use `snippet_save` to add real code that runs on the live site — the safe alternative to editing `functions.php`. A PHP snippet that fatals is auto-deactivated so the site never breaks.

## Types & where they run
- **php** — runs in WordPress. Do NOT include `<?php` tags. Register hooks/filters/shortcodes or define functions. Location `everywhere` (default) runs on `plugins_loaded`; `admin_only` / `frontend_only` scope it.
- **css** — injected in `<head>` (location `header`) — theme/layout tweaks.
- **js** — injected in `header` or `footer` — behavior, tracking, pixels.
- **html** — output in `header`, `footer`, `before_content`, `after_content`, or via `[claudpress_snippet id=N]`.

## Locations
`everywhere`, `admin_only`, `frontend_only`, `header`, `footer`, `before_content`, `after_content`, `shortcode`, `page_template` (serve raw PHP/HTML as a whole page for `target_id` — the pixel-perfect page mode).

## Conditions
Pass `conditions` to scope loading: `{ "logged_in": true, "front_page": true, "post_types": ["product"], "post_ids": [12,44] }`.

## Workflow
1. Write the smallest snippet that does the job. For PHP, hook the right action/filter rather than running work at import time.
2. `snippet_save … active=true`. Give it a clear `name` and `description`.
3. For PHP, after activating, call `snippet_get` to check `last_error` — if it auto-deactivated, read the error, fix, and re-save.
4. Verify the effect on the front end (load the page) before calling it done.

## Examples
- Tracking pixel: `snippet_type=js location=header` with the pixel code.
- Remove a WooCommerce field: `snippet_type=php location=everywhere` adding a filter on `woocommerce_checkout_fields`.
- Section under every blog post: `snippet_type=html location=after_content` with `conditions={"post_types":["post"]}`.

## Guardrails
- Never call `exit`/`die` in an `everywhere` PHP snippet — it halts the site.
- Prefer snippets over editing theme files; they survive theme updates and auto-recover from fatals.
- Deactivate (don't delete) while iterating so you keep the code. Save reusable snippets' purpose to memory.
