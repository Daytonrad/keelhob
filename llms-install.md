# Installing Keelhob — written for an AI agent

This file is for a coding agent setting Keelhob up on someone's behalf. A human
should read [README.md](README.md) and [the manual](https://keelhob.com/docs)
instead.

Keelhob is a **WordPress plugin that is itself an MCP server**. There is no
npm package, no Docker image, and no hosted endpoint. The server runs inside the
user's own WordPress site, so its address is different on every install:

```
https://<their-site>/wp-json/keelhob/v1/mcp
```

Transport is **streamable HTTP**. Authentication is a bearer token.

---

## What you cannot do for them

**Steps 1 and 2 need a human.** A plugin is installed through the WordPress
admin, which needs their login. Do not ask for their password, and do not try to
automate the upload. Give them the steps and wait.

---

## Step 1 — the human installs the plugin

Tell them, in these words:

1. Download `keelhob.zip` from
   <https://github.com/calflint/keelhob/releases/latest>
2. In WordPress: **Plugins → Add New → Upload Plugin** → choose the zip →
   **Install Now** → **Activate**
3. Open **Keelhob** in the admin sidebar

Requirements: **WordPress 6.3 or newer, PHP 8.0 or newer.** WooCommerce and an
SEO plugin (Yoast, Rank Math, AIOSEO, SEOPress) are detected if present. Neither
is required.

## Step 2 — the human copies the connect command

The **Keelhob → Connect** screen prints a ready-made command with their real
site URL and a real token already in it. Ask them to paste that command to you,
or to run it themselves.

The command looks like this:

```bash
claude mcp add keelhob-<sitename> --transport http \
  https://<their-site>/wp-json/keelhob/v1/mcp \
  --header "Authorization: Bearer kh_xxxxxxxx"
```

For Claude Desktop, Cursor or Codex, the same screen prints a JSON `mcpServers`
block to paste into the client's config.

**The token is a secret.** Do not print it back to them, do not write it into a
file in their project, and do not include it in a commit, a log or a bug report.

## Step 3 — confirm it is connected

Start a fresh session so the client reloads its server list, then call
`tools/list`. A healthy install answers with the full tool set.

A good first thing to try, because it reads and changes nothing:

> list the plugins on this site

---

## What you get

**About 70 tools in all**, covering content and media, WooCommerce, SEO,
Elementor pages, the filesystem, live code snippets, raw PHP and SQL, plus a
read-only site grade. A fresh install lists roughly **60** of them, because ten
capability groups ship switched off — see below. `resources/list` and
`prompts/list` both answer with real content, not an error.

**Call `memory_list` first, once, at the start of a session.** It holds that
site's operating context — build mode, conventions, decisions other agents
already made. Ignoring it is how two agents undo each other's work.

---

## The one thing to understand before you change anything

**This is a live website, and several tools have no undo.**

- Read before you write. Never overwrite a post, option, file or product you
  have not read first.
- Prefer the specific tool (`create_post`, `wc_product`, `seo_meta`) over the
  sharp one (`execute_php`, `execute_sql`).
- A saved snippet runs for real visitors on every page load. Keep each one small
  and single-purpose, and check the site still renders afterwards.
- `"success": true` is not verification. Read the thing back.
- **Never return a 4xx or 5xx from the front end**, not even for a coming-soon
  page. Managed hosts read a `503` on the home page as a dead server and stop
  routing traffic — taking `wp-admin` and this MCP endpoint down with it, so you
  could not undo your own change.

## Capabilities are off by default, on purpose

Ten groups ship **disabled**: `abilities`, `sitecare_fix`, `store_orders`,
`payments`, `shipping`, `tax`, `users`, `updates`, `comms`, `deploy`.

If a tool refuses, that is the fence doing its job. **Ask the owner to switch
the group on in WP Admin → Keelhob.** Do not route around it with `execute_php`.

## If a snippet breaks the site

Tell the owner to add this to `wp-config.php`, above the *"That's all, stop
editing"* line:

```php
define('KEELHOB_SNIPPETS_OFF', true);
```

Every snippet stops at once. Nothing is deleted. Removing the line switches them
back on. This works without a loading site and without a database client, which
is the whole point of it.

---

## Links

- Source and releases — <https://github.com/calflint/keelhob>
- The manual, one page — <https://keelhob.com/docs>
- How risky this is, stated plainly — <https://keelhob.com/docs/risk>
- Licence — GPL-2.0-or-later
