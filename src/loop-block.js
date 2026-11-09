/**
 * Editor UI for wp-dynamic-metafield/loop container block.
 */
import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import {
	useBlockProps,
	InspectorControls,
	InnerBlocks,
} from '@wordpress/block-editor';
import { PanelBody, SelectControl, TextControl, Spinner } from '@wordpress/components';
import { useEffect, useMemo, useState } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';
import { filterLoopableFields } from './utils/field-compat';
import {
	resolveEditorEntity,
	buildFieldsQuery,
} from './utils/editor-query';

const I18N = 'wp-dynamic-metafield';

const settings =
	typeof window !== 'undefined' && window.dmfSettings
		? window.dmfSettings
		: { postId: 0, restNamespace: 'wp-dynamic-metafield/v1' };

const REST_NS = settings.restNamespace || 'wp-dynamic-metafield/v1';

const CONTEXT_OPTIONS = [
	{ label: __( 'Post', I18N ), value: 'post' },
	{ label: __( 'SCF Options', I18N ), value: 'option' },
	{ label: __( 'User (author)', I18N ), value: 'user' },
	{ label: __( 'Term', I18N ), value: 'term' },
];

const ALLOWED = [
	'core/paragraph',
	'core/heading',
	'core/image',
	'core/button',
	'core/group',
	'core/columns',
	'core/column',
	'wp-dynamic-metafield/loop',
	'dynamic-metafield/loop',
	'dai-dmf/loop',
];

function Edit( { attributes, setAttributes } ) {
	const { field, context, layout, taxonomy, tagName } = attributes;
	const [ fields, setFields ] = useState( [] );
	const [ loading, setLoading ] = useState( false );

	const { postId, postType } = useSelect(
		( select ) => resolveEditorEntity( select, {}, settings ),
		[]
	);

	useEffect( () => {
		let cancelled = false;
		setLoading( true );
		const qs = buildFieldsQuery( {
			scfContext: context || 'post',
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
			.catch( () => {
				if ( ! cancelled ) {
					setFields( [] );
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
	}, [ context, postId, postType, taxonomy ] );

	const loopables = useMemo(
		() => filterLoopableFields( fields ),
		[ fields ]
	);

	const fieldOptions = useMemo( () => {
		const opts = [
			{
				label: __( '— Choose a repeater / flexible field —', I18N ),
				value: '',
			},
		];
		loopables.forEach( ( f ) => {
			opts.push( {
				label: `${ f.label }`,
				value: f.name,
			} );
		} );
		return opts;
	}, [ loopables ] );

	const blockProps = useBlockProps( {
		className: 'wp-block-wp-dynamic-metafield-loop',
	} );

	return (
		<>
			<InspectorControls>
				<PanelBody
					title={ __( 'SCF Loop', I18N ) }
					initialOpen={ true }
				>
					<SelectControl
						label={ __( 'Context', I18N ) }
						value={ context }
						options={ CONTEXT_OPTIONS }
						onChange={ ( value ) => setAttributes( { context: value } ) }
					/>
					{ context === 'term' && (
						<TextControl
							label={ __( 'Taxonomy', I18N ) }
							value={ taxonomy }
							onChange={ ( value ) => setAttributes( { taxonomy: value } ) }
						/>
					) }
					{ loading ? (
						<Spinner />
					) : (
						<SelectControl
							label={ __( 'Loopable field', I18N ) }
							value={ field }
							options={ fieldOptions }
							onChange={ ( value ) => setAttributes( { field: value } ) }
						/>
					) }
					<TextControl
						label={ __( 'Flexible layout (optional)', I18N ) }
						value={ layout }
						onChange={ ( value ) => setAttributes( { layout: value } ) }
						help={ __(
							'If set, only Flexible Content rows with this layout are rendered.',
							I18N
						) }
					/>
					<SelectControl
						label={ __( 'HTML tag', I18N ) }
						value={ tagName || 'div' }
						options={ [
							{ label: 'div', value: 'div' },
							{ label: 'section', value: 'section' },
							{ label: 'ul', value: 'ul' },
							{ label: 'ol', value: 'ol' },
						] }
						onChange={ ( value ) => setAttributes( { tagName: value } ) }
					/>
				</PanelBody>
			</InspectorControls>
			<div { ...blockProps }>
				<p className="dmf-loop__label">
					{ field
						? __( 'SCF loop: ', I18N ) + field
						: __(
								'SCF loop — choose a Repeater / Flexible field',
								I18N
						  ) }
				</p>
				<InnerBlocks allowedBlocks={ ALLOWED } />
			</div>
		</>
	);
}

export function registerLoopBlock() {
	registerBlockType( 'wp-dynamic-metafield/loop', {
		apiVersion: 3,
		title: __( 'SCF Field Loop', I18N ),
		description: __(
			'Loop over a Secure Custom Fields Repeater or Flexible Content field.',
			I18N
		),
		category: 'theme',
		icon: 'update',
		attributes: {
			field: { type: 'string', default: '' },
			context: { type: 'string', default: 'post' },
			layout: { type: 'string', default: '' },
			userId: { type: 'number', default: 0 },
			termId: { type: 'number', default: 0 },
			taxonomy: { type: 'string', default: '' },
			tagName: { type: 'string', default: 'div' },
		},
		supports: {
			html: false,
			align: [ 'wide', 'full' ],
		},
		usesContext: [ 'postId', 'postType', 'termId', 'taxonomy' ],
		edit: Edit,
		save: () => <InnerBlocks.Content />,
	} );
}
