import { __, _n, sprintf } from '@wordpress/i18n';
import { applyFilters } from '@wordpress/hooks';
import { useState, useEffect } from '@wordpress/element';
import { useApi, useStored, send } from '../ui/lib/hooks';
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
	ErrorBoundary,
} from '../ui/components/ui';
import {
	KeyValues,
	TextField,
	TextArea,
	SelectField,
	SortControl,
	Pager,
	confirmAction,
} from '../ui/components/kit';
import PolicyEditor from '../components/PolicyEditor';
import NoticeCard from '../components/NoticeCard';
import { message, TierBadge } from '../components/common';

const boot = window.hdhBoot || {};

/**
 * Options for "which policy": the default and each template.
 *
 * @param {Array}   templates Templates.
 * @param {boolean} custom    Include "Custom rules for this site".
 * @return {Array} Options.
 */
export function policyOptions( templates, custom = false ) {
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
	// From the tier: the site has no policy of its own.
	return source.type === 'custom' ? 'custom' : 'default';
}

export function tierOptions( tiers ) {
	return [
		{ value: '', label: __( 'No tier', 'helpdesk-hero-hub' ) },
		...tiers.map( ( t ) => ( { value: t.id, label: t.name } ) ),
	];
}

const SITE_SORTS = [
	{ value: 'name', label: __( 'Name', 'helpdesk-hero-hub' ) },
	{ value: 'tier', label: __( 'Customer tier', 'helpdesk-hero-hub' ) },
	{ value: 'open', label: __( 'Open tickets', 'helpdesk-hero-hub' ) },
	{ value: 'seen', label: __( 'Last contact', 'helpdesk-hero-hub' ) },
	{ value: 'added', label: __( 'Date added', 'helpdesk-hero-hub' ) },
];

/**
 * Sites matching a search ("vip", a name, a URL, an email or words from the notes).
 *
 * @param {Array}  sites Sites.
 * @param {string} q     Search.
 * @return {Array} Matches.
 */
function searchSites( sites, q ) {
	const needle = q.trim().toLowerCase();
	if ( ! needle ) {
		return sites;
	}
	return sites.filter( ( s ) =>
		[
			s.name,
			s.label,
			s.url,
			s.contact_email,
			s.notes,
			s.tier ? s.tier.name : '',
			s.policy_source ? s.policy_source.name : '',
		]
			.join( ' ' )
			.toLowerCase()
			.includes( needle )
	);
}

function sortSites( sites, by, order ) {
	const dir = order === 'asc' ? 1 : -1;
	const key = {
		name: ( s ) => ( s.name || '' ).toLowerCase(),
		// Most important tier = rank 1; sites without a tier sort after every tier.
		tier: ( s ) => ( s.tier ? -s.tier.rank : -9999 ),
		open: ( s ) => s.open_tickets,
		seen: ( s ) => s.last_seen || '',
		added: ( s ) => s.created_at || '',
	}[ by ];
	if ( ! key ) {
		return sites;
	}
	return [ ...sites ].sort( ( a, b ) => {
		const x = key( a );
		const y = key( b );
		if ( x === y ) {
			return b.id - a.id;
		}
		return ( x > y ? 1 : -1 ) * dir;
	} );
}

