import { __, _n, sprintf } from '@wordpress/i18n';
import { applyFilters } from '@wordpress/hooks';
import { useState, useEffect } from '@wordpress/element';
import { useApi, send } from '../ui/lib/hooks';
import { ago, when } from '../ui/lib/time';
import {
	Card,
	PageHead,
	Button,
	Drawer,
	Empty,
	ErrorNotice,
	Loading,
	Pill,
	useToast,
} from '../ui/components/ui';
import {
	CopyBox,
	KeyValues,
	TextField,
	SelectField,
	confirmAction,
} from '../ui/components/kit';
import PolicyEditor from '../components/PolicyEditor';
import NoticeCard from '../components/NoticeCard';
import { message } from '../components/common';

const boot = window.hdhBoot || {};

/**
 * Options for "which policy": the default and each template.
 *
 * @param {Array}   templates Templates.
 * @param {boolean} custom    Include "Custom rules for this site".
 * @return {Array} Options.
 */
function policyOptions( templates, custom = false ) {
	const opts = [
		{
			value: 'default',
			label: __( 'Default policy', 'helpdesk-hero-hub' ),
		},
		...templates.map( ( t ) => ( {
			value: `template:${ t.id }`,
			/* translators: %s: template name */
			label: sprintf( __( 'Template: %s', 'helpdesk-hero-hub' ), t.name ),
		} ) ),
	];
	if ( custom ) {
		opts.push( {
			value: 'custom',
			label: __( 'Custom rules for this site', 'helpdesk-hero-hub' ),
		} );
	}
	return opts;
}

function sourceValue( source ) {
	if ( source.type === 'template' ) {
		return `template:${ source.template }`;
	}
	return source.type === 'custom' ? 'custom' : 'default';
}

