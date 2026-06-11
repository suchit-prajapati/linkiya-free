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
├── .gitignore
├── linkiya.php               ← main plugin file
├── package.json              ← npm config
├── package-lock.json         ← npm lock file (do not edit manually)
├── README.md                 ← GitHub readme
├── readme.txt                ← WP.org readme
└── uninstall.php
```

---

## REST API

Linkiya exposes REST endpoints used by the Gutenberg sidebar:

| Method | Endpoint | Description |
|---|---|---|
| `POST` | `/wp-json/linkiya/v1/suggest` | Scan content, return link suggestions |
| `POST` | `/wp-json/linkiya/v1/apply` | Apply accepted links to content |
| `GET` | `/wp-json/linkiya/v1/status` | Get plugin status and feature flags |

All endpoints require `edit_posts` capability. The `/suggest` and `/apply` endpoints additionally verify the user can edit the specific post via `edit_post`.

---

## Hooks for Developers

Linkiya uses standard WordPress filters and actions so addons can extend it cleanly.

### `linkiya_loaded` _(action)_
Fires after the free plugin initialises. Use this to init your addon.
```php
add_action( 'linkiya_loaded', function() {
    // your addon init here
} );
```

### `linkiya_suggest_post_types` _(filter)_
Add custom post types to the link suggestion scan.
```php
// $types = ['post', 'page'] by default
add_filter( 'linkiya_suggest_post_types', function( $types, $post_id ) {
    $types[] = 'my_custom_post_type';
    return $types;
}, 10, 2 );
```

### `linkiya_post_keywords` _(filter)_
Add or modify keywords extracted from a single post title.
```php
// $keywords = ['keyword one', 'keyword', 'one'] — sorted longest-first
add_filter( 'linkiya_post_keywords', function( $keywords, $post_id ) {
    $keywords[] = 'custom keyword';
    return $keywords;
}, 10, 2 );
```

### `linkiya_keyword_map` _(filter)_
Modify the full keyword map before suggestions are generated.
Each entry has: `post_id`, `title`, `url`, `post_type`, `keywords[]`.
```php
add_filter( 'linkiya_keyword_map', function( $map, $post_id ) {
    // Add a custom entry
    $map[] = [
        'post_id'   => 0,
        'title'     => 'My Custom Page',
        'url'       => 'https://example.com/my-page',
        'post_type' => 'custom',
        'keywords'  => [ 'my custom page', 'custom page' ],
    ];
    return $map;
}, 10, 2 );
```

### `linkiya_suggestions` _(filter)_
Filter the final suggestions array before returning to the sidebar.
```php
add_filter( 'linkiya_suggestions', function( $suggestions, $post_id ) {
    // Remove suggestions to posts in a specific category
    return array_values( array_filter( $suggestions, function( $s ) {
        return ! has_category( 'excluded', $s['post_id'] );
    } ) );
}, 10, 2 );
```

### `linkiya_link_attrs` _(filter)_
Add extra HTML attributes to applied links (e.g. for click tracking).
```php
// $attrs = ' target="_blank"' or ' rel="nofollow"' etc.
add_filter( 'linkiya_link_attrs', function( $attrs, $url ) {
    $attrs .= ' data-tracked="1"';
    return $attrs;
}, 10, 2 );
```

### `linkiya_links_applied` _(action)_
Fires after links are applied to a post. Use to log or process applied links.
```php
add_action( 'linkiya_links_applied', function( $post_id, $applied_links ) {
    foreach ( $applied_links as $link ) {
        // $link has: keyword, anchor, post_id, url, nofollow, new_tab
        error_log( "Link applied: {$link['anchor']} → {$link['url']}" );
    }
}, 10, 2 );
```

### `linkiya_settings_fields` _(action)_
Add extra rows to the settings page form table.
```php
add_action( 'linkiya_settings_fields', function( $settings ) {
    ?>
    <tr>
        <th scope="row"><label for="my_setting">My Setting</label></th>
        <td>
            <input type="text" id="my_setting" name="my_setting"
                value="<?php echo esc_attr( $settings['my_setting'] ?? '' ); ?>">
        </td>
    </tr>
    <?php
} );
```

### `linkiya_rest_status` _(filter)_
Modify the status response returned by `/wp-json/linkiya/v1/status`.
```php
add_filter( 'linkiya_rest_status', function( $status ) {
    $status['is_pro']              = true;
    $status['features']['bulk_mode'] = true;
    return $status;
} );
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