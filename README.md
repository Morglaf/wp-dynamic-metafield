# WP Dynamic Metafield

WordPress plugin that binds native Gutenberg blocks to **Secure Custom Fields** (ACF-compatible API) via the **Block Bindings API**, an **InspectorControls** panel, and server-side `render_block` (Query Loop aware).

**Author:** Morglaf  
**Slug:** `wp-dynamic-metafield`  
**License:** GPL-2.0-or-later  
**Languages:** English (source), French, Spanish, German

## Requirements

- WordPress **6.7+**
- **Secure Custom Fields** (or ACF exposing `get_field` / `acf_get_field_groups`)
- Node.js 18+ (to build editor assets)

## Installation

1. Copy this folder to `wp-content/plugins/wp-dynamic-metafield`
2. Build assets:

```bash
npm install
npm run build
```

3. Activate the plugin in WordPress admin

Development watch mode: `npm start`.

If your local git folder is still named `dai-dynamic-metafield`, rename it to `wp-dynamic-metafield` to match the plugin slug.

## Usage

### Before / After affixes

On a binding, you can add text **before** and **after** the field value.

Example: field = `github.com/user/app`

- Before: `https://`
- After: `/releases.atom`

→ `https://github.com/user/app/releases.atom`

Useful for buttons, paragraphs, **RSS** feeds, or **YouTube** embeds.

### Supported blocks

- Paragraph, Heading, Image, Button
- **RSS** (`feedURL`)
- **Embed / YouTube** (`core/embed`)

1. Open content in the block editor
2. Select a supported block
3. In the sidebar, **SCF Binding** panel:
   - Choose **context**: post, SCF options, user (author), or term
   - Choose the **field** (including group subfields / repeater paths)
   - Optional: **Hide if binding is empty**, visibility by filled field or taxonomy/term
4. Save and preview on the front end

Bindings are stored in `metadata.bindings` (portable, no proprietary meta).

### Repeater / Flexible — **SCF Field Loop**

1. Insert **SCF Field Loop** (`wp-dynamic-metafield/loop`)
2. Choose a **repeater** or **flexible content** field
3. Place child blocks bound to **subfields** (relative paths)
4. On the front end, the container iterates each row and renders children

Optional `layout` filter to keep only one Flexible Content layout.

Legacy block names `dynamic-metafield/loop` and `dai-dmf/loop` still render.

### Query Loop

The `render_block` filter reads `$block['context']['postId']` so bindings work inside a query loop.

## Structure

```
wp-dynamic-metafield.php
includes/          # PHP (bindings, render, REST, assets, loop)
blocks/loop/       # block.json
src/               # React / Gutenberg source
build/             # Compiled JS (generated)
languages/         # POT/PO/MO + editor JSON (en/fr/es/de)
.wordpress-org/    # Directory icons & banners (SVN /assets, not in zip)
```

## REST API

- `GET /wp-json/wp-dynamic-metafield/v1/fields?scf_context=post&post_id=123&post_type=post`
- `GET /wp-json/wp-dynamic-metafield/v1/values?scf_context=option`
- `GET /wp-json/wp-dynamic-metafield/v1/taxonomies?post_type=post`

Legacy namespaces `dynamic-metafield/v1` and `dai-dmf/v1` remain registered. Capability: `edit_posts`.

## Packaging

Local zip (gitignored script):

```powershell
.\make-zip.ps1
```

The zip includes `src/`, `package.json`, `package-lock.json`, `build/`, and `languages/` for WordPress.org Guideline 4.

WordPress.org SVN assets (icons/banners) live in `.wordpress-org/` and are uploaded to the SVN `/assets` folder, not inside the plugin zip.

## License

GPL-2.0-or-later — see [LICENSE](LICENSE).
