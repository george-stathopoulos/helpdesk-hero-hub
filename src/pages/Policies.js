import { __, _n, sprintf } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import { useApi, send } from '../ui/lib/hooks';
import {
	Card,
	PageHead,
	Button,
	Drawer,
	ErrorNotice,
	Loading,
	useToast,
} from '../ui/components/ui';
import { TextField, confirmAction } from '../ui/components/kit';
import { SaveBar } from '../ui/lib/settings';
import PolicyEditor from '../components/PolicyEditor';
import { message } from '../components/common';

/**
 * JSON with sorted keys, so two policies with the same rules compare equal.
 *
 * @param {any} value Value.
 * @return {string} JSON.
 */
function stable( value ) {
	if ( Array.isArray( value ) ) {
		return `[${ value.map( stable ).join( ',' ) }]`;
	}
	if ( value && typeof value === 'object' ) {
		return `{${ Object.keys( value )
			.sort()
			.map( ( k ) => `${ JSON.stringify( k ) }:${ stable( value[ k ] ) }` )
			.join( ',' ) }}`;
	}
	return JSON.stringify( value );
}

export default function Policies() {
	const policy = useApi( 'admin/policy' );
	const templates = useApi( 'admin/templates' );
	const [ draft, setDraft ] = useState( null );
	const [ saving, setSaving ] = useState( false );
	const [ editing, setEditing ] = useState( null );
	const toast = useToast();

	useEffect( () => {
		if ( policy.data ) {
			setDraft( policy.data.policy );
		}
	}, [ policy.data ] );

	if ( policy.error || templates.error ) {
		return (
			<ErrorNotice
				error={ policy.error || templates.error }
				onRetry={ () => window.location.reload() }
			/>
		);
	}
	if ( ! policy.data || ! templates.data || ! draft ) {
		return <Loading />;
	}
	const { roles, sections, ratings } = policy.data;
	const saved = stable( policy.data.policy );
	const matching = templates.data.templates.find(
		( t ) => stable( t.policy ) === saved
	);
	const dirty =
		JSON.stringify( draft ) !== JSON.stringify( policy.data.policy );

	const saveDefault = () => {
		setSaving( true );
		send( 'admin/policy', 'POST', draft )
			.then( () => {
				toast(
					__(
						'Default policy saved and sent to the sites that use it',
						'helpdesk-hero-hub'
					)
				);
				policy.reload();
			} )
			.catch( ( e ) => toast( message( e ), 'alert' ) )
			.finally( () => setSaving( false ) );
	};

	const remove = ( tpl ) => {
		const msg = tpl.sites
			? sprintf(
					/* translators: 1: template, 2: number of sites */
					_n(
						'Delete “%1$s”? %2$d site uses it and will go back to your default policy.',
						'Delete “%1$s”? %2$d sites use it and will go back to your default policy.',
						tpl.sites,
						'helpdesk-hero-hub'
					),
					tpl.name,
					tpl.sites
			  )
			: sprintf(
					/* translators: %s: template */
					__( 'Delete “%s”?', 'helpdesk-hero-hub' ),
					tpl.name
			  );
		confirmAction( msg, {
			confirmText: __( 'Delete', 'helpdesk-hero-hub' ),
		} ).then( ( yes ) => {
			if ( ! yes ) {
				return;
			}
			send( `admin/templates/${ tpl.id }`, 'DELETE' )
				.then( () => {
					toast( __( 'Template deleted', 'helpdesk-hero-hub' ) );
					templates.reload();
				} )
				.catch( ( e ) => toast( message( e ), 'alert' ) );
		} );
	};

	return (
		<>
			<PageHead
				title={ __( 'Policies', 'helpdesk-hero-hub' ) }
				lede={ __(
					'Your rules for connected sites: what access your team gets, what customers can change, and what they send with a ticket. Sites use the default policy unless you give them a template or their own rules (Sites).',
					'helpdesk-hero-hub'
				) }
			/>

			<div
				className="hdh-default-policy"
				role="note"
				aria-label={ __( 'Default policy', 'helpdesk-hero-hub' ) }
			>
				<span className="hdh-default-policy__badge">
					{ __( 'Default', 'helpdesk-hero-hub' ) }
				</span>
				<div className="hdh-default-policy__text">
					<strong>
						{ __(
							'Your default support policy',
							'helpdesk-hero-hub'
						) }
					</strong>
					<span className="hdh-muted">
						{ sprintf(
							/* translators: %d: number of sites */
							_n(
								'Used by %d site, and by every new site unless you choose a template.',
								'Used by %d sites, and by every new site unless you choose a template.',
								templates.data.default_sites || 0,
								'helpdesk-hero-hub'
							),
							templates.data.default_sites || 0
						) }
						{ matching
							? ' ' +
							  sprintf(
									/* translators: %s: template name */
									__(
										'Same rules as the “%s” template.',
										'helpdesk-hero-hub'
									),
									matching.name
							  )
							: '' }
					</span>
				</div>
				<Button
					size="sm"
					onClick={ () => {
						const el = document.getElementById( 'default-policy' );
						if ( el ) {
							el.scrollIntoView( { behavior: 'smooth' } );
							el.focus( { preventScroll: true } );
						}
					} }
				>
					{ __( 'Edit default policy', 'helpdesk-hero-hub' ) }
				</Button>
			</div>

			<Card
				title={ __( 'Templates', 'helpdesk-hero-hub' ) }
				sub={ __(
					'Saved policies to apply to sites one by one or in bulk. Editing a template updates every site that uses it.',
					'helpdesk-hero-hub'
				) }
				action={
					<div className="hdh-row">
						<Button
							size="sm"
							onClick={ () =>
								setEditing( {
									id: '',
									name: '',
									description: '',
									policy: draft,
								} )
							}
						>
							{ __(
								'Save default as template',
								'helpdesk-hero-hub'
							) }
						</Button>
						<Button
							size="sm"
							variant="primary"
							icon="plus"
							onClick={ () =>
								setEditing( {
									id: '',
									name: '',
									description: '',
									policy: templates.data.templates[ 0 ]
										.policy,
								} )
							}
						>
							{ __( 'New template', 'helpdesk-hero-hub' ) }
						</Button>
					</div>
				}
				bodyClass={ null }
				style={ { marginBottom: 18 } }
			>
				<div className="hdh-table-wrap">
					<table className="hdh-table">
						<thead>
							<tr>
								<th scope="col">
									{ __( 'Template', 'helpdesk-hero-hub' ) }
								</th>
								<th scope="col">
									{ __( 'Sites', 'helpdesk-hero-hub' ) }
								</th>
								<th scope="col" style={ { width: 1 } }>
									<span className="hdh-sr">
										{ __( 'Actions', 'helpdesk-hero-hub' ) }
									</span>
								</th>
							</tr>
						</thead>
						<tbody>
							{ templates.data.templates.map( ( t ) => (
								<tr key={ t.id }>
									<td>
										<button
											type="button"
											className="hdh-strong-link"
											style={ {
												background: 'none',
												border: 0,
												padding: 0,
												cursor: 'pointer',
												font: 'inherit',
											} }
											onClick={ () => setEditing( t ) }
										>
											{ t.name }
										</button>
										{ matching &&
											matching.id === t.id && (
												<span className="hdh-default-pill">
													{ __(
														'Same as default',
														'helpdesk-hero-hub'
													) }
												</span>
											) }
										<div className="hdh-muted">
											{ t.description }
										</div>
									</td>
									<td>{ t.sites }</td>
									<td>
										<div
											className="hdh-row"
											style={ { flexWrap: 'nowrap' } }
										>
											<Button
												size="sm"
												variant="ghost"
												icon="edit"
												onClick={ () =>
													setEditing( t )
												}
											>
												{ __(
													'Edit',
													'helpdesk-hero-hub'
												) }
											</Button>
											<Button
												size="sm"
												variant="ghost"
												onClick={ () => {
													setDraft( t.policy );
													toast(
														__(
															'Loaded into the default policy below. Save to apply.',
															'helpdesk-hero-hub'
														)
													);
												} }
											>
												{ __(
													'Use as default',
													'helpdesk-hero-hub'
												) }
											</Button>
											<Button
												size="sm"
												variant="ghost"
												icon="trash"
												aria-label={ __(
													'Delete',
													'helpdesk-hero-hub'
												) }
												onClick={ () => remove( t ) }
											/>
										</div>
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</div>
			</Card>

			<div
				id="default-policy"
				className="hdh-section-title hdh-default-policy__title"
				tabIndex={ -1 }
			>
				<span className="hdh-default-policy__badge">
					{ __( 'Default', 'helpdesk-hero-hub' ) }
				</span>
				{ __( 'Default policy', 'helpdesk-hero-hub' ) }
			</div>
			<PolicyEditor
				policy={ draft }
				onChange={ setDraft }
				roles={ roles }
				sections={ sections }
				ratings={ ratings }
			/>
			<SaveBar
				dirty={ dirty }
				saving={ saving }
				save={ saveDefault }
				discard={ () => setDraft( policy.data.policy ) }
			/>

			{ editing && (
				<TemplateDrawer
					template={ editing }
					roles={ roles }
					sections={ sections }
					ratings={ ratings }
					onClose={ () => setEditing( null ) }
					onSaved={ () => {
						setEditing( null );
						templates.reload();
					} }
				/>
			) }
		</>
	);
}

function TemplateDrawer( {
	template,
	roles,
	sections,
	ratings,
	onClose,
	onSaved,
} ) {
	const [ tpl, setTpl ] = useState( template );
	const [ busy, setBusy ] = useState( false );
	const toast = useToast();
	const save = () => {
		setBusy( true );
		send( 'admin/templates', 'POST', tpl )
			.then( () => {
				toast( __( 'Template saved', 'helpdesk-hero-hub' ) );
				onSaved();
			} )
			.catch( ( e ) => toast( message( e ), 'alert' ) )
			.finally( () => setBusy( false ) );
	};
	return (
		<Drawer
			wide
			title={
				<h2 className="hdh-card__title">
					{ template.id
						? template.name
						: __( 'New template', 'helpdesk-hero-hub' ) }
				</h2>
			}
			onClose={ onClose }
			footer={
				<div className="hdh-row" style={ { width: '100%' } }>
					{ template.sites > 0 && (
						<span className="hdh-muted">
							{ sprintf(
								/* translators: %d: number of sites */
								_n(
									'Saving updates %d site.',
									'Saving updates %d sites.',
									template.sites,
									'helpdesk-hero-hub'
								),
								template.sites
							) }
						</span>
					) }
					<span className="hdh-spacer" />
					<Button
						variant="primary"
						disabled={ busy || ! tpl.name.trim() }
						onClick={ save }
					>
						{ __( 'Save template', 'helpdesk-hero-hub' ) }
					</Button>
				</div>
			}
		>
			<div className="hdh-stack" style={ { gap: 18 } }>
				<TextField
					label={ __( 'Name', 'helpdesk-hero-hub' ) }
					value={ tpl.name }
					onChange={ ( name ) => setTpl( { ...tpl, name } ) }
				/>
				<TextField
					label={ __(
						'Description (for your team)',
						'helpdesk-hero-hub'
					) }
					value={ tpl.description }
					onChange={ ( description ) =>
						setTpl( { ...tpl, description } )
					}
				/>
				<PolicyEditor
					policy={ tpl.policy }
					onChange={ ( p ) => setTpl( { ...tpl, policy: p } ) }
					roles={ roles }
					sections={ sections }
					ratings={ ratings }
				/>
			</div>
		</Drawer>
	);
}
