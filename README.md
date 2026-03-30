# LOOM  -  Intelligent Internal Linking Engine for WordPress

<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="assets/img/logo-gh.png">
    <source media="(prefers-color-scheme: light)" srcset="assets/img/logo-wide.png">
    <img src="assets/img/logo-gh.png" alt="LOOM" width="400">
  </picture><br><br>
  <strong>v2.4.0</strong> · 34 files · 9,192 lines · Zero external PHP dependencies<br>
  WordPress 6.0+ · PHP 8.0+ · OpenAI API · Google Search Console (optional)<br>
  <br>
  Created by <strong><a href="https://marcinzmuda.com">Marcin Żmuda</a></strong>
</p>

LOOM analyzes your WordPress site's content, builds a semantic + topological graph of all pages and their connections, and uses AI to suggest internal links that are semantically relevant, structurally beneficial, and aligned with your SEO goals. It inserts real `<a>` tags into `post_content`  -  clean HTML that survives plugin deactivation.

---

## Table of Contents

- [Quick Start](#quick-start)
- [How It Works](#how-it-works)
- [The Podlinkuj Pipeline](#the-podlinkuj-pipeline)
- [Architecture](#architecture)
- [Core Systems](#core-systems)
  - [Content Scanner](#1-content-scanner)
  - [Embedding Engine](#2-embedding-engine)
  - [Two-Stage Similarity Search](#3-two-stage-similarity-search)
  - [Graph Engine](#4-graph-engine)
  - [Keyword Extraction (5 Layers)](#5-keyword-extraction-5-layers)
  - [Composite Scoring (11 Dimensions)](#6-composite-scoring-11-dimensions)
  - [AI Suggestion Engine](#7-ai-suggestion-engine)
  - [Link Inserter](#8-link-inserter)
  - [Google Search Console](#9-google-search-console-integration)
  - [Money Page System](#10-money-page-system)
  - [Site Analysis](#11-site-analysis)
- [Google Search Console  -  Setup Guide](#google-search-console--setup-guide)
- [Dashboard](#dashboard)
- [Metabox](#metabox)
- [Database Schema](#database-schema)
- [AJAX API](#ajax-api)
- [Configuration](#configuration)
- [File Structure](#file-structure)
- [Dependencies Between Components](#dependencies-between-components)
- [Security](#security)
- [Performance](#performance)
- [Uninstall](#uninstall)

---

## Quick Start

1. Upload the `loom` folder to `wp-content/plugins/`
2. Activate in WordPress
3. **LOOM -> Settings**  -  paste your OpenAI API key
4. **LOOM -> Dashboard**  -  click **🔍 Skanuj stronę**
5. After scan: click **Generuj embeddingi** in Settings
6. Open any post -> see the LOOM metabox -> click **🔗 Podlinkuj**

Optional: [connect Google Search Console](#google-search-console--setup-guide) for striking distance data.

---

## How It Works

LOOM operates in three phases:

**Phase 1  -  Index.** The scanner crawls every published post and page. For each one it extracts clean text, parses all internal links (anchor text, position, nofollow), computes click depth from homepage via BFS, and runs keyword extraction (layers 0-2, free).

**Phase 2  -  Analyze.** Three engines run independently. The graph engine builds a directed graph and computes Internal PageRank, identifies dead ends, bridge nodes, and connected components. The embedding engine sends each post's title+content to OpenAI to produce 512-dimensional semantic vectors. GSC integration (optional) fetches real search positions, impressions, CTR, and queries from Google.

**Phase 3  -  Suggest.** When you click "Podlinkuj" on any post, a 16-step pipeline finds semantically similar posts, scores each across 11 weighted dimensions, formats a detailed prompt, sends to GPT-4o-mini with strict JSON schema, validates the response, and presents suggestion cards. Approved links are inserted into `post_content` as standard `<a>` tags.

---

## The Podlinkuj Pipeline

Exact sequence when user clicks "🔗 Podlinkuj":

```
Step  1  Loom_DB::get_index_row()              Load source post (35 columns)
Step  2  Loom_OpenAI::get_embedding()           Generate 512D embedding if missing
                                                 Input: "title | title | title | 2500 chars"
Step  3  Loom_DB::get_all_with_embeddings()     Load ALL targets (33 columns each)
Step  4  Loom_Similarity::find_similar()        64D cosine pre-filter → 512D dot product
                                                 TOP 50 → TOP 30
Step  5  Paragraph-level embeddings             Embed top 5 paragraphs of source article
                                                 Match each target → best paragraph + similarity
Step  6  Loom_Composite::rank_targets()         11-dimensional scoring → TOP 15
                                                 Includes: topical authority + placement quality
Step  7  Filter rejected targets                3+ rejections = blacklisted
Step  8  Loom_Composite::format_for_prompt()    Targets with alerts, GSC, keywords, anchor
                                                 profiles, paragraph match hints
Step  9  Loom_Suggester::get_system_prompt()    System prompt (patents, anchor diversity rules)
Step 10  Loom_Suggester::build_user_prompt()    Paragraphs + targets + context
Step 11  Loom_OpenAI::chat()                    gpt-4o-mini, temp 0.3, strict JSON schema
Step 12  Validate: anchor in paragraph          mb_strpos check
Step 13  Validate: anchor in post_content       Cross-check raw HTML
Step 14  Loom_Analyzer::enrich_suggestions()    Reasonable Surfer + match scores
Step 15  Return JSON → user sees cards          Approve/reject UI
Step 16  Loom_Linker::insert_link()             Insert <a> (safety: not inside <a>/<h1-6>)
Step 17  Loom_DB::recalc_counters()             Update counts + orphan status
```

Timing: ~200ms local + 2-4s API + ~1s paragraph embeddings. Cost: ~$0.0015 per request.

---

## Architecture

```
┌──────────────────────────────────────────────────────────────────┐
│                        USER INTERFACE                            │
│  Dashboard (6 tabs)      Metabox (per-post)      Bulk Manager    │
├────────────────────── AJAX (24 endpoints) ───────────────────────┤
│  Scanner -> Graph + Embeddings + Keywords + GSC -> Similarity      │
│  -> Composite (11 dims) -> Suggester (GPT) -> Linker -> DB           │
├──────────────────────────────────────────────────────────────────┤
│  Loom_DB: loom_index · loom_links · loom_log · loom_clusters     │
├──────────────────────────────────────────────────────────────────┤
│  OpenAI API (embeddings + chat)   Google Search Console API      │
└──────────────────────────────────────────────────────────────────┘
```

19 PHP classes. Zero Composer. All HTTP via `wp_remote_post()`. All DB via `$wpdb`. Weekly WP Cron for automated rescan.

---

## Core Systems

### 1. Content Scanner

**Class:** `Loom_Scanner` · 263 lines

Crawls all posts in AJAX batches of 20. Per post: renders through `the_content` filter (wrapped in try/catch for safety), strips HTML to `clean_text`, parses `<a>` tags with DOMDocument (anchor text, target post, nofollow, position as % of text), stores in `loom_index` + `loom_links`. Static URL cache prevents repeated `url_to_postid()` lookups. Triggers keyword extraction layers 0-2. Logs orphan trend after each complete scan.

**WP Cron (v2.4):** Weekly automatic rescan — recalculates counters, graph, click depths, and logs orphan trend. Registered on `plugins_loaded`, cleaned up on deactivation.

**Publish-time orphan alert (v2.4):** On `save_post`, if a new/updated post has 0 incoming links, displays an `admin_notice` warning: "This post is an orphan — open LOOM to generate linking suggestions."

### 2. Embedding Engine

**Class:** `Loom_OpenAI` · 292 lines · Model: `text-embedding-3-small` at 512D

Input: `"Title | Title | Title | first 2500 chars of content"`. Title repeated 3× to ensure topic dominates over content tone. 512D via Matryoshka truncation from native 1536D  -  preserves ~99% recall at 3× smaller vectors. Vectors are unit-normalized by OpenAI, enabling dot product as cosine equivalent. Retry: 2× exponential backoff on 429/500+.

### 3. Two-Stage Similarity Search

**Class:** `Loom_Similarity` · 240 lines

**Stage 1 (fast):** First 64 dimensions, full cosine (prefix not unit-normalized). Rejects below `threshold × 0.5`. TOP 50 advance.

**Stage 2 (precise):** All 512 dimensions, dot product (unit-normalized -> equals cosine, skips ~40% operations). Results below `min_threshold` (default 0.35) filtered. TOP 30 returned.

Optimizations: JSON decode cache (static), memory release after stage 1, early threshold rejection.

**Paragraph-level intent matching (v2.3):** After document-level similarity finds TOP 30, LOOM generates embeddings for the 5 longest paragraphs of the source article. Each target is matched against each paragraph - finding which specific section of the article best relates to which target. This data flows into composite scoring (placement dimension) and GPT prompt (`Best match: Paragraph X`).

### 4. Graph Engine

**Class:** `Loom_Graph` · 589 lines

**PageRank:** Iterative power method, d=0.85, convergence 0.0001, max 100 iterations. Dead end redistribution. Normalized to sum=1.0.

**Betweenness:** Sampled BFS from 60 nodes. Top 10% = bridge nodes.

**Components:** BFS on undirected graph. Detects isolated clusters.

**Dead Ends:** Pages with incoming > 0 but outgoing = 0.

Cached aggregates: avg PageRank + avg outlinks computed once per request (static properties).

### 5. Keyword Extraction (5 Layers)

**Class:** `Loom_Keywords` · 455 lines

| Layer | Source | Score | Cost |
|-------|--------|-------|------|
| 0 | SEO plugin (Yoast/Rank Math) | 1.00 | Free |
| 1 | Title + H2/H3 headings | ≤1.0 | Free |
| 2 | TF-IDF (unigrams + bigrams) | ≤1.0 | Free |
| 3 | GPT-4o-mini extraction | 0.75-0.95 | ~$0.001 |
| 4 | Google Search Console queries | 0.90 | Free |

Max 7 per post. Deduplication via substring overlap. TF-IDF built in 100-post batches.

### 6. Composite Scoring (11 Dimensions)

**Class:** `Loom_Composite` · 398 lines

| # | Dimension | Default | What it measures |
|---|-----------|---------|------------------|
| 1 | Semantic | 22% | Cosine similarity between embeddings |
| 2 | Orphan | 8% | Zero incoming links |
| 3 | Depth | 6% | Click distance from homepage |
| 4 | Tier | 6% | Linking up the hierarchy (article -> pillar) |
| 5 | Cluster | 6% | Same topic cluster membership |
| 6 | Velocity | 4% | Link acquisition rate vs page age |
| 7 | Graph | 10% | PageRank, dead ends, bridges, components |
| 8 | Money | 10% | Conversion page priority + anchor diversity |
| 9 | GSC | 8% | Striking distance, impressions, CTR |
| 10 | Authority | 10% | Topical authority (cluster links, PR, keyword depth) |
| 11 | Placement | 10% | Paragraph-level match quality (Reasonable Surfer) |

Weights configurable via UI sliders. Auto-normalized to sum 1.0.

**Topical Authority (dim 10):** Measures how authoritative a target is within its topic. Based on: incoming links from same cluster (max +0.4), PageRank relative to average (3x = topic leader, +0.3), keyword depth (5+ keywords = +0.3). Prevents linking to semantically similar but weak pages.

**Placement Quality (dim 11):** Uses paragraph-level embedding data. If a target best matches a paragraph in the first 1/3 of the article, it gets a 1.5x boost. Middle 1/3 = 1.0x. Last 1/3 = 0.5x. Implements Reasonable Surfer at the scoring level - not just in the prompt.

### 7. AI Suggestion Engine

**Class:** `Loom_Suggester` · 725 lines · Model: `gpt-4o-mini`, temp 0.3, Structured Outputs

System prompt: 4 SEO principles from Google patents, 5-step analysis process, money page + striking distance instructions, paragraph-level placement hints, anchor diversity control rules, bidirectional linking hint (v2.4), 3 few-shot examples, 7 "Common Mistakes to Avoid".

JSON schema: `analysis` (chain-of-thought: topics + target evaluation) -> `suggestions` (paragraph, anchor, target, reason, priority). `strict: true` guarantees valid JSON.

**Anchor diversity control (v2.3):** For every target with 2+ incoming links, the prompt includes a full anchor profile breakdown (exact/partial/contextual/generic %) with specific warnings when any category is over-concentrated. GPT is instructed to diversify.

**Bidirectional linking (v2.4):** System prompt now recommends two-way links for strongly related pages (score > 0.6). GPT appends "(↔ bidirectional recommended)" in the reason field when both pages would benefit from linking to each other.

**Reverse Orphan Rescue (v2.4):** New `ajax_reverse_rescue` endpoint — for a given orphan or near-orphan page, searches all other pages by semantic similarity to find articles that SHOULD link to it. Uses adaptive threshold (40% lower than `min_similarity` for pages with ≤2 incoming links). Returns up to 10 candidates with similarity score, already-linked status, PageRank, and direct edit links. Zero additional API calls — uses existing embeddings.

Post-processing: anchor must exist verbatim in paragraph AND in raw `post_content`.

### 8. Link Inserter

**Class:** `Loom_Linker` · 585 lines

Finds anchor in `post_content` via `mb_strpos()`. Safety checks: not inside existing `<a>` tag, not inside `<h1>`-`<h6>`. Content backup to post meta before modification. `remove_action('save_post')` before `wp_update_post()` to prevent scan loop.

**Remove all LOOM links:** One-click removal of ALL links inserted by LOOM. Strips `<a>` tags while preserving anchor text. Backup before each post modification. Double-confirm in UI.

### 9. Google Search Console Integration

**Class:** `Loom_GSC` · 603 lines · Auth: **Service Account** (JSON key)

No OAuth dance. User pastes Service Account JSON -> LOOM signs a JWT with the private key (RS256 via `openssl_sign`), exchanges it for an access token, and fetches data. Token cached in transient (~55 min).

GSC provides 4 types of data: per-page position, impressions/CTR, striking distance flags (position 5-20), and real search queries. These feed into composite scoring (dimension 9) and enrich GPT prompts.

**Setup:** [See detailed guide below](#google-search-console--setup-guide).

### 10. Money Page System

Money pages (products, services, pricing) get: `money_priority` (1-5), `target_links_goal` (default 10). Composite boost: +0.50 base, +0.25 if deficit, -0.30 if source IS money page (prevent equity leakage). Anchor distribution monitored  -  over-optimization warning at 40%.

### 11. Site Analysis

**Class:** `Loom_Site_Analysis` · 349 lines

Click depth via BFS from homepage. Site tiers: 0=homepage, 1=pillar, 2=category, 3=article. User rejection tracking (3+ = permanent blacklist).

**Silo Integrity Check (v2.4):** Verifies bidirectional linking within `loom_clusters`. For each cluster: checks that pillar links to all members AND all members link back to pillar. Reports missing links per cluster with issue count.

**Diagnostics Panel (v2.4):** One-click health check covering 5 areas:
- **Keyword cannibalization:** Detects 2+ pages sharing the same top GSC query — extracts first query per page from `gsc_top_queries` JSON, groups by normalized query string.
- **Anchor cannibalization:** Detects same anchor text pointing to 2+ different target pages — signals confused relevance to Google.
- **Duplicate links:** Finds same source→target pair appearing multiple times with different anchors.
- **Overlinked pages:** Pages with >20 outgoing links where equity dilution is a concern.
- **Near-orphan list:** Pages with 1-2 incoming links, sorted by PageRank desc — highest-value pages that need reinforcement first.

---

## Google Search Console  -  Setup Guide

### Part 1: Create Service Account (Google Cloud, ~3 min)

**Step 1.** Go to [console.cloud.google.com](https://console.cloud.google.com). Create a project (or select existing).

**Step 2.** Enable the Search Console API:
-> [console.cloud.google.com/apis/library/searchconsole.googleapis.com](https://console.cloud.google.com/apis/library/searchconsole.googleapis.com)
-> Click **Enable**.

**Step 3.** Create a Service Account:
-> [console.cloud.google.com/iam-admin/serviceaccounts](https://console.cloud.google.com/iam-admin/serviceaccounts)
-> **+ Create Service Account** -> name it (e.g. "loom") -> **Create and Continue** -> skip permissions -> **Done**.

**Step 4.** Download JSON key:
-> Click on the new service account -> **Keys** tab -> **Add Key** -> **Create new key** -> **JSON** -> **Create**.
-> File downloads automatically.

### Part 2: Add to Google Search Console (~30 sec)

**Step 5.** Open the downloaded JSON, copy the `client_email` value (e.g. `loom@project.iam.gserviceaccount.com`).

**Step 6.** Go to [search.google.com/search-console](https://search.google.com/search-console) -> select your site -> **Settings** -> **Users and permissions** -> **Add user** -> paste the email -> **Restricted** -> **Add**.

### Part 3: Connect in LOOM (~10 sec)

**Step 7.** WordPress -> **LOOM -> Settings** -> **Google Search Console** section -> paste the **entire JSON file contents** into the text field -> set your site URL (exactly as in GSC) -> click **📊 Connect GSC**.

**Step 8.** Click **🔄 Synchronize** to fetch data.

### Troubleshooting

| Error | Fix |
|-------|-----|
| 403 Forbidden | Service account email not added as GSC user (step 6) |
| 404 Not Found | Site URL doesn't match GSC property exactly (with/without www, trailing slash) |
| JWT sign error | JSON incomplete  -  make sure you copied everything from `{` to `}` |
| "Not a service account" | Wrong JSON type  -  needs `"type": "service_account"` |
| "API has not been used" | API not enabled (step 2) |

---

## Dashboard

6 tabs accessible from the main LOOM menu:

| Tab | Content |
|-----|---------|
| **📊 Overview** | 11 metrics (incl. near-orphans, structural, overlinked), equity distribution, quick actions (diagnostics, silo check), orphan trend chart, broken links |
| **💰 Money Pages** | Progress bars (links/goal), anchor diversity, GSC position, status |
| **🎯 Striking Distance** | Pages at position 5-20 sorted by proximity, impressions, CTR, top query |
| **🕸️ Graph** | 5 views: 🎯 Rings (hierarchy), 📋 List+Panel, 🫧 Bubble Scatter (IN×OUT×PR), 🔑 Keyword Galaxy (GSC queries), 🔗 Anchor Explorer (anchor profiling) |
| **📋 Posts** | Filterable table (9 filters incl. near-orphan, structural, overlinked), structural 🏗️ toggle, rescue 🔍 button for orphans, ⭐ money toggle |
| **⚙️ Settings** | 11 weight sliders with live sum, GSC status, general settings |

---

## Metabox

Per-post panel below the editor:

| Section | Data |
|---------|------|
| **Metrics** | IN, OUT, depth, PageRank (percentile), GSC position/impr/CTR/clicks, status badges |
| **Keywords** | Color-coded by source: 🟢 seo_plugin, 🟣 gsc, 🔵 tfidf, 🟡 gpt |
| **Anchor distribution** | Progress bars per incoming anchor (red ≥40%, amber ≥25%) |
| **Links OUT** | Anchor, target, Reasonable Surfer position, source, match score, health |
| **Links IN** | Source page, anchor, position, source |

---

## Database Schema

**`loom_index`**  -  35 columns, 1 row per post. Groups: identity, content, links, structure (incl. `is_structural` v2.4), embeddings, keywords, graph, money page, GSC, timestamps.

**`loom_links`**  -  12 columns, 1 row per link. Includes: anchor_text, link_position (top/middle/bottom), position_percent, is_plugin_generated, is_broken, is_nofollow, anchor_match_score, created_at.

**`loom_log`**  -  API cost tracking (action, tokens, cost).

**`loom_clusters`**  -  Topic clusters with pillar pages.

**`loom_rejections`**  -  User-rejected suggestions.

---

## AJAX API

24 endpoints, all requiring `loom_nonce` verification:

| Endpoint | Cap | Description |
|----------|-----|-------------|
| `loom_batch_scan` | manage_options | Scan posts in batches of 20 |
| `loom_podlinkuj` | edit_posts | Full 17-step suggestion pipeline |
| `loom_apply_links` | edit_posts | Insert approved links |
| `loom_auto_podlinkuj` | edit_posts | Auto-insert high+medium |
| `loom_save_settings` | manage_options | Save settings and weights |
| `loom_generate_embeddings` | manage_options | Batch embedding generation |
| `loom_extract_keywords` | manage_options | Batch keyword extraction |
| `loom_get_link_map` | edit_posts | Graph data for visualization |
| `loom_reject_suggestion` | edit_posts | Record user rejection |
| `loom_recalc_depth` | edit_posts | Recalculate click depths |
| `loom_recalc_graph` | manage_options | Full graph recomputation |
| `loom_structural_suggestions` | edit_posts | Graph-based structural suggestions |
| `loom_gsc_save_credentials` | manage_options | Save Service Account JSON |
| `loom_gsc_sync` | manage_options | Sync GSC data |
| `loom_gsc_disconnect` | manage_options | Remove GSC connection |
| `loom_set_money_page` | edit_posts | Toggle money page status |
| `loom_get_money_pages` | edit_posts | Money pages with health metrics |
| `loom_remove_all_links` | manage_options | Remove ALL LOOM-inserted links |
| `loom_get_broken_links` | edit_posts | List broken internal links |
| `loom_fix_broken_link` | edit_posts | Fix or remove a broken link |
| `loom_set_structural` | manage_options | Toggle structural page status (v2.4) |
| `loom_reverse_rescue` | edit_posts | Find pages that should link TO an orphan (v2.4) |
| `loom_silo_check` | manage_options | Verify bidirectional linking within clusters (v2.4) |
| `loom_diagnostics` | manage_options | Cannibalization, duplicates, overlink check (v2.4) |

---

## Configuration

### Settings (`loom_settings` option)

| Key | Default | Description |
|-----|---------|-------------|
| `post_types` | `['post','page']` | Post types to index |
| `min_similarity` | `0.35` | Minimum cosine similarity threshold (0.05–0.80, step 0.01) |
| `max_suggestions` | `8` | Max suggestions per Podlinkuj |
| `language` | `'pl'` | Content language for GPT |
| `weight_semantic` | `0.22` | Semantic similarity weight |
| `weight_orphan` | `0.08` | Orphan boost weight |
| `weight_depth` | `0.06` | Click depth weight |
| `weight_tier` | `0.06` | Site tier weight |
| `weight_cluster` | `0.06` | Topic cluster weight |
| `weight_equity` | `0.04` | Link velocity weight |
| `weight_graph` | `0.10` | Graph need weight |
| `weight_money` | `0.10` | Money page weight |
| `weight_gsc` | `0.08` | GSC boost weight |
| `weight_authority` | `0.10` | Topical authority weight |
| `weight_placement` | `0.10` | Placement quality weight |

### Other Options

| Option | Description |
|--------|-------------|
| `loom_openai_key` | AES-256-CBC encrypted OpenAI API key |
| `loom_gsc_service_account` | AES-256-CBC encrypted Service Account JSON |
| `loom_gsc_site_url` | GSC property URL |
| `loom_scan_completed` | Has initial scan been run |
| `loom_db_version` | Database schema version |
| `loom_df_cache` | TF-IDF document frequency cache |

---

## File Structure

```
loom/
├── loom.php                       114 ln  Entry point, constants, 24 AJAX registrations, WP Cron
├── uninstall.php                   33 ln  Clean removal of all plugin data
├── readme.txt                             WordPress.org plugin header
├── README.md                              This documentation
│
├── assets/
│   ├── css/loom-admin.css         290 ln  Design system (DM Sans, teal palette, tooltip styles)
│   ├── js/loom-admin.js          1953 ln  5 graph views, rescue modal, diagnostics, silo, trend chart
│   └── img/                               Logos (square, wide, GitHub, icon-20)
│
├── admin/
│   ├── class-loom-admin.php       112 ln  Menu, assets, orphan notice
│   ├── class-loom-dashboard.php   617 ln  6-tab dashboard, structural toggle, near-orphan badges, trend chart
│   ├── class-loom-metabox.php     195 ln  Per-post panel (GSC, keywords, anchors)
│   ├── class-loom-bulk.php        126 ln  Multi-post management
│   ├── class-loom-settings.php    324 ln  API keys, GSC connect, weights, danger zone
│   └── class-loom-onboarding.php   19 ln  Placeholder
│
├── includes/
│   ├── class-loom-activator.php   200 ln  Tables, defaults, upgrade migration (is_structural)
│   ├── class-loom-db.php          558 ln  DB ops, structural toggle, orphan trend, duplicate/overlink detection
│   ├── class-loom-scanner.php     263 ln  Content crawl, WP Cron rescan, publish-time orphan alert
│   ├── class-loom-openai.php      292 ln  Embeddings (single + batch) + chat with retry
│   ├── class-loom-similarity.php  240 ln  Two-stage 64D→512D search
│   ├── class-loom-graph.php       589 ln  PageRank, betweenness, components
│   ├── class-loom-gsc.php         605 ln  Service Account JWT auth + sync + scoring
│   ├── class-loom-keywords.php    455 ln  5-layer extraction
│   ├── class-loom-composite.php   469 ln  11-dimensional scoring, batch prefetch, topical authority
│   ├── class-loom-suggester.php   725 ln  System prompt, paragraph embeddings, reverse rescue, bidirectional
│   ├── class-loom-analyzer.php     79 ln  Reasonable Surfer + anchor mismatch
│   ├── class-loom-linker.php      585 ln  Link insertion (paragraph-aware) + removal
│   └── class-loom-site-analysis.php 349 ln Silo check, diagnostics, cannibalization ×2, click depth
│
└── languages/
    ├── loom-en_US.po             248 strings  English translation
    └── loom-en_US.mo                          Compiled binary
```

---

## Dependencies Between Components

```
Scanner ──-> loom_index + loom_links
  ├──-> Graph (PageRank, bridges, components)
  ├──-> Keywords (layers 0-2)
  ├──-> Site_Analysis (click depth, tiers)
  └──-> log_orphan_trend() (v2.4)

OpenAI ──-> Embeddings (512D vectors in loom_index)
GSC    ──-> Position, impressions, queries in loom_index

Similarity (needs embeddings) ──-> TOP 30 candidates
Composite (needs ALL: similarity + graph + GSC + keywords + money) ──-> TOP 15
Suggester (needs composite + OpenAI chat) ──-> Validated suggestions
Linker (needs suggestions) ──-> Modified post_content

Reverse Rescue (v2.4) ──-> Similarity search (reversed: orphan = source, all pages = targets)
Diagnostics (v2.4) ──-> loom_links + loom_index (cannibalization, duplicates, overlink)
Silo Check (v2.4) ──-> loom_clusters + loom_links (bidirectional verification)
WP Cron (v2.4) ──-> Scanner.recalc + Graph.analyze + log_orphan_trend (weekly)
```

Scanner, Graph, Embeddings, Keywords, GSC all run independently and write to `loom_index`. Pipeline converges at Composite scoring. Reverse Rescue and Diagnostics are read-only — they query existing data without API calls.

---

## Security

| Layer | Implementation |
|-------|---------------|
| Authentication | `check_ajax_referer('loom_nonce')` on all 24 endpoints |
| Authorization | `manage_options` for admin, `edit_posts` for content |
| Input | `sanitize_text_field(wp_unslash())` on all POST data |
| Output | `esc_html()`, `esc_attr()`, `esc_url()` on all rendered values |
| SQL | `$wpdb->prepare()` on all dynamic queries |
| API keys | AES-256-CBC encryption with random IV |
| GSC credentials | AES-256-CBC encrypted Service Account private key |
| Rate limiting | 5-second per-user cooldown on Podlinkuj |
| Content safety | Backup to post meta before any modification |
| Dependencies | Zero Composer, zero npm |

---

## Performance

| Operation | Time | Cost |
|-----------|------|------|
| Full scan (100 posts) | ~5s | $0 |
| Embeddings (100 posts) | ~30s | ~$0.01 |
| Graph analysis | ~2s | $0 |
| Single Podlinkuj | ~3-5s | ~$0.001 |
| GSC sync (50 pages) | ~15s | $0 |
| Reverse Rescue (v2.4) | ~1s | $0 (uses existing embeddings) |
| Diagnostics (v2.4) | <1s | $0 (DB queries only) |
| Silo Check (v2.4) | <1s | $0 (DB queries only) |
| Weekly Cron rescan (v2.4) | ~10s | $0 (recalc only, no API) |

---

## Uninstall

`uninstall.php` removes: 5 tables, all `loom_%` options, all `_loom_%` post/user meta, all `_transient_loom_%`.

**Links in `post_content` are NOT removed**  -  they are standard `<a>` tags that continue working.

To remove all LOOM links before uninstalling: **Settings -> Danger Zone -> Remove all LOOM links**.

---

## Third-Party Services

This plugin connects to:

**OpenAI API** (`api.openai.com`)  -  for embeddings and link suggestions. Your post content is sent when you click "Podlinkuj". [Privacy Policy](https://openai.com/privacy) · [Terms](https://openai.com/terms)

**Google Search Console API** (`googleapis.com`)  -  optional, for search performance data. Only page URLs, positions, and queries are read. Nothing is modified. [Terms](https://developers.google.com/terms)

---

## Changelog

### 2.4.0

**Reverse Orphan Rescue:**
- New `ajax_reverse_rescue` endpoint — for any orphan/near-orphan page, finds semantically similar articles that could add a link TO it
- Adaptive similarity threshold: automatically lowered by 40% for pages with 0-2 incoming links
- Modal UI with candidate list sorted by similarity, already-linked status, PageRank, and direct edit links

**Structural Page Management:**
- `is_structural` column added to `loom_index` with automatic migration
- Toggle button 🏗️ in posts table — marks navigation/menu pages
- Structural pages excluded from orphan/near-orphan metrics and alerts
- New dashboard filter and counter

**Near-Orphan Tracking (1-2 IN):**
- New status badge 🟡 "Near-orphan" — distinct from full orphans and weak pages
- Separate counter in dashboard metrics, separate filter in posts table
- Included in diagnostics output

**Diagnostics Panel (🩺):**
- One-click health check covering 5 areas:
  - Keyword cannibalization: 2+ pages sharing same top GSC query
  - Anchor cannibalization: same anchor text → different target pages
  - Duplicate links: same source→target pair with multiple anchors
  - Overlinked pages: >20 outgoing links with ⚠️ OL badge
  - Near-orphan list with PageRank and GSC position

**Silo Integrity Check (🏗️):**
- Verifies bidirectional linking within `loom_clusters`
- Reports missing: pillar→member links and member→pillar links
- Per-cluster breakdown with issue count

**Bidirectional Linking Hint:**
- System prompt updated: GPT recommends `(↔ bidirectional recommended)` in reason field when semantic score > 0.6
- Encourages mesh linking instead of single-path structures

**Automation & Monitoring:**
- WP Cron weekly rescan: recalculates counters, graph, click depths, and logs orphan trend
- Publish-time orphan alert: `admin_notice` when a new post has 0 incoming links
- `log_orphan_trend()` called after every batch scan — stores orphan/near-orphan counts for trend tracking

**Bug fix:**
- `min_similarity` setting never persisted — JS omitted value from AJAX, PHP always fell back to 0.35

### 2.3.0

**New scoring dimensions:**
- **Topical authority** (dim 10) — cluster links + relative PageRank + keyword depth. Prevents linking to semantically similar but weak pages.
- **Placement quality** (dim 11) — paragraph-level embedding match × Reasonable Surfer position. First third of article gets 1.5× boost.
- Paragraph-level intent matching — 5 paragraph embeddings per article, batch API call. Each target matched to best-fitting paragraph.
- Anchor diversity control — every target with 2+ incoming links gets exact/partial/contextual/generic profile in GPT prompt.
- 11-dimensional composite scoring with configurable weight sliders.

**Graph visualization — 5 interactive views:**
- Removed force-directed graph (spaghetti with 60+ nodes) and adjacency matrix (unreadable at 30+ pages)
- New **Concentric Rings** view — nodes by tier (Homepage → Pillar → Category → Article), click = show connections only for that node, drag = reposition, double-click = reset, pulse animation on selection
- New **List + Panel** view — sortable table on left, click page → right panel shows all IN/OUT links with clickable navigation and LOOM badges
- New **Bubble Scatter** view — X-axis = incoming links, Y-axis = outgoing links, bubble size = PageRank, color = cluster. Red zone (bottom-left) highlights orphans and dead ends, green zone (top-right) highlights healthy hubs
- New **Keyword Galaxy** view — all GSC queries displayed as interactive tag cloud. Size = impressions, color = Google position (green = top 3, teal = page 1, purple = striking distance). Filter by page. Hover any keyword = "use as anchor text" recommendation
- New **Anchor Explorer** view — per-page incoming anchor profile. Classifies every anchor as exact match / partial match / contextual / generic. Anchor Health Score 0-100 with automatic warnings (over-optimization at >30% exact, wasted links at >15% generic). Expandable: click anchor → see all source pages with link position (top/middle/bottom) and LOOM badge

**Bug fixes (10):**
- Embedding generation infinite loop — errors now reported instead of silent 0-progress retry
- Auto-Podlinkuj embedding formula mismatch — was `title | content`, now `title | title | title | content` matching batch
- `remove_all_loom_links` re-added `save_post` hook with wrong priority (10) and args (2) — fixed to 20/3
- Re-scan deleted LOOM-generated links — `DELETE WHERE source_post_id = X` now filters `AND is_plugin_generated = 0`
- `insert_link()` always linked first occurrence — now uses `paragraph_number` hint to find correct position
- `money_pages_health` missing GSC columns — added `gsc_position`, `gsc_impressions`, `gsc_ctr`
- `the_content` filter crash killed entire scan batch — now wrapped in try/catch with raw content fallback
- GSC URL double encoding — normalized with `rtrim(trim())`
- `min_similarity` setting never persisted — JS omitted the value from AJAX request, PHP always fell back to 0.35. Input now accepts 0.05–0.80 with 0.01 step
- Link velocity used `last_scanned` (scan date) instead of `post_date` (publication date)
- JS syntax error: missing `}` for `if(canvas)` block — all JS dead on onboarding page

**Performance:**
- Composite scoring: eliminated 45 N+1 queries per Podlinkuj (batch `_prime_post_caches`, batch cluster links, batch pillar lookup)
- Dashboard stats: 16 separate `SELECT COUNT(*)` → 3 aggregated queries
- Paragraph embeddings: 5 API calls → 1 batch call (`get_embeddings_batch`)
- Settings static cache — `get_option()` called once per request instead of 20+
- Link removal regex handles nested tags (`<a><strong>text</strong></a>`)

**UI:**
- 34 tooltips across entire dashboard — every metric, table header, button, badge, filter, tab has hover explanation
- Embedding progress bar shows `3/66` instead of "Pozostało: 66"
- Error messages displayed on embedding failure with button re-enabled for retry
- `cursor: help` on tooltip elements with subtle teal hover highlight

**Localization:**
- English translation: 238 strings in `languages/loom-en_US.po` + compiled `.mo`
- Switch WordPress language to English → full English UI

### 2.2.0

- Google Search Console integration (Service Account JWT auth)
- Money page system with priority, goals, anchor distribution monitoring
- 6-tab dashboard (Overview, Money Pages, Striking, Graph, Posts, Settings)
- One-click removal of all LOOM links with content backup
- Internal PageRank, betweenness centrality, dead-end and bridge detection
- 5-layer keyword extraction (SEO plugin, title, TF-IDF, GPT, GSC queries)
- Two-stage similarity search (64D Matryoshka pre-filter → 512D dot product)
- GPT-4o-mini with Structured Outputs and strict JSON schema
- UI redesign: DM Sans + JetBrains Mono, teal palette, component system

---

## License

GPLv2 or later. See [LICENSE](LICENSE) for details.

## Author

**Marcin Żmuda**  -  [marcinzmuda.com](https://marcinzmuda.com)