export default function Sites( { go, param } ) {
	const { data, error, reload } = useApi( 'admin/sites' );
	const templates = useApi( 'admin/templates' );
	const tiersApi = useApi( 'admin/tiers' );
	const [ search, setSearch ] = useState( '' );
	const [ tierFilter, setTierFilter ] = useState( '' );
	const [ sort, setSort ] = useStored( 'sites-sort', {
		by: 'name',
		order: 'asc',
	} );
	const [ perPage, setPerPage ] = useStored( 'sites-per-page', 20 );
	const [ page, setPage ] = useState( 1 );
	// #/sites/<id> opens that site (quick switcher, links).
	const [ open, setOpen ] = useState( param ? parseInt( param, 10 ) || null : null );
	const [ editOnOpen, setEditOnOpen ] = useState( false );
	const [ selected, setSelected ] = useState( [] );
	const [ bulk, setBulk ] = useState( 'default' );
	const [ busy, setBusy ] = useState( false );
	const toast = useToast();

	if ( error && ! data ) {
		return <ErrorNotice error={ error } onRetry={ reload } />;
	}
	if ( ! data || ! templates.data || ! tiersApi.data ) {
		return <Loading />;
	}
	const tiers = tiersApi.data.tiers;
	const tpls = templates.data.templates;
	let matches = searchSites( data.sites, search );
	if ( tierFilter ) {
		matches = matches.filter( ( s ) =>
			tierFilter === 'none'
				? ! s.tier
				: s.tier && s.tier.id === tierFilter
		);
	}
	matches = sortSites( matches, sort.by, sort.order );
	const pages = Math.max( 1, Math.ceil( matches.length / perPage ) );
	const current = Math.min( page, pages );
	const sites = matches.slice( ( current - 1 ) * perPage, current * perPage );
	const allIds = matches.map( ( s ) => s.id );
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
					onClick={ () => go( 'connect' ) }
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

			<Card
				bodyClass={ null }
				title={ __( 'Connected sites', 'helpdesk-hero-hub' ) }
				action={
					data.sites.length > 0 && (
						<div className="hdh-toolbar">
							<input
								type="search"
								className="hdh-input"
								placeholder={ __(
									'Search name, site, email, tier or notes',
									'helpdesk-hero-hub'
								) }
								aria-label={ __(
									'Search sites',
									'helpdesk-hero-hub'
								) }
								value={ search }
								onChange={ ( e ) => {
									setSearch( e.target.value );
									setPage( 1 );
								} }
								style={ { minWidth: 260 } }
							/>
							{ tiers.length > 0 && (
								<select
									className="hdh-input hdh-select"
									aria-label={ __(
										'Customer tier',
										'helpdesk-hero-hub'
									) }
									value={ tierFilter }
									onChange={ ( e ) => {
										setTierFilter( e.target.value );
										setPage( 1 );
									} }
								>
									<option value="">
										{ __(
											'All tiers',
											'helpdesk-hero-hub'
										) }
									</option>
									{ tiers.map( ( t ) => (
										<option key={ t.id } value={ t.id }>
											{ t.name }
										</option>
									) ) }
									<option value="none">
										{ __( 'No tier', 'helpdesk-hero-hub' ) }
									</option>
								</select>
							) }
							<SortControl
								options={ SITE_SORTS }
								value={ sort.by }
								order={ sort.order }
								onChange={ ( by, order ) => {
									setSort( { by, order } );
									setPage( 1 );
								} }
							/>
						</div>
					)
				}
			>
				{ data.sites.length > 0 && ! matches.length ? (
					<Empty
						compact
						title={ __(
							'No sites match your search.',
							'helpdesk-hero-hub'
						) }
					/>
				) : ! sites.length ? (
					<Empty
						title={ __( 'No sites yet', 'helpdesk-hero-hub' ) }
						text={ __(
							'Create a connection link and send it to your customer. It walks them through installing the free Helpdesk Hero plugin and connecting.',
							'helpdesk-hero-hub'
						) }
					>
						<Button
							variant="primary"
							icon="plus"
							onClick={ () => go( 'connect' ) }
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
													allIds.length > 0 &&
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
									{ tiers.length > 0 && (
										<th scope="col">
											{ __( 'Tier', 'helpdesk-hero-hub' ) }
										</th>
									) }
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
									{ boot.canManage && (
										<th scope="col">
											<span className="hdh-sr">
												{ __(
													'Actions',
													'helpdesk-hero-hub'
												) }
											</span>
										</th>
									) }
								</tr>
							</thead>
							<tbody>
								{ sites.map( ( s ) => (
									<tr
										key={ s.id }
										className="hdh-row-link"
										onClick={ () => {
											setEditOnOpen( false );
											setOpen( s.id );
										} }
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
										{ tiers.length > 0 && (
											<td>
												{ s.tier ? (
													<TierBadge
														tier={ s.tier }
													/>
												) : (
													<span className="hdh-muted">
														—
													</span>
												) }
											</td>
										) }
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
										{ boot.canManage && (
											<td
												onClick={ ( e ) =>
													e.stopPropagation()
												}
											>
												<Button
													size="sm"
													variant="ghost"
													icon="edit"
													onClick={ () => {
														setEditOnOpen( true );
														setOpen( s.id );
													} }
												>
													{ __(
														'Edit',
														'helpdesk-hero-hub'
													) }
												</Button>
											</td>
										) }
									</tr>
								) ) }
							</tbody>
						</table>
						<Pager
							page={ current }
							perPage={ perPage }
							total={ matches.length }
							onPage={ setPage }
							onPer={ ( n ) => {
								setPerPage( n );
								setPage( 1 );
							} }
						/>
					</div>
				) }
			</Card>

			{ open && (
				<SiteDrawer
					id={ open }
					go={ go }
					templates={ tpls }
					tiers={ tiers }
					startEditing={ editOnOpen }
					onClose={ () => {
						setOpen( null );
						setEditOnOpen( false );
						reload();
						templates.reload();
					} }
				/>
			) }
		</>
	);
}

