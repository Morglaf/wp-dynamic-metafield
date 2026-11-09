<?php
/**
 * Frontend render via render_block (Query Loop + hideIfEmpty + showIfField).
 *
 * @package DynamicMetafield
 */

namespace DynamicMetafield;

defined( 'ABSPATH' ) || exit;

/**
 * Intercepts block HTML / attributes to inject SCF values and honor visibility.
 */
class Render_Block {

	/**
	 * Supported core blocks and primary attribute for emptiness checks.
	 *
	 * @var array<string, string>
	 */
	const PRIMARY_ATTR = array(
		'core/paragraph' => 'content',
		'core/heading'   => 'content',
		'core/button'    => 'url',
		'core/image'     => 'url',
		'core/rss'       => 'feedURL',
		'core/embed'     => 'url',
	);

	/**
	 * Hook render filters.
	 */
	public static function register() {
		// Patch attributes before render (critical for dynamic RSS / embed).
		add_filter( 'render_block_data', array( __CLASS__, 'filter_block_data' ), 10, 1 );
		add_filter( 'render_block', array( __CLASS__, 'filter_render' ), 20, 2 );
	}

	/**
	 * Read plugin meta bag (dmf + legacy daiDmf).
	 *
	 * @param array<string, mixed> $meta Block metadata.
	 * @return array<string, mixed>
	 */
	private static function dmf_meta( $meta ) {
		$dmf    = ( isset( $meta['dmf'] ) && is_array( $meta['dmf'] ) ) ? $meta['dmf'] : array();
		$legacy = ( isset( $meta['daiDmf'] ) && is_array( $meta['daiDmf'] ) ) ? $meta['daiDmf'] : array();
		return array_merge( $legacy, $dmf );
	}

	/**
	 * Inject bound attribute values into parsed block before rendering.
	 *
	 * @param array<string, mixed> $parsed_block Parsed block.
	 * @return array<string, mixed>
	 */
	public static function filter_block_data( $parsed_block ) {
		if ( empty( $parsed_block['blockName'] ) || empty( $parsed_block['attrs']['metadata']['bindings'] ) ) {
			return $parsed_block;
		}

		$our = self::extract_our_bindings( $parsed_block['attrs']['metadata']['bindings'] );
		if ( empty( $our ) ) {
			return $parsed_block;
		}

		$context = array(
			'postId'   => (int) get_the_ID(),
			'postType' => get_post_type() ? get_post_type() : '',
		);

		foreach ( $our as $attribute_name => $source_args ) {
			$value = Field_Resolver::resolve_bound_value( $source_args, $context, $attribute_name );
			if ( null === $value ) {
				continue;
			}
			$parsed_block['attrs'][ $attribute_name ] = $value;
		}

		return $parsed_block;
	}

	/**
	 * Filter rendered block HTML.
	 *
	 * @param string               $block_content HTML.
	 * @param array<string, mixed> $block         Parsed block.
	 * @return string
	 */
	public static function filter_render( $block_content, $block ) {
		if ( empty( $block['blockName'] ) ) {
			return $block_content;
		}

		$meta    = ( isset( $block['attrs']['metadata'] ) && is_array( $block['attrs']['metadata'] ) ) ? $block['attrs']['metadata'] : array();
		$dmf     = self::dmf_meta( $meta );
		$context = isset( $block['context'] ) && is_array( $block['context'] ) ? $block['context'] : array(
			'postId'   => (int) get_the_ID(),
			'postType' => get_post_type() ? get_post_type() : '',
		);

		// Visibility rules — independent of URL/text bindings.
		if ( self::should_hide_by_visibility( $dmf, $context ) ) {
			return '';
		}

		$bindings = isset( $meta['bindings'] ) && is_array( $meta['bindings'] ) ? $meta['bindings'] : array();
		$our      = self::extract_our_bindings( $bindings );
		if ( empty( $our ) ) {
			return $block_content;
		}

		$hide       = ! empty( $dmf['hideIfEmpty'] );
		$block_name = $block['blockName'];
		$resolved   = array();

		foreach ( $our as $attribute_name => $source_args ) {
			$resolved[ $attribute_name ] = Field_Resolver::resolve_bound_value( $source_args, $context, $attribute_name );
		}

		if ( $hide && self::should_hide( $block_name, $resolved, $our ) ) {
			return '';
		}

		return self::inject_html( $block_content, $block_name, $resolved );
	}

	/**
	 * Hide block according to field / taxonomy visibility rules.
	 *
	 * @param array<string, mixed> $dmf     Plugin meta.
	 * @param array<string, mixed> $context Block context.
	 * @return bool
	 */
	private static function should_hide_by_visibility( $dmf, $context ) {
		$type = isset( $dmf['showIfType'] ) ? (string) $dmf['showIfType'] : '';

		// Backward compat: old configs only had showIfField.
		if ( '' === $type && ! empty( $dmf['showIfField'] ) ) {
			$type = 'field';
		}

		if ( 'field' === $type ) {
			return self::should_hide_by_show_if_field( $dmf, $context );
		}

		if ( 'taxonomy' === $type || 'tax' === $type ) {
			return self::should_hide_by_taxonomy( $dmf, $context );
		}

		return false;
	}

