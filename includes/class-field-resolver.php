<?php
/**
 * Resolve SCF field definitions and values across contexts.
 *
 * @package DynamicMetafield
 */

namespace DynamicMetafield;

defined( 'ABSPATH' ) || exit;

/**
 * Field listing and value resolution helper.
 */
class Field_Resolver {

	/**
	 * Scalar / bindable field types.
	 *
	 * @var string[]
	 */
	const BINDABLE_TYPES = array(
		'text',
		'textarea',
		'wysiwyg',
		'number',
		'range',
		'email',
		'url',
		'password',
		'select',
		'radio',
		'button_group',
		'true_false',
		'date_picker',
		'date_time_picker',
		'time_picker',
		'color_picker',
		'image',
		'file',
		'link',
		'page_link',
		'post_object',
		'oembed',
	);

	/**
	 * Container field types that hold children.
	 *
	 * @var string[]
	 */
	const CONTAINER_TYPES = array(
		'group',
		'repeater',
		'flexible_content',
	);

	/**
	 * Build ACF post_id selector for a context.
	 *
	 * @param string               $context Context: post|option|user|term.
	 * @param array<string, mixed> $args    Context args (postId, userId, termId, taxonomy).
	 * @return mixed
	 */
	public static function resolve_acf_id( $context, $args = array() ) {
		switch ( $context ) {
			case 'option':
			case 'options':
				return 'option';
			case 'user':
				$user_id = isset( $args['userId'] ) ? (int) $args['userId'] : 0;
				if ( ! $user_id && ! empty( $args['postId'] ) ) {
					$user_id = (int) get_post_field( 'post_author', (int) $args['postId'] );
				}
				if ( ! $user_id ) {
					$user_id = get_current_user_id();
				}
				return $user_id ? 'user_' . $user_id : false;
			case 'term':
				$term_id  = isset( $args['termId'] ) ? (int) $args['termId'] : 0;
				$taxonomy = isset( $args['taxonomy'] ) ? (string) $args['taxonomy'] : '';
				if ( ! $term_id || ! $taxonomy ) {
					return false;
				}
				return $taxonomy . '_' . $term_id;
			case 'post':
			default:
				if ( ! empty( $args['postId'] ) ) {
					return (int) $args['postId'];
				}
				return get_the_ID();
		}
	}

	/**
	 * List bindable fields for a context, including nested paths.
	 *
	 * @param string               $context Context key.
	 * @param array<string, mixed> $args    Context args.
	 * @return array<int, array<string, mixed>>
	 */
	public static function list_fields( $context = 'post', $args = array() ) {
		$groups = self::get_groups_for_context( $context, $args );
		$out    = array();

		foreach ( $groups as $group ) {
			$parent = isset( $group['key'] ) ? $group['key'] : ( isset( $group['ID'] ) ? $group['ID'] : $group );
			$fields = acf_get_fields( $parent );
			if ( empty( $fields ) || ! is_array( $fields ) ) {
				continue;
			}
			$group_title = isset( $group['title'] ) ? $group['title'] : '';
			self::flatten_fields( $fields, $out, '', $group_title, false );
		}

		return $out;
	}

	/**
	 * Field groups matching a binding context.
	 *
	 * @param string               $context Context.
	 * @param array<string, mixed> $args    Args.
	 * @return array<int, array<string, mixed>>
	 */
	private static function get_groups_for_context( $context, $args ) {
		switch ( $context ) {
			case 'option':
			case 'options':
				$groups = acf_get_field_groups( array( 'options_page' => '' ) );
				if ( empty( $groups ) ) {
					$groups = array_filter(
						acf_get_field_groups(),
						array( __CLASS__, 'group_has_options_location' )
					);
				}
				return array_values( $groups );
			case 'user':
				$groups = acf_get_field_groups( array( 'user_form' => 'all' ) );
				if ( empty( $groups ) ) {
					$groups = array_filter(
						acf_get_field_groups(),
						array( __CLASS__, 'group_has_user_location' )
					);
				}
				return array_values( $groups );
			case 'term':
				$taxonomy = isset( $args['taxonomy'] ) ? $args['taxonomy'] : '';
				if ( $taxonomy ) {
					return acf_get_field_groups( array( 'taxonomy' => $taxonomy ) );
				}
				return array_values(
					array_filter(
						acf_get_field_groups(),
						array( __CLASS__, 'group_has_taxonomy_location' )
					)
				);
			case 'post':
			default:
				$post_id   = isset( $args['postId'] ) ? (int) $args['postId'] : 0;
				$post_type = isset( $args['postType'] ) ? (string) $args['postType'] : '';
				$groups    = array();

				// Prefer Query Loop / block post type (template editing has a different post_id).
				if ( $post_type ) {
					$groups = acf_get_field_groups( array( 'post_type' => $post_type ) );
				}

				if ( empty( $groups ) && $post_id ) {
					$groups = acf_get_field_groups( array( 'post_id' => $post_id ) );
				}

				// Fallback: all groups (editor can still bind; location is best-effort).
				if ( empty( $groups ) ) {
					$groups = acf_get_field_groups();
				}

				return array_values( $groups );
		}
	}

	/**
	 * @param array<string, mixed> $group Group.
	 * @return bool
	 */
	private static function group_has_options_location( $group ) {
		return self::group_has_param( $group, 'options_page' );
	}

	/**
	 * @param array<string, mixed> $group Group.
	 * @return bool
	 */
	private static function group_has_user_location( $group ) {
		return self::group_has_param( $group, 'user_form' ) || self::group_has_param( $group, 'user_role' );
	}

	/**
	 * @param array<string, mixed> $group Group.
	 * @return bool
	 */
	private static function group_has_taxonomy_location( $group ) {
		return self::group_has_param( $group, 'taxonomy' );
	}

	/**
	 * Whether a group location uses a param.
	 *
	 * @param array<string, mixed> $group Group.
	 * @param string               $param Location param.
	 * @return bool
	 */
	private static function group_has_param( $group, $param ) {
		if ( empty( $group['location'] ) || ! is_array( $group['location'] ) ) {
			return false;
		}
		foreach ( $group['location'] as $row ) {
			foreach ( (array) $row as $rule ) {
				if ( isset( $rule['param'] ) && $rule['param'] === $param ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Recursively flatten fields into bindable entries.
	 *
	 * @param array<int, array<string, mixed>> $fields      Fields.
	 * @param array<int, array<string, mixed>> $out         Output (by ref).
	 * @param string                           $path_prefix Dot path prefix.
	 * @param string                           $group_title Group title.
	 * @param bool                             $relative    Whether path is relative (inside loop).
	 */
	private static function flatten_fields( $fields, &$out, $path_prefix, $group_title, $relative ) {
		foreach ( $fields as $field ) {
			if ( empty( $field['name'] ) || empty( $field['type'] ) ) {
				continue;
			}

			$type = $field['type'];
			$name = $field['name'];
			$path = $path_prefix ? $path_prefix . '.' . $name : $name;
			$label = isset( $field['label'] ) ? $field['label'] : $name;
			if ( $path_prefix ) {
				$label = $path_prefix . ' → ' . $label;
			}

			if ( in_array( $type, self::BINDABLE_TYPES, true ) ) {
				$out[] = array(
					'name'      => $path,
					'label'     => $label,
					'type'      => $type,
					'group'     => $group_title,
					'relative'  => $relative,
					'parent'    => $path_prefix,
					'loopable'  => false,
				);
			}

			if ( 'group' === $type && ! empty( $field['sub_fields'] ) ) {
				self::flatten_fields( $field['sub_fields'], $out, $path, $group_title, $relative );
			}

			if ( 'repeater' === $type ) {
				$out[] = array(
					'name'     => $path,
					'label'    => $label . ' (' . __( 'repeater', 'dynamic-metafield' ) . ')',
					'type'     => $type,
					'group'    => $group_title,
					'relative' => $relative,
					'parent'   => $path_prefix,
					'loopable' => true,
				);
				if ( ! empty( $field['sub_fields'] ) ) {
					self::flatten_fields( $field['sub_fields'], $out, $path, $group_title, true );
				}
			}

			if ( 'flexible_content' === $type ) {
				$out[] = array(
					'name'     => $path,
					'label'    => $label . ' (' . __( 'flexible', 'dynamic-metafield' ) . ')',
					'type'     => $type,
					'group'    => $group_title,
					'relative' => $relative,
					'parent'   => $path_prefix,
					'loopable' => true,
				);
				if ( ! empty( $field['layouts'] ) && is_array( $field['layouts'] ) ) {
					foreach ( $field['layouts'] as $layout ) {
						$layout_label = isset( $layout['label'] ) ? $layout['label'] : ( isset( $layout['name'] ) ? $layout['name'] : '' );
						$layout_name  = isset( $layout['name'] ) ? $layout['name'] : '';
						$layout_path  = $path . ':' . $layout_name;
						if ( ! empty( $layout['sub_fields'] ) ) {
							self::flatten_fields(
								$layout['sub_fields'],
								$out,
								$layout_path,
								$group_title . ' / ' . $layout_label,
								true
							);
						}
					}
				}
			}
		}
	}

	/**
	 * Get raw field value for a binding key.
	 *
	 * @param string               $key      Field path (may contain dots / :layout).
	 * @param string               $context  Context.
	 * @param array<string, mixed> $args     Context args.
	 * @param bool                 $relative Use get_sub_field (inside loop).
	 * @return mixed
	 */
	public static function get_value( $key, $context = 'post', $args = array(), $relative = false ) {
		if ( '' === $key || null === $key ) {
			return null;
		}

		if ( $relative || ! empty( $GLOBALS['DMF_in_loop'] ) ) {
			// Inside dynamic-metafield/loop: prefer sub field for last segment.
			$segments = self::parse_path( $key );
			$leaf     = end( $segments );
			if ( function_exists( 'get_sub_field' ) ) {
				$value = get_sub_field( $leaf );
				if ( null !== $value && false !== $value ) {
					return $value;
				}
			}
		}

		$acf_id = self::resolve_acf_id( $context, $args );
		if ( false === $acf_id ) {
			return null;
		}

		$segments = self::parse_path( $key );
		if ( count( $segments ) === 1 ) {
			return get_field( $segments[0], $acf_id );
		}

		// Walk nested group / first-row repeater paths outside a loop.
		$root = get_field( $segments[0], $acf_id );
		return self::walk_value( $root, array_slice( $segments, 1 ) );
	}

	/**
	 * Parse a field path into segments (strip flexible layout suffix after :).
	 *
	 * @param string $key Path.
	 * @return string[]
	 */
	public static function parse_path( $key ) {
		$parts = explode( '.', (string) $key );
		$out   = array();
		foreach ( $parts as $part ) {
			if ( false !== strpos( $part, ':' ) ) {
				$part = strstr( $part, ':', true );
			}
			if ( '' !== $part ) {
				$out[] = $part;
			}
		}
		return $out;
	}

	/**
	 * Walk into nested array values (group or first repeater row).
	 *
	 * @param mixed    $value    Current value.
	 * @param string[] $segments Remaining path.
	 * @return mixed
	 */
	private static function walk_value( $value, $segments ) {
		if ( empty( $segments ) ) {
			return $value;
		}
		$head = array_shift( $segments );

		if ( ! is_array( $value ) ) {
			return null;
		}

		// Group: associative.
		if ( array_key_exists( $head, $value ) ) {
			return self::walk_value( $value[ $head ], $segments );
		}

		// Repeater: use first row.
		if ( isset( $value[0] ) && is_array( $value[0] ) && array_key_exists( $head, $value[0] ) ) {
			return self::walk_value( $value[0][ $head ], $segments );
		}

		return null;
	}

	/**
	 * Adapt a field value to a block attribute name.
	 *
	 * @param mixed  $value          Raw SCF value.
	 * @param string $attribute_name Block attribute.
	 * @return mixed
	 */
	public static function adapt_for_attribute( $value, $attribute_name ) {
		if ( null === $value || false === $value ) {
			return null;
		}

		// Image field (array / id / url).
		if ( is_array( $value ) && ( isset( $value['ID'] ) || isset( $value['id'] ) || isset( $value['url'] ) ) ) {
			$id  = isset( $value['ID'] ) ? (int) $value['ID'] : ( isset( $value['id'] ) ? (int) $value['id'] : 0 );
			$url = isset( $value['url'] ) ? $value['url'] : ( $id ? wp_get_attachment_url( $id ) : '' );
			$alt = isset( $value['alt'] ) ? $value['alt'] : ( $id ? (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) : '' );
			$title = isset( $value['title'] ) ? $value['title'] : ( $id ? get_the_title( $id ) : '' );
			$caption = isset( $value['caption'] ) ? $value['caption'] : ( $id ? wp_get_attachment_caption( $id ) : '' );

			switch ( $attribute_name ) {
				case 'id':
					return $id;
				case 'url':
					return $url;
				case 'alt':
					return $alt;
				case 'title':
					return $title;
				case 'caption':
					return $caption;
				default:
					return $url;
			}
		}

		if ( is_numeric( $value ) && in_array( $attribute_name, array( 'id', 'url', 'alt', 'title', 'caption' ), true ) ) {
			$id = (int) $value;
			switch ( $attribute_name ) {
				case 'id':
					return $id;
				case 'url':
					return wp_get_attachment_url( $id );
				case 'alt':
					return (string) get_post_meta( $id, '_wp_attachment_image_alt', true );
				case 'title':
					return get_the_title( $id );
				case 'caption':
					return wp_get_attachment_caption( $id );
			}
		}

		// Link field.
		if ( is_array( $value ) && isset( $value['url'] ) ) {
			switch ( $attribute_name ) {
				case 'url':
					return $value['url'];
				case 'text':
				case 'content':
					return isset( $value['title'] ) ? $value['title'] : $value['url'];
				case 'linkTarget':
					return ! empty( $value['target'] ) ? $value['target'] : '_self';
				default:
					return $value['url'];
			}
		}

		// Post object / page link.
		if ( $value instanceof \WP_Post ) {
			switch ( $attribute_name ) {
				case 'url':
					return get_permalink( $value );
				case 'text':
				case 'content':
					return get_the_title( $value );
				case 'id':
					return (int) $value->ID;
				default:
					return get_the_title( $value );
			}
		}

		if ( is_array( $value ) ) {
			// Select multiple, etc.
			$value = implode( ', ', array_map( 'strval', $value ) );
		}

		if ( is_bool( $value ) ) {
			$value = $value ? '1' : '';
		}

		return is_scalar( $value ) ? (string) $value : null;
	}

	/**
	 * Prefix / suffix around a scalar value (Avant / Après).
	 *
	 * @param mixed  $value  Adapted value.
	 * @param string $before Prefix.
	 * @param string $after  Suffix.
	 * @return mixed
	 */
	public static function apply_affixes( $value, $before = '', $after = '' ) {
		if ( null === $value || false === $value ) {
			return $value;
		}
		if ( ! is_scalar( $value ) ) {
			return $value;
		}
		$before = (string) $before;
		$after  = (string) $after;
		if ( '' === $before && '' === $after ) {
			return $value;
		}
		return $before . (string) $value . $after;
	}

	/**
	 * Full resolve: get field → adapt → affixes.
	 *
	 * @param array<string, mixed> $source_args Binding args.
	 * @param array<string, mixed> $context     Block context.
	 * @param string               $attribute   Attribute name.
	 * @return mixed
	 */
	public static function resolve_bound_value( $source_args, $context, $attribute ) {
		$key      = isset( $source_args['key'] ) ? (string) $source_args['key'] : '';
		$ctx_name = isset( $source_args['context'] ) ? (string) $source_args['context'] : 'post';
		$relative = ! empty( $source_args['relative'] );
		$before   = isset( $source_args['before'] ) ? (string) $source_args['before'] : '';
		$after    = isset( $source_args['after'] ) ? (string) $source_args['after'] : '';

		if ( '' === $key ) {
			return null;
		}

		$args = array(
			'postId'   => isset( $context['postId'] ) ? (int) $context['postId'] : (int) get_the_ID(),
			'postType' => isset( $context['postType'] ) ? $context['postType'] : '',
			'userId'   => isset( $source_args['userId'] ) ? (int) $source_args['userId'] : 0,
			'termId'   => isset( $source_args['termId'] ) ? (int) $source_args['termId'] : ( isset( $context['termId'] ) ? (int) $context['termId'] : 0 ),
			'taxonomy' => isset( $source_args['taxonomy'] ) ? (string) $source_args['taxonomy'] : ( isset( $context['taxonomy'] ) ? (string) $context['taxonomy'] : '' ),
		);

		if ( 'term' === $ctx_name ) {
			if ( empty( $args['termId'] ) && isset( $context['termId'] ) ) {
				$args['termId'] = (int) $context['termId'];
			}
			if ( empty( $args['taxonomy'] ) && isset( $context['taxonomy'] ) ) {
				$args['taxonomy'] = (string) $context['taxonomy'];
			}
		}

		$raw = self::get_value( $key, $ctx_name, $args, $relative );
		if ( self::is_empty_value( $raw ) ) {
			return self::adapt_for_attribute( $raw, $attribute );
		}

		$adapted = self::adapt_for_attribute( $raw, $attribute );
		return self::apply_affixes( $adapted, $before, $after );
	}

	/**
	 * Whether a value is considered empty for hideIfEmpty.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	public static function is_empty_value( $value ) {
		if ( null === $value || false === $value ) {
			return true;
		}
		if ( is_string( $value ) && '' === trim( wp_strip_all_tags( $value ) ) ) {
			return true;
		}
		if ( is_array( $value ) && empty( $value ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Escape a URL for href/src while keeping custom schemes (obsidian://, etc.).
	 *
	 * WordPress esc_url() drops unknown protocols by default.
	 *
	 * @param mixed $url URL.
	 * @return string
	 */
	public static function esc_href( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}

		// Never allow scriptable schemes.
		if ( preg_match( '#^\s*(javascript|data|vbscript):#i', $url ) ) {
			return '';
		}

		$protocols = wp_allowed_protocols();
		if ( preg_match( '#^([a-z][a-z0-9+.\-]*):#i', $url, $matches ) ) {
			$scheme = strtolower( $matches[1] );
			if ( ! in_array( $scheme, $protocols, true ) ) {
				$protocols[] = $scheme;
			}
		}

		$escaped = esc_url( $url, $protocols );
		if ( $escaped ) {
			return $escaped;
		}

		// Last resort for exotic schemes still rejected by esc_url.
		if ( preg_match( '#^[a-z][a-z0-9+.\-]*:#i', $url ) ) {
			return esc_attr( $url );
		}

		return '';
	}

	/**
	 * Map of formatted values for editor preview.
	 *
	 * @param string               $context Context.
	 * @param array<string, mixed> $args    Args.
	 * @return array<string, mixed>
	 */
	public static function list_values( $context = 'post', $args = array() ) {
		$fields = self::list_fields( $context, $args );
		$values = array();
		foreach ( $fields as $field ) {
			if ( ! empty( $field['loopable'] ) ) {
				continue;
			}
			$raw = self::get_value( $field['name'], $context, $args, ! empty( $field['relative'] ) );
			$values[ $field['name'] ] = array(
				'raw'  => $raw,
				'type' => $field['type'],
				'text' => self::adapt_for_attribute( $raw, 'content' ),
				'url'  => self::adapt_for_attribute( $raw, 'url' ),
				'id'   => self::adapt_for_attribute( $raw, 'id' ),
				'alt'  => self::adapt_for_attribute( $raw, 'alt' ),
			);
		}
		return $values;
	}
}