export default function Sites( { go } ) {
	const { data, error, reload } = useApi( 'admin/sites' );
	const templates = useApi( 'admin/templates' );
	const [ inviting, setInviting ] = useState( false );
	const [ open, setOpen ] = useState( null );
	const [ selected, setSelected ] = useState( [] );
	const [ bulk, setBulk ] = useState( 'default' );
	const [ busy, setBusy ] = useState( false );
	const toast = useToast();

	if ( error && ! data ) {
		return <ErrorNotice error={ error } onRetry={ reload } />;
	}
	if ( ! data || ! templates.data ) {
		return <Loading />;
	}
	const sites = data.sites;
	const tpls = templates.data.templates;
	const allIds = sites.map( ( s ) => s.id );
	const toggle = ( id, on ) =>
		setSelected(
			on ? [ ...selected, id ] : selected.filter( ( x ) => x !== id )
		);

	const applyBulk = () => {
		setBusy( true );
		send( 'admin/sites/policy', 'POST', { ids: selected, policy: bulk } )
			.then( ( r ) => {
				toast(
					sprintf(
						/* translators: %d: number of sites */
						_n(
							'Policy applied to %d site',
							'Policy applied to %d sites',
							r.updated,
							'helpdesk-hero-hub'
						),
						r.updated
					)
				);
				setSelected( [] );
				reload();
				templates.reload();
			} )
			.catch( ( e ) => toast( message( e ), 'alert' ) )
			.finally( () => setBusy( false ) );
	};

	return (
		<>
			<PageHead
				title={ __( 'Sites', 'helpdesk-hero-hub' ) }
				lede={ __(
					'Your customers’ WordPress sites. Each has its own signing key; disconnecting a site stops it at once.',
					'helpdesk-hero-hub'
				) }
			>
				<Button
					variant="primary"
					icon="plus"
					onClick={ () => setInviting( true ) }
				>
					{ __( 'Connect a site', 'helpdesk-hero-hub' ) }
				</Button>
			</PageHead>

			{ applyFilters( 'helpdeskHeroHub.sitesPanels', [] ).map(
				( Panel, i ) => (
					<Panel key={ i } sites={ sites } reload={ reload } />
				)
			) }

			{ selected.length > 0 && boot.canManage && (
				<div
					className="hdh-banner"
					role="region"
					aria-label={ __( 'Bulk actions', 'helpdesk-hero-hub' ) }
				>
					<div className="hdh-banner__text">
						<strong>
							{ sprintf(
								/* translators: %d: number of sites */
								_n(
									'%d site selected',
									'%d sites selected',
									selected.length,
									'helpdesk-hero-hub'
								),
								selected.length
							) }
						</strong>
					</div>
					<select
						className="hdh-input hdh-select"
						aria-label={ __(
							'Policy to apply',
							'helpdesk-hero-hub'
						) }
						value={ bulk }
						onChange={ ( e ) => setBulk( e.target.value ) }
					>
						{ policyOptions( tpls ).map( ( o ) => (
							<option key={ o.value } value={ o.value }>
								{ o.label }
							</option>
						) ) }
					</select>
					<Button
						size="sm"
						variant="primary"
						disabled={ busy }
						onClick={ applyBulk }
					>
						{ __( 'Apply policy', 'helpdesk-hero-hub' ) }
					</Button>
					<Button
						size="sm"
						variant="ghost"
						onClick={ () => setSelected( [] ) }
					>
						{ __( 'Clear', 'helpdesk-hero-hub' ) }
					</Button>
				</div>
			) }

			<Card bodyClass={ null }>
				{ ! sites.length ? (
					<Empty
						title={ __( 'No sites yet', 'helpdesk-hero-hub' ) }
						text={ __(
							'Create a connection code and send it to your customer. They paste it in Get Help after installing the free Helpdesk Hero plugin.',
							'helpdesk-hero-hub'
						) }
					>
						<Button
							variant="primary"
							icon="plus"
							onClick={ () => setInviting( true ) }
						>
							{ __( 'Connect a site', 'helpdesk-hero-hub' ) }
						</Button>
					</Empty>
				) : (
					<div className="hdh-table-wrap">
						<table className="hdh-table">
							<thead>
								<tr>
									{ boot.canManage && (
										<th scope="col" style={ { width: 1 } }>
											<input
												type="checkbox"
												className="hdh-select-row"
												aria-label={ __(
													'Select all sites',
													'helpdesk-hero-hub'
												) }
												checked={
													selected.length ===
													allIds.length
												}
												onChange={ ( e ) =>
													setSelected(
														e.target.checked
															? allIds
															: []
													)
												}
											/>
										</th>
									) }
									<th scope="col">
										{ __( 'Site', 'helpdesk-hero-hub' ) }
									</th>
									<th scope="col">
										{ __( 'Status', 'helpdesk-hero-hub' ) }
									</th>
									<th scope="col">
										{ __( 'Policy', 'helpdesk-hero-hub' ) }
									</th>
									<th scope="col">
										{ __(
											'Open tickets',
											'helpdesk-hero-hub'
										) }
									</th>
									<th scope="col">
										{ __(
											'Versions',
											'helpdesk-hero-hub'
										) }
									</th>
									<th scope="col">
										{ __(
											'Last contact',
											'helpdesk-hero-hub'
										) }
									</th>
								</tr>
							</thead>
							<tbody>
								{ sites.map( ( s ) => (
									<tr
										key={ s.id }
										className="hdh-row-link"
										onClick={ () => setOpen( s.id ) }
									>
										{ boot.canManage && (
											<td
												onClick={ ( e ) =>
													e.stopPropagation()
												}
											>
												<input
													type="checkbox"
													className="hdh-select-row"
													aria-label={ sprintf(
														/* translators: %s: site name */
														__(
															'Select %s',
															'helpdesk-hero-hub'
														),
														s.name
													) }
													checked={ selected.includes(
														s.id
													) }
													onChange={ ( e ) =>
														toggle(
															s.id,
															e.target.checked
														)
													}
												/>
											</td>
										) }
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
												onClick={ ( e ) => {
													e.stopPropagation();
													setOpen( s.id );
												} }
											>
												{ s.name }
											</button>
											<div className="hdh-muted">
												{ s.url ||
													s.contact_email ||
													s.label }
											</div>
										</td>
										<td>
											{ s.status === 'active' ? (
												<Pill tone="good" dot>
													{ __(
														'Connected',
														'helpdesk-hero-hub'
													) }
												</Pill>
											) : (
												<Pill tone="warning" dot>
													{ __(
														'Waiting for the site',
														'helpdesk-hero-hub'
													) }
												</Pill>
											) }
										</td>
										<td>
											{ s.policy_source.type ===
											'default' ? (
												<span className="hdh-muted">
													{ s.policy_source.name }
												</span>
											) : (
												<Pill tone="accent">
													{ s.policy_source.name }
												</Pill>
											) }
										</td>
										<td>
											{ s.open_tickets ? (
												<a
													href={ `#/inbox?site=${ s.id }` }
													onClick={ ( e ) =>
														e.stopPropagation()
													}
												>
													{ s.open_tickets }
												</a>
											) : (
												<span className="hdh-muted">
													0
												</span>
											) }
										</td>
										<td>
											{ s.wordpress
												? `WordPress ${ s.wordpress }`
												: '—' }
											{ s.version && (
												<div className="hdh-muted">
													Helpdesk Hero { s.version }
												</div>
											) }
										</td>
										<td title={ when( s.last_seen ) }>
											{ s.last_seen
												? ago( s.last_seen )
												: '—' }
										</td>
									</tr>
								) ) }
							</tbody>
						</table>
					</div>
				) }
			</Card>

			{ inviting && (
				<InviteDrawer
					templates={ tpls }
					onClose={ () => {
						setInviting( false );
						reload();
					} }
				/>
			) }
			{ open && (
				<SiteDrawer
					id={ open }
					go={ go }
					templates={ tpls }
					onClose={ () => {
						setOpen( null );
						reload();
						templates.reload();
					} }
				/>
			) }
		</>
	);
}