	/**
	 * Hide block when showIfField is set and that SCF field is empty.
	 *
	 * @param array<string, mixed> $dmf     Plugin meta.
	 * @param array<string, mixed> $context Block context.
	 * @return bool
	 */
	private static function should_hide_by_show_if_field( $dmf, $context ) {
		$field = isset( $dmf['showIfField'] ) ? (string) $dmf['showIfField'] : '';
		if ( '' === $field ) {
			return false;
		}

		$ctx_name = isset( $dmf['showIfContext'] ) ? (string) $dmf['showIfContext'] : 'post';
		$relative = ! empty( $dmf['showIfRelative'] ) || ! empty( $GLOBALS['dmf_in_loop'] );

		$args = array(
			'postId'   => isset( $context['postId'] ) ? (int) $context['postId'] : (int) get_the_ID(),
			'postType' => isset( $context['postType'] ) ? $context['postType'] : '',
			'userId'   => isset( $dmf['showIfUserId'] ) ? (int) $dmf['showIfUserId'] : 0,
			'termId'   => isset( $context['termId'] ) ? (int) $context['termId'] : 0,
			'taxonomy' => isset( $context['taxonomy'] ) ? (string) $context['taxonomy'] : '',
		);

		$raw = Field_Resolver::get_value( $field, $ctx_name, $args, $relative );
		return Field_Resolver::is_empty_value( $raw );
	}

	/**
	 * Hide block when taxonomy condition fails.
	 *
	 * showIfTaxonomy required.
	 * showIfTerm optional (slug or ID) — empty = any term in that taxonomy.
	 * showIfTaxOperator: has|not_has (default has).
	 *
	 * @param array<string, mixed> $dmf     Plugin meta.
	 * @param array<string, mixed> $context Block context.
	 * @return bool
	 */
	private static function should_hide_by_taxonomy( $dmf, $context ) {
		$taxonomy = isset( $dmf['showIfTaxonomy'] ) ? (string) $dmf['showIfTaxonomy'] : '';
		if ( '' === $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			return false;
		}

		$post_id = isset( $context['postId'] ) ? (int) $context['postId'] : (int) get_the_ID();
		if ( ! $post_id ) {
			return true;
		}

		$term     = isset( $dmf['showIfTerm'] ) ? $dmf['showIfTerm'] : '';
		$operator = isset( $dmf['showIfTaxOperator'] ) ? (string) $dmf['showIfTaxOperator'] : 'has';
		if ( ! in_array( $operator, array( 'has', 'not_has' ), true ) ) {
			$operator = 'has';
		}

		if ( '' === $term || null === $term ) {
			$terms = get_the_terms( $post_id, $taxonomy );
			$has   = ! empty( $terms ) && ! is_wp_error( $terms );
		} else {
			// Accept numeric ID or slug.
			$term_query = is_numeric( $term ) ? (int) $term : (string) $term;
			$has        = has_term( $term_query, $taxonomy, $post_id );
		}

		if ( 'has' === $operator ) {
			return ! $has;
		}

		return $has;
	}

	/**
	 * Decide hideIfEmpty based on block type and resolved values.
	 *
	 * @param string               $block_name Block name.
	 * @param array<string, mixed> $resolved   Resolved attributes.
	 * @param array<string, mixed> $our        Our bindings (attribute => args).
	 * @return bool
	 */
	private static function should_hide( $block_name, $resolved, $our ) {
		if ( 'core/button' === $block_name ) {
			$url_bound  = isset( $our['url'] );
			$text_bound = isset( $our['text'] );
			$url_empty  = $url_bound && Field_Resolver::is_empty_value( $resolved['url'] ?? null );
			$text_empty = $text_bound && Field_Resolver::is_empty_value( $resolved['text'] ?? null );

			if ( $url_empty ) {
				return true;
			}
			if ( $text_bound && ! $url_bound && $text_empty ) {
				return true;
			}
			if ( $url_bound && $text_bound && $url_empty && $text_empty ) {
				return true;
			}
			return false;
		}

		$primary_attr = self::primary_attribute( $block_name );
		if ( $primary_attr && array_key_exists( $primary_attr, $resolved ) ) {
			return Field_Resolver::is_empty_value( $resolved[ $primary_attr ] );
		}

		if ( ! empty( $resolved ) ) {
			return Field_Resolver::is_empty_value( reset( $resolved ) );
		}

		return false;
	}

	/**
	 * Primary attribute for a block name.
	 *
	 * @param string $block_name Block name.
	 * @return string|null
	 */
	private static function primary_attribute( $block_name ) {
		if ( isset( self::PRIMARY_ATTR[ $block_name ] ) ) {
			return self::PRIMARY_ATTR[ $block_name ];
		}
		if ( 0 === strpos( $block_name, 'core-embed/' ) ) {
			return 'url';
		}
		return null;
	}

