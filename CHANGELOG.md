# Changelog

All notable changes to the Dsquared Hub Connector will be documented in this file.

This project adheres to [Semantic Versioning](https://semver.org/).

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