function SecurityText( { security } ) {
	if ( ! security ) {
		return '—';
	}
	switch ( security.type ) {
		case 'keypair':
			return (
				<>
					{ __(
						'Key pairs (Ed25519). Each site signs with its own key.',
						'helpdesk-hero-hub'
					) }
					<div className="hdh-muted">
						{ sprintf(
							/* translators: 1: hub key ID, 2: site key ID */
							__(
								'Hub key %1$s · site key %2$s',
								'helpdesk-hero-hub'
							),
							security.key_id,
							security.site_key_id
						) }
					</div>
				</>
			);
		case 'upgrading':
			return (
				<>
					{ __(
						'Switching to new keys. Completes on its own when the site next syncs (within about 10 minutes); the connection keeps working meanwhile.',
						'helpdesk-hero-hub'
					) }
					<div className="hdh-muted">
						{ sprintf(
							/* translators: 1: hub key ID, 2: site key ID */
							__(
								'New hub key %1$s · new site key %2$s',
								'helpdesk-hero-hub'
							),
							security.key_id,
							security.site_key_id
						) }
					</div>
				</>
			);
		case 'shared':
			return __(
				'Shared key (connected before version 2.1). Upgrades to key pairs on its own once the site runs Helpdesk Hero 2.1 or later.',
				'helpdesk-hero-hub'
			);
		case 'unreadable':
			return __(
				'The keys can’t be read (the security keys in wp-config.php changed). Send the site a new connection code.',
				'helpdesk-hero-hub'
			);
	}
	return '—';
}

function DetailsForm( { site, tiers, busy, onSave, onCancel } ) {
	const [ form, setForm ] = useState( {
		label: site.label,
		contact_email: site.contact_email,
		tier: site.tier ? site.tier.id : '',
		notes: site.notes || '',
	} );
	const set = ( patch ) => setForm( { ...form, ...patch } );
	return (
		<div className="hdh-card hdh-stack" style={ { padding: 16 } }>
			<TextField
				label={ __( 'Customer or site name', 'helpdesk-hero-hub' ) }
				value={ form.label }
				onChange={ ( v ) => set( { label: v } ) }
			/>
			<TextField
				label={ __( 'Customer email', 'helpdesk-hero-hub' ) }
				type="email"
				value={ form.contact_email }
				onChange={ ( v ) => set( { contact_email: v } ) }
			/>
			{ tiers.length > 0 ? (
				<SelectField
					label={ __( 'Customer tier', 'helpdesk-hero-hub' ) }
					value={ form.tier }
					onChange={ ( v ) => set( { tier: v } ) }
					options={ tierOptions( tiers ) }
					help={
						boot.sla
							? __(
									'The tier sets this site’s service level agreement.',
									'helpdesk-hero-hub'
							  )
							: undefined
					}
				/>
			) : (
				<p className="hdh-muted">
					{ __(
						'Want to mark important customers, such as “VIP”? Create customer tiers under Settings.',
						'helpdesk-hero-hub'
					) }{ ' ' }
					<a href="#/settings">
						{ __( 'Settings', 'helpdesk-hero-hub' ) }
					</a>
				</p>
			) }
			<TextArea
				label={ __( 'Notes', 'helpdesk-hero-hub' ) }
				value={ form.notes }
				onChange={ ( v ) => set( { notes: v } ) }
				rows={ 4 }
				help={ __(
					'For your team only. Customers never see them.',
					'helpdesk-hero-hub'
				) }
			/>
			<div className="hdh-row">
				<Button
					variant="primary"
					disabled={ busy || ! form.label.trim() }
					onClick={ () => onSave( form ) }
				>
					{ __( 'Save details', 'helpdesk-hero-hub' ) }
				</Button>
				<Button variant="ghost" onClick={ onCancel }>
					{ __( 'Cancel', 'helpdesk-hero-hub' ) }
				</Button>
			</div>
		</div>
	);
}

