import { __, _n, sprintf } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import { addQueryArgs } from '@wordpress/url';
import { applyFilters } from '@wordpress/hooks';
import MyWork from '../components/MyWork';
import { useApi, useStored, send } from '../ui/lib/hooks';
import { ago, when } from '../ui/lib/time';
import {
	Card,
	PageHead,
	Empty,
	ErrorNotice,
	Loading,
	Pill,
	Button,
	useToast,
} from '../ui/components/ui';
import {
	TicketStatus,
	AccessState,
	TagPills,
	Stars,
	SortControl,
	Pager,
	confirmAction,
	BillingBadge,
} from '../ui/components/kit';
import Icon from '../ui/components/Icon';
import {
	STATUS_LABELS,
	PRIORITY_LABELS,
	TierBadge,
	SlaBadge,
	message,
} from '../components/common';
import {
	filterDefs,
	FilterButton,
	FilterChips,
	StatusTabs,
} from '../components/InboxFilters';

const boot = window.hdhBoot || {};

function sortOptions() {
	const opts = [
		{ value: 'updated', label: __( 'Last updated', 'helpdesk-hero-hub' ) },
		{ value: 'created', label: __( 'Date opened', 'helpdesk-hero-hub' ) },
		{ value: 'priority', label: __( 'Priority', 'helpdesk-hero-hub' ) },
		{ value: 'status', label: __( 'Status', 'helpdesk-hero-hub' ) },
		{ value: 'health', label: __( 'Health', 'helpdesk-hero-hub' ) },
		{ value: 'site', label: __( 'Site', 'helpdesk-hero-hub' ) },
		{ value: 'customer', label: __( 'Customer', 'helpdesk-hero-hub' ) },
	];
	if ( boot.hasTiers ) {
		opts.push( {
			value: 'tier',
			label: __( 'Customer tier', 'helpdesk-hero-hub' ),
		} );
	}
	if ( boot.sla ) {
		opts.push( {
			value: 'due',
			label: __( 'SLA due', 'helpdesk-hero-hub' ),
		} );
	}
	return opts;
}

