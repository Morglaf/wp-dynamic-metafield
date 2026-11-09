<?php
/**
 * Editor assets.
 *
 * @package DynamicMetafield
 */

namespace DynamicMetafield;

defined( 'ABSPATH' ) || exit;

/**
 * Enqueue block editor script.
 */
class Assets {

	const HANDLE = 'wp-dynamic-metafield-editor';

	/**
	 * Hook enqueue.
	 */
	public static function register() {
		add_action( 'init', array( __CLASS__, 'register_script' ), 5 );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Register script early so block.json can reference the handle.
	 */
	public static function register_script() {
		$asset_file = DMF_PATH . 'build/index.asset.php';
		$script     = DMF_PATH . 'build/index.js';

		if ( ! file_exists( $script ) ) {
			return;
		}

		$asset = file_exists( $asset_file )
			? include $asset_file
			: array(
				'dependencies' => array(
					'wp-blocks',
					'wp-block-editor',
					'wp-components',
					'wp-compose',
					'wp-data',
					'wp-element',
					'wp-hooks',
					'wp-i18n',
					'wp-api-fetch',
				),
				'version'      => DMF_VERSION,
			);

		wp_register_script(
			self::HANDLE,
			DMF_URL . 'build/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		$style = DMF_PATH . 'build/index.css';
		if ( file_exists( $style ) ) {
			wp_register_style(
				self::HANDLE,
				DMF_URL . 'build/index.css',
				array(),
				$asset['version']
			);
		}

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( self::HANDLE, DMF_TEXTDOMAIN, DMF_PATH . 'languages' );
		}
	}

	/**
	 * Enqueue + localize for the current editor post.
	 */
	public static function enqueue() {
		if ( ! wp_script_is( self::HANDLE, 'registered' ) ) {
			self::register_script();
		}
		if ( ! wp_script_is( self::HANDLE, 'registered' ) ) {
			return;
		}

		wp_enqueue_script( self::HANDLE );
		if ( wp_style_is( self::HANDLE, 'registered' ) ) {
			wp_enqueue_style( self::HANDLE );
		}

		$post_id = get_the_ID();
		if ( ! $post_id && isset( $_GET['post'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$post_id = (int) $_GET['post']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		wp_localize_script(
			self::HANDLE,
			'dmfSettings',
			array(
				'source'        => DMF_SOURCE,
				'restNamespace' => Rest::NAMESPACE,
				'restUrl'       => esc_url_raw( rest_url( Rest::NAMESPACE ) ),
				'nonce'         => wp_create_nonce( 'wp_rest' ),
				'postId'        => (int) $post_id,
				'pluginUrl'     => DMF_URL,
			)
		);
	}
}
