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

**Smart Matching**
* **Topic-driven matching** — analyses what your article is *about*, not just raw keyword presence. A post about Reiki finds the Reiki article even if the word appears only once
* **Bigram matching** — two-word phrases like "control anger" are matched before single words for more precise, meaningful suggestions
* **Frequency scoring** — keywords that appear more often in your content score higher, so the most relevant posts are suggested first
* **Whole word matching** — never links partial words or substrings
* **Stop word filtering** — ignores common words like "the", "and", "how" to keep suggestions meaningful
* **Minimum keyword length** — set the minimum word length for matching
* **Smart deduplication** — if "emotional boundaries" is already suggested, "emotional" alone is suppressed since it would produce no additional link

**Workflow**
* **Gutenberg sidebar panel** — works natively inside the block editor, no page reload needed
* **Review before applying** — check or uncheck each suggestion before anything is written to your post
* **Anchor text editing** — edit anchor text per suggestion before applying
* **Remove all links** — strip all Linkiya-applied links from a post in one click
* **Re-scan** — rescan after editing content without leaving the editor

**Link Control**
* **Max links per post** — limit how many suggestions appear per scan (0 = unlimited)
* **Link target setting** — configure `target="_blank"` globally
* **nofollow / rel settings** — configure `rel="nofollow"`, `noopener`, `noreferrer`
* **Post exclusion** — exclude specific posts by ID from appearing as link targets
* **Cross-type toggles** — control whether pages are suggested on posts and vice versa

**Settings**
* **Import / export settings** — back up and migrate your configuration as JSON
* **Cache invalidation** — keyword map is automatically rebuilt whenever a post is saved, deleted, or settings change

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

= How does topic-driven matching work? =
Linkiya analyses the content of the post you are editing and extracts its main topics — the words and phrases that appear most frequently and meaningfully. It then finds other published posts whose titles cover those same topics, scoring them by relevance. The most relevant posts appear at the top of the suggestion list.

= Does it suggest links based on my article's meaning, not just exact keywords? =
Yes. The topic-driven engine scores suggestions by how frequently a keyword appears in your content, so posts that are genuinely relevant to what you are writing about rank higher than posts that only share a single incidental word.

= Does it conflict with other SEO plugins? =
No. Linkiya does not modify SEO meta data, sitemaps, or schema. It only adds anchor tags to your post content inside the block editor.

= Does it slow down my site? =
No. The plugin only runs inside the Gutenberg editor when you click Run Internal Linking. It adds no front-end scripts or styles to your live site.

= Where is my data stored? =
All data is stored in your own WordPress database. Linkiya does not connect to any external server.

= How do I clear the keyword cache? =
Go to **Linkiya → Settings** and click Save. This rebuilds the keyword map immediately.

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
* Terms of service: https://www.mypluginstore.com/terms-of-service
* Privacy policy: https://www.mypluginstore.com/privacy-policy

== Screenshots ==

1. Gutenberg sidebar panel showing link suggestions with topic-driven matching
2. Reviewing and selecting suggestions before applying
3. Editing anchor text per suggestion
4. Settings page — link attributes, exclusions, import and export

== Changelog ==

= 1.0.0 =
* Initial release of Linkiya
* Topic-driven matching — suggestions based on article meaning and keyword frequency
* Bigram matching — two-word phrases matched before single words
* Smart deduplication — single words suppressed when already covered by a bigram suggestion
* Gutenberg sidebar with review before applying workflow
* Anchor text editing per suggestion
* Remove all links in one click
* Max links per post setting
* Link target and rel settings
* Cross-type suggestion toggles (pages on posts, posts on pages)
* Post exclusion by ID
* Minimum keyword length setting
* Stop word configuration
* Import and export settings as JSON
* Automatic cache invalidation on post save, delete, and settings change

== Upgrade Notice ==

= 1.0.0 =
Initial release.
