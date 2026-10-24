# Immutable WordPress hook fixtures

Test-only copies from official WordPress 6.8.3, licensed under GPL-2.0-or-later as WordPress core. The repository's GPL license is retained in LICENSE. No local edits. Never package tests with the plugin.

| File | Official source | SHA-256 |
| --- | --- | --- |
| plugin.php | https://raw.githubusercontent.com/WordPress/WordPress/6.8.3/wp-includes/plugin.php | 87a819241f339b4702b8e986dd2bd9f817755bae61d4631a1db11fdc34149c31 |
| class-wp-hook.php | https://raw.githubusercontent.com/WordPress/WordPress/6.8.3/wp-includes/class-wp-hook.php | 711375e96ed5b31d2435e079602168c4d15032e4b7bd96271c89eac039600766 |

The upload regression validates these hashes before executing the real hook implementation. The SVG provider and missing-DOM behavior remain explicitly modeled; this is not a full WordPress/provider installation test.
