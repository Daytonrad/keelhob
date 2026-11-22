# Shot list

Four screenshots and one video. The README already has the captions written and the file paths
wired up — drop the files into this directory with these exact names and nothing else has to
change.

| File | Screen |
|---|---|
| `01-connect.png` | Keelhob → Connect |
| `02-studio.png` | Keelhob → Snippets (Studio list) |
| `03-capabilities.png` | The Capabilities panel |
| `04-built-site.png` | The front end of a site the agent built |

**01, 02 and 03 are generated**, not taken by hand:

```
bash tests/rig/sync-plugin.sh /c/wp-rig/wordpress
node tests/rig/ui/make-docs-shots.mjs
```

It shoots all three at 1440x900 against the kept rig, collapses the sidebar so another plugin's
menu stays out of frame, seeds the snippet rows, and deletes everything it seeded in a `finally`.
`04-built-site.png` is shot the same way, but against the live demo store instead of the rig, and
**logged out** so no admin bar is in frame:

```
node tests/rig/ui/make-built-site-shot.mjs      # https://test.keelhob.com, 1440x900, viewport only
```

It asserts there is no `#wpadminbar` and no `logged-in` body class before it saves, because a shot
with the admin bar in it is the one mistake that is invisible until the README is public. The hero
is the frame to use: the page is a scroll-driven build, so scrolling to the product row catches a
pinned section mid-animation and the shot comes out with a black band across the top.

The video cannot be generated — it needs a person recording.

## Before you start — every shot

Set up a throwaway site for this. Not your own site, not a client's.

- **Fresh WordPress**, a demo domain or a local install with a clean-looking URL.
- **Browser at 1440×900**, no other tabs visible, no bookmarks bar, no extension icons.
- **Light mode**, default WordPress admin colour scheme. Whatever you pick, use it in all four.
- **PNG**, full-width of the content area. Crop out the browser chrome and the OS bar.
- Collapse or crop the WordPress admin sidebar only if it clutters the shot — but keep it
  consistent across all four so they read as one set.

### Must NOT be visible, in any shot or the video

- **Any real token or key.** As of 2.0.0 the Connect page does NOT show the bearer token: it is
  stored hashed, the field renders as dots and the copy command carries the literal
  `YOUR_TOKEN_HERE`. So there is nothing to redact in `01-connect.png`. This still applies to
  Anthropic/OpenAI/Google keys, Telegram bot tokens and the Telegram webhook secret, which the
  Automation screen does show.
- **Real customer data** — names, email addresses, order addresses, phone numbers. Use WooCommerce
  sample data or invented customers.
- **Your personal email address**, in the admin bar, the profile menu, the "Howdy, …" greeting or a
  settings field. Set the account up as something like `demo@example.com`.
- **Your real domain**, if it is a site you care about, and any staging URL that resolves to a real
  business.
- **Browser autofill dropdowns, notification toasts, password managers, other people's names** in
  comments or users.

Check every shot at full size before you commit it. A token in a screenshot is a compromised site,
and a screenshot is forever once it is in git history.

---

## `01-connect.png` — Connect

**Screen:** Keelhob → Connect.

**State:** the command block as the visual centre. No token needs generating: the command shows
`YOUR_TOKEN_HERE` whether or not one exists.

**Must be visible:** the site URL in the command, the `--transport http` flag, the full endpoint
path, the copy button, and enough of the surrounding page that it reads as a WordPress admin
screen.

**Nice to have:** the "Other clients" JSON block partly visible below it, so people can see there
is more than one way in.

---

## `02-studio.png` — Snippets Studio

**Screen:** Keelhob → Snippets.

**State:** at least five snippets, a mix of types, and at least one **inactive** row so the status
column is doing visible work. Snippets from a real build are better than seeded ones; the shot in
the repo today is seeded, and says so below.

**Must be visible:** the Name / Description / Type / Status columns and the per-row
activate-deactivate controls.

**There is no expanded row.** An earlier version of this list asked for one showing the
purpose/impact panel. That panel is on the Edit screen, not inline in the list, so the shot cannot
show it. Do not go looking for a disclosure control that is not there.

**Ideally:** one row carrying a real `last_error` from a snippet that crashed and auto-deactivated
itself. That single row proves the crash guard is real and is worth more than a tidy list.

---

## `03-capabilities.png` — Capabilities

**Screen:** the Capabilities panel.

**State:** **defaults, untouched.** The whole point of this shot is showing the ten groups that
ship off — `abilities`, `sitecare_fix`, `store_orders`, `payments`, `shipping`, `tax`, `users`,
`updates`, `comms`, `deploy` — so do not switch anything on first.

"Untouched" means the `keelhob_capabilities` option is **absent**, not that it holds the default
values. Saving the form once writes an explicit value for every group, and the screen then looks
identical while no longer being the default.

**Must be visible:** enough rows that both on and off toggles are in frame, at least four of the
eight off groups with their labels, and one of the hover/info descriptions open if the layout
allows it without covering the toggles.

**Must NOT be visible:** a site where someone has already enabled everything. Re-install on a clean
site rather than resetting by hand.

---

## `04-built-site.png` — the output

**Screen:** the front end, not the admin.

**State:** a real store the agent built from one prompt, in a browser with no admin bar (log out or
use a private window). Scrolled to the strongest part of the page — usually the hero plus the first
product row, so both design and commerce are in one frame.

**Must be visible:** real product imagery, real prices, a working navigation, and a layout that
does not look like a default theme.

**Must NOT be visible:** placeholder text, broken images, "Sample Page", the WordPress admin bar,
or a cookie banner covering the hero.

---

## The demo video

**Three minutes. One take. Unedited. No cuts, no speed-ups, no jump cuts.** The value of this video
is entirely that it is not edited — anyone can fake a fast build with a cut.

**Recipe:**

1. Start on a **fresh WordPress install** with nothing on it. Show that: the plugins list, the
   empty pages list, a default theme.
2. **Install the zip** — Plugins → Add New → Upload Plugin → Activate. Real time.
3. **Connect an agent** — open Keelhob → Connect, copy the command, paste it into a terminal,
   start a session, and show the tool list arriving.
4. **One prompt.** Say what you want in a sentence, the way a real person would. No prompt
   engineering, no pasted brief.
5. **Let it run.** Do not narrate over the waiting. If it takes ninety seconds, the video shows
   ninety seconds. If it makes a mistake and corrects itself, leave that in — it is the most
   credible thing in the whole video.
6. **Open the finished site** in a browser at the end. Nothing else.

**Recording notes:** 1080p or better, system audio off unless you are talking, no music, no
titles beyond a plain first frame if you want one. Same "must not be visible" list as the
screenshots — cover or blur the token before uploading, and regenerate it afterwards regardless.

Put it wherever it is easiest to keep (YouTube unlisted or public, or a release asset), then
replace the demo block at the top of [README.md](../../README.md) — it is marked
`<!-- TODO(owner): record -->` so it is easy to find.
