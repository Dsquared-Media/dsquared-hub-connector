# Changelog

All notable changes to the Dsquared Hub Connector will be documented in this file.

This project adheres to [Semantic Versioning](https://semver.org/).

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
