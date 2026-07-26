---
name: seo-optimization
description: Optimize pages, posts, and products for search — set SEO titles and meta descriptions, target keywords, add structured data, fix on-page issues, manage redirects and sitemaps. Use whenever the task involves SEO, ranking, search visibility, meta tags, schema, keywords, or "make this rank".
enable_agentic: true
enable_prompt: true
---

# SEO optimization

Improve organic search performance using the `seo_*` tools. They write to whatever SEO plugin is active (Yoast, Rank Math, AIOSEO, SEOPress) and fall back to Claudpress's own head injection when none is present — so your changes apply either way.

## Workflow for a single URL
1. `seo_analyze post_id=… focus_keyword=…` — get the on-page report (title/meta length, keyword placement, density, word count, headings, links, alt coverage, a 0–100 score).
2. Fix the failing checks, in priority order:
   - **SEO title** (`seo_meta action=set title=…`): 30–60 chars, keyword near the front, one clear promise. Do not stuff.
   - **Meta description** (`description=…`): 120–160 chars, includes the keyword naturally, ends with a reason to click.
   - **Keyword placement**: ensure the focus keyword appears in the title, URL slug, first paragraph, and at least one H2. Edit the post with `update_post` when the body needs it.
   - **Density**: keep 0.5–2.5%. If higher, rewrite for readability, don't delete mechanically.
   - **Images**: every content image needs descriptive `alt` text.
   - **Internal links**: add at least one relevant internal link.
3. Re-run `seo_analyze` and report the before/after score.

## Structured data
Add JSON-LD with `seo_schema action=set`. Match the type to the page: `Article`/`BlogPosting` for posts, `Product` (+ `offers`, `aggregateRating`) for products, `FAQPage` for FAQs, `LocalBusiness`/`Organization` site-wide, `BreadcrumbList` for navigation. Provide real values from the page, never placeholders.

## Titles that win clicks
Front-load the keyword, add a differentiator (year, number, benefit, location), stay under 60 chars so it doesn't truncate. Every page needs a *unique* title and description — never duplicate across pages.

## Technical
- Redirects: when a slug changes, add a 301 with `seo_redirect action=add from=/old to=/new` so link equity and users survive.
- Sitemap: `seo_sitemap action=get_sitemap` for the URL; `action=ping` after a big content change.
- robots.txt: `seo_sitemap action=set_robots` for extra rules — never block resources search engines need to render the page.

## Guardrails
- Never set `noindex` on a page the user wants ranked, and never bulk-noindex without explicit confirmation.
- Write, don't overwrite blindly: read current SEO meta before replacing it, and keep the human's wording where it already works.
- Save the site's keyword targets and title formula to `memory_save` so future content stays on-strategy.
