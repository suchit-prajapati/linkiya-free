=== Linkiya ===
Contributors: mypluginstore
Tags: internal links, seo, gutenberg, internal linking, link building
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically find and apply internal links inside the Gutenberg editor. Link kiya? Done.

== Description ==

**Linkiya** helps you build a stronger internal linking structure without the manual work. Open any post in Gutenberg, run a scan, review the suggested links, and apply them in one click.

Internal links are one of the most impactful on-page SEO improvements you can make — and one of the most time-consuming to do manually. Linkiya automates the discovery so you can focus on writing.

= How it works =

1. Open any post or page in the Gutenberg editor
2. Click the **Linkiya** icon in the sidebar panel
3. Click **Run Internal Linking** to scan your content
4. Review the suggested links — check the ones you want
5. Edit the anchor text if needed using the ✏️ button
6. Click **Apply Links** — your content is updated instantly
7. Hit **Update** to save your post

= Free Features =

* **Gutenberg sidebar panel** — works natively inside the block editor
* **Smart keyword matching** — extracts keywords and two-word phrases from published post titles
* **Bigram matching** — "control anger" links before just "anger" for more precise suggestions
* **Stop word filtering** — ignores common words like "the", "and", "how"
* **Whole word matching** — never links partial words
* **Review before applying** — check or uncheck each suggestion before applying
* **Anchor text editing** — edit anchor text per suggestion before applying
* **Max links per post** — limit how many suggestions appear per scan
* **Link target setting** — configure target="_blank" globally
* **nofollow / rel settings** — configure rel="nofollow", noopener, noreferrer
* **Minimum keyword length** — set minimum word length for matching
* **Post exclusion** — exclude specific posts from appearing as link targets
* **Import / export settings** — back up and migrate your settings as JSON

= Linkiya Pro =

[Linkiya Pro](https://www.mypluginstore.com/linkiya) is a separate plugin that extends Linkiya with 21 advanced features:

**Linking Power**
* Custom post type support
* Per-post keyword configuration
* Same-category filter
* Taxonomy and archive linking
* Anchor text diversification
* External and affiliate link support
* Full post blacklist
* Auto-link mode
* Page builder support (Elementor, Divi, Beaver Builder)
* WooCommerce deep integration

**Bulk and Automation**
* Bulk linking across your entire site
* Smart filters for auto-linking
* Multi-language support (WPML and Polylang)

**Reports and Analytics**
* Link report dashboard
* Inbound link tracking
* Orphan page detection
* Broken link scanner
* Click analytics
* Full analytics dashboard
* AI-powered link intent analysis

== Installation ==

1. Upload the `linkiya` folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** menu in WordPress
3. Open any post in the Gutenberg editor
4. Click the **Linkiya** icon in the right sidebar to get started

== Frequently Asked Questions ==

= Does it work with the Classic Editor? =
The Gutenberg sidebar requires the block editor. Linkiya is built for the modern WordPress block editor.

= Does it work with custom post types? =
Custom post type support is available in Linkiya Pro. The free version scans posts and pages.

= Will it add links automatically without my review? =
No. You always review and approve suggestions before they are applied. An optional Auto-Link mode is available in Pro, but it is opt-in and disabled by default.

= Does it conflict with other SEO plugins? =
No. Linkiya does not modify SEO meta data, sitemaps, or schema. It only adds anchor tags to your post content inside the block editor.

= Does it slow down my site? =
No. The plugin only runs inside the Gutenberg editor when you click Run Internal Linking. It adds no front-end scripts or styles to your live site.

= Where is my data stored? =
All data is stored in your own WordPress database. Linkiya does not connect to any external server.

== Source Code ==

The Gutenberg sidebar is built from source available at:
https://github.com/suchit-prajapati/linkiya-free/tree/main/src

Build tool: webpack (via @wordpress/scripts)
To rebuild: `npm install && npm run build`

== External Services ==

The free version of this plugin does not connect to any external services. All processing happens locally on your WordPress installation.

Linkiya Pro (a separate plugin sold at mypluginstore.com) optionally connects to mypluginstore.com solely for license key verification. When a license key is activated or verified:
* The license key and your site URL are sent to mypluginstore.com
* No post content, user data, or personal information is transmitted
* Terms of service: https://www.mypluginstore.com/terms
* Privacy policy: https://www.mypluginstore.com/privacy

== Screenshots ==

1. Gutenberg sidebar panel showing link suggestions
2. Reviewing and selecting suggestions before applying
3. Editing anchor text per suggestion
4. Settings page — link attributes, exclusions, import and export

== Changelog ==

= 1.0.0 =
* Initial release of Linkiya
* Gutenberg sidebar with smart keyword matching
* Review before applying workflow
* Anchor text editing per suggestion
* Max links per post setting
* Link target and rel settings
* Post exclusion by ID
* Minimum keyword length setting
* Import and export settings as JSON

== Upgrade Notice ==

= 1.0.0 =
Initial release.