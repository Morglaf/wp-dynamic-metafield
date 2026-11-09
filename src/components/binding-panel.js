/**
 * Inspector panel: bind SCF fields to supported core blocks.
 */
import { __ } from '@wordpress/i18n';
import { InspectorControls } from '@wordpress/block-editor';
import {
	PanelBody,
	SelectControl,
	ToggleControl,
	Spinner,
	Button,
	Notice,
	TextControl,
	RangeControl,
} from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { useEffect, useMemo, useState } from '@wordpress/element';
import { createHigherOrderComponent } from '@wordpress/compose';
import { addFilter } from '@wordpress/hooks';
import apiFetch from '@wordpress/api-fetch';
import {
	isSupportedBlock,
	filterFieldsForBlock,
	filterFieldsForButtonUrl,
	filterFieldsForButtonText,
	attributesForBinding,
} from '../utils/field-compat';
import {
	resolveEditorEntity,
	buildFieldsQuery,
} from '../utils/editor-query';

const settings =
	typeof window !== 'undefined' && window.dmfSettings
		? window.dmfSettings
		: { source: 'wp-dynamic-metafield/scf', restNamespace: 'wp-dynamic-metafield/v1', postId: 0 };

const OUR_SOURCES = [
	settings.source || 'wp-dynamic-metafield/scf',
	'wp-dynamic-metafield/scf',
	'dynamic-metafield/scf',
	'dai-dmf/scf',
];

const REST_NS =
	settings.restNamespace || 'wp-dynamic-metafield/v1';

/**
 * @param {string} source
 * @return {boolean}
 */
function isOurSource( source ) {
	return OUR_SOURCES.includes( source );
}

const CONTEXT_OPTIONS = [
	{ label: __( 'Post', 'wp-dynamic-metafield' ), value: 'post' },
	{ label: __( 'SCF Options', 'wp-dynamic-metafield' ), value: 'option' },
	{ label: __( 'User (author)', 'wp-dynamic-metafield' ), value: 'user' },
	{ label: __( 'Term', 'wp-dynamic-metafield' ), value: 'term' },
];

const EMPTY_OPTION = {
	label: __( '— No binding —', 'wp-dynamic-metafield' ),
	value: '',
};

/**
 * @param {Array} fields
 * @return {Array}
 */
function toOptions( fields ) {
	return [
		EMPTY_OPTION,
		...( fields || [] ).map( ( field ) => ( {
			label: `${ field.label } [${ field.type }]`,
			value: field.name,
		} ) ),
	];
}

/**
 * @param {Object} field
 * @param {string} scfContext
 * @param {string} taxonomy
 * @param {string} before
 * @param {string} after
 * @return {Object}
 */
function bindingArgs( field, scfContext, taxonomy, before = '', after = '' ) {
	const args = {
		key: field.name,
		context: scfContext,
		relative: !! field.relative,
	};
	if ( scfContext === 'term' && taxonomy ) {
		args.taxonomy = taxonomy;
	}
	if ( before ) {
		args.before = before;
	}
	if ( after ) {
		args.after = after;
	}
	return args;
}

/**
 * @param {Function} setAttributes
 * @param {Object}   attributes
 * @param {Object}   patch
 * @param {boolean}  hideIfEmpty
 * @param {boolean}  clearOurs
 */
function writeBindings(
	setAttributes,
	attributes,
	patch,
	hideIfEmpty,
	clearOurs = false
) {
	const prevMeta = attributes.metadata || {};
	const prevBindings = { ...( prevMeta.bindings || {} ) };

	if ( clearOurs ) {
		Object.keys( prevBindings ).forEach( ( attr ) => {
			if ( prevBindings[ attr ]?.source && isOurSource(prevBindings[ attr ].source) ) {
				delete prevBindings[ attr ];
			}
		} );
	} else if ( patch ) {
		Object.entries( patch ).forEach( ( [ attr, binding ] ) => {
			if ( ! binding ) {
				delete prevBindings[ attr ];
			} else {
				prevBindings[ attr ] = binding;
			}
		} );
	}

	const metadata = {
		...prevMeta,
		dmf: {
			...( prevMeta.daiDmf || {} ),
			...( prevMeta.dmf || {} ),
			hideIfEmpty: !! hideIfEmpty,
		},
	};

	if ( Object.keys( prevBindings ).length ) {
		metadata.bindings = prevBindings;
	} else {
		delete metadata.bindings;
	}

	setAttributes( {
		metadata: Object.keys( metadata ).length ? metadata : undefined,
	} );
}

/**
 * @param {Object} bindings
 * @param {string} attribute
 * @return {string}
 */
function keyForAttribute( bindings, attribute ) {
	const binding = bindings?.[ attribute ];
	if ( binding?.source && isOurSource(binding.source) ) {
		return binding.args?.key || '';
	}
	return '';
}

/**
 * First of our bindings (for shared affixes on simple blocks).
 *
 * @param {Object} bindings
 * @return {Object|null}
 */
function firstOurBinding( bindings ) {
	return (
		Object.values( bindings || {} ).find(
			( b ) => b?.source && isOurSource(b.source)
		) || null
	);
}

/**
 * Affix fields UI.
 *
 * @param {Object} props
 */
function AffixControls( { before, after, onBefore, onAfter, help } ) {
	return (
		<>
			<TextControl
				label={ __( 'Before (prefix)', 'wp-dynamic-metafield' ) }
				value={ before }
				onChange={ onBefore }
				placeholder="https://"
			/>
			<TextControl
				label={ __( 'After (suffix)', 'wp-dynamic-metafield' ) }
				value={ after }
				onChange={ onAfter }
				placeholder="/releases.atom"
				help={
					help ||
					__(
						'Wrapped around the field value, e.g. https:// + field + /releases.atom',
						'wp-dynamic-metafield'
					)
				}
			/>
		</>
	);
}

/**
 * Native RSS / Atom display options (kept available when feedURL is bound).
 *
 * @param {Object} props
 */
function RssSettingsPanel( { attributes, setAttributes } ) {
	const {
		itemsToShow = 5,
		displayAuthor = false,
		displayDate = false,
		displayExcerpt = false,
		excerptLength = 55,
		openInNewTab = false,
		blockLayout = 'list',
		columns = 2,
	} = attributes || {};

	return (
		<PanelBody
			title={ __( 'RSS / Atom feed options', 'wp-dynamic-metafield' ) }
			initialOpen={ true }
		>
			<RangeControl
				label={ __( 'Number of items', 'wp-dynamic-metafield' ) }
				value={ itemsToShow }
				onChange={ ( value ) =>
					setAttributes( { itemsToShow: value } )
				}
				min={ 1 }
				max={ 20 }
			/>
			<ToggleControl
				label={ __( 'Display author', 'wp-dynamic-metafield' ) }
				checked={ !! displayAuthor }
				onChange={ ( value ) =>
					setAttributes( { displayAuthor: value } )
				}
			/>
			<ToggleControl
				label={ __( 'Display date', 'wp-dynamic-metafield' ) }
				checked={ !! displayDate }
				onChange={ ( value ) => setAttributes( { displayDate: value } ) }
			/>
			<ToggleControl
				label={ __( 'Display excerpt', 'wp-dynamic-metafield' ) }
				checked={ !! displayExcerpt }
				onChange={ ( value ) =>
					setAttributes( { displayExcerpt: value } )
				}
			/>
			{ displayExcerpt && (
				<RangeControl
					label={ __( 'Max excerpt words', 'wp-dynamic-metafield' ) }
					value={ excerptLength }
					onChange={ ( value ) =>
						setAttributes( { excerptLength: value } )
					}
					min={ 1 }
					max={ 100 }
				/>
			) }
			<ToggleControl
				label={ __(
					'Open links in a new tab',
					'wp-dynamic-metafield'
				) }
				checked={ !! openInNewTab }
				onChange={ ( value ) =>
					setAttributes( { openInNewTab: value } )
				}
			/>
			<SelectControl
				label={ __( 'Layout', 'wp-dynamic-metafield' ) }
				value={ blockLayout }
				options={ [
					{
						label: __( 'List', 'wp-dynamic-metafield' ),
						value: 'list',
					},
					{
						label: __( 'Grid', 'wp-dynamic-metafield' ),
						value: 'grid',
					},
				] }
				onChange={ ( value ) =>
					setAttributes( { blockLayout: value } )
				}
			/>
			{ blockLayout === 'grid' && (
				<RangeControl
					label={ __( 'Columns', 'wp-dynamic-metafield' ) }
					value={ columns }
					onChange={ ( value ) => setAttributes( { columns: value } ) }
					min={ 2 }
					max={ 6 }
				/>
			) }
		</PanelBody>
	);
}

