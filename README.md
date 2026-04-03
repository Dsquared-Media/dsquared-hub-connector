# Dsquared Hub Connector

**Connect your WordPress site to the Dsquared Media Hub** — auto-post drafts, inject schema markup, sync SEO meta, monitor site health, make your business visible to AI search, detect stale content, and capture leads with built-in spam filtering. All features are subscription-gated and will gracefully disable if your subscription lapses without affecting your website.

![Version](https://img.shields.io/badge/version-1.5.0-5661FF)
![WordPress](https://img.shields.io/badge/WordPress-5.8%2B-21759b)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4)
![License](https://img.shields.io/badge/license-GPL--2.0-green)

---

## Overview

The Dsquared Hub Connector is a lightweight WordPress plugin that bridges your WordPress site with the [Dsquared Media Hub](https://hub.dsquaredmedia.net). It enables seamless content publishing, SEO optimization, AI search visibility, lead capture, and performance monitoring — all controlled from the Hub dashboard.

---

## Modules

### v1.0 — Core Modules

| Module | Description | Tier |
|--------|-------------|------|
| **Auto-Post to Draft** | Receive blog content from the Hub and create WordPress draft posts. Supports title, body, categories, tags, excerpts, and featured images. | Starter+ |
| **Schema Injector** | Push JSON-LD structured data from the Hub's Schema Generator into page `<head>`. Supports per-post and site-wide schemas. | Growth+ |
| **SEO Meta Sync** | Sync meta titles, descriptions, and OG data. Compatible with Yoast, Rank Math, AIOSEO, and SEOPress. Falls back to native meta if no SEO plugin detected. | Growth+ |
| **Site Health Monitor** | Collect real-user Core Web Vitals (LCP, CLS, INP, TTFB, FCP) via a lightweight ~2KB frontend script and report to the Hub. | Pro |

### v1.5 — New Modules

| Module | Description | Tier |
|--------|-------------|------|
| **AI Discovery** | Generate an AI-readable business profile (`llms.txt`, `llms-full.txt`), inject LocalBusiness/Service schemas, and ping IndexNow (Bing/Yandex) when content changes. Makes your business visible to ChatGPT, Gemini, Perplexity, Claude, and other AI platforms. | Pro |
| **Content Decay Alerts** | Monitor published posts for freshness. Posts not updated in 6+ months are flagged yellow, 12+ months flagged red. Reports stale content to the Hub for review and refresh recommendations. | Growth+ |
| **Form Submission Capture** | Hook into Contact Form 7, Gravity Forms, WPForms, Elementor Forms, and Ninja Forms. Built-in spam filtering (disposable emails, keyword blocking, velocity limiting, gibberish detection). Sends clean leads to the Hub pipeline — no personal data stored locally. | Pro |

---

## Installation

1. Download the plugin ZIP file or clone this repository
2. Upload to `wp-content/plugins/dsquared-hub-connector/`
3. Activate the plugin in WordPress Admin > Plugins
4. Navigate to **Dsquared Hub** in the admin sidebar
5. Enter your API key from [Hub > Account > API Keys](https://hub.dsquaredmedia.net/dashboard.html#account)
6. Enable the modules you want to use

---

## Subscription & Graceful Degradation

This plugin is designed with a **zero-disruption guarantee**:

- If the plugin is **disabled** or your **subscription lapses**, it will **not interrupt your website** in any way
- Hub features will simply become unavailable until reactivated
- Your existing content, schema markup, and SEO settings are preserved
- No frontend scripts are loaded when the subscription is inactive
- **Keeping an active subscription is suggested** for continued access to all features

### Tier Access

| Tier | Modules Available |
|------|-------------------|
| **Starter** | Auto-Post to Draft |
| **Growth** | Auto-Post, Schema Injector, SEO Meta Sync, Content Decay Alerts |
| **Pro** | All modules including AI Discovery, Site Health Monitor, Form Capture |

---

## REST API Endpoints

All endpoints are available at `your-site.com/wp-json/dsquared-hub/v1/`

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| GET | `/status` | None | Plugin version, connection status, module availability |
| POST | `/post` | API Key | Create a draft post from Hub content |
| POST | `/schema` | API Key | Push schema markup to a post or site-wide |
| POST | `/seo-meta` | API Key | Sync SEO meta data to a post |
| POST | `/health` | None | Receive Core Web Vitals beacon data |
| POST | `/ai-discovery` | API Key | Push business profile for AI discovery |
| GET | `/content-decay` | API Key | Trigger content decay scan and get results |
| GET | `/leads` | API Key | Get lead capture statistics |

Authentication is via the `X-DHC-API-Key` header.

---

## AI Discovery

The AI Discovery module makes your business visible to AI search platforms by:

1. **Generating `llms.txt` and `llms-full.txt`** — Plain-text business summaries served at your site root, following the emerging LLM discovery standard
2. **Injecting LocalBusiness + Service schemas** — Structured data that AI engines parse to understand what your business offers
3. **Pinging IndexNow** — Notifies Bing and Yandex instantly when content changes, rather than waiting for crawlers
4. **Building a machine-readable business profile** — Services, service areas, hours, FAQs, and contact info formatted for AI consumption

### Setup

1. Go to the **AI Discovery** tab in the plugin settings
2. Fill out your business information (name, services, areas, hours)
3. Click **Save & Generate Files**
4. The plugin creates `llms.txt`, `llms-full.txt`, and injects schemas automatically

---

## Form Submission Capture — Spam Filtering

The Form Capture module includes a multi-layer spam filter that runs before any lead is sent to the Hub:

| Filter | What It Catches | Method |
|--------|----------------|--------|
| **Disposable email detection** | Throwaway emails | Checks against ~3,000 known disposable domains |
| **Content pattern matching** | Spam phrases | Regex for casino, pharma, "buy now," Cyrillic spam |
| **Submission velocity** | Bot floods | Rate limiting per IP (max 3 submissions per 5 minutes) |
| **Gibberish detection** | Nonsense entries | Checks consonant-to-vowel ratio in text fields |

**Important:** The plugin does not store form data locally. It intercepts submissions, filters spam, and sends only clean leads to the Hub. The actual form submission still goes through normally to wherever the site owner has it configured.

---

## Auto-Updates

The plugin includes a self-hosted auto-updater that checks `hub.dsquaredmedia.net` for new versions. Updates appear in the WordPress admin just like any other plugin. The update check is cached for 12 hours and requires a valid API key.

---

## Privacy

The plugin integrates with WordPress's privacy policy page generator and includes GDPR-compliant data handling:

- **Site Health Monitor** collects anonymous performance metrics (no PII)
- **Form Capture** does not store personal data locally — only sends sanitized lead records to the Hub
- **AI Discovery** publishes only business information the site owner explicitly provides
- Full privacy policy text is auto-suggested for the site's privacy policy page

---

## SEO Plugin Compatibility

The SEO Meta Sync module automatically detects and writes to the correct meta fields for:

- **Yoast SEO** — `_yoast_wpseo_title`, `_yoast_wpseo_metadesc`, `_yoast_wpseo_focuskw`
- **Rank Math** — `rank_math_title`, `rank_math_description`, `rank_math_focus_keyword`
- **All in One SEO** — `_aioseo_title`, `_aioseo_description`, `_aioseo_keyphrases`
- **SEOPress** — `_seopress_titles_title`, `_seopress_titles_desc`

If no SEO plugin is detected, the plugin outputs meta tags directly in `wp_head`.

---

## File Structure

```
dsquared-hub-connector/
├── dsquared-hub-connector.php    # Main plugin bootstrap
├── uninstall.php                 # Clean removal
├── readme.txt                    # WordPress.org format readme
├── LICENSE                       # GPL v2
├── CHANGELOG.md                  # Version history
├── README.md                     # This file
├── includes/
│   ├── class-dhc-core.php        # Singleton controller
│   ├── class-dhc-api-key.php     # API key validation & tier mapping
│   ├── class-dhc-rest.php        # REST API endpoint registration
│   ├── class-dhc-admin.php       # Admin settings page
│   ├── class-dhc-updater.php     # Self-hosted auto-updater
│   ├── class-dhc-privacy.php     # Privacy policy integration
│   └── modules/
│       ├── class-dhc-auto-post.php     # Module 1: Auto-Post to Draft
│       ├── class-dhc-schema.php        # Module 2: Schema Injector
│       ├── class-dhc-seo-meta.php      # Module 3: SEO Meta Sync
│       ├── class-dhc-site-health.php   # Module 4: Site Health Monitor
│       ├── class-dhc-ai-discovery.php  # Module 5: AI Discovery
│       ├── class-dhc-content-decay.php # Module 6: Content Decay Alerts
│       └── class-dhc-form-capture.php  # Module 7: Form Submission Capture
├── admin/
│   ├── css/dhc-admin.css         # Admin styles (Hub design language)
│   └── js/dhc-admin.js           # Admin JavaScript
└── assets/
    └── dhc-site-health.js        # Frontend CWV collection script
```

---

## Requirements

- WordPress 5.8 or higher
- PHP 7.4 or higher
- Active Dsquared Media Hub subscription
- REST API enabled (default in WordPress)

---

## Support

For support, visit the [Dsquared Media Hub Help Center](https://hub.dsquaredmedia.net/dashboard.html#help-center) or contact [support@dsquaredmedia.net](mailto:support@dsquaredmedia.net).

---

**Built by [Dsquared Media](https://dsquaredmedia.net)** — Digital Marketing, Web Design & AI Solutions
