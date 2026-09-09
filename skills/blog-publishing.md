---
name: blog-publishing
description: Write and publish blog posts and articles end to end — draft the content, structure it with headings, add images, set categories and tags, and optimize it for search before publishing. Use whenever the task is to write, draft, publish, or schedule a blog post or article.
enable_agentic: true
enable_prompt: true
---

# Blog publishing

Take a topic to a published, search-ready post.

## Before writing
- Check existing content: `list_posts search=<topic>` so you don't duplicate or cannibalize an existing post — link to it instead.
- Pull the site's voice/keyword conventions with `memory_list`. If none exist and the user has a preference, save it with `memory_save`.

## Draft
Write genuinely useful, specific content — not filler.
- **Structure**: one H1 (the title), then H2 sections, H3s where needed. Short paragraphs. Lists and a summary/FAQ where they help.
- **Length**: match the topic; most ranking posts run 800–1,800 words. Depth over padding.
- **Format**: Gutenberg block markup or clean HTML in `content`. Include a compelling first paragraph that states the payoff and contains the focus keyword.
- **Excerpt**: write a 140–160 char excerpt; don't let WordPress auto-truncate.

## Media
- Add a featured image (`featured_image` on `create_post`, a URL or media ID). Add in-body images where they aid understanding, each with descriptive alt text.
- Use `upload_media` for images sourced from a URL or generated data.

## Publish
1. `create_post post_type=post` with title, content, excerpt, `categories`, `tags`, `featured_image`, and `status` (`draft` to review, `publish` to go live, `future` + `date` to schedule).
2. Immediately run the SEO pass: set `seo_meta` (title + description + focus keyword) and add `Article`/`BlogPosting` schema via `seo_schema`. Then `seo_analyze` and fix anything failing.
3. Add at least one internal link to a related post and, where natural, one authoritative external link.

## After publishing
- Report the live URL and edit link.
- If it's a cornerstone post, `seo_sitemap action=ping`.
- Offer to schedule a short social/summary follow-up only if the user wants it — don't assume.

## Guardrails
- Never publish straight to `publish` if the user asked for a draft or review.
- Don't invent facts, statistics, or quotes. If a claim needs a source and you don't have one, say so.

## Related skills

A post is not finished when the words are written.

- **`seo-optimization`** — meta, schema, the answer-first opening and the FAQ block that
  gets a post quoted by AI search. Load it before publishing, not after.
- **`web-design-master`** — the post template itself: measure, type scale, image
  treatment. A well-written post in a bad template still looks cheap.
- **`motion-and-interaction`** — if the post has a hero or anything interactive.
- **`build-standard`** — what "published" actually requires.