export default function Inbox( { go } ) {
	const hashArgs = new URLSearchParams(
		window.location.hash.split( '?' )[ 1 ] || ''
	);
	// Filters can come from the address (#/inbox?site=3&access=1), e.g. from the counters.
	const [ filters, setFilters ] = useState( () => {
		const f = { status: hashArgs.get( 'status' ) || 'active' };
		[ 'site', 'access', 'unread', 'assignee', 'sla' ].forEach( ( k ) => {
			if ( hashArgs.get( k ) ) {
				f[ k ] = hashArgs.get( k );
			}
		} );
		return f;
	} );
	const [ search, setSearch ] = useState( '' );
	const [ sort, setSort ] = useStored( 'inbox-sort', {
		by: 'updated',
		order: 'desc',
	} );
	const [ perPage, setPerPage ] = useStored( 'inbox-per-page', 20 );
	const [ page, setPage ] = useState( 1 );
	const [ selected, setSelected ] = useState( [] );
	const team = useApi( 'admin/team' );
	const tags = useApi( 'admin/tags' );
	const tiers = useApi( 'admin/tiers' );
	const toast = useToast();
	const defs = filterDefs( {
		team: team.data ? team.data.team : [],
		tags: tags.data ? tags.data.tags : [],
		tiers: tiers.data ? tiers.data.tiers : [],
	} );
	const setFilter = ( key, value ) =>
		setFilters( ( f ) => {
			const next = { ...f, [ key ]: value };
			if ( value === '' || value === undefined ) {
				delete next[ key ];
			}
			return next;
		} );

	useEffect( () => {
		const t = window.setTimeout(
			() =>
				setFilters( ( f ) => {
					if ( ( f.search || '' ) === search ) {
						return f;
					}
					const next = { ...f, search };
					if ( ! search ) {
						delete next.search;
					}
					return next;
				} ),
			300
		);
		return () => window.clearTimeout( t );
	}, [ search ] );

	// A new filter or sort starts from the first page.
	const filterKey = JSON.stringify( filters );
	useEffect( () => {
		setPage( 1 );
		setSelected( [] );
	}, [ filterKey, sort.by, sort.order, perPage ] );

	const { data, error, loading, reload } = useApi(
		addQueryArgs( 'admin/tickets', {
			...filters,
			orderby: sort.by,
			order: sort.order,
			page,
			per_page: perPage,
		} )
	);

	if ( error && ! data ) {
		return <ErrorNotice error={ error } onRetry={ reload } />;
	}
	if ( ! data ) {
		return <Loading />;
	}
	const { counts, tickets } = data;
	// The filters as the delete and export endpoints take them.
	const narrowed = Object.keys( filters ).some(
		( k ) => k !== 'status' && filters[ k ] !== ''
	);
	const exportUrl = ( params ) => {
		const qs = new URLSearchParams( { _wpnonce: boot.restNonce, ...params } );
		return `${ boot.restUrl }admin/tickets/export?${ qs.toString() }`;
	};
	const filterParams = Object.fromEntries(
		Object.entries( filters )
			.filter( ( [ , v ] ) => v !== '' && v !== undefined )
			.map( ( [ k, v ] ) => [ `filters[${ k }]`, v ] )
	);
	const deleteMatching = async () => {
		const { count } = await send( 'admin/tickets/delete', 'POST', {
			filters,
			dry_run: true,
		} );
		if ( ! count ) {
			toast( __( 'Nothing matches these filters.', 'helpdesk-hero-hub' ), 'alert' );
			return;
		}
		const yes = await confirmAction(
			sprintf(
				/* translators: %d: number of tickets */
				_n(
					'Delete %d ticket matching these filters, with its conversation, history and files? Download a CSV export first if you might need it. This can’t be undone.',
					'Delete %d tickets matching these filters, with their conversations, history and files? Download a CSV export first if you might need them. This can’t be undone.',
					count,
					'helpdesk-hero-hub'
				),
				count
			),
			{ confirmText: __( 'Delete', 'helpdesk-hero-hub' ) }
		);
		if ( ! yes ) {
			return;
		}
		let total = 0;
		let remaining = count;
		try {
			while ( remaining > 0 ) {
				const r = await send( 'admin/tickets/delete', 'POST', { filters } );
				total += r.deleted;
				remaining = r.deleted ? r.remaining : 0;
			}
			toast(
				sprintf(
					/* translators: %d: number of tickets */
					_n( '%d ticket deleted', '%d tickets deleted', total, 'helpdesk-hero-hub' ),
					total
				)
			);
		} catch ( e ) {
			toast( message( e ), 'alert' );
		}
		reload();
	};
	const deleteSelected = async () => {
		const yes = await confirmAction(
			sprintf(
				/* translators: %d: number of tickets */
				_n(
					'Delete %d ticket with its conversation, history and files? This can’t be undone.',
					'Delete %d tickets with their conversations, history and files? This can’t be undone.',
					selected.length,
					'helpdesk-hero-hub'
				),
				selected.length
			),
			{ confirmText: __( 'Delete', 'helpdesk-hero-hub' ) }
		);
		if ( ! yes ) {
			return;
		}
		send( 'admin/tickets/delete', 'POST', { ids: selected } )
			.then( ( r ) => {
				toast(
					sprintf(
						/* translators: %d: number of tickets */
						_n( '%d ticket deleted', '%d tickets deleted', r.deleted, 'helpdesk-hero-hub' ),
						r.deleted
					)
				);
				setSelected( [] );
				reload();
			} )
			.catch( ( e ) => toast( message( e ), 'alert' ) );
	};
	const pageIds = tickets.map( ( t ) => t.id );
	const allOnPage =
		pageIds.length > 0 && pageIds.every( ( id ) => selected.includes( id ) );
	const toggle = ( id, on ) =>
		setSelected(
			on ? [ ...selected, id ] : selected.filter( ( x ) => x !== id )
		);
	const runBulk = async ( action, value, label ) => {
		const yes = await confirmAction(
			sprintf(
				/* translators: 1: action, 2: number of tickets */
				_n(
					'%1$s for %2$d ticket?',
					'%1$s for %2$d tickets?',
					selected.length,
					'helpdesk-hero-hub'
				),
				label,
				selected.length
			),
			{ confirmText: __( 'Apply', 'helpdesk-hero-hub' ) }
		);
		if ( ! yes ) {
			return;
		}
		send( 'admin/tickets/bulk', 'POST', { ids: selected, action, value } )
			.then( ( r ) => {
				toast(
					sprintf(
						/* translators: %d: number of tickets */
						_n(
							'%d ticket updated',
							'%d tickets updated',
							r.updated,
							'helpdesk-hero-hub'
						),
						r.updated
					)
				);
				setSelected( [] );
				reload();
			} )
			.catch( ( e ) => toast( message( e ), 'alert' ) );
	};

	return (
		<>
			<PageHead
				title={ __( 'Inbox', 'helpdesk-hero-hub' ) }
				lede={
					boot.helpdesk
						? sprintf(
								/* translators: %s: help desk name */
								__(
									'Tickets from your customers’ sites. Conversations sync with %s.',
									'helpdesk-hero-hub'
								),
								boot.helpdesk
						  )
						: __(
								'Tickets from every connected customer site. New tickets and replies are also emailed to your team.',
								'helpdesk-hero-hub'
						  )
				}
			/>

			<MyWork
				counts={ data ? { ...data.mywork, access: counts.access } : null }
				filters={ filters }
				onApply={ setFilters }
			/>

			{ ! counts.sites && ! tickets.length ? (
				<Card>
					<Empty
						title={ __( 'Set up your hub', 'helpdesk-hero-hub' ) }
						text={ __(
							'Three steps and your customers can send you tickets from their dashboards.',
							'helpdesk-hero-hub'
						) }
					>
						<div className="hdh-steps">
							<a className="hdh-step" href="#/settings">
								<span className="hdh-step__n">1</span>
								<strong className="hdh-step__title">
									{ __(
										'Check your team email',
										'helpdesk-hero-hub'
									) }
								</strong>
								<span className="hdh-step__text">
									{ __(
										'New tickets and replies are emailed there.',
										'helpdesk-hero-hub'
									) }
								</span>
							</a>
							<a className="hdh-step" href="#/policies">
								<span className="hdh-step__n">2</span>
								<strong className="hdh-step__title">
									{ __(
										'Set your support policy',
										'helpdesk-hero-hub'
									) }
								</strong>
								<span className="hdh-step__text">
									{ __(
										'Decide what access you get, for how long, and what customers can change.',
										'helpdesk-hero-hub'
									) }
								</span>
							</a>
							<a className="hdh-step" href="#/connect">
								<span className="hdh-step__n">3</span>
								<strong className="hdh-step__title">
									{ __(
										'Connect a customer site',
										'helpdesk-hero-hub'
									) }
								</strong>
								<span className="hdh-step__text">
									{ __(
										'Create a connection link and send it to your customer.',
										'helpdesk-hero-hub'
									) }
								</span>
							</a>
						</div>
					</Empty>
				</Card>
			) : (
				<Card
					title={ __( 'Tickets', 'helpdesk-hero-hub' ) }
					action={
						<div className="hdh-toolbar">
							<input
								type="search"
								className="hdh-input"
								placeholder={ __(
									'Search subject, customer, site or number',
									'helpdesk-hero-hub'
								) }
								aria-label={ __(
									'Search tickets',
									'helpdesk-hero-hub'
								) }
								value={ search }
								onChange={ ( e ) =>
									setSearch( e.target.value )
								}
							/>
							<FilterButton
								filters={ filters }
								defs={ defs }
								onChange={ setFilter }
							/>
							<SortControl
								options={ sortOptions() }
								value={ sort.by }
								order={ sort.order }
								onChange={ ( by, order ) =>
									setSort( { by, order } )
								}
							/>
						</div>
					}
					bodyClass={ null }
				>
					{ applyFilters( 'helpdeskHeroHub.inboxViews', [] ).map(
						( Views, i ) => (
							<Views
								key={ i }
								filters={ filters }
								sort={ sort }
								apply={ ( f, so ) => {
									setSearch( f.search || '' );
									setFilters( f );
									if ( so ) {
										setSort( so );
									}
								} }
							/>
						)
					) }
					<div className="hdh-inbox-bar">
						<StatusTabs
							value={ filters.status || 'active' }
							counts={ data.tabs }
							onChange={ ( v ) => setFilter( 'status', v ) }
						/>
						<FilterChips
							filters={ filters }
							defs={ defs }
							onRemove={ ( k ) => setFilter( k, '' ) }
							onClear={ () =>
								setFilters( { status: filters.status || 'active' } )
							}
						/>
					</div>
					{ boot.canManage && narrowed && data.total > 0 && (
						<div
							className="hdh-row"
							style={ { padding: '12px 18px 0', gap: 8 } }
						>
							<span className="hdh-muted">
								{ sprintf(
									/* translators: %d: number of tickets */
									_n(
										'%d ticket matches.',
										'%d tickets match.',
										data.total,
										'helpdesk-hero-hub'
									),
									data.total
								) }
							</span>
							<a
								className="hdh-btn is-sm"
								href={ exportUrl( filterParams ) }
							>
								{ __( 'Export as CSV', 'helpdesk-hero-hub' ) }
							</a>
							<Button
								size="sm"
								variant="ghost"
								icon="trash"
								onClick={ deleteMatching }
							>
								{ __( 'Delete all matching…', 'helpdesk-hero-hub' ) }
							</Button>
						</div>
					) }
					{ selected.length > 0 && (
						<BulkBar
							onDelete={ boot.canManage ? deleteSelected : null }
							exportHref={
								boot.canManage
									? exportUrl( { ids: selected.join( ',' ) } )
									: null
							}
							count={ selected.length }
							team={ team.data ? team.data.team : [] }
							tags={ tags.data ? tags.data.tags : [] }
							onRun={ runBulk }
							onClear={ () => setSelected( [] ) }
						/>
					) }
					{ ! tickets.length ? (
						<Empty
							compact
							title={ __( 'Nothing here.', 'helpdesk-hero-hub' ) }
						/>
					) : (
						<div
							className={ `hdh-table-wrap ${
								loading ? 'hdh-is-loading' : ''
							}` }
						>
							<table className="hdh-table">
								<thead>
									<tr>
										<th scope="col" style={ { width: 1 } }>
											<input
												type="checkbox"
												className="hdh-select-row"
												aria-label={ __(
													'Select all tickets on this page',
													'helpdesk-hero-hub'
												) }
												checked={ allOnPage }
												onChange={ ( e ) =>
													setSelected(
														e.target.checked
															? [
																	...new Set( [
																		...selected,
																		...pageIds,
																	] ),
															  ]
															: selected.filter(
																	( id ) =>
																		! pageIds.includes(
																			id
																		)
															  )
													)
												}
											/>
										</th>
										<th scope="col">
											{ __(
												'Ticket',
												'helpdesk-hero-hub'
											) }
										</th>
										<th scope="col">
											{ __(
												'Site',
												'helpdesk-hero-hub'
											) }
										</th>
										<th scope="col">
											{ __(
												'Status',
												'helpdesk-hero-hub'
											) }
										</th>
										<th scope="col">
											{ __(
												'Health',
												'helpdesk-hero-hub'
											) }
										</th>
										<th scope="col">
											{ __(
												'Access',
												'helpdesk-hero-hub'
											) }
										</th>
										<th scope="col">
											{ __(
												'Updated',
												'helpdesk-hero-hub'
											) }
										</th>
									</tr>
								</thead>
								<tbody>
									{ tickets.map( ( t ) => (
										<tr
											key={ t.id }
											className="hdh-row-link"
											onClick={ () =>
												go( `ticket/${ t.id }` )
											}
										>
											<td
												onClick={ ( e ) =>
													e.stopPropagation()
												}
											>
												<input
													type="checkbox"
													className="hdh-select-row"
													aria-label={ sprintf(
														/* translators: %s: ticket subject */
														__(
															'Select %s',
															'helpdesk-hero-hub'
														),
														t.subject
													) }
													checked={ selected.includes(
														t.id
													) }
													onChange={ ( e ) =>
														toggle(
															t.id,
															e.target.checked
														)
													}
												/>
											</td>
											<td>
												{ t.unread && (
													<span
														className={ `hdh-unread is-${ t.unread }` }
													>
														{ t.unread === 'reply'
															? __(
																	'Customer replied',
																	'helpdesk-hero-hub'
															  )
															: __(
																	'New',
																	'helpdesk-hero-hub'
															  ) }
													</span>
												) }
												<a
													className="hdh-strong-link"
													href={ `#/ticket/${ t.id }` }
													onClick={ ( e ) =>
														e.stopPropagation()
													}
												>
													{ t.subject }
												</a>
												<span className="hdh-row-icons">
													{ ( t.merged_from || [] ).length > 0 && (
														<span
															className="hdh-merge-chip"
															title={ sprintf(
																/* translators: %s: ticket numbers */
																__( 'Merged from %s', 'helpdesk-hero-hub' ),
																t.merged_from.map( ( n ) => `#${ n }` ).join( ', ' )
															) }
														>
															<Icon name="merge" size={ 13 } />
															{ t.merged_from.length }
														</span>
													) }
													{ t.billing && (
														<BillingBadge state={ t.billing } compact />
													) }
													<button
														type="button"
														className={ `hdh-bookmark ${ t.bookmarked ? 'is-on' : '' }` }
														aria-pressed={ !! t.bookmarked }
														title={
															t.bookmarked
																? __( 'Remove bookmark', 'helpdesk-hero-hub' )
																: __( 'Bookmark', 'helpdesk-hero-hub' )
														}
														onClick={ ( e ) => {
															e.stopPropagation();
															send( `admin/tickets/${ t.id }/bookmark`, 'POST', {
																on: ! t.bookmarked,
															} )
																.then( reload )
																.catch( ( err ) => toast( message( err ), 'alert' ) );
														} }
													>
														<Icon name="bookmark" size={ 14 } />
													</button>
												</span>
												<div className="hdh-muted">
													#{ t.id } · { t.customer } ·{ ' ' }
													{ PRIORITY_LABELS[
														t.priority
													] || t.priority }
													{ t.assignee
														? ` · ${ sprintf(
																/* translators: %s: person */
																__(
																	'Assigned to %s',
																	'helpdesk-hero-hub'
																),
																t.assignee.name
														  ) }`
														: '' }
													{ t.reference
														? ` · ${ t.reference }`
														: '' }
												</div>
												{ ( t.tags.length > 0 ||
													t.service ||
													t.rating > 0 ||
													t.channel === 'email' ) && (
													<div
														className="hdh-row"
														style={ {
															gap: 6,
															marginTop: 4,
														} }
													>
														{ t.channel ===
															'email' && (
															<Pill icon="mail">
																{ __(
																	'Emailed',
																	'helpdesk-hero-hub'
																) }
															</Pill>
														) }
														{ t.service && (
															<span
																className="hdh-tagpill is-service"
																style={ {
																	'--tag':
																		t
																			.service
																			.color,
																} }
															>
																{
																	t.service
																		.name
																}
															</span>
														) }
														<TagPills
															tags={ t.tags }
														/>
														{ t.rating > 0 && (
															<Stars
																value={
																	t.rating
																}
																size={ 12 }
															/>
														) }
													</div>
												) }
											</td>
											<td>
												{ t.site_name }{ ' ' }
												<TierBadge tier={ t.tier } />
												<div className="hdh-muted">
													{ t.site_host }
												</div>
											</td>
											<td>
												<TicketStatus
													status={ t.status }
													labels={
														t.custom_status
															? {
																	...STATUS_LABELS,
																	[ t.status ]:
																		t
																			.custom_status
																			.label,
															  }
															: STATUS_LABELS
													}
												/>
												{ t.sla && (
													<div>
														<SlaBadge
															sla={ t.sla }
															compact
														/>
													</div>
												) }
											</td>
											<td>
												<div
													className="hdh-row"
													style={ { gap: 4 } }
												>
													{ t.critical > 0 && (
														<Pill
															tone="critical"
															icon="alert"
														>
															{ t.critical }
														</Pill>
													) }
													{ t.warnings > 0 && (
														<Pill tone="warning">
															{ t.warnings }
														</Pill>
													) }
													{ ! t.critical &&
														! t.warnings && (
															<Icon
																name="check"
																size={ 15 }
																style={ {
																	color: 'var(--hdh-good)',
																} }
															/>
														) }
												</div>
											</td>
											<td>
												<AccessState
													active={ t.access_active }
													expires={ t.access_expires }
												/>
											</td>
											<td title={ when( t.updated_at ) }>
												{ ago( t.updated_at ) }
											</td>
										</tr>
									) ) }
								</tbody>
							</table>
							<Pager
								page={ data.page || page }
								perPage={ perPage }
								total={ data.total || 0 }
								onPage={ setPage }
								onPer={ setPerPage }
							/>
						</div>
					) }
				</Card>
			) }
		</>
	);
}

