# Architecture

Where things live and why, for anyone about to change one of them. [README.md](../README.md) has
the product; [CONTRIBUTING.md](../CONTRIBUTING.md) has the workflow and what "done" means.

No build step, no Composer, no npm — the plugin runs from its own source, and the zip is the source
tree minus the dev files.

## Layout

```
claudpress.php              bootstrap, constants, activation, require_once list
includes/
  class-claudpress.php      orchestrator, settings, upgrade routines
  class-auth.php            bearer token + application password auth, rate limiting
  class-secrets.php         encryption for stored API keys and tokens
  class-mcp-server.php      JSON-RPC 2.0 MCP protocol (initialize/tools/prompts/resources)
  class-rest.php            /wp-json/claudpress/v1/mcp transport, /status, /import
  class-tool-registry.php   tool registration + dispatch
  class-capabilities.php    capability groups, DISABLED_BY_DEFAULT, always-on list
  class-policy.php          guardrails for unattended runs
  class-logger.php          tool-call audit log
  class-memory.php          cross-session memory store
  class-skills.php          skills store + markdown parser + bundled seeding
  class-skills-remote.php   signed skill updates from GitHub releases (Ed25519)
  class-skill-catalog.php   the bundled skill manifest
  class-sandbox.php         persistent AI PHP with crash recovery
  class-snippets.php        the live snippet engine: lint, execute, crash guard
  class-snippet-log.php     snippet lifecycle log (installed, edited, fatal-deactivated)
  class-provider.php        Text-model adapter base + registry
  class-provider-*.php      Anthropic / OpenAI / Google text adapters
  class-image-provider*.php Image-model adapters
  class-transcribe-*.php    Speech-to-text adapters
  class-media-check.php     Media-provider readiness probe
  class-agent-runtime.php   on-site agentic loop (retries, backoff, budget caps)
  class-agent-runs.php      autonomous run history + spend accounting
  class-tasks.php           WP-Cron scheduler (cadence + hour of day)
  class-digest.php          status snapshot
  class-notifier.php        email / webhook / Telegram (outbound)
  class-telegram.php        Telegram facade: the callables hooks and tools bind to
  class-telegram-router.php Command routing, roles, approvals, media absorption
  class-telegram-api.php    Outbound Bot API calls and webhook management
  (was one file: webhook, commands,
                            conversation history, roles, voice, photos, approvals
  class-events.php          push alerts (orders, stock, crashed snippet, updates)
  class-undo.php            one-step undo of a turn's content changes
  class-migration.php       portable bundles (products, menus, widgets)
  class-admin.php           Menu, hooks, notices, and delegates to the four below
  class-admin-screens.php   Connect, Guide and Activity screens
  class-admin-automation.php  The Automation screen and its cards
  class-admin-providers.php   Provider pickers, model fetch, connection tests
  class-admin-settings.php    Saving, secrets, consent, channels
  class-admin-snippets.php  the Snippets page: what the AI installed, and its off switch
  class-admin-skills.php    the Skills page
  class-studio*.php         the Snippets Studio: list, edit, compose (a chat), actions, AI
  class-updater.php         updates from GitHub releases + the anonymous site count
  class-donate.php          the donate button, and the page behind it
  tools/                    core, filesystem, content, site, woocommerce, seo, memory, skills,
                            snippets, elementor, ops, automation, payments, deploy, readiness
skills/                     bundled expert skill playbooks (markdown)
tests/                      dependency-free unit suite, live MCP smoke test, WordPress rig
bin/build-zip.sh            reproducible plugin packaging
```

## The transport

The MCP transport answers a single `application/json` JSON-RPC response per request — the simplest
valid Streamable-HTTP mode, which every MCP client accepts. No Node bridge is required for
HTTP-capable clients.

Authentication is either a bearer token (stored as a hash, shown once at creation) or a WordPress
Application Password sent as `Authorization: Basic base64(user:app_password)`. Ordinary account
passwords are refused: they route around the login-page protections that security and 2FA plugins
watch.

## Two gates in front of every tool

1. **AI abilities**, the master switch. Off, the endpoint still authenticates but exposes only
   read-only introspection.
2. **Capability groups** in `class-capabilities.php`. A disabled group's tools are hidden from the
   tool list, refused if called anyway, and named as off-limits in the session instructions — for
   interactive and autonomous runs alike. Eight groups ship off; the constant
   `DISABLED_BY_DEFAULT` is the single source of that list, deliberately not duplicated into the
   settings defaults, because two lists drift.

Tools registered by third-party plugins through the same registry are allowed by default: refusing
anything unrecognised would silently break them.

## Tests

```bash
php tests/run.php                 # the whole unit suite, ~2,500 assertions
php tests/run.php snippets        # only files matching "snippets"
bash tests/rig/run-all.sh /path/to/wordpress   # against a real WP on SQLite
```

`tests/run.php` covers the decision logic where the costly bugs live — policy guardrails,
capability gating, budget caps, retry classification and backoff, task scheduling, provider
adapters, memory key normalization — against a hand-written WordPress stub in `tests/bootstrap.php`.
It is deliberately not a WordPress test harness: a suite that needs a database and a bootstrap is a
suite nobody runs.

It also runs **whole-plugin integrity checks** that read the source: every registered tool must
belong to a capability group or the always-on list, so a new tool cannot silently escape the
owner's off switch; every file in `includes/` must actually be loaded; the version must match
across the plugin header, the constant and `readme.txt`; and the migration option whitelist must
contain nothing credential-shaped.

Anything a stub cannot honestly answer — path traversal, real WooCommerce behaviour, whether a
snippet really deactivated — lives in `tests/rig/`, which runs against a real WordPress on SQLite
with no Docker and no MySQL. `tests/rig/README.md` builds it from scratch.

A live integration test runs against a real site, read-only by default:

```bash
CLAUDPRESS_URL=https://your-site.com CLAUDPRESS_TOKEN=cp_xxx node tests/live-smoke.mjs
node tests/live-smoke.mjs --url https://your-site.com --token cp_xxx --write
```

## CI

`.github/workflows/ci.yml` lints every PHP file on 8.0–8.4, runs the suite on 8.0/8.3/8.4, builds
the zip and asserts its layout (one top-level `claudpress/`, no dev files). The live smoke test runs
on manual dispatch when the `CLAUDPRESS_URL` / `CLAUDPRESS_TOKEN` secrets are configured.

## Shape problems, and what was done about them

Both of the things previously named here are fixed, and it is worth saying how, because the
method matters more than the result.

`claudpress.php` carried 52 manual `require_once` lines. It now has a small autoloader mapping
class name to file path — no namespaces, no renaming, because a rename would break the
`claudpress_register_tools` seam for anyone who has used it. `tests/unit/test-integrity.php`
asserts every class declared under `includes/` resolves through it to the file that declares it,
so a new file cannot be silently unreachable.

`class-admin.php` was a 2,440-line class rendering four screens; `class-providers.php` held
eleven classes in one file; `class-telegram.php` was 1,322 lines. All three are split. The
largest file in `includes/` is now 818 lines.

The split is deliberately behaviour-neutral. Every method registered as a WordPress hook is
still a method on the original class, delegating to the new one — because what WordPress stores
is a callable, and repointing it would silently break any third-party
`remove_action([$admin, 'ajax_test_media'])`. The new classes are callable directly; nothing has
to go through the facade.
