=== LOOM - AI Internal Linking ===
Contributors: marcinzmuda
Tags: internal linking, seo, ai, openai, pagerank, google search console
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 2.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-powered internal linking engine. Semantic embeddings, PageRank, 11-dimensional scoring, GPT-4o-mini suggestions, Google Search Console integration.

== Description ==

LOOM analyzes your content with AI and suggests optimal internal links. Click "Podlinkuj"  -  approve suggestions  -  links inserted.

**Core:**

* Semantic analysis via OpenAI embeddings (text-embedding-3-small, 512D)
* 11-dimensional composite scoring (semantic, orphan, depth, tier, cluster, velocity, graph, money page, GSC, topical authority, placement quality)
* GPT-4o-mini with Structured Outputs for precise link suggestions
* Internal PageRank computation with bridge and dead-end detection
* Two-stage similarity search (64D pre-filter, 512D dot product)

**Google Search Console (optional):**

* Striking distance pages (position 5-20) get automatic priority boost
* Real search queries from Google enrich keyword data
* Service Account auth  -  paste JSON, connected in 10 seconds

**Money Pages:**

* Mark conversion pages as money pages
* Link equity funneled toward money pages
* Anchor distribution monitoring with over-optimization warning

**Third-Party Services:**

This plugin connects to **OpenAI API** (api.openai.com) to generate text embeddings and link suggestions. Post content is sent when you click "Podlinkuj".

* [OpenAI Privacy Policy](https://openai.com/privacy)
* [OpenAI Terms of Use](https://openai.com/terms)

Optionally connects to **Google Search Console API** (googleapis.com) to read search performance data.

* [Google API Terms](https://developers.google.com/terms)

An OpenAI API key is required. Get one at [platform.openai.com](https://platform.openai.com).

== Installation ==

1. Upload the `loom` folder to `/wp-content/plugins/`
2. Activate the plugin
3. Go to LOOM > Settings, paste your OpenAI API key
4. Go to LOOM > Dashboard, click "Skanuj stronę"
5. Generate embeddings in Settings
6. Click "Podlinkuj" on any post to get AI suggestions

Optional: connect Google Search Console in Settings (Service Account JSON).

== Frequently Asked Questions ==

= How much does it cost? =

The plugin is free. You pay only for OpenAI API usage (~$0.001 per suggestion).

= Will links survive uninstall? =

Yes. LOOM inserts standard HTML `<a>` tags. After uninstall, links remain.

= Can I remove all LOOM links? =

Yes. Settings > Danger Zone > Remove all LOOM links.

= Does LOOM slow down my site? =

No. Zero frontend scripts. Admin panel only.

== Changelog ==

= 2.3.0 =

**New features:**
* Paragraph-level intent matching — 5 paragraph embeddings per article, each target matched to best paragraph
* Topical authority dimension (#10) — cluster links + relative PageRank + keyword depth
* Placement quality dimension (#11) — Reasonable Surfer at scoring level, not just prompt
* Anchor diversity control for ALL targets — exact/partial/contextual/generic % profiling in prompts
* 11-dimensional composite scoring with configurable weight sliders (was 9)
* Batch embedding API — 1 call for 5 paragraphs instead of 5 separate calls
* English translation (en_US) — 238 strings, .po + .mo included

**Graph visualization — 5 views:**
* Removed force-directed spaghetti graph and adjacency matrix
* New: Concentric Rings view — nodes by tier, click to show connections, drag to reposition
* New: List + Panel view — click page to see all IN/OUT links with LOOM badges
* New: Bubble Scatter view — X=IN, Y=OUT, size=PageRank, color=cluster. Orphan/hub zones highlighted
* New: Keyword Galaxy view — GSC queries as tag cloud, size=impressions, color=position. Filter per page. Hover = "use as anchor text"
* New: Anchor Explorer view — per-page anchor profile: exact/partial/contextual/generic %, health score 0-100, over-optimization warnings, expandable source list with link position
* Pulse animation on selected node, hover tooltips, double-click to reset

**Bug fixes:**
* Fixed embedding generation infinite loop — error now reported instead of silent retry
* Fixed Auto-Podlinkuj using different embedding formula (missing 3x title)
* Fixed remove_all_loom_links re-adding save_post hook with wrong priority (10→20) and args (2→3)
* Fixed re-scan deleting LOOM-generated links (now preserves is_plugin_generated=1)
* Fixed insert_link() always linking first occurrence — now uses paragraph_number hint
* Fixed money_pages_health missing GSC columns (gsc_position, gsc_impressions, gsc_ctr)
* Fixed the_content crash killing entire scan batch — now try/catch with fallback
* Fixed GSC URL double encoding — normalize with rtrim/trim
* Fixed JS syntax error (missing closing brace for canvas block) breaking all JS on onboarding

**Performance:**
* Eliminated N+1 queries in composite scoring: batch prefetch for get_post (15→0), cluster links (15→1), pillar check (15→1)
* Dashboard stats: 16 separate queries → 3 aggregated queries
* Settings static cache — loaded once per request instead of 20+ times
* Link velocity dimension now uses post_date (publication) instead of last_scanned

**UI improvements:**
* 34 tooltips across entire dashboard — hover any metric, header, button or badge for explanation
* Cursor: help on all tooltip-enabled elements with subtle teal hover highlight
* Progress bar for embedding generation shows X/Y instead of "Pozostało: 66"
* Error messages shown on embedding failure instead of infinite spinner

= 2.2.0 =
* Google Search Console integration (Service Account)
* Money page system with anchor distribution monitoring
* 6-tab dashboard
* One-click removal of all LOOM links
* Internal PageRank, betweenness, dead-end detection
* 5-layer keyword extraction
* Two-stage similarity search (Matryoshka)
* GPT-4o-mini Structured Outputs

== Upgrade Notice ==

= 2.3.0 =
Major update: 11-dimensional scoring, paragraph-level matching, 5 graph views (rings, list, scatter, keyword galaxy, anchor explorer), 10 bug fixes, 34 UI tooltips, English translation. Recommended for all users.