function InviteDrawer( { templates, onClose } ) {
	const [ label, setLabel ] = useState( '' );
	const [ email, setEmail ] = useState( '' );
	const [ policy, setPolicy ] = useState( 'default' );
	const [ code, setCode ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const toast = useToast();
	const create = () => {
		setBusy( true );
		send( 'admin/sites', 'POST', { label, email, policy } )
			.then( ( r ) => setCode( r.code ) )
			.catch( ( e ) => toast( message( e ), 'alert' ) )
			.finally( () => setBusy( false ) );
	};
	return (
		<Drawer
			title={
				<h2 className="hdh-card__title">
					{ __( 'Connect a site', 'helpdesk-hero-hub' ) }
				</h2>
			}
			onClose={ onClose }
		>
			{ ! code ? (
				<div className="hdh-stack">
					<TextField
						label={ __(
							'Customer or site name',
							'helpdesk-hero-hub'
						) }
						value={ label }
						onChange={ setLabel }
					/>
					<TextField
						label={ __(
							'Customer email (optional)',
							'helpdesk-hero-hub'
						) }
						type="email"
						value={ email }
						onChange={ setEmail }
					/>
					{ boot.canManage && (
						<SelectField
							label={ __( 'Policy', 'helpdesk-hero-hub' ) }
							value={ policy }
							onChange={ setPolicy }
							options={ policyOptions( templates ) }
							help={ __(
								'What the site starts with. You can change it any time.',
								'helpdesk-hero-hub'
							) }
						/>
					) }
					<div className="hdh-row">
						<Button
							variant="primary"
							icon="key"
							disabled={ busy || ! label.trim() }
							onClick={ create }
						>
							{ __(
								'Create connection code',
								'helpdesk-hero-hub'
							) }
						</Button>
					</div>
				</div>
			) : (
				<div className="hdh-stack">
					<p>
						{ __(
							'Send this code to your customer. It works once and expires in 7 days.',
							'helpdesk-hero-hub'
						) }
					</p>
					<CopyBox
						value={ code }
						label={ __( 'Copy code', 'helpdesk-hero-hub' ) }
						rows={ 4 }
					/>
					<div className="hdh-card" style={ { padding: 16 } }>
						<div className="hdh-section-title">
							{ __(
								'What your customer does',
								'helpdesk-hero-hub'
							) }
						</div>
						<ol style={ { margin: '0 0 0 18px' } }>
							<li>
								{ __(
									'Installs the free Helpdesk Hero plugin:',
									'helpdesk-hero-hub'
								) }{ ' ' }
								<a
									href={ boot.customerPluginUrl }
									target="_blank"
									rel="noopener noreferrer"
								>
									{ boot.customerPluginUrl }
								</a>
							</li>
							<li>
								{ __(
									'Opens Get Help in their dashboard and pastes the code.',
									'helpdesk-hero-hub'
								) }
							</li>
							<li>
								{ __(
									'Done: your policy applies to their site straight away.',
									'helpdesk-hero-hub'
								) }
							</li>
						</ol>
					</div>
				</div>
			) }
		</Drawer>
	);
}

function SiteDrawer( { id, go, templates, onClose } ) {
	const { data, error, reload } = useApi( `admin/sites/${ id }` );
	const policyApi = useApi( 'admin/policy' );
	const [ choice, setChoice ] = useState( null );
	const [ draft, setDraft ] = useState( null );
	const [ busy, setBusy ] = useState( '' );
	const toast = useToast();

	useEffect( () => {
		if ( data ) {
			setChoice( sourceValue( data.policy_source ) );
			setDraft( data.policy );
		}
	}, [ data ] );

	const run = ( key, promise, done ) => {
		setBusy( key );
		return promise
			.then( ( r ) => {
				if ( done ) {
					done( r );
				}
				reload();
			} )
			.catch( ( e ) => toast( message( e ), 'alert' ) )
			.finally( () => setBusy( '' ) );
	};

	const savePolicy = () =>
		run(
			'policy',
			send( `admin/sites/${ id }`, 'POST', {
				policy: choice === 'custom' ? draft : choice,
			} ),
			() =>
				toast(
					__(
						'Policy saved and sent to the site',
						'helpdesk-hero-hub'
					)
				)
		);

	const changed =
		data &&
		choice !== null &&
		( choice !== sourceValue( data.policy_source ) ||
			( choice === 'custom' &&
				JSON.stringify( draft ) !== JSON.stringify( data.policy ) ) );

	return (
		<Drawer
			wide
			title={
				<h2 className="hdh-card__title">
					{ data ? data.name : __( 'Site', 'helpdesk-hero-hub' ) }
				</h2>
			}
			sub={ data && <p className="hdh-card__sub">{ data.url }</p> }
			onClose={ onClose }
			footer={
				data && boot.canManage ? (
					<div className="hdh-row" style={ { width: '100%' } }>
						<Button
							variant="ghost"
							icon="trash"
							onClick={ () => {
								confirmAction(
									data.status === 'active'
										? __(
												'Disconnect this site? It can no longer send tickets, and your team loses access to it.',
												'helpdesk-hero-hub'
										  )
										: __(
												'Cancel this invitation? The connection code stops working.',
												'helpdesk-hero-hub'
										  )
								).then( ( yes ) => {
									if ( yes ) {
										run(
											'revoke',
											send(
												`admin/sites/${ id }`,
												'DELETE'
											),
											onClose
										);
									}
								} );
							} }
						>
							{ data.status === 'active'
								? __( 'Disconnect', 'helpdesk-hero-hub' )
								: __(
										'Cancel invitation',
										'helpdesk-hero-hub'
								  ) }
						</Button>
						<span className="hdh-spacer" />
						<Button
							variant="primary"
							onClick={ savePolicy }
							disabled={ busy === 'policy' || ! changed }
						>
							{ __( 'Save policy', 'helpdesk-hero-hub' ) }
						</Button>
					</div>
				) : null
			}
		>
			{ error && <ErrorNotice error={ error } onRetry={ reload } /> }
			{ ! data ? (
				<Loading />
			) : (
				<div className="hdh-stack" style={ { gap: 18 } }>
					<KeyValues
						rows={ [
							[
								__( 'Status', 'helpdesk-hero-hub' ),
								data.status === 'active'
									? __( 'Connected', 'helpdesk-hero-hub' )
									: sprintf(
											/* translators: %s: relative time */
											__(
												'Waiting for the site · code expires %s',
												'helpdesk-hero-hub'
											),
											ago( data.invite_expires )
									  ),
							],
							[
								__( 'Customer', 'helpdesk-hero-hub' ),
								data.label,
							],
							data.contact_email
								? [
										__( 'Email', 'helpdesk-hero-hub' ),
										data.contact_email,
								  ]
								: null,
							data.wordpress
								? [ 'WordPress', data.wordpress ]
								: null,
							data.version
								? [ 'Helpdesk Hero', data.version ]
								: null,
							[
								__( 'Last contact', 'helpdesk-hero-hub' ),
								data.last_seen ? when( data.last_seen ) : '—',
							],
							[
								__( 'Added', 'helpdesk-hero-hub' ),
								when( data.created_at ),
							],
						] }
					/>
					{ data.status === 'active' && (
						<div className="hdh-row">
							<Button
								size="sm"
								icon="refresh"
								disabled={ busy === 'ping' }
								onClick={ () =>
									run(
										'ping',
										send(
											`admin/sites/${ id }/ping`,
											'POST'
										),
										() =>
											toast(
												__(
													'The site is reachable',
													'helpdesk-hero-hub'
												)
											)
									)
								}
							>
								{ __(
									'Check connection',
									'helpdesk-hero-hub'
								) }
							</Button>
							<Button
								size="sm"
								icon="inbox"
								onClick={ () => go( `inbox?site=${ id }` ) }
							>
								{ __( 'Tickets', 'helpdesk-hero-hub' ) }
							</Button>
						</div>
					) }

					{ boot.canManage && policyApi.data && choice !== null && (
						<div className="hdh-stack">
							<SelectField
								label={ __( 'Policy', 'helpdesk-hero-hub' ) }
								value={ choice }
								onChange={ ( v ) => {
									setChoice( v );
									if (
										v === 'custom' &&
										data.policy_source.type !== 'custom'
									) {
										setDraft( data.policy );
									}
								} }
								options={ policyOptions( templates, true ) }
								help={ __(
									'Templates keep the site in step with the template; custom rules apply to this site only.',
									'helpdesk-hero-hub'
								) }
							/>
							{ choice === 'custom' && (
								<PolicyEditor
									policy={ draft }
									onChange={ setDraft }
									roles={ policyApi.data.roles }
									sections={ policyApi.data.sections }
									ratings={ policyApi.data.ratings }
								/>
							) }
						</div>
					) }

					{ data.status === 'active' && (
						<NoticeCard
							busy={ busy === 'notice' }
							onSend={ ( n, reset ) =>
								run(
									'notice',
									send(
										`admin/sites/${ id }/notice`,
										'POST',
										n
									),
									() => {
										toast(
											__(
												'Message sent',
												'helpdesk-hero-hub'
											)
										);
										reset();
									}
								)
							}
						/>
					) }
				</div>
			) }
		</Drawer>
	);
}
