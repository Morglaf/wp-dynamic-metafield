/**
 * Client-side Block Bindings source registration.
 */
import { registerBlockBindingsSource } from '@wordpress/blocks';
import { select } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';
import {
	normalizePostId,
	buildFieldsQuery,
	resolveEditorEntity,
} from './utils/editor-query';

const settings =
	typeof window !== 'undefined' && window.dmfSettings
		? window.dmfSettings
		: {
				source: 'wp-dynamic-metafield/scf',
				restNamespace: 'wp-dynamic-metafield/v1',
				restUrl: '',
				postId: 0,
		  };

const REST_NS =
	settings.restNamespace || 'wp-dynamic-metafield/v1';

let valuesCache = { key: '', data: null, promise: null };

/**
 * Load SCF values for a context.
 *
 * @param {Object} params Query params.
 * @return {Promise<Object>}
 */
function loadValues( params ) {
	const key = JSON.stringify( params );
	if ( valuesCache.key === key && valuesCache.data ) {
		return Promise.resolve( valuesCache.data );
	}
	if ( valuesCache.key === key && valuesCache.promise ) {
		return valuesCache.promise;
	}

	const qs = buildFieldsQuery( params );

	valuesCache = {
		key,
		data: null,
		promise: apiFetch( {
			path: `/${ REST_NS }/values?${ qs.toString() }`,
		} ).then( ( res ) => {
			valuesCache.data = res.values || {};
			valuesCache.promise = null;
			return valuesCache.data;
		} ),
	};

	return valuesCache.promise;
}

/**
 * Sync snapshot of values (best-effort for getValues).
 *
 * @type {Object}
 */
let lastValues = {};

/**
 * Prefetch values for current post / template context.
 */
export function prefetchValues( context = 'post', extra = {} ) {
	const entity = resolveEditorEntity(
		select,
		{
			postId: extra.postId,
			postType: extra.postType,
		},
		settings
	);

	return loadValues( {
		scfContext: context,
		postId: normalizePostId( extra.postId ) || entity.postId,
		postType: extra.postType || entity.postType,
		userId: extra.userId || 0,
		termId: extra.termId || 0,
		taxonomy: extra.taxonomy || '',
	} ).then( ( data ) => {
		lastValues = data;
		return data;
	} );
}

/**
 * Shared source handlers.
 */
function buildSourceConfig( label ) {
	return {
		label,
		usesContext: [ 'postId', 'postType' ],
		getValues( { bindings, context } ) {
			const result = {};
			Object.entries( bindings || {} ).forEach(
				( [ attribute, binding ] ) => {
					const args = binding?.args || {};
					const key = args.key;
					if ( ! key ) {
						return;
					}
					const entry = lastValues[ key ];
					if ( ! entry ) {
						prefetchValues( args.context || 'post', {
							postId: context?.postId,
							postType: context?.postType,
							userId: args.userId,
							termId: args.termId,
							taxonomy: args.taxonomy,
						} );
						return;
					}
					if ( attribute === 'id' ) {
						result[ attribute ] = entry.id;
					} else if ( attribute === 'url' ) {
						result[ attribute ] = entry.url;
					} else if ( attribute === 'alt' ) {
						result[ attribute ] = entry.alt;
					} else {
						result[ attribute ] =
							entry.text ?? entry.url ?? entry.raw ?? '';
					}
				}
			);
			return result;
		},
		getFieldsList() {
			return Object.keys( lastValues ).map( ( name ) => ( {
				label: name,
				type: 'string',
				args: {
					key: name,
					context: 'post',
				},
			} ) );
		},
	};
}

/**
 * Register the bindings source in the editor.
 */
export function registerScfBindingsSource() {
	const primary = settings.source || 'wp-dynamic-metafield/scf';
	const legacy = [ 'dynamic-metafield/scf', 'dai-dmf/scf' ];

	registerBlockBindingsSource( {
		name: primary,
		...buildSourceConfig( 'Secure Custom Fields' ),
	} );

	legacy.forEach( ( name ) => {
		if ( name !== primary ) {
			registerBlockBindingsSource( {
				name,
				...buildSourceConfig( 'Secure Custom Fields (legacy)' ),
			} );
		}
	} );

	prefetchValues( 'post' );
}