	/**
	 * Keep only dynamic-metafield bindings.
	 *
	 * @param array<string, mixed> $bindings Bindings map.
	 * @return array<string, array<string, mixed>>
	 */
	private static function extract_our_bindings( $bindings ) {
		$our = array();
		foreach ( $bindings as $attr => $binding ) {
			if ( ! is_array( $binding ) ) {
				continue;
			}
			if ( empty( $binding['source'] ) || ! dmf_is_our_source( $binding['source'] ) ) {
				continue;
			}
			$our[ $attr ] = isset( $binding['args'] ) && is_array( $binding['args'] ) ? $binding['args'] : array();
		}
		return $our;
	}

	/**
	 * Inject resolved values into rendered HTML.
	 *
	 * @param string               $html       HTML.
	 * @param string               $block_name Block name.
	 * @param array<string, mixed> $resolved   Attribute => value.
	 * @return string
	 */
	private static function inject_html( $html, $block_name, $resolved ) {
		if ( empty( $resolved ) ) {
			return $html;
		}

		// Dynamic RSS: attribute already patched via render_block_data; HTML may be empty on hide.
		if ( 'core/rss' === $block_name ) {
			return is_string( $html ) ? $html : '';
		}

		$is_embed = ( 'core/embed' === $block_name || 0 === strpos( $block_name, 'core-embed/' ) );

		switch ( true ) {
			case 'core/paragraph' === $block_name:
			case 'core/heading' === $block_name:
				if ( isset( $resolved['content'] ) && null !== $resolved['content'] ) {
					$html = preg_replace(
						'#(<(?:p|h[1-6])\b[^>]*>)(.*?)(</(?:p|h[1-6])>)#s',
						'$1' . self::escape_inner( $resolved['content'] ) . '$3',
						$html,
						1
					);
				}
				break;

			case 'core/button' === $block_name:
				if ( array_key_exists( 'url', $resolved ) && null !== $resolved['url'] && '' !== $resolved['url'] ) {
					$href = Field_Resolver::esc_href( $resolved['url'] );
					$html = preg_replace(
						'#(<a\b[^>]*\bhref=)(["\'])(.*?)\2#i',
						'$1$2' . $href . '$2',
						$html,
						1
					);
					if ( $html && ! preg_match( '#<a\b[^>]*\bhref=#i', $html ) ) {
						$html = preg_replace(
							'#(<a\b)#i',
							'$1 href="' . $href . '"',
							$html,
							1
						);
					}
				}
				if ( array_key_exists( 'text', $resolved ) && null !== $resolved['text'] ) {
					$html = preg_replace(
						'#(<a\b[^>]*>)(.*?)(</a>)#s',
						'$1' . self::escape_inner( $resolved['text'] ) . '$3',
						$html,
						1
					);
				}
				if ( array_key_exists( 'linkTarget', $resolved ) && null !== $resolved['linkTarget'] ) {
					$target = esc_attr( $resolved['linkTarget'] );
					if ( preg_match( '#\btarget=#i', $html ) ) {
						$html = preg_replace( '#\btarget=(["\'])(.*?)\1#i', 'target=$1' . $target . '$1', $html, 1 );
					} elseif ( $target && '_self' !== $target ) {
						$html = preg_replace( '#(<a\b)#i', '$1 target="' . $target . '"', $html, 1 );
					}
				}
				break;

			case 'core/image' === $block_name:
				if ( isset( $resolved['url'] ) && null !== $resolved['url'] ) {
					$html = preg_replace(
						'#(<img\b[^>]*\bsrc=)(["\'])(.*?)\2#i',
						'$1$2' . Field_Resolver::esc_href( $resolved['url'] ) . '$2',
						$html,
						1
					);
				}
				if ( isset( $resolved['alt'] ) && null !== $resolved['alt'] ) {
					$alt = esc_attr( $resolved['alt'] );
					if ( preg_match( '#\balt=#i', $html ) ) {
						$html = preg_replace( '#\balt=(["\'])(.*?)\1#i', 'alt=$1' . $alt . '$1', $html, 1 );
					} else {
						$html = preg_replace( '#(<img\b)#i', '$1 alt="' . $alt . '"', $html, 1 );
					}
				}
				break;

			case $is_embed:
				if ( isset( $resolved['url'] ) && null !== $resolved['url'] && '' !== $resolved['url'] ) {
					$url = Field_Resolver::esc_href( $resolved['url'] );
					$html = preg_replace(
						'#(<div\b[^>]*class="[^"]*wp-block-embed__wrapper[^"]*"[^>]*>)\s*.*?\s*(</div>)#is',
						'$1' . $url . '$2',
						$html,
						1
					);
				}
				break;
		}

		return is_string( $html ) ? $html : '';
	}

	/**
	 * Escape inner HTML allowing basic formatting from wysiwyg.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function escape_inner( $value ) {
		return wp_kses_post( (string) $value );
	}
}
