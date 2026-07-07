# Changelog

All notable changes to LOOM are documented in this file.
Full per-version details (including 2.3.x and 2.4.0) live in `readme.txt`.

## [2.4.1]  -  2026-07-07

### Fixed
- Orphan trend chart never displayed  -  query used non-existent table `loom_logs` instead of `loom_log`
- "Reject suggestion" feedback loop stored `target_post_id = 0`  -  rejections were never matched/filtered
- Links to the homepage were flagged as broken (empty URL path skipped all known-URL checks)
- Money-page equity-leak penalty was a no-op (composite score clamped at 0)
- Cluster-links batch cache: cached `0` fell through to a per-target SQL query (N+1 despite PERF-2)
- Dashboard language selector was never persisted
- Money/structural toggles silently failed for editors (capability now `edit_others_posts`, errors surfaced)
- GSC domain properties (`sc-domain:`) received an invalid trailing slash
- LOOM-inserted links recorded without `target_url`; "Remove all LOOM links" matched by anchor only and could strip manual links
- `preg_replace()` null result could blank post content on regex failure
- Publish-time orphan alert never displayed in Gutenberg (REST save); alerts now queue for the next admin page load
- Multibyte-safe word counting (Polish/German diacritics inflated `str_word_count()`)
- Structural graph suggestions: endpoint existed but no UI; field-name mismatch (`title`/`reason`)
- XSS hardening in Diagnostics / Reverse Rescue / Silo panels (GSC queries and post titles are external data)

### Performance
- Embedding generation batched into a single API call per chunk
- `Loom_Graph::analyze()` writes via batched `CASE` updates instead of per-row `UPDATE`s
- Link map / paragraph matching / prompt building no longer load full index rows (longtext) per node
- Keyword extraction uses a cheap cached `COUNT(*)` instead of full dashboard aggregations per post
- Admin orphan notice uses a 10-min cached count; BFS queues use index pointers instead of `array_shift()`
- GSC per-page query sync stops before PHP timeout with partial results logged

### Privacy
- Removed Google Fonts `@import` from admin CSS (no admin IPs sent to Google); system font stack

## [2.2.0]  -  2026-03-05

### Added
- Google Search Console integration (Service Account JWT auth  -  paste JSON, done)
- Striking distance detection  -  pages at position 5-20 get automatic priority boost
- 9th composite dimension: GSC boost (position, impressions, CTR)
- Money page system  -  mark conversion pages, track link goals, anchor diversity monitoring
- Force-directed graph visualization with 7 node types (hub, normal, orphan, dead-end, bridge, striking, money)
- 6-tab dashboard: Overview, Money Pages, Striking Distance, Graph, Posts, Settings
- Interactive weight sliders for all 9 scoring dimensions with live normalization
- Per-post metabox with GSC metrics, keyword sources, anchor distribution bars
- One-click removal of ALL LOOM-inserted links (Settings -> Danger Zone)
- Keyword enrichment from GSC real search queries (layer 4)
- Link velocity dimension (replaces equity  -  measures link acquisition rate vs page age)
- Pillar page detection from loom_clusters table
- Upgrade migration with automatic weight re-normalization
- WordPress admin notices moved above LOOM dashboard (no overlap)

### Fixed
- `get_all_with_embeddings()` was missing 9 columns  -  money page and GSC data never reached composite scoring
- Inline embedding used different formula than batch (title×1 vs title×3)
- `$incoming` undefined in `format_for_prompt()`  -  deficit always equaled goal
- `cluster_boost` exceeded 0.0-1.0 range (was 1.5 for pillar pages)
- `is_pillar` was dead code  -  column didn't exist in loom_index
- Triple duplicate DROP TABLE in uninstall.php
- Money toggle used wrong AJAX variable (`ajaxurl` instead of `loom_ajax.ajaxurl`)

### Changed
- GSC auth: OAuth2 (6 steps) -> Service Account (paste JSON, 1 step)
- Equity dimension replaced with Link Velocity (no longer duplicates orphan signal)
- Default weights rebalanced for 9 dimensions (sum = 1.00)
- CSS redesigned: DM Sans font, teal palette, new badge system
- Dashboard completely rewritten with tabbed interface
