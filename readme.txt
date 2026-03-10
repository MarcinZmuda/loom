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
* 9-dimensional composite scoring (semantic, orphan, depth, tier, cluster, velocity, graph, money page, GSC)
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
* Paragraph-level intent matching (5 paragraph embeddings per article)
* Topical authority dimension (cluster links, PR ratio, keyword depth)
* Anchor diversity control for all targets (exact/partial/contextual/generic %)
* Placement quality dimension (Reasonable Surfer in scoring)
* 11-dimensional composite scoring with weight sliders

= 2.3.0 =
* Paragraph-level intent matching (5 paragraph embeddings per request)
* Topical authority dimension (cluster links + relative PageRank + keyword depth)
* Placement quality dimension (Reasonable Surfer at scoring level)
* Anchor diversity control for ALL targets (exact/partial/contextual/generic profile)
* 11-dimensional composite scoring (was 9)
* System prompt: paragraph placement hints + anchor diversity rules

= 2.2.0 =
* Google Search Console integration (Service Account)
* Money page system with anchor distribution monitoring
* Force-directed graph visualization
* 6-tab dashboard
* One-click removal of all LOOM links
* Internal PageRank, betweenness, dead-end detection
* 5-layer keyword extraction
* Two-stage similarity search (Matryoshka)
* GPT-4o-mini Structured Outputs

== Upgrade Notice ==

= 2.3.0 =
* Paragraph-level intent matching (5 paragraph embeddings per request)
* Topical authority dimension (cluster links + relative PageRank + keyword depth)
* Placement quality dimension (Reasonable Surfer at scoring level)
* Anchor diversity control for ALL targets (exact/partial/contextual/generic profile)
* 11-dimensional composite scoring (was 9)
* System prompt: paragraph placement hints + anchor diversity rules

= 2.2.0 =
Major release with GSC, graph engine, 11-dimensional scoring.
