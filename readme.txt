=== Dynamic Metafield ===
Contributors: morglaf
Tags: gutenberg, block bindings, secure custom fields, acf, metafields
Requires at least: 6.7
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Bind native Gutenberg blocks to Secure Custom Fields via Block Bindings, InspectorControls, and render_block.

== Description ==

Dynamic Metafield connects WordPress block editor content to **Secure Custom Fields** (ACF-compatible API) without proprietary meta.

Features:

* Bind Paragraph, Heading, Image, Button, RSS (`feedURL`), and Embed / YouTube (`url`) to SCF fields
* Inspector panel: context (post, options, user, term), field picker, before/after affixes
* Hide if empty, show if field filled, show if taxonomy / term
* Container block **SCF Field Loop** for Repeater and Flexible Content
* Works inside the Query Loop (`postId` context)
* Supports custom URL schemes (e.g. `obsidian://`) that `esc_url` would strip
* UI translations: English (default), French, Spanish, German

Requires the **Secure Custom Fields** plugin (or another plugin exposing `get_field` / `acf_get_field_groups`).

== Installation ==

1. Upload the `wp-dynamic-metafield` folder to `/wp-content/plugins/`
2. Activate **Dynamic Metafield** through the Plugins screen
3. Ensure Secure Custom Fields (or compatible ACF API) is active
4. Edit a post or template, select a supported block, open the **SCF Binding** panel

== Frequently Asked Questions ==

= Does this work without Secure Custom Fields? =

No. The plugin needs SCF or an ACF-compatible API (`get_field`, `acf_get_field_groups`).

= Which WordPress version is required? =

WordPress 6.7 or newer (Block Bindings API).

= Will existing content break after renaming? =

Legacy binding sources `dynamic-metafield/scf` and `dai-dmf/scf`, and legacy loop block names, remain registered so older content still resolves.

== Development ==

Editor assets are built with `@wordpress/scripts`. Readable sources live in `/src`.

1. Install Node.js 18+
2. From the plugin directory: `npm install && npm run build`
3. Watch mode: `npm start`

The distribution zip must include `src/`, `package.json`, `package-lock.json`, `build/`, and `languages/`.

== Changelog ==

= 1.0.0 =
* Initial WordPress.org-ready release
* Block Bindings + InspectorControls + render_block
* SCF Field Loop, RSS / embed bindings, visibility rules
* Translations: fr_FR, es_ES, de_DE
* Slug: wp-dynamic-metafield

== Upgrade Notice ==

= 1.0.0 =
First public release under slug wp-dynamic-metafield. Legacy sources remain for existing bindings.
