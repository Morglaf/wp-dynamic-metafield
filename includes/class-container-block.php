<?php
/**
 * Generic loop container block for repeaters / flexible content.
 *
 * @package DynamicMetafield
 */

namespace DynamicMetafield;

defined( 'ABSPATH' ) || exit;

/**
 * Registers wp-dynamic-metafield/loop and renders SCF row loops server-side.
 */
class Container_Block {

	/**
	 * Shared attributes for legacy block registrations.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function loop_attributes() {
		return array(
			'field'    => array( 'type' => 'string', 'default' => '' ),
			'context'  => array( 'type' => 'string', 'default' => 'post' ),
			'layout'   => array( 'type' => 'string', 'default' => '' ),
			'userId'   => array( 'type' => 'number', 'default' => 0 ),
			'termId'   => array( 'type' => 'number', 'default' => 0 ),
			'taxonomy' => array( 'type' => 'string', 'default' => '' ),
			'tagName'  => array( 'type' => 'string', 'default' => 'div' ),
		);
	}

	/**
	 * Register block type.
	 */
	public static function register() {
		add_action( 'init', array( __CLASS__, 'register_block' ) );
	}

	/**
	 * Register from block.json + render callback + legacy names.
	 */
	public static function register_block() {
		$block_json = DMF_PATH . 'blocks/loop';
		if ( ! file_exists( $block_json . '/block.json' ) ) {
			return;
		}

		register_block_type(
			$block_json,
			array(
				'render_callback' => array( __CLASS__, 'render' ),
				'editor_script'   => Assets::HANDLE,
				'editor_style'    => Assets::HANDLE,
			)
		);

		$legacy_names = array(
			'dynamic-metafield/loop' => __( 'SCF Field Loop (legacy)', 'wp-dynamic-metafield' ),
			'dai-dmf/loop'           => __( 'SCF Field Loop (legacy)', 'wp-dynamic-metafield' ),
		);

		foreach ( $legacy_names as $name => $title ) {
			register_block_type(
				$name,
				array(
					'api_version'     => 3,
					'title'           => $title,
					'category'        => 'theme',
					'render_callback' => array( __CLASS__, 'render' ),
					'attributes'      => self::loop_attributes(),
					'supports'        => array( 'html' => false ),
					'uses_context'    => array( 'postId', 'postType', 'termId', 'taxonomy' ),
				)
			);
		}
	}

	/**
	 * Server render: loop SCF rows and re-render inner blocks each time.
	 *
	 * @param array     $attributes Block attributes.
	 * @param string    $content    Inner content (unused — we re-render from parsed).
	 * @param \WP_Block $block      Block instance.
	 * @return string
	 */
	public static function render( $attributes, $content, $block ) {
		$field   = isset( $attributes['field'] ) ? (string) $attributes['field'] : '';
		$context = isset( $attributes['context'] ) ? (string) $attributes['context'] : 'post';
		$layout  = isset( $attributes['layout'] ) ? (string) $attributes['layout'] : '';

		if ( '' === $field ) {
			return $content;
		}

		// Strip flexible layout suffix from field path for have_rows selector.
		$field_selector = $field;
		if ( false !== strpos( $field_selector, ':' ) ) {
			$field_selector = strstr( $field_selector, ':', true );
		}
		$segments       = Field_Resolver::parse_path( $field_selector );
		$field_selector = implode( '.', $segments );
		// have_rows expects top-level or nested with parent loops; use leaf for simplicity.
		$leaf = end( $segments );

		$args = array(
			'postId'   => isset( $block->context['postId'] ) ? (int) $block->context['postId'] : (int) get_the_ID(),
			'postType' => isset( $block->context['postType'] ) ? $block->context['postType'] : '',
			'userId'   => isset( $attributes['userId'] ) ? (int) $attributes['userId'] : 0,
			'termId'   => isset( $attributes['termId'] ) ? (int) $attributes['termId'] : ( isset( $block->context['termId'] ) ? (int) $block->context['termId'] : 0 ),
			'taxonomy' => isset( $attributes['taxonomy'] ) ? (string) $attributes['taxonomy'] : ( isset( $block->context['taxonomy'] ) ? (string) $block->context['taxonomy'] : '' ),
		);

		$acf_id = Field_Resolver::resolve_acf_id( $context, $args );
		if ( false === $acf_id ) {
			return '';
		}

		if ( ! function_exists( 'have_rows' ) || ! have_rows( $leaf, $acf_id ) ) {
			return '';
		}

		$inner_blocks = $block->parsed_block['innerBlocks'] ?? array();
		if ( empty( $inner_blocks ) ) {
			return '';
		}

		$output                 = '';
		$GLOBALS['DMF_in_loop'] = true;

		while ( have_rows( $leaf, $acf_id ) ) {
			the_row();

			if ( $layout ) {
				$row_layout = function_exists( 'get_row_layout' ) ? get_row_layout() : '';
				if ( $row_layout && $row_layout !== $layout ) {
					continue;
				}
			}

			foreach ( $inner_blocks as $inner ) {
				$output .= render_block( $inner );
			}
		}

		$GLOBALS['DMF_in_loop'] = false;

		$tag   = isset( $attributes['tagName'] ) ? tag_escape( $attributes['tagName'] ) : 'div';
		$class = 'wp-block-wp-dynamic-metafield-loop';
		if ( ! empty( $attributes['className'] ) ) {
			$class .= ' ' . $attributes['className'];
		}

		return sprintf(
			'<%1$s class="%2$s">%3$s</%1$s>',
			$tag ? $tag : 'div',
			esc_attr( $class ),
			$output
		);
	}
}
