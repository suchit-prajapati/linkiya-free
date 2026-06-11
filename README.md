# Linkiya — WordPress Internal Linking Plugin

**Automatically find and apply internal links inside the Gutenberg editor. Link kiya? Done.**

Linkiya helps you build a stronger internal linking structure without the manual work. Open any post in Gutenberg, run a scan, review the suggested links, and apply them in one click.

[![WordPress Plugin](https://img.shields.io/badge/WordPress-6.0%2B-blue)](https://wordpress.org/plugins/linkiya/)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-purple)](https://php.net)
[![License](https://img.shields.io/badge/License-GPLv2-green)](https://www.gnu.org/licenses/gpl-2.0.html)
[![Version](https://img.shields.io/badge/Version-1.0.0-orange)](https://github.com/suchit-prajapati/linkiya-free/releases)

---

## How It Works

1. Open any post or page in the Gutenberg editor
2. Click the **Linkiya** icon in the sidebar panel
3. Click **Run Internal Linking** to scan your content
4. Review the suggested links — check the ones you want
5. Edit anchor text if needed using the ✏️ button
6. Click **Apply Links** — your content is updated instantly
7. Hit **Update** to save your post

---

## Free Features

| Feature | Description |
|---|---|
| Gutenberg sidebar | Works natively inside the block editor |
| Smart keyword matching | Extracts keywords from published post titles |
| Bigram matching | "control anger" matches before just "anger" |
| Stop word filtering | Ignores common words like "the", "and", "how" |
| Whole word matching | Never links partial words |
| Review before applying | Check/uncheck each suggestion before applying |
| Anchor text editing | Edit anchor text per suggestion |
| Max links per post | Limit suggestions per scan |
| Link target | Configure `target="_blank"` globally |
| nofollow / rel | Configure `rel` attribute globally |
| Minimum keyword length | Set minimum word length for matching |
| Post exclusion | Exclude specific posts from link targets |
| Import / export settings | Back up settings as JSON |

---

## Linkiya Pro

[Linkiya Pro](https://www.mypluginstore.com/linkiya) is a separate plugin that extends Linkiya with 21 advanced features including bulk linking, broken link scanner, orphan detection, click analytics, and AI-powered link intent analysis.

---

## Installation

### From WordPress.org
1. Go to **Plugins → Add New**
2. Search for **Linkiya**
3. Click **Install Now** then **Activate**

### Manual Installation
1. Download the latest release zip
2. Go to **Plugins → Add New → Upload Plugin**
3. Upload the zip and activate

---

## Development

### Requirements
- Node.js 18+
- npm 9+
- PHP 8.0+
- WordPress 6.0+

### Setup

```bash
# Clone the repo
git clone https://github.com/suchit-prajapati/linkiya-free.git
cd linkiya-free

# Install dependencies
npm install

# Build the Gutenberg sidebar
npm run build

# Watch for changes during development
npm run start
```

### Build Output

The `src/` folder contains the Gutenberg sidebar source:
- `src/sidebar.js` — React components
- `src/sidebar.css` — Sidebar styles

Running `npm run build` compiles these into the `build/` folder:
- `build/sidebar.js` — compiled JS
- `build/sidebar.css` — compiled CSS
- `build/sidebar-rtl.css` — RTL CSS
- `build/sidebar.asset.php` — dependency manifest

### File Structure

```
linkiya/
├── admin/
│   ├── css/admin.css
│   └── views/settings.php
├── build/                    ← compiled (do not edit directly)
│   ├── sidebar.js
│   ├── sidebar.css
│   ├── sidebar-rtl.css
│   └── sidebar.asset.php
├── includes/
│   ├── class-linkiya-assets.php
│   ├── class-linkiya-keyword-extractor.php
│   ├── class-linkiya-matcher.php
│   ├── class-linkiya-rest-api.php
│   └── class-linkiya-settings.php
├── languages/
├── src/                      ← edit these
│   ├── sidebar.js
│   └── sidebar.css
├── linkiya.php               ← main plugin file
├── readme.txt                ← WP.org readme
├── uninstall.php
├── package.json
└── .gitignore
```

---

## REST API

Linkiya exposes two REST endpoints used by the Gutenberg sidebar:

| Method | Endpoint | Description |
|---|---|---|
| `POST` | `/wp-json/linkiya/v1/suggest` | Scan content, return link suggestions |
| `POST` | `/wp-json/linkiya/v1/apply` | Apply accepted links to content |
| `GET` | `/wp-json/linkiya/v1/status` | Get plugin status and feature flags |

All endpoints require `edit_posts` capability. The `/suggest` and `/apply` endpoints additionally verify the user can edit the specific post via `edit_post`.

---

## Hooks for Developers

Linkiya Pro hooks into the free plugin using standard WordPress filters and actions. You can use these same hooks in your own plugins:

```php
// Add custom post types to suggestion scan
add_filter( 'linkiya_suggest_post_types', function( $types, $post_id ) {
    $types[] = 'my_custom_type';
    return $types;
}, 10, 2 );

// Add or modify keyword map
add_filter( 'linkiya_keyword_map', function( $map, $post_id ) {
    $map['custom keyword'] = [ 'url' => 'https://example.com', 'post_id' => 123 ];
    return $map;
}, 10, 2 );

// Filter suggestions before returning to sidebar
add_filter( 'linkiya_suggestions', function( $suggestions, $post_id ) {
    // Remove suggestions to posts in a specific category
    return array_filter( $suggestions, function( $s ) {
        return ! has_category( 'excluded', $s['post_id'] );
    });
}, 10, 2 );

// Hook into after links are applied
add_action( 'linkiya_links_applied', function( $post_id, $applied_links ) {
    // Log or process applied links
}, 10, 2 );

// Add extra fields to settings page
add_action( 'linkiya_settings_fields', function( $settings ) {
    echo '<tr><th>My Setting</th><td>...</td></tr>';
});

// Fired when free plugin is loaded — use to init your addon
add_action( 'linkiya_loaded', function() {
    // Your addon init here
});
```

---

## Contributing

Pull requests are welcome. For major changes please open an issue first.

1. Fork the repo
2. Create your branch: `git checkout -b feature/my-feature`
3. Commit your changes: `git commit -m 'Add my feature'`
4. Push: `git push origin feature/my-feature`
5. Open a Pull Request

---

## License

[GPLv2 or later](https://www.gnu.org/licenses/gpl-2.0.html)

---

## Links

- [WordPress.org Plugin Page](https://wordpress.org/plugins/linkiya/)
- [Linkiya Pro](https://www.mypluginstore.com/linkiya)
- [Support](https://wordpress.org/support/plugin/linkiya/)
- [Report a Bug](https://github.com/suchit-prajapati/linkiya-free/issues)