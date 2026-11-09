<?php
/**
 * REST API for field lists and values.
 *
 * @package DynamicMetafield
 */

namespace DynamicMetafield;

defined( 'ABSPATH' ) || exit;

/**
 * REST routes under dynamic-metafield/v1.
 */
class Rest {

	const NAMESPACE = 'dynamic-metafield/v1';

	/**
	 * Register routes.
	 */
	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Routes.
	 */
	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/fields',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_fields' ),
				'permission_callback' => array( __CLASS__, 'can_edit' ),
				'args'                => self::context_args(),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/values',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_values' ),
				'permission_callback' => array( __CLASS__, 'can_edit' ),
				'args'                => self::context_args(),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/taxonomies',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_taxonomies' ),
				'permission_callback' => array( __CLASS__, 'can_edit' ),
				'args'                => array(
					'post_type' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);
	}

	/**
	 * Shared context query args.
	 *
	 * @return array<string, mixed>
	 */
	private static function context_args() {
		// NOTE: never name a param "context" — it collides with WP REST global context (view|edit|embed).
		// post_id / user_id / term_id are sanitized loosely: FSE template IDs are strings and must not 400.
		return array(
			'scf_context' => array(
				'type'    => 'string',
				'default' => 'post',
				'enum'    => array( 'post', 'option', 'options', 'user', 'term' ),
			),
			'post_id'     => array(
				'required'          => false,
				'default'           => 0,
				'sanitize_callback' => array( __CLASS__, 'sanitize_id_param' ),
			),
			'post_type'   => array(
				'type'    => 'string',
				'default' => '',
			),
			'user_id'     => array(
				'required'          => false,
				'default'           => 0,
				'sanitize_callback' => array( __CLASS__, 'sanitize_id_param' ),
			),
			'term_id'     => array(
				'required'          => false,
				'default'           => 0,
				'sanitize_callback' => array( __CLASS__, 'sanitize_id_param' ),
			),
			'taxonomy'    => array(
				'type'    => 'string',
				'default' => '',
			),
		);
	}

	/**
	 * Accept numeric IDs only; coerce template string IDs to 0.
	 *
	 * @param mixed $value Raw request value.
	 * @return int
	 */
	public static function sanitize_id_param( $value ) {
		if ( is_numeric( $value ) ) {
			return absint( $value );
		}
		return 0;
	}

	/**
	 * Permission: edit posts.
	 *
	 * @return bool
	 */
	public static function can_edit() {
		return current_user_can( 'edit_posts' );
	}

	/**
	 * Normalize request into resolver args.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array{0:string,1:array<string,mixed>}
	 */
	private static function parse_request( $request ) {
		$context = (string) $request->get_param( 'scf_context' );
		if ( '' === $context ) {
			$context = (string) $request->get_param( 'context' );
		}
		if ( 'options' === $context ) {
			$context = 'option';
		}
		if ( ! in_array( $context, array( 'post', 'option', 'user', 'term' ), true ) ) {
			$context = 'post';
		}
		$args = array(
			'postId'   => (int) $request->get_param( 'post_id' ),
			'postType' => (string) $request->get_param( 'post_type' ),
			'userId'   => (int) $request->get_param( 'user_id' ),
			'termId'   => (int) $request->get_param( 'term_id' ),
			'taxonomy' => (string) $request->get_param( 'taxonomy' ),
		);
		return array( $context, $args );
	}

	/**
	 * GET /fields.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function get_fields( $request ) {
		list( $context, $args ) = self::parse_request( $request );
		$fields = Field_Resolver::list_fields( $context, $args );
		return rest_ensure_response( array( 'fields' => $fields ) );
	}

	/**
	 * GET /values.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function get_values( $request ) {
		list( $context, $args ) = self::parse_request( $request );
		$values = Field_Resolver::list_values( $context, $args );
		return rest_ensure_response( array( 'values' => $values ) );
	}

	/**
	 * GET /taxonomies — taxonomies (+ terms), optionally scoped to a post type.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function get_taxonomies( $request ) {
		$post_type = sanitize_key( (string) $request->get_param( 'post_type' ) );
		$out       = self::collect_taxonomies( $post_type );

		// If post_type was wrong / empty result, still expose usable taxonomies.
		if ( empty( $out ) && $post_type ) {
			$out = self::collect_taxonomies( '' );
		}

		return rest_ensure_response(
			array(
				'taxonomies' => $out,
				'post_type'  => $post_type,
			)
		);
	}

	/**
	 * Build taxonomy list with terms.
	 *
	 * @param string $post_type Optional post type slug.
	 * @return array<int, array<string, mixed>>
	 */
	private static function collect_taxonomies( $post_type = '' ) {
		$tax_objects = array();

		if ( $post_type && post_type_exists( $post_type ) ) {
			$tax_objects = get_object_taxonomies( $post_type, 'objects' );
		} else {
			// All taxonomies with an admin UI (includes non-public CPT taxonomies).
			$tax_objects = get_taxonomies( array( 'show_ui' => true ), 'objects' );
			if ( empty( $tax_objects ) ) {
				$tax_objects = get_taxonomies( array(), 'objects' );
			}
		}

		$out = array();
		foreach ( (array) $tax_objects as $tax ) {
			if ( empty( $tax->name ) ) {
				continue;
			}
			// Skip internal/builtin link taxonomy.
			if ( in_array( $tax->name, array( 'nav_menu', 'link_category', 'post_format', 'wp_pattern_category' ), true ) ) {
				continue;
			}
			// Keep taxonomies usable in admin or assigned to objects.
			if ( empty( $tax->public ) && empty( $tax->show_ui ) && empty( $tax->show_in_rest ) ) {
				continue;
			}

			$terms_raw = get_terms(
				array(
					'taxonomy'   => $tax->name,
					'hide_empty' => false,
					'number'     => 300,
				)
			);
			$terms = array();
			if ( ! is_wp_error( $terms_raw ) && is_array( $terms_raw ) ) {
				foreach ( $terms_raw as $term ) {
					$terms[] = array(
						'id'   => (int) $term->term_id,
						'slug' => (string) $term->slug,
						'name' => (string) $term->name,
					);
				}
			}

			$label = '';
			if ( ! empty( $tax->labels ) && ! empty( $tax->labels->singular_name ) ) {
				$label = (string) $tax->labels->singular_name;
			} elseif ( ! empty( $tax->label ) ) {
				$label = (string) $tax->label;
			} else {
				$label = (string) $tax->name;
			}

			$out[] = array(
				'name'         => (string) $tax->name,
				'label'        => $label,
				'object_type'  => array_values( (array) $tax->object_type ),
				'for_post_type'=> ( $post_type && in_array( $post_type, (array) $tax->object_type, true ) ),
				'terms'        => $terms,
			);
		}

		// Prefer taxonomies attached to the requested post type.
		usort(
			$out,
			static function ( $a, $b ) {
				if ( $a['for_post_type'] === $b['for_post_type'] ) {
					return strcasecmp( $a['label'], $b['label'] );
				}
				return $a['for_post_type'] ? -1 : 1;
			}
		);

		return $out;
	}
}
