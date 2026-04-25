# Changelog

All notable changes to the Dsquared Hub Connector will be documented in this file.

This project adheres to [Semantic Versioning](https://semver.org/).

## [1.13.1] - 2026-04-25

### Added
- **Event beacons to the Hub** for the agency rollup view. Captured events are batched client-side and flushed to `hub.dsquaredmedia.net` every 30s + via `navigator.sendBeacon` on tab close (so the last few events aren't lost). New "Send event counts to the Hub" toggle on the Event Tracking master switch — opt-in, default ON. No personal data sent — just event names + minimal params (CTA text, file name, etc).

## [1.13.0] - 2026-04-24

### Added
- **Event Tracking module** — new D2 Hub sub-page. Lets non-technical clients turn on common GTM-style events without ever opening GTM. Every event fires to `window.dataLayer` (GTM) **and** `gtag()` (GA4) so it works regardless of which you have.
  - **Preset events** (each a single checkbox): `form_submit` (auto-detects Contact Form 7, Gravity, WPForms, Elementor, plus a generic fallback), `phone_click` (any `tel:` link), `email_click` (any `mailto:`), `outbound_click` (hostname ≠ yours), `scroll_depth` (25/50/75/100%), `video_play` / `video_complete` (YouTube + Vimeo postMessage), `cta_click` (configurable selector list), `file_download` (pdf/doc/xls/ppt/zip/csv/txt/mp3/mp4).
  - **Custom events** — name + CSS selector pair. "Fire `book_now_click` when anyone clicks `.book-now-btn`."
  - **JavaScript API** — `window.dhcTrack('checkout_started', { plan: 'pro' })` for developer-defined events.
  - **PHP hook** — `do_action('dhc_track_event', 'lead_scored', [ 'score' => 42 ])` for other plugins / themes. Queued as an option + flushed on the next client page load so server-initiated events land in GA4.
- Event listeners run entirely in-page (no Hub round-trip) so tracking has zero added latency and keeps working even when the Hub is offline.

## [1.12.5] - 2026-04-24

### Added
- **Month-over-month delta pills on KPI tiles.** Clicks / Impressions / CTR / Avg Position each show a ▲ or ▼ pill with the % change vs the preceding period of equal length. Avg Position shows the raw position delta (e.g. "−2.1") instead of %, because clients read position moves more intuitively that way. Pills are color-coded (green improved / red declined / gray flat) with a ±1% deadzone so noise doesn't look alarming.
- **Backend**: `/api/plugin/dashboard` now runs the previous-period GSC summary query in parallel with the current one (no extra latency) and returns `previous_summary` + `previous_period` in the payload.

## [1.12.4] - 2026-04-24

### Added
- **301 redirect manager in Link Scanner.** Every row in "404 errors from real visitors" gets a "+ Add 301" button that pre-fills a redirect form — type the destination, save, done. Redirects fire on the very next visit (WP template_redirect hook at priority 1 — before WP can 404 the request). Full CRUD table of active redirects with hit counts. No .htaccess / server config required. Supports 301 / 302 / 307 / 308.
- **Analytics "Scan homepage" button.** Fetches your homepage (3-step loopback fallback for CF-fronted sites) and detects existing `G-XXXX` / `GTM-XXXX` tags from other plugins (Yoast, Site Kit, MonsterInsights, theme). Shows a colored alert when a duplicate is detected so you can turn one off instead of firing events twice.
- **Colorful dashboard charts.** New SVG donut for device breakdown + traffic sources, horizontal bar chart for top countries. Zero JS lib — pure SVG with inline paletted colors.

### Changed
- **API key input no longer has the eye/reveal toggle.** Stays masked for privacy — admins can paste a new key any time, but never see the existing one in plaintext.
- **Modules tab cards** changed from indigo-tinted background when active to pure white with an indigo stroke. Matches the rest of the plugin's design and the Hub's card style.
- **Removed "Top page" KPI tile** per user feedback.
- **Alignment fix (CWV + Activity Log)** — WP's `.widefat` table had left/right padding that was pushing Core Web Vitals + Recent Activity cards visibly further right than the others. Neutralized inside `.dhc-wrap`.

## [1.12.3] - 2026-04-24

### Added
- **Date range selector** on the Hub Dashboard (7 / 14 / 28 / 30 / 60 / 90 days). Plugin cache is bucketed per-window so switching ranges doesn't force a round-trip, and the Hub backend now honors the `days` query param.
- **More KPI tiles** — "Top keyword" (highest-clicks query this period, with position), "Top page" (highest-clicks landing page), "Top device / source / country" (populated when the Hub payload includes these, coming in the companion Hub release). Dashboard now renders up to 14 tiles, aligned in a strict 4-column grid.

### Changed
- **Pink header icon** — the chart glyph on the Hub Dashboard header card now renders in pink (`#EC4899`) so it pops against the dark header.
- **4-column KPI grid enforced** — explicit `grid-template-columns: repeat(4, 1fr)` with a 2-col fallback under 980px and 1-col under 540px.
- **Fixed alignment** of cards on Site Health and Activity Log tabs — they were inheriting slightly different horizontal margins than the Connection tab. All `.dhc-tab-content .dhc-card` now gets `width: 100%; margin-left: 0; margin-right: 0`.

## [1.12.2] - 2026-04-23

### Changed
- **Admin menu repositioned.** Renamed "Dsquared Hub" → "D2 Hub" and moved the menu to position 3 (directly under Dashboard, matching Site Kit's placement). Clients see us as first-class, not buried.
- **Dashboard KPI strip expanded** from 4 tiles to up to 10. Derived from data the Hub already returns, so no Hub-side API changes needed: Keywords in Top 3 / Top 10 (from top_queries positions), Pages getting traffic (top_pages count), 14-day Sessions total (sum of traffic sparkline), CTR quick-wins count, Tracked changes, Drafts ready. Only shows tiles that have data.
- **Activity Log labels** — default case no longer says "Unknown action". Added friendly labels for profile_synced, content_decay_scan, heartbeat_sent, llms_txt_generated, inventory_snapshot, link_scan_completed. Legacy rows without an action now show "Event recorded".
- **llms.txt / llms-full.txt / IndexNow key** rendering — URLs were inheriting WP admin's default link color, showing as a jarring pink/red. Now styled as code-chips with a neutral gray background and subtle indigo link color.

### Fixed
- **Scrape homepage "Network error" on CF-fronted sites.** The loopback fetch was hitting Cloudflare in front of the WP site, which blocked the server's own outbound IP. Added a 3-step fallback chain: (1) normal loopback, (2) loopback with sslverify off, (3) direct curl to 127.0.0.1 with `Host:` header set — bypasses DNS and any CDN entirely. Errors from each attempt are bundled into the final failure message for diagnostic visibility.

## [1.12.1] - 2026-04-23

### Fixed
- **PHP 8+ fatal on plugin load.** `class-dhc-inventory.php` line 149 had an unparenthesized nested ternary (`a ? b : c ? d : e`) in the MySQL version lookup. PHP 7.4 deprecated the ambiguous form; PHP 8.0+ makes it a hard fatal. Added explicit parentheses so the inventory module (and the plugin overall) loads again on PHP 8 hosts.

## [1.12.0] - 2026-04-23

### Added
- **Loopback fetch endpoint** (`POST /dsquared-hub/v1/fetch-page`). Hub's audit crawler calls this to pull page HTML from the WordPress server itself — bypassing any Cloudflare, Sucuri, or host-level bot protection in front of the site. The request originates from the WP server's own IP, so external firewalls never see it. Clients with this plugin installed + active don't have to deal with "blocked by bot protection" audit failures anymore.
- **Open-proxy guard**: the endpoint only accepts URLs pointing at this WP install's home_url() host (with www/non-www normalization). Can't be weaponized to scrape arbitrary third-party sites even with a valid API key.
- Response capped at 2 MB and request requires the existing X-DHC-API-Key.

## [1.11.1] - 2026-04-23

### Fixed
- **`/llms.txt` self-healing** — sites where another plugin flushed rewrite rules after ours would 404 on `/llms.txt` and `/.well-known/ai-plugin.json`. On every admin page load, we now check for the llms.txt rewrite rule and re-flush if it's missing. Cheap (one `get_option` read in the hot path).
- **`/llms.txt` fallback path** — REQUEST_URI strict-match missed URLs with query strings (e.g. `/llms.txt?utm_source=…`) and case variations. Path is now parsed via `parse_url()` and normalized to lowercase before matching.

## [1.11.0] - 2026-04-23

### Added
- **Hub Dashboard — at-a-glance analytics in WordPress admin.** A Site Kit-style landing page that renders your actual data from the Dsquared Hub right inside WP admin, the second a client clicks into the plugin. No more "what am I paying for?" moment after connecting.
  - **KPI tiles** — last 7 days of Clicks, Impressions, Avg Position, CTR (from GSC).
  - **14-day sessions trend** — SVG sparkline from GA4 + 14-day total.
  - **Quick wins** — CTR-gap keywords where you rank 2-10 but CTR is <60% of expected. Each row shows the current CTR, expected CTR, and the estimated click recovery from a title/meta rewrite.
  - **Top search queries** and **Top landing pages** — side-by-side tables of your highest-traffic queries + URLs, with position.
  - **Recent SEO changes tracked** — the Hub's SEO Watch rows for this site showing Δ position + Δ clicks per shipped AutoReason or Deep Page Analysis win.
  - **Fresh content drafts** — recent Content Studio drafts ready to review.
  - **Recent Hub activity** — latest 5 AI runs (competitor intel, authority recs, briefs, etc.).
- Data blocks only render when they have content, so an early-life site doesn't show empty panels.
- Refresh button (manual cache-bust) + plugin-side transient cache with 30-minute TTL so we don't hammer GSC / GA4 / the Hub on every page load.
- **Dashboard is now the default landing page** when you click "Dsquared Hub" in the admin sidebar. The Connection settings form moved to its own sub-menu link.

### Changed
- Admin menu order: **Dashboard → Connection → Link Scanner → Analytics**. Asset enqueue unchanged — all sub-pages share the same CSS shell plus a small inline block just for the dashboard's KPI grid + sparkline.

### Requires
- Hub endpoint `GET /api/plugin/dashboard` (shipped alongside this release on `hub.dsquaredmedia.net`). The plugin authenticates via the existing X-DHC-API-Key — no new credentials needed.

## [1.10.0] - 2026-04-23

### Added
- **Body content push** — new `POST /dsquared-hub/v1/posts/content` endpoint that accepts `{post_id|url, content_html, post_title?, revision_note?, dry_run?}` and applies the update via `wp_update_post()`. Core WP auto-creates a revision, so rollback is available via Posts → Edit → Revisions. Closes the AutoReason loop in the Hub — winning body rewrites can now ship directly to WP without copy-paste. Accepts `wp_kses_post`-safe HTML; scripts + event handlers stripped. Gated on `seo_meta` subscription module.
- **Bulk SEO meta rescue** — new `POST /dsquared-hub/v1/seo-meta/bulk` endpoint that accepts up to 100 `{post_id|url, meta_title?, meta_description?, ...}` updates per call. Mirrors the v1.9.0 bulk alt-text pattern. Per-item errors are returned inline; the batch only 500s if auth or the module gate fails. Re-uses the single-item SEO-plugin-detect + fallback logic so Yoast / Rank Math / AIOSEO / SEOPress behavior is consistent.
- **Daily site inventory push** — new `DHC_Inventory` module pushes a snapshot of the site to the Hub once a day via WP-Cron:
  - Post / page / attachment / comment counts
  - Last 10 publishes from the previous 14 days
  - Active theme name + version + parent
  - Active plugins with version, author, WP/PHP requirements
  - WP version, PHP version, MySQL version, memory limit, upload max, disk free
  - Cache plugin detected (WP Rocket / W3TC / LiteSpeed / WP Fastest / Autoptimize / WP Engine)
  - Multisite / HTTPS / debug flags
  Hub side receives at `POST /api/plugin/inventory` and persists in `plugin_site_inventory` — one row per (user, site_url) always holding the latest. Unlocks "what changed on your site this week" in the Hub's Today widget + plugin-conflict diagnosis + caching-off alerts.
- **GA4 + GTM injection** — new `DHC_Analytics` module. Paste your GA4 Measurement ID (`G-XXXXXXXXXX`) and/or GTM Container ID (`GTM-XXXXXXX`) on the new **Dsquared Hub → Analytics** sub-page. The plugin injects the correct snippets on `wp_head` (gtag + GTM head) and after `wp_body_open` (GTM noscript), with admin / feed / REST / AJAX requests automatically skipped. Format-validates IDs on save. Removes the "install GA4 correctly without editing functions.php or using Site Kit" support task.
- **Link Scanner sub-page** (new nav item **Dsquared Hub → Link Scanner**) with two complementary features:
  - **404 logger** — hooks `template_redirect`, buckets 404s by path (increments count on repeats instead of spamming), filters bot user-agents, captures referer + last-seen timestamp. Shows the top-50 paths sorted by hit count in the admin UI.
  - **Weekly broken-link scanner** — `wp-cron` job iterates the 200 most-recently-modified posts + pages, extracts `<a href>` URLs, HEAD-pings each (falls back to GET on 405/501), and records any 4xx/5xx + connection failures with the source post. De-dupes URLs across posts so the same URL is only checked once per run. Up to 500 findings retained.
  - Admin UI has **Scan now** + **Clear log** actions.
  - Also exposed at `GET /dsquared-hub/v1/link-scan` so the Hub's Today widget can surface findings.

### Changed
- Admin menu now has proper sub-menu pages under **Dsquared Hub**: Connection (main) / Link Scanner / Analytics. Asset enqueue matches sub-pages by prefix so future additions pick up the styles automatically.
- Activation + admin_init both self-heal the new cron schedules (daily inventory, weekly link scan) so a cleared or migrated cron table recovers on the next admin page load.

### Gating
- Body content push + bulk meta ride the `seo_meta` subscription module flag (same as the existing single-item meta endpoint).
- Link Scanner API endpoint gates on `site_health`.
- Analytics injection is not subscription-gated — pure utility.

## [1.9.1] - 2026-04-23

### Changed
- **Admin UI refresh — cleaner white/black/grey palette.** Less warm-beige, less pastel. Specific changes:
  - Card borders switched from warm slate (`#e2e8f0`) to neutral zinc (`#eaeaea`). Same applies to tab container, inputs, and notice boxes.
  - Core Web Vitals tiles are now all-white with a 3px status bar at the bottom (was pastel-green / pastel-amber / pastel-red tints across the whole tile).
  - Only "Poor" CWV values render in red; good + needs-work render in neutral black so your eye isn't pulled to every number equally.
  - Status badges on the dark header switched from saturated green/amber backgrounds to translucent white with a low-saturation dot. Reads against navy without candy-coloring the top strip.
  - Notice cards (warning + info) dropped the amber/blue backgrounds in favor of neutral zinc with a 3px left-accent border.
  - Inputs use single-weight neutral border (was 2px warm slate) with a smaller focus ring.
  - Sync-box icon background recolored from blue tint to neutral zinc.
- Net result: the plugin screen looks less 2018-dashboard-colorful and more 2026-admin-clean. Typography, logo, and accent indigo unchanged.

## [1.9.0] - 2026-04-22

### Added
- **Alt Text push endpoint** — new `POST /dsquared-hub/v1/media/alt` route that lets the Hub write `alt_text` to WordPress media attachments using the existing `X-DHC-API-Key` header. Supports single updates (`{ media_id, alt_text }`) and bulk batches up to 50 per call (`{ updates: [...] }`). Fixes the silent failure where the Hub's Alt Text Generator appeared to push but nothing updated — the Hub was hitting core `/wp-json/wp/v2/media/:id`, which WordPress doesn't authenticate with our custom header (it needs Application Password or cookie+nonce). This new route uses the same plugin-authenticated pattern as `/seo-meta` and `/schema`.
- Writes alt text via `update_post_meta( $id, '_wp_attachment_image_alt', $alt )`. Sanitizes input and caps at 250 chars. Treats "new value equals existing value" as a successful no-op rather than a failure. Logs the aggregate event to the plugin's Event Log so admins can see push activity.

### Gating
- The alt-text endpoint rides the `seo_meta` subscription-module flag — if you have SEO Meta Sync, you have alt-text push.

## [1.8.1] - 2026-04-21

### Fixed
- `/llms.txt` no longer 404s on sites that haven't filled in AI Discovery settings yet. Falls back to a profile built from the WP blogname, blogdescription, and any schema.org LocalBusiness / Organization JSON-LD on the homepage. Cached for 6 hours. AI crawlers always get something useful.
- Rewrite rules now auto-flush on plugin version upgrade. Previously new routes (like `/llms.txt`) wouldn't take effect after an auto-update because the activation hook only runs on manual activate — so the rule existed in code but not in WordPress's rewrite cache.

## [1.8.0] - 2026-04-21

### Added
- **Scrape homepage** button on the AI Discovery tab. One click reads the site's homepage + schema.org LocalBusiness JSON-LD and auto-fills the Business Name, Description, Services, Phone, Email, Address, Hours, and Service Areas fields. Only fills empty fields so manual input is never overwritten.

### Changed
- Form field styling refreshed to match Dsquared brand. Indigo focus ring, bolder labels, tinted input backgrounds — fixes the "white on white" readability issue on the AI Discovery settings page.

## [1.7.1] - 2026-04-21

### Fixed
- API key validation now accepts base64url characters (`-` and `_`) alongside the legacy alphanumeric-only format. The Hub's current key generator emits base64url-encoded 43-character bodies; the stricter `ctype_alnum` check was rejecting every freshly-generated key.

## [1.7.0] - 2026-04-21

### Added
- **Auto-updates enabled by default.** The plugin now opts itself into WordPress's `auto_update_plugin` filter, so new releases are applied automatically overnight without requiring the user to click "Enable auto-updates" in the Plugins screen. Site owners can still opt out by setting the `dhc_disable_auto_update` option to `true`.

### Fixed
- Tightened the Hub's `/api/plugin/update-check` fallback so it queries GitHub Releases dynamically instead of returning a hard-coded (and drifting) version string.

## [1.5.0] - 2026-04-03

### Added
- **AI Discovery module** — Generates `llms.txt` and `llms-full.txt` for AI search engine discovery, injects LocalBusiness schema, and pings IndexNow (Bing/Yandex) when content changes. Includes a business profile editor in the admin settings.
- **Content Decay Alerts module** — Scans all published posts for freshness, flags stale content (6+ months yellow, 12+ months red), and reports findings to the Hub for content health monitoring.
- **Form Submission Capture module** — Hooks into Contact Form 7, Gravity Forms, WPForms, Elementor Forms, and Ninja Forms. Filters spam in real-time using disposable email detection, keyword blocking, velocity limiting, and gibberish detection. Sends clean leads to the Hub pipeline without storing personal data locally.
- **Self-hosted auto-updater** — Checks `hub.dsquaredmedia.net` for new plugin versions and integrates with WordPress's native update system. Users see update notifications in the admin just like any other plugin.
- **WordPress Privacy Policy integration** — Hooks into WordPress's privacy policy page generator to disclose data collection practices. Includes GDPR-compliant personal data exporter and eraser (returns empty since no PII is stored).
- **GPL v2 License file** for WordPress.org compliance.
- **WordPress-format readme.txt** with proper sections for potential directory submission.
- AI Discovery settings tab in admin UI with business profile editor.
- New module cards for AI Discovery, Content Decay, and Form Capture in the Modules tab.
- PHP 7.4 and WordPress 5.8 minimum version checks on activation.

### Changed
- Version bumped from 1.0.0 to 1.5.0.
- Tier module mapping expanded: Growth tier now includes `content_decay`, Pro tier includes `ai_discovery`, `content_decay`, and `form_capture`.
- Admin UI updated with new tabs and module cards.
- Uninstall routine updated to clean up new module options and cron jobs.
- REST API expanded with new endpoints for AI Discovery, Content Decay, and Form Capture.

## [1.0.0] - 2026-04-02

### Added
- Initial release.
- **Auto-Post to Draft module** — Receive blog content from the Hub and create WordPress draft posts. Supports title, body, categories, tags, excerpts, and featured images.
- **Schema Injector module** — Push JSON-LD structured data from the Hub's Schema Generator into page `<head>`. Supports per-post and site-wide schemas.
- **SEO Meta Sync module** — Sync optimized meta titles, descriptions, and OG data. Compatible with Yoast, Rank Math, AIOSEO, and SEOPress.
- **Site Health Monitor module** — Collect real-user Core Web Vitals (LCP, CLS, INP, TTFB, FCP) via a lightweight ~2KB frontend script.
- API key validation with 12-hour caching and offline fallback.
- Admin settings page matching Hub design language (dark navy, Plus Jakarta Sans, indigo accent).
- Graceful subscription lapse handling — features disable without affecting the website.
- Activity log for tracking Hub actions.
- REST API endpoints for all modules.