function SiteDrawer( {
	id,
	go,
	templates,
	tiers,
	startEditing = false,
	onClose,
} ) {
	const { data, error, reload } = useApi( `admin/sites/${ id }` );
	const policyApi = useApi( 'admin/policy' );
	const [ choice, setChoice ] = useState( null );
	const [ draft, setDraft ] = useState( null );
	const [ busy, setBusy ] = useState( '' );
	const [ editing, setEditing ] = useState( startEditing );
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
												'Cancel this invitation? The connection link and code stop working.',
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
					{ editing ? (
						<DetailsForm
							site={ data }
							tiers={ tiers }
							busy={ busy === 'details' }
							onCancel={ () => setEditing( false ) }
							onSave={ ( form ) =>
								run(
									'details',
									send( `admin/sites/${ id }`, 'POST', form ),
									() => {
										toast(
											__(
												'Details saved',
												'helpdesk-hero-hub'
											)
										);
										setEditing( false );
									}
								)
							}
						/>
					) : (
					<KeyValues
						rows={ [
							[
								__( 'Status', 'helpdesk-hero-hub' ),
								data.status === 'active'
									? __( 'Connected', 'helpdesk-hero-hub' )
									: sprintf(
											/* translators: %s: relative time */
											__(
												'Waiting for the site · link expires %s',
												'helpdesk-hero-hub'
											),
											ago( data.invite_expires )
									  ),
							],
							[
								__( 'Customer', 'helpdesk-hero-hub' ),
								data.label,
							],
							[
								__( 'Tier', 'helpdesk-hero-hub' ),
								data.tier ? (
									<TierBadge tier={ data.tier } />
								) : (
									<span className="hdh-muted">—</span>
								),
							],
							data.notes
								? [
										__( 'Notes', 'helpdesk-hero-hub' ),
										<span
											key="notes"
											style={ { whiteSpace: 'pre-wrap' } }
										>
											{ data.notes }
										</span>,
								  ]
								: null,
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
							data.status === 'active'
								? [
										__( 'Security', 'helpdesk-hero-hub' ),
										<SecurityText
											key="security"
											security={ data.security }
										/>,
								  ]
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
					) }
					{ ! editing && boot.canManage && (
						<div className="hdh-row">
							<Button
								size="sm"
								icon="edit"
								onClick={ () => setEditing( true ) }
							>
								{ __( 'Edit details', 'helpdesk-hero-hub' ) }
							</Button>
						</div>
					) }

					{ data.status !== 'active' && (
						<div className="hdh-row">
							<Button
								size="sm"
								variant="primary"
								icon="link"
								onClick={ () => go( `connect/${ id }` ) }
							>
								{ __( 'Continue connecting', 'helpdesk-hero-hub' ) }
							</Button>
						</div>
					) }

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
							{ boot.canManage && (
								<Button
									size="sm"
									icon="key"
									disabled={ busy === 'keys' }
									onClick={ async () => {
										const yes = await confirmAction(
											__(
												'Replace this site’s connection keys? The hub and the site create new key pairs and swap them over the existing secure connection, so there’s nothing to copy or paste, and nothing for the customer to do. The old keys keep working until the switch completes, so nothing is interrupted. Do this if you think a key may have leaked (for example a lost database backup).',
												'helpdesk-hero-hub'
											),
											{
												confirmText: __(
													'Rotate keys',
													'helpdesk-hero-hub'
												),
											}
										);
										if ( yes ) {
											run(
												'keys',
												send(
													`admin/sites/${ id }/keys`,
													'POST'
												),
												( r ) =>
													toast(
														sprintf(
															/* translators: 1: hub key ID, 2: site key ID */
															__(
																'New keys created (hub %1$s, site %2$s). Nothing else to do: the switch completes on the site’s next sync.',
																'helpdesk-hero-hub'
															),
															r.security.key_id,
															r.security.site_key_id
														)
													)
											);
										}
									} }
								>
									{ __( 'Rotate keys', 'helpdesk-hero-hub' ) }
								</Button>
							) }
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

					{ applyFilters( 'helpdeskHeroHub.sitePanels', [] ).map(
						( Panel, i ) => (
							<ErrorBoundary key={ i }>
								<Panel site={ data } reload={ reload } />
							</ErrorBoundary>
						)
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