/**
 * @param {Object} props
 */
function BindingPanel( {
	name,
	attributes,
	setAttributes,
	context: blockContext,
} ) {
	const isButton = name === 'core/button';
	const isUrlBlock =
		name === 'core/rss' ||
		name === 'core/embed' ||
		name?.startsWith( 'core-embed/' );

	const [ scfContext, setScfContext ] = useState( 'post' );
	const [ fields, setFields ] = useState( [] );
	const [ taxonomies, setTaxonomies ] = useState( [] );
	const [ taxLoading, setTaxLoading ] = useState( false );
	const [ taxError, setTaxError ] = useState( '' );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ taxonomy, setTaxonomy ] = useState( '' );
	const [ before, setBefore ] = useState( '' );
	const [ after, setAfter ] = useState( '' );
	const [ urlBefore, setUrlBefore ] = useState( '' );
	const [ urlAfter, setUrlAfter ] = useState( '' );

	const { postId, postType } = useSelect(
		( select ) => resolveEditorEntity( select, blockContext, settings ),
		[ blockContext ]
	);

	const bindings = attributes?.metadata?.bindings || {};
	const dmfMeta = {
		...( attributes?.metadata?.daiDmf || {} ),
		...( attributes?.metadata?.dmf || {} ),
	};
	const hideIfEmpty = !! dmfMeta.hideIfEmpty;
	const showIfField = dmfMeta.showIfField || '';
	const showIfContext = dmfMeta.showIfContext || scfContext;
	const showIfType =
		dmfMeta.showIfType ||
		( showIfField ? 'field' : '' ) ||
		( dmfMeta.showIfTaxonomy ? 'taxonomy' : '' );
	const showIfTaxonomy = dmfMeta.showIfTaxonomy || '';
	const showIfTerm = dmfMeta.showIfTerm ? String( dmfMeta.showIfTerm ) : '';
	const showIfTaxOperator = dmfMeta.showIfTaxOperator || 'has';

	const currentKey = useMemo( () => {
		const first = firstOurBinding( bindings );
		return first?.args?.key || '';
	}, [ bindings ] );

	const urlKey = useMemo(
		() => keyForAttribute( bindings, 'url' ),
		[ bindings ]
	);
	const textKey = useMemo(
		() => keyForAttribute( bindings, 'text' ),
		[ bindings ]
	);

	const currentContext = useMemo( () => {
		const first = firstOurBinding( bindings );
		return first?.args?.context || scfContext;
	}, [ bindings, scfContext ] );

	const hasOurBinding = useMemo(
		() =>
			Object.values( bindings ).some(
				( b ) => b?.source && isOurSource(b.source)
			),
		[ bindings ]
	);

	// Sync affix local state from existing bindings.
	useEffect( () => {
		if ( isButton ) {
			const urlBinding = bindings.url;
			if ( urlBinding?.source && isOurSource( urlBinding.source ) ) {
				setUrlBefore( urlBinding.args?.before || '' );
				setUrlAfter( urlBinding.args?.after || '' );
			}
			return;
		}
		const first = firstOurBinding( bindings );
		if ( first ) {
			setBefore( first.args?.before || '' );
			setAfter( first.args?.after || '' );
		}
	}, [ bindings, isButton ] );

	useEffect( () => {
		let cancelled = false;
		setLoading( true );
		setError( '' );
		const qs = buildFieldsQuery( {
			scfContext,
			postId,
			postType,
			taxonomy,
		} );
		apiFetch( { path: `/${ REST_NS }/fields?${ qs.toString() }` } )
			.then( ( res ) => {
				if ( ! cancelled ) {
					setFields( res.fields || [] );
				}
			} )
			.catch( ( err ) => {
				if ( ! cancelled ) {
					setFields( [] );
					setError(
						err?.message ||
							__(
								'Unable to load SCF fields.',
								'wp-dynamic-metafield'
							)
					);
				}
			} )
			.finally( () => {
				if ( ! cancelled ) {
					setLoading( false );
				}
			} );
		return () => {
			cancelled = true;
		};
	}, [ scfContext, postId, postType, taxonomy ] );

	useEffect( () => {
		let cancelled = false;
		setTaxLoading( true );
		setTaxError( '' );

		const loadTaxonomies = async () => {
			try {
				const qs = new URLSearchParams();
				if ( postType ) {
					qs.set( 'post_type', postType );
				}
				let res = await apiFetch( {
					path: `/${ REST_NS }/taxonomies?${ qs.toString() }`,
				} );
				let list = res?.taxonomies || [];

				// Fallback: no post type filter if scoped result is empty.
				if ( ! list.length && postType ) {
					res = await apiFetch( {
						path: `/${ REST_NS }/taxonomies`,
					} );
					list = res?.taxonomies || [];
				}

				if ( ! cancelled ) {
					setTaxonomies( list );
					if ( ! list.length ) {
						setTaxError(
							__(
								'No taxonomy found. Check that a taxonomy with an admin UI is registered for this post type.',
								'wp-dynamic-metafield'
							)
						);
					}
				}
			} catch ( err ) {
				if ( ! cancelled ) {
					setTaxonomies( [] );
					setTaxError(
						err?.message ||
							__(
								'Unable to load taxonomies.',
								'wp-dynamic-metafield'
							)
					);
				}
			} finally {
				if ( ! cancelled ) {
					setTaxLoading( false );
				}
			}
		};

		loadTaxonomies();
		return () => {
			cancelled = true;
		};
	}, [ postType ] );

	const compatible = useMemo(
		() => filterFieldsForBlock( fields, name ),
		[ fields, name ]
	);
	const urlFields = useMemo(
		() => filterFieldsForButtonUrl( fields ),
		[ fields ]
	);
	const textFields = useMemo(
		() => filterFieldsForButtonText( fields ),
		[ fields ]
	);

	const clearBinding = () => {
		writeBindings( setAttributes, attributes, null, hideIfEmpty, true );
		setBefore( '' );
		setAfter( '' );
		setUrlBefore( '' );
		setUrlAfter( '' );
	};

	/**
	 * Patch before/after on existing bindings (and keep keys).
	 *
	 * @param {string} scope 'all' | 'url'
	 * @param {string} nextBefore
	 * @param {string} nextAfter
	 */
	const persistAffixes = ( scope, nextBefore, nextAfter ) => {
		const prevBindings = { ...( attributes.metadata?.bindings || {} ) };
		let changed = false;

		Object.keys( prevBindings ).forEach( ( attr ) => {
			const binding = prevBindings[ attr ];
			if ( !binding?.source || !isOurSource(binding.source) ) {
				return;
			}
			if ( scope === 'url' && attr !== 'url' && attr !== 'linkTarget' ) {
				return;
			}
			const args = { ...( binding.args || {} ) };
			if ( nextBefore ) {
				args.before = nextBefore;
			} else {
				delete args.before;
			}
			if ( nextAfter ) {
				args.after = nextAfter;
			} else {
				delete args.after;
			}
			prevBindings[ attr ] = { ...binding, args };
			changed = true;
		} );

		if ( ! changed ) {
			return;
		}

		setAttributes( {
			metadata: {
				...( attributes.metadata || {} ),
				bindings: prevBindings,
				dmf: {
					...( attributes.metadata?.dmf || {} ),
					hideIfEmpty,
				},
			},
		} );
	};

	const applyAttributeBinding = (
		attribute,
		fieldName,
		pool,
		extraAttrs = [],
		affixBefore = '',
		affixAfter = ''
	) => {
		if ( ! fieldName ) {
			const patch = { [ attribute ]: null };
			extraAttrs.forEach( ( a ) => {
				patch[ a ] = null;
			} );
			writeBindings( setAttributes, attributes, patch, hideIfEmpty );
			return;
		}
		const field = pool.find( ( f ) => f.name === fieldName );
		if ( ! field ) {
			return;
		}
		const args = bindingArgs(
			field,
			scfContext,
			taxonomy,
			affixBefore,
			affixAfter
		);
		const patch = {
			[ attribute ]: {
				source: settings.source,
				args,
			},
		};
		extraAttrs.forEach( ( a ) => {
			patch[ a ] = {
				source: settings.source,
				args,
			};
		} );
		writeBindings( setAttributes, attributes, patch, hideIfEmpty );
	};

	const applyBinding = ( fieldName ) => {
		if ( ! fieldName ) {
			clearBinding();
			return;
		}
		const field = compatible.find( ( f ) => f.name === fieldName );
		if ( ! field ) {
			return;
		}
		const attrs = attributesForBinding( name, field.type );
		if ( ! attrs.length ) {
			return;
		}

		const prevBindings = { ...( attributes.metadata?.bindings || {} ) };
		Object.keys( prevBindings ).forEach( ( attr ) => {
			if ( prevBindings[ attr ]?.source && isOurSource(prevBindings[ attr ].source) ) {
				delete prevBindings[ attr ];
			}
		} );

		const args = bindingArgs(
			field,
			scfContext,
			taxonomy,
			before,
			after
		);
		const next = {};
		attrs.forEach( ( attr ) => {
			next[ attr ] = {
				source: settings.source,
				args,
			};
		} );

		setAttributes( {
			metadata: {
				...( attributes.metadata || {} ),
				bindings: {
					...prevBindings,
					...next,
				},
				dmf: {
					...( attributes.metadata?.daiDmf || {} ),
					...( attributes.metadata?.dmf || {} ),
					hideIfEmpty,
				},
			},
		} );
	};

	const setHideIfEmpty = ( value ) => {
		writeBindings( setAttributes, attributes, null, value, false );
	};

	/**
	 * Persist visibility-by-field without touching bindings.
	 *
	 * @param {Object} patch Partial dmf meta.
	 */
	const patchDmfMeta = ( patch ) => {
		const prevMeta = attributes.metadata || {};
		const nextDmf = {
			...( prevMeta.daiDmf || {} ),
			...( prevMeta.dmf || {} ),
			...patch,
		};

		const type = nextDmf.showIfType || '';
		if ( ! type || type === 'none' ) {
			delete nextDmf.showIfType;
			delete nextDmf.showIfField;
			delete nextDmf.showIfContext;
			delete nextDmf.showIfRelative;
			delete nextDmf.showIfTaxonomy;
			delete nextDmf.showIfTerm;
			delete nextDmf.showIfTaxOperator;
		} else if ( type === 'field' ) {
			delete nextDmf.showIfTaxonomy;
			delete nextDmf.showIfTerm;
			delete nextDmf.showIfTaxOperator;
			if ( ! nextDmf.showIfField ) {
				delete nextDmf.showIfField;
				delete nextDmf.showIfContext;
				delete nextDmf.showIfRelative;
			}
		} else if ( type === 'taxonomy' ) {
			delete nextDmf.showIfField;
			delete nextDmf.showIfContext;
			delete nextDmf.showIfRelative;
			if ( ! nextDmf.showIfTaxonomy ) {
				delete nextDmf.showIfTaxonomy;
				delete nextDmf.showIfTerm;
				delete nextDmf.showIfTaxOperator;
			}
		}

		setAttributes( {
			metadata: {
				...prevMeta,
				dmf: nextDmf,
			},
		} );
	};

	const setShowIfType = ( type ) => {
		if ( ! type || type === 'none' ) {
			patchDmfMeta( { showIfType: '' } );
			return;
		}
		patchDmfMeta( { showIfType: type } );
	};

	const setShowIfField = ( fieldName ) => {
		if ( ! fieldName ) {
			patchDmfMeta( {
				showIfType: 'field',
				showIfField: '',
				showIfContext: undefined,
				showIfRelative: undefined,
			} );
			return;
		}
		const field = fields.find( ( f ) => f.name === fieldName );
		patchDmfMeta( {
			showIfType: 'field',
			showIfField: fieldName,
			showIfContext: scfContext,
			showIfRelative: !! field?.relative,
		} );
	};

	const setShowIfTaxonomy = ( taxName ) => {
		patchDmfMeta( {
			showIfType: 'taxonomy',
			showIfTaxonomy: taxName || '',
			showIfTerm: '',
			showIfTaxOperator: showIfTaxOperator || 'has',
		} );
	};

	const setShowIfTerm = ( termValue ) => {
		patchDmfMeta( {
			showIfType: 'taxonomy',
			showIfTaxonomy,
			showIfTerm: termValue || '',
			showIfTaxOperator: showIfTaxOperator || 'has',
		} );
	};

	const setShowIfTaxOperator = ( op ) => {
		patchDmfMeta( {
			showIfType: 'taxonomy',
			showIfTaxonomy,
			showIfTerm,
			showIfTaxOperator: op || 'has',
		} );
	};

	const allFieldOptions = useMemo( () => {
		const opts = [
			{
				label: __( '— Choose a field —', 'wp-dynamic-metafield' ),
				value: '',
			},
		];
		( fields || [] )
			.filter( ( f ) => ! f.loopable )
			.forEach( ( field ) => {
				opts.push( {
					label: `${ field.label } [${ field.type }]`,
					value: field.name,
				} );
			} );
		return opts;
	}, [ fields ] );

	const taxonomyOptions = useMemo( () => {
		const opts = [
			{
				label: __( '— Choose a taxonomy —', 'wp-dynamic-metafield' ),
				value: '',
			},
		];
		( taxonomies || [] ).forEach( ( tax ) => {
			opts.push( {
				label: `${ tax.label } (${ tax.name })`,
				value: tax.name,
			} );
		} );
		return opts;
	}, [ taxonomies ] );

	const termOptions = useMemo( () => {
		const tax = ( taxonomies || [] ).find( ( t ) => t.name === showIfTaxonomy );
		const opts = [
			{
				label: __( '— Any term —', 'wp-dynamic-metafield' ),
				value: '',
			},
		];
		( tax?.terms || [] ).forEach( ( term ) => {
			opts.push( {
				label: term.name,
				value: String( term.id ),
			} );
		} );
		return opts;
	}, [ taxonomies, showIfTaxonomy ] );

	const scfTaxonomyOptions = useMemo( () => {
		const opts = [
			{
				label: __( '— Choose —', 'wp-dynamic-metafield' ),
				value: '',
			},
		];
		( taxonomies || [] ).forEach( ( tax ) => {
			opts.push( {
				label: `${ tax.label } (${ tax.name })`,
				value: tax.name,
			} );
		} );
		if ( ! opts.find( ( o ) => o.value === 'category' ) ) {
			opts.push( { label: 'category', value: 'category' } );
		}
		if ( ! opts.find( ( o ) => o.value === 'post_tag' ) ) {
			opts.push( { label: 'post_tag', value: 'post_tag' } );
		}
		return opts;
	}, [ taxonomies ] );

	return (
		<InspectorControls>
			<PanelBody
				title={ __( 'SCF Binding', 'wp-dynamic-metafield' ) }
				initialOpen={ true }
			>
				{ error && (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) }
				<SelectControl
					label={ __( 'Context', 'wp-dynamic-metafield' ) }
					value={ currentContext }
					options={ CONTEXT_OPTIONS }
					onChange={ setScfContext }
				/>
				{ scfContext === 'term' && (
					<SelectControl
						label={ __( 'Taxonomy', 'wp-dynamic-metafield' ) }
						value={ taxonomy }
						options={ scfTaxonomyOptions }
						onChange={ setTaxonomy }
					/>
				) }
				{ loading ? (
					<Spinner />
				) : isButton ? (
					<>
						<SelectControl
							label={ __( 'Button link (URL)', 'wp-dynamic-metafield' ) }
							value={ urlKey }
							options={ toOptions( urlFields ) }
							onChange={ ( value ) =>
								applyAttributeBinding(
									'url',
									value,
									urlFields,
									[ 'linkTarget' ],
									urlBefore,
									urlAfter
								)
							}
						/>
						{ ( urlKey || urlBefore || urlAfter ) && (
							<AffixControls
								before={ urlBefore }
								after={ urlAfter }
								onBefore={ ( v ) => {
									setUrlBefore( v );
									persistAffixes( 'url', v, urlAfter );
								} }
								onAfter={ ( v ) => {
									setUrlAfter( v );
									persistAffixes( 'url', urlBefore, v );
								} }
								help={ __(
									'E.g. before=https:// and after=/releases.atom for a GitHub path.',
									'wp-dynamic-metafield'
								) }
							/>
						) }
						<SelectControl
							label={ __( 'Button label', 'wp-dynamic-metafield' ) }
							value={ textKey }
							options={ toOptions( textFields ) }
							onChange={ ( value ) =>
								applyAttributeBinding( 'text', value, textFields )
							}
						/>
					</>
				) : (
					<>
						<SelectControl
							label={
								isUrlBlock
									? name === 'core/rss'
										? __( 'RSS feed URL field', 'wp-dynamic-metafield' )
										: __( 'URL field (embed / YouTube)', 'wp-dynamic-metafield' )
									: __( 'SCF field', 'wp-dynamic-metafield' )
							}
							value={ currentKey }
							options={ toOptions( compatible ) }
							onChange={ applyBinding }
							help={
								compatible.length
									? undefined
									: __(
											'No compatible field for this block.',
											'wp-dynamic-metafield'
									  )
							}
						/>
						{ ( currentKey || before || after ) && (
							<AffixControls
								before={ before }
								after={ after }
								onBefore={ ( v ) => {
									setBefore( v );
									persistAffixes( 'all', v, after );
								} }
								onAfter={ ( v ) => {
									setAfter( v );
									persistAffixes( 'all', before, v );
								} }
							/>
						) }
					</>
				) }
				<SelectControl
					label={ __( 'Visibility condition', 'wp-dynamic-metafield' ) }
					value={ showIfType || 'none' }
					options={ [
						{
							label: __( 'Always visible', 'wp-dynamic-metafield' ),
							value: 'none',
						},
						{
							label: __( 'If SCF field is filled', 'wp-dynamic-metafield' ),
							value: 'field',
						},
						{
							label: __( 'By taxonomy / term', 'wp-dynamic-metafield' ),
							value: 'taxonomy',
						},
					] }
					onChange={ setShowIfType }
					help={ __(
						'Independent of bound link / label. Useful to show a button only on some content.',
						'wp-dynamic-metafield'
					) }
				/>
				{ showIfType === 'field' && (
					<>
						<SelectControl
							label={ __( 'Field to check', 'wp-dynamic-metafield' ) }
							value={ showIfField }
							options={ allFieldOptions }
							onChange={ setShowIfField }
						/>
						{ showIfField && (
							<p
								style={ {
									marginTop: 0,
									opacity: 0.75,
									fontSize: '12px',
								} }
							>
								{ __( 'Condition context: ', 'wp-dynamic-metafield' ) +
									( showIfContext || scfContext ) }
							</p>
						) }
					</>
				) }
				{ showIfType === 'taxonomy' && (
					<>
						{ taxLoading && <Spinner /> }
						{ taxError && (
							<Notice status="warning" isDismissible={ false }>
								{ taxError }
								{ postType
									? ` (post_type: ${ postType })`
									: '' }
							</Notice>
						) }
						<SelectControl
							label={ __( 'Taxonomy', 'wp-dynamic-metafield' ) }
							value={ showIfTaxonomy }
							options={ taxonomyOptions }
							onChange={ setShowIfTaxonomy }
							help={
								postType
									? __(
											'Taxonomies for the current post type, then others if needed.',
											'wp-dynamic-metafield'
									  )
									: __(
											'All taxonomies with an admin UI.',
											'wp-dynamic-metafield'
									  )
							}
						/>
						<SelectControl
							label={ __( 'Term', 'wp-dynamic-metafield' ) }
							value={ showIfTerm }
							options={ termOptions }
							onChange={ setShowIfTerm }
							help={
								showIfTaxonomy && termOptions.length <= 1
									? __(
											'No terms in this taxonomy yet — create one in the admin.',
											'wp-dynamic-metafield'
									  )
									: __(
											'Leave “any term” to show whenever at least one term is assigned.',
											'wp-dynamic-metafield'
									  )
							}
						/>
						<SelectControl
							label={ __( 'Rule', 'wp-dynamic-metafield' ) }
							value={ showIfTaxOperator }
							options={ [
								{
									label: __(
										'Show if the term is present',
										'wp-dynamic-metafield'
									),
									value: 'has',
								},
								{
									label: __(
										'Show if the term is absent',
										'wp-dynamic-metafield'
									),
									value: 'not_has',
								},
							] }
							onChange={ setShowIfTaxOperator }
						/>
					</>
				) }
				<ToggleControl
					label={ __( 'Hide if binding is empty', 'wp-dynamic-metafield' ) }
					checked={ hideIfEmpty }
					onChange={ setHideIfEmpty }
					help={
						isButton
							? __(
									'Hides the button if the bound SCF link or label is empty.',
									'wp-dynamic-metafield'
							  )
							: __(
									'Hides the block if the bound field value (before prefix/suffix) is empty.',
									'wp-dynamic-metafield'
							  )
					}
				/>
				{ hasOurBinding && (
					<Button
						variant="secondary"
						isDestructive
						onClick={ clearBinding }
					>
						{ __( 'Remove binding', 'wp-dynamic-metafield' ) }
					</Button>
				) }
			</PanelBody>
			{ name === 'core/rss' && (
				<RssSettingsPanel
					attributes={ attributes }
					setAttributes={ setAttributes }
				/>
			) }
		</InspectorControls>
	);
}

const withBindingPanel = createHigherOrderComponent( ( BlockEdit ) => {
	return ( props ) => {
		if ( ! isSupportedBlock( props.name ) ) {
			return <BlockEdit { ...props } />;
		}
		return (
			<>
				<BlockEdit { ...props } />
				<BindingPanel { ...props } />
			</>
		);
	};
}, 'withDmfBindingPanel' );

addFilter(
	'editor.BlockEdit',
	'wp-dynamic-metafield/with-binding-panel',
	withBindingPanel
);
