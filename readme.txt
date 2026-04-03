=== Dsquared Hub Connector ===
Contributors: dsquaredmedia
Tags: seo, schema, core web vitals, auto post, ai discovery
Requires at least: 5.8
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect your WordPress site to Dsquared Media Hub — auto-post drafts, inject schema markup, sync SEO meta, and monitor site health.

== Description ==

The **Dsquared Hub Connector** bridges your WordPress website directly to your Dsquared Media Hub account. It allows the Hub to push content, SEO settings, and schema markup directly to your site, while reporting real-user performance metrics back to your dashboard.

All features are subscription-gated via an API key. If your subscription lapses, the plugin gracefully disables its features without affecting your website's functionality or breaking any existing content.

### Core Modules (v1.5.0)

*   **Auto-Post to Draft:** Receive blog content from the Hub's Blog Writer and create WordPress draft posts automatically. Supports title, body, categories, tags, and featured images. (Starter+ Tier)
*   **Schema Injector:** Push JSON-LD structured data from the Hub's Schema Generator directly into your pages. Supports per-post and site-wide schemas. (Growth+ Tier)
*   **SEO Meta Sync:** Sync optimized meta titles, descriptions, and OG data from the Hub's Page Optimizer. Compatible with Yoast, Rank Math, AIOSEO, and SEOPress. (Growth+ Tier)
*   **Site Health Monitor:** Collect real-user Core Web Vitals (LCP, CLS, INP, TTFB, FCP) and report them to the Hub for monitoring. Uses a lightweight ~2KB script. (Pro Tier)
*   **AI Discovery:** Generate an AI-readable business profile (`llms.txt`), inject LocalBusiness schema, and automatically ping IndexNow (Bing/Yandex) when content changes to ensure AI search engines know you exist. (Pro Tier)
*   **Content Decay Alerts:** Monitor your published posts for freshness and report stale content back to the Hub so you know what needs updating. (Growth+ Tier)
*   **Form Submission Capture:** Hook into popular form plugins (Contact Form 7, Gravity Forms, WPForms, Elementor) to capture leads, filter spam in real-time, and send clean lead data to your Hub pipeline. (Pro Tier)

### Privacy & Data Collection

This plugin collects anonymous Core Web Vitals performance data from your visitors to help you monitor site health. If the Form Submission Capture module is enabled, it processes form submissions in real-time to filter spam and forwards clean leads to your Hub account. It does not store personal data locally.

== Installation ==

1.  Upload the `dsquared-hub-connector` folder to the `/wp-content/plugins/` directory, or install the ZIP file via the WordPress admin (Plugins &rarr; Add New &rarr; Upload Plugin).
2.  Activate the plugin through the 'Plugins' menu in WordPress.
3.  Navigate to the new **Dsquared Hub** menu item in your WordPress admin sidebar.
4.  Enter your Hub API key (found in your Hub Account settings) and click "Save & Validate".
5.  Once connected, enable the modules you want to use on the "Modules" tab.

== Frequently Asked Questions ==

= What happens if my subscription expires? =

If your Dsquared Media Hub subscription expires, the plugin will gracefully disable its features. Your website will not break, and all previously synced content, schema, and SEO meta will remain intact. You simply won't be able to push new changes from the Hub or collect new Site Health data until you renew.

= Does this plugin slow down my site? =

No. The plugin is designed to be extremely lightweight. The Site Health Monitor script is only ~2KB and runs asynchronously. The REST API endpoints only execute when the Hub specifically calls them.

= Which SEO plugins are supported for Meta Sync? =

The SEO Meta Sync module automatically detects and integrates with Yoast SEO, Rank Math, All in One SEO (AIOSEO), and SEOPress. If none of these are installed, it falls back to outputting native meta tags in the `<head>`.

= Which form plugins are supported for Lead Capture? =

The Form Submission Capture module currently supports Contact Form 7, Gravity Forms, WPForms, Elementor Forms, and Ninja Forms.

== Screenshots ==

1. The Connection tab showing API key validation and subscription status.
2. The Modules tab where you can enable/disable specific features based on your tier.
3. The Site Health tab showing real-user Core Web Vitals metrics.
4. The Activity Log showing recent actions pushed from the Hub.

== Changelog ==

= 1.5.0 =
*   New: AI Discovery module — generates `llms.txt`, LocalBusiness schema, and pings IndexNow.
*   New: Content Decay Alerts module — monitors post freshness and reports stale content.
*   New: Form Submission Capture module — hooks into popular form plugins, filters spam, and sends leads to the Hub.
*   New: Self-hosted auto-updater integration.
*   New: WordPress Privacy Policy integration.
*   Update: Admin UI expanded to support new modules and AI Discovery settings.

= 1.0.0 =
*   Initial release.
*   Core modules: Auto-Post to Draft, Schema Injector, SEO Meta Sync, Site Health Monitor.