function BulkBar( {
	count,
	team,
	tags,
	onRun,
	onClear,
	onDelete,
	exportHref,
} ) {
	const [ action, setAction ] = useState( '' );
	const options = [
		{
			value: 'status:open',
			label: __( 'Set status: Open', 'helpdesk-hero-hub' ),
		},
		{
			value: 'status:pending',
			label: __( 'Set status: Waiting on customer', 'helpdesk-hero-hub' ),
		},
		{
			value: 'status:closed',
			label: __( 'Set status: Closed', 'helpdesk-hero-hub' ),
		},
		{ value: 'assign:me', label: __( 'Assign to me', 'helpdesk-hero-hub' ) },
		{ value: 'assign:0', label: __( 'Unassign', 'helpdesk-hero-hub' ) },
		...team
			.filter( ( u ) => u.id !== boot.userId )
			.map( ( u ) => ( {
				value: `assign:${ u.id }`,
				/* translators: %s: person */
				label: sprintf( __( 'Assign to %s', 'helpdesk-hero-hub' ), u.name ),
			} ) ),
		...tags.map( ( t ) => ( {
			value: `add_tag:${ t.id }`,
			/* translators: %s: tag */
			label: sprintf( __( 'Add tag: %s', 'helpdesk-hero-hub' ), t.name ),
		} ) ),
		...tags.map( ( t ) => ( {
			value: `remove_tag:${ t.id }`,
			/* translators: %s: tag */
			label: sprintf( __( 'Remove tag: %s', 'helpdesk-hero-hub' ), t.name ),
		} ) ),
		...( boot.customStatuses || [] ).map( ( c ) => ( {
			value: `custom_status:${ c.id }`,
			/* translators: %s: status */
			label: sprintf( __( 'Set status: %s', 'helpdesk-hero-hub' ), c.label ),
		} ) ),
	];
	const chosen = options.find( ( o ) => o.value === action );
	return (
		<div
			className="hdh-banner"
			role="region"
			aria-label={ __( 'Bulk actions', 'helpdesk-hero-hub' ) }
			style={ { margin: '12px 18px 0' } }
		>
			<div className="hdh-banner__text">
				<strong>
					{ sprintf(
						/* translators: %d: number of tickets */
						_n(
							'%d ticket selected',
							'%d tickets selected',
							count,
							'helpdesk-hero-hub'
						),
						count
					) }
				</strong>
			</div>
			<select
				className="hdh-input hdh-select"
				aria-label={ __( 'Bulk action', 'helpdesk-hero-hub' ) }
				value={ action }
				onChange={ ( e ) => setAction( e.target.value ) }
			>
				<option value="">
					{ __( 'Choose an action…', 'helpdesk-hero-hub' ) }
				</option>
				{ options.map( ( o ) => (
					<option key={ o.value } value={ o.value }>
						{ o.label }
					</option>
				) ) }
			</select>
			<Button
				size="sm"
				variant="primary"
				disabled={ ! chosen }
				onClick={ () => {
					const [ name, ...rest ] = action.split( ':' );
					onRun( name, rest.join( ':' ), chosen.label );
				} }
			>
				{ __( 'Apply', 'helpdesk-hero-hub' ) }
			</Button>
			{ exportHref && (
				<a className="hdh-btn is-sm" href={ exportHref }>
					{ __( 'Export CSV', 'helpdesk-hero-hub' ) }
				</a>
			) }
			{ onDelete && (
				<Button size="sm" variant="ghost" icon="trash" onClick={ onDelete }>
					{ __( 'Delete', 'helpdesk-hero-hub' ) }
				</Button>
			) }
			<Button size="sm" variant="ghost" onClick={ onClear }>
				{ __( 'Clear', 'helpdesk-hero-hub' ) }
			</Button>
		</div>
	);
}
