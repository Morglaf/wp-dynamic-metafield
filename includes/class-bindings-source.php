<?php
/**
 * Block Bindings source registration.
 *
 * @package DynamicMetafield
 */

namespace DynamicMetafield;

defined( 'ABSPATH' ) || exit;

/**
 * Registers wp-dynamic-metafield/scf bindings source + extra bindable attributes.
 */
class Bindings_Source {

	/**
	 * Register on init.
	 */
	public static function register() {
		add_action( 'init', array( __CLASS__, 'register_source' ) );
		add_filter( 'block_bindings_supported_attributes', array( __CLASS__, 'supported_attributes' ), 10, 2 );
	}

	/**
	 * Allow bindings on RSS feedURL and Embed url (incl. YouTube).
	 *
	 * @param string[] $attributes Supported attributes.
	 * @param string   $block_type Block name.
	 * @return string[]
	 */
	public static function supported_attributes( $attributes, $block_type ) {
		if ( 'core/rss' === $block_type ) {
			$attributes[] = 'feedURL';
		}
		if ( 'core/embed' === $block_type || 0 === strpos( (string) $block_type, 'core-embed/' ) ) {
			$attributes[] = 'url';
		}
		return array_values( array_unique( $attributes ) );
	}

	/**
	 * register_block_bindings_source.
	 */
	public static function register_source() {
		if ( ! function_exists( 'register_block_bindings_source' ) ) {
			return;
		}

		$sources = array(
			DMF_SOURCE => __( 'Secure Custom Fields', 'wp-dynamic-metafield' ),
		);

		if ( DMF_SOURCE_LEGACY_DM !== DMF_SOURCE ) {
			$sources[ DMF_SOURCE_LEGACY_DM ] = __( 'Secure Custom Fields (legacy)', 'wp-dynamic-metafield' );
		}
		if ( DMF_SOURCE_LEGACY !== DMF_SOURCE && DMF_SOURCE_LEGACY !== DMF_SOURCE_LEGACY_DM ) {
			$sources[ DMF_SOURCE_LEGACY ] = __( 'Secure Custom Fields (legacy)', 'wp-dynamic-metafield' );
		}

		foreach ( $sources as $name => $label ) {
			register_block_bindings_source(
				$name,
				array(
					'label'              => $label,
					'get_value_callback' => array( __CLASS__, 'get_value' ),
					'uses_context'       => array( 'postId', 'postType' ),
				)
			);
		}
	}

	/**
	 * Bindings callback.
	 *
	 * @param array     $source_args    Source args.
	 * @param \WP_Block $block_instance Block.
	 * @param string    $attribute_name Attribute.
	 * @return mixed
	 */
	public static function get_value( $source_args, $block_instance, $attribute_name ) {
		$context = is_array( $block_instance->context ) ? $block_instance->context : array();
		return Field_Resolver::resolve_bound_value( $source_args, $context, $attribute_name );
	}
}
