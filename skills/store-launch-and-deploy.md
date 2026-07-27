---
name: store-launch-and-deploy
description: Take a WooCommerce store from empty to able-to-take-real-orders (payments, shipping, tax), and ship changes from a staging site to production. Use when setting up checkout, enabling payment methods, configuring shipping/tax, launching a store, or deploying/migrating a site staging→production.
enable_agentic: true
enable_prompt: true
---

# Store launch & deploy

## Part A — make checkout work (payments · shipping · tax)
A store can't take orders until all three are configured. Confirm currency/base country first (`wc_settings action=get`).

1. **Payments** (`wc_payments`):
   - `action=list` to see gateway ids and enabled state.
   - Fast start with no processor: `action=enable gateway=cod` (cash on delivery) and/or `gateway=bacs` (bank transfer — then `action=configure` its account details).
   - Card payments: enable and `configure` the relevant gateway (e.g. Stripe: set `publishable_key`, `secret_key`, `testmode`; PayPal: set the email/client id). Ask the owner for keys — never invent them. Use `testmode: "yes"` until they've done a live test.
2. **Shipping** (`wc_shipping`):
   - Create a zone per region you ship to: `action=create_zone zone_name="UAE" regions=["AE"]`.
   - Add a method to each zone: `action=add_method zone_id=<id> method=flat_rate cost="15" title="Standard"` (or `free_shipping`, `local_pickup`).
   - Add `create_class` shipping classes if products need different rates.
3. **Tax** (`wc_tax`): `action=enable`, then `action=add_rate country=AE rate="5.0" name="VAT"` for each jurisdiction.
4. **Verify**: read each back (`list`/`list_zones`/`list_rates`) and tell the owner exactly what a customer will see and pay at checkout.

Guardrails: payment keys are secrets — set them, then confirm they're stored (the tool redacts them in its response). Don't switch a gateway out of test mode without the owner's explicit go-ahead.

## Part B — staging → production deploy
Build and validate on staging, then ship to the live site. Never edit production directly.

1. On **staging**, build/verify the work (pages, products, snippets, design).
2. Export what you changed: `export_bundle types=["pages","products","snippets","skills","memory","options"]`. Secrets are never exported.
3. Ship it, either:
   - **One call** (preferred): the owner sets the production URL + token in Claudpress → Settings, then `deploy_push types=[...]`. It exports here and imports on production in one step. **Confirm with the owner before pushing to a live site.**
   - **Manual**: take the bundle from `export_bundle` and run `import_bundle` on the production site (or hand it to the agent connected there).
4. Imports are **idempotent** — matched by slug/SKU/name/key, so re-deploying updates instead of duplicating. Featured images are sideloaded.
5. After deploy, spot-check production: load a migrated page/product and confirm it renders, then report what changed.

Guardrails: production is real. Announce exactly which entities you'll push and get a yes. Prefer pushing a narrow set (the things you actually changed) over "everything". Keep production abilities OFF except during a supervised deploy window.
