# Rebrand plan — Claudpress → Quasara

Date: 2026-08-19. Status: name chosen, nothing bought or renamed yet.

## Why rebrand

"Claudpress" contains "Claud", which reads as Anthropic's Claude. That is a trademark risk
and it locks the product to one AI client, even though Claudpress speaks MCP to any client.
WordPress.org also forbids "WordPress" in a plugin name ("WP" is allowed).

## The bar to clear: Novamira

Novamira (novamira.ai) is the direct competitor — same idea, MCP server plugin giving AI
agents full control of a WordPress site. Their name works because it is:

- invented, so search results belong only to them
- built from real roots: *nova* (a star that flares) + *mira* (a named variable star)
- free of "WP" and "press", so it sounds like a company, not a plugin
- three syllables, easy to say, and `.ai` was available

Their positioning line: *"Your AI agent builds inside your WordPress."*
They ship a Pro tier, a CLI, and a Discord.

Lesson learned: plugin-style names (Presto, Ghostshell, Vibepress, Sudopress) sound smaller
than Novamira. Only a company-class invented name competes.

## Chosen name: Quasara

A quasar is the brightest object in the universe — far brighter than a nova. The name
attacks Novamira on their own astronomy ladder and the tagline writes itself:

> A nova flares once. A quasar never stops.

### Availability (checked 2026-08-19; trademarks NOT checked)

| Asset | State |
|---|---|
| quasara.com | Taken but **for sale** on atom.com — **$5,000** one-time, or **$500 down + $216/mo x 24** (total $5,684). Ownership verified, escrow-held. |
| quasara.ai | Registered, no live site |
| quasara.io / .app / .sh | **Free** |
| wordpress.org slug `quasara` | **Free** |
| github.com/quasara | Taken — use an org like `quasara-wp` |
| Anyone trading as "Quasara" | **No** — no live company found |

### Recommended path

1. Register **quasara.io** now (~$40/yr) and reserve the wp.org slug `quasara`.
2. Launch on quasara.io. `.io` is normal for developer tools, so it costs no credibility.
3. Buy quasara.com later, once there is revenue. Risk of losing it is real but low for an
   invented word.
4. Run a real trademark search (TESS / EUIPO) before spending money on the .com.

## Shortlist considered

Ranked at the time of the decision, with why each fell short.

| Name | Verdict |
|---|---|
| **Quasara** | **Chosen.** Best meaning, no live user, .com buyable. |
| Tessara | Dropped — tessara.ai is an active AI brand. Head-on clash. |
| Nyxara | Dropped — nyxara.com is a live telehealth company. .com gone for good. |
| Aranea | Weak — a Latin dictionary word (spider/web). Hard to own, hard to search. |
| Praxora | **Best fallback.** Nothing live anywhere; .io/.dev/.sh free. Meaning is "theory turned into action". Colder, less magical. |
| Quasira | Rejected — checked on request. Loses the "quasar" meaning entirely, and quasira.com belongs to a live Italian safety consultancy, so the .com is permanently unavailable. One letter from Quasara, so it invites typos. |

Earlier plugin-class ideas, kept only for reference: Presto, Sitegeist, Vibepress,
Ghostshell, Sudopress, Synthpress, Warpstack, Wintermute.
Deliberately avoided: Lumira, Novara and similar — too close to Novamira, which would make
the product look like a copy instead of a challenger.

## Open work

- [ ] Trademark search for "Quasara" (software / SaaS classes)
- [ ] Register quasara.io + reserve wp.org slug
- [ ] Decide the GitHub org name
- [ ] Rename the plugin: folder, main file, text domain, option keys, MCP server label,
      REST namespace (`/wp-json/claudpress/v1/mcp`), admin menu, docs, README
- [ ] Plan a migration path so existing installs keep their tokens, memory, and snippets
