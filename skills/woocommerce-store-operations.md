---
name: woocommerce-store-operations
description: Run the WooCommerce shop end to end — add or edit products, manage stock and pricing, process and fulfil orders, create coupons and sales, and read sales reports. Use whenever the task involves the store, products, inventory, orders, customers, discounts, or shop revenue.
enable_agentic: true
enable_prompt: true
---

# WooCommerce store operations

You are operating a live WooCommerce store. Prefer the dedicated `wc_*` tools; drop to `execute_php` only for things they do not cover (WooCommerce class APIs are all available there).

## Orientation first
Before acting, call `get_site_info` (confirms WooCommerce + currency) and, for anything financial, `wc_settings action=get`. Never assume currency, tax mode, or country.

## Products
- Create with `wc_product action=create`. Always set: `name`, `regular_price`, a `short_description` (1–2 sentences, benefit-led), a fuller `description`, at least one image URL, and one or more `categories`. Set `sku` when the user has a scheme; if a duplicate-SKU error returns, ask rather than guessing.
- Pricing: `sale_price` must be below `regular_price`. To run a timed sale prefer a coupon or scheduled price, and tell the user the sale has no end date unless they set one.
- Stock: set `manage_stock=true` + `stock_quantity` for tracked items; otherwise set `stock_status`.
- Variable products: create the product `type=variable` with variation `attributes`, then add variations via `execute_php` using `WC_Product_Variation` (set attributes, price, stock, save).

## Orders
- List with `wc_order action=list` (filter by `status`). Read one with `action=get` before changing it.
- Advance fulfilment with `action=update_status` (processing → completed). Add `add_note` for the customer when you ship.
- Refunds are real money and are hard to reverse: confirm the amount and the order with the user before `action=refund`. Never refund on your own initiative.

## Coupons & promotions
- `wc_coupon action=create`: set `code` (uppercase, memorable), `discount_type`, `amount`, and where relevant `expiry_date`, `usage_limit`, `minimum_amount`. Report the final code back.

## Reporting
- `wc_report` for revenue, order count, AOV, and top sellers over a date range. Lead with the headline number and the date range, then the top sellers. Do not present unfinished/cancelled orders as revenue.

## Guardrails
- Destructive or financial actions (delete product, refund, bulk price change, change base country/currency): state exactly what you will do and get a yes first.
- After a batch change, verify by reading one affected record back and report what changed.
- Persist store conventions (brand voice, category structure, SKU scheme, price rounding) with `memory_save` so later sessions stay consistent.
