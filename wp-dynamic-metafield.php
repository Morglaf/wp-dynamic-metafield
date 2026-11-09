<?php
/**
 * Plugin Name:       WP Dynamic Metafield
 * Plugin URI:        https://wordpress.org/plugins/wp-dynamic-metafield/
 * Description:       Dynamically bind Gutenberg blocks to Secure Custom Fields (Block Bindings + render_block).
 * Version:           1.0.0
 * Requires at least: 6.7
 * Requires PHP:      7.4
 * Author:            Morglaf
 * Author URI:        https://profiles.wordpress.org/morglaf/
 * Text Domain:       wp-dynamic-metafield
 * Domain Path:       /languages
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package DynamicMetafield
 */

defined( 'ABSPATH' ) || exit;

define( 'DMF_VERSION', '1.0.0' );
define( 'DMF_FILE', __FILE__ );
define( 'DMF_PATH', plugin_dir_path( __FILE__ ) );
define( 'DMF_URL', plugin_dir_url( __FILE__ ) );
define( 'DMF_TEXTDOMAIN', 'wp-dynamic-metafield' );
define( 'DMF_SOURCE', 'wp-dynamic-metafield/scf' );
/** @deprecated Kept for content saved as dynamic-metafield/scf. */
define( 'DMF_SOURCE_LEGACY_DM', 'dynamic-metafield/scf' );
/** @deprecated Kept for content saved as dai-dmf/scf. */
define( 'DMF_SOURCE_LEGACY', 'dai-dmf/scf' );

/**
 * Whether a bindings source belongs to this plugin.
 *
 * @param string $source Source name.
 * @return bool
 */
function dmf_is_our_source( $source ) {
	return in_array(
		$source,
		array( DMF_SOURCE, DMF_SOURCE_LEGACY_DM, DMF_SOURCE_LEGACY ),
		true
	);
}

/**
 * Load translations.
 */
function dmf_load_textdomain() {
	load_plugin_textdomain(
		DMF_TEXTDOMAIN,
		false,
		dirname( plugin_basename( DMF_FILE ) ) . '/languages'
	);
}
add_action( 'init', 'dmf_load_textdomain' );

/**
 * Bootstrap.
 */
function dmf_bootstrap() {
	global $wp_version;

	if ( version_compare( $wp_version, '6.7', '<' ) ) {
		add_action( 'admin_notices', 'dmf_notice_wp_version' );
		return;
	}

	if ( ! function_exists( 'get_field' ) || ! function_exists( 'acf_get_field_groups' ) ) {
		add_action( 'admin_notices', 'dmf_notice_scf_missing' );
		return;
	}

	require_once DMF_PATH . 'includes/class-field-resolver.php';
	require_once DMF_PATH . 'includes/class-bindings-source.php';
	require_once DMF_PATH . 'includes/class-render-block.php';
	require_once DMF_PATH . 'includes/class-rest.php';
	require_once DMF_PATH . 'includes/class-assets.php';
	require_once DMF_PATH . 'includes/class-container-block.php';
	require_once DMF_PATH . 'includes/class-plugin.php';

	\DynamicMetafield\Plugin::instance()->init();
}
add_action( 'plugins_loaded', 'dmf_bootstrap', 20 );

/**
 * Admin notice: WordPress too old.
 */
function dmf_notice_wp_version() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	echo '<div class="notice notice-error"><p>';
	echo esc_html__( 'Dynamic Metafield requires WordPress 6.7 or newer.', 'wp-dynamic-metafield' );
	echo '</p></div>';
}

/**
 * Admin notice: SCF / ACF missing.
 */
function dmf_notice_scf_missing() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	echo '<div class="notice notice-error"><p>';
	echo esc_html__( 'Dynamic Metafield requires Secure Custom Fields (or a compatible ACF API) to be active.', 'wp-dynamic-metafield' );
	echo '</p></div>';
}
