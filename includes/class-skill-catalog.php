<?php
/**
 * The skill catalogue: what exists, what it does, and which tier it belongs to.
 *
 * This is deliberately separate from the skill BODIES. Descriptions ship in the
 * free plugin so the Skills screen can show someone exactly what Pro would give
 * them - that is the most honest upsell there is, and it costs nothing to give
 * away. The bodies are the product and ship only where they are licensed.
 *
 * Keep this in step with skills/ when a skill is added or renamed. The test
 * suite fails if the two drift, which is the point.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Claudpress_Skill_Catalog
{
    /**
     * @return array<string, array{title:string, description:string, tier:string}>
     */
    public static function all(): array
    {
        return [
            'blog-publishing' => [
                'title'       => 'Blog publishing',
                'description' => 'Write and publish blog posts and articles end to end — draft the content, structure it with headings, add images, set categories and tags, and optimize it for search before publishing.',
            ],
            'build-standard' => [
                'title'       => 'Build standard',
                'description' => 'The Definition of Done for any build work on this site — what "finished" requires before a page, a site, or a launch can be reported as complete.',
            ],
            'code-snippets' => [
                'title'       => 'Code snippets',
                'description' => 'Add custom functionality or behavior to the site by writing live code snippets (PHP, CSS, JS, HTML) — hooks, filters, shortcodes, tracking pixels, tweaks, fixes.',
            ],
            'elementor-page-building' => [
                'title'       => 'Elementor page building',
                'description' => 'Build or edit fully editable pages, headers, and footers with Elementor (free or Pro, incl.',
            ],
            'pre-launch-audit' => [
                'title'       => 'Pre-launch audit',
                'description' => 'Audit a WordPress or WooCommerce site before it goes live and produce a scored report — design, responsiveness, performance, functionality, accessibility, security, SEO and conversion.',
            ],
            'security-standard' => [
                'title'       => 'Security standard',
                'description' => 'Harden and audit a WordPress/WooCommerce site\'s security — 20 automatic checks, a 7-question owner-decision intake, WooCommerce/card-skimming specifics, and a compromise-detection sweep to run before building on a site you did not build.',
            ],
            'seo-optimization' => [
                'title'       => 'SEO + GEO optimization',
                'description' => 'Required build phase, not an optional pass — meta title/description, Open Graph/Twitter cards, JSON-LD schema, sitemap, robots.txt, and llms.txt for every page, verified in the rendered HTML.',
            ],
            'site-build-kickoff' => [
                'title'       => 'Site build kickoff',
                'description' => 'The FIRST skill to load whenever someone asks to build a WordPress site from scratch — "build me a website", "make my site", "I need a website for my business", or any brand-new-site request with no detailed brief attached.',
            ],
            'site-safety' => [
                'title'       => 'Site safety',
                'description' => 'Baseline safety and verification rules for making changes on this WordPress site.',
            ],
            'skill-creator' => [
                'title'       => 'Skill creator',
                'description' => 'Create, refine, or improve a Claudpress skill.',
            ],
            'solace-header-footer' => [
                'title'       => 'Solace header & footer',
                'description' => 'Build or edit the header/footer of a site running the Solace theme.',
            ],
            'store-launch-and-deploy' => [
                'title'       => 'Store launch & deploy',
                'description' => 'Take a WooCommerce store from empty to able-to-take-real-orders (payments, shipping, tax), and ship changes from a staging site to production.',
            ],
            'web-design-master' => [
                'title'       => 'Web design master',
                'description' => 'The design reference for building modern, beautiful, responsive websites and pages.',
            ],
            'website-building' => [
                'title'       => 'Website building',
                'description' => 'Build a beautiful website, landing page, homepage, header, or footer in WordPress from a brief, screenshot, or design.',
            ],
            'woocommerce-store-operations' => [
                'title'       => 'WooCommerce store operations',
                'description' => 'Run the WooCommerce shop end to end — add or edit products, manage stock and pricing, process and fulfil orders, create coupons and sales, and read sales reports.',
            ],
        ];
    }

    /** @return string[] */
    public static function names(): array
    {
        return array_keys(self::all());
    }
}
