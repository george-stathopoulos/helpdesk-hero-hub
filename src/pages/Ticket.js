import { __, sprintf } from '@wordpress/i18n';
import { applyFilters } from '@wordpress/hooks';
import { useState, useMemo, useRef, useEffect } from '@wordpress/element';
import { useApi, send } from '../ui/lib/hooks';
import { ago, when } from '../ui/lib/time';
import {
	Card,
	Button,
	Segmented,
	ErrorNotice,
	Loading,
	useToast,
	ErrorBoundary,
} from '../ui/components/ui';
import {
	Thread,
	FlagList,
	CodeBlock,
	TicketStatus,
	AccessState,
	isPermanentAccess,
	BillingBadge,
	Check,
	KeyValues,
	TextArea,
	TextField,
	SelectField,
	TagPills,
	Stars,
	confirmAction,
} from '../ui/components/kit';
import Icon from '../ui/components/Icon';
import {
	STATUS_LABELS,
	PRIORITY_LABELS,
	message,
	TierBadge,
	SlaBadge,
} from '../components/common';
import NoticeCard from '../components/NoticeCard';

const boot = window.hdhBoot || {};

/**
 * Tell add-ons someone is working on a ticket (Pro starts its automatic timer).
 *
 * @param {number} id   Ticket.
 * @param {string} what typing | login | note | status.
 */
function working( id, what ) {
	window.dispatchEvent(
		new window.CustomEvent( 'hdh:ticket-work', { detail: { id, what } } )
	);
}

const HOURS = [
	{ value: '4', label: __( '4 hours', 'helpdesk-hero-hub' ) },
	{ value: '24', label: __( '24 hours', 'helpdesk-hero-hub' ) },
	{ value: '72', label: __( '3 days', 'helpdesk-hero-hub' ) },
	{ value: '168', label: __( '7 days', 'helpdesk-hero-hub' ) },
];

export default function Ticket( { param } ) {
	const id = parseInt( param, 10 );
	const { data, error, reload } = useApi( `admin/tickets/${ id }` );
	const [ override, setOverride ] = useState( null );
	const ticket = override && override.id === id ? override : data;
	const toast = useToast();
	const [ busy, setBusy ] = useState( '' );

	const act = ( key, path, body, done ) => {
		setBusy( key );
		return send( `admin/tickets/${ id }/${ path }`, 'POST', body )
			.then( ( res ) => {
				if ( res && res.id ) {
					setOverride( res );
				}
				if ( done ) {
					done( res );
				}
				return res;
			} )
			.catch( ( e ) => toast( message( e ), 'alert' ) )
			.finally( () => setBusy( '' ) );
	};

	// Status changes wait a few seconds before they're sent, with an Undo in the toast, so a
	// mis-click never reaches the customer (closing can also end support access). Leaving the
	// page sends a waiting change straight away.
	const [ pendingStatus, setPendingStatus ] = useState( null );
	const statusTimer = useRef( null );
	const statusFlush = useRef( null );
	useEffect( () => {
		const flush = () => statusFlush.current && statusFlush.current();
		window.addEventListener( 'beforeunload', flush );
		return () => {
			window.removeEventListener( 'beforeunload', flush );
			flush();
		};
	}, [] );
	const changeStatus = ( next, previous ) => {
		if ( statusFlush.current ) {
			statusFlush.current();
		}
		if ( next === previous ) {
			return;
		}
		setPendingStatus( next );
		const commit = () => {
			window.clearTimeout( statusTimer.current );
			statusFlush.current = null;
			act(
				'status',
				'status',
				next.indexOf( 'custom:' ) === 0
					? { custom_status: next.slice( 7 ) }
					: { status: next }
			).finally( () => setPendingStatus( null ) );
		};
		statusFlush.current = commit;
		statusTimer.current = window.setTimeout( commit, 6000 );
		toast(
			sprintf(
				/* translators: %s: status */
				__( 'Status changed to %s', 'helpdesk-hero-hub' ),
				STATUS_LABELS[ next ] ||
					(
						( boot.customStatuses || [] ).find(
							( c ) => `custom:${ c.id }` === next
						) || {}
					).label ||
					next
			),
			'check',
			{
				duration: 6000,
				action: {
					label: __( 'Undo', 'helpdesk-hero-hub' ),
					onClick: () => {
						window.clearTimeout( statusTimer.current );
						statusFlush.current = null;
						setPendingStatus( null );
					},
				},
			}
		);
	};

	if ( error && ! ticket ) {
		return <ErrorNotice error={ error } onRetry={ reload } />;
	}
	if ( ! ticket ) {
		return <Loading />;
	}

	const thread = ticket.thread.map( ( m ) => ( {
		...m,
		from: { support: 'support', customer: 'you', note: 'note' }[ m.kind ] || 'event',
	} ) );

	return (
		<>
			<a className="hdh-back" href="#/inbox">
				<Icon name="arrowLeft" size={ 14 } />
				{ __( 'Inbox', 'helpdesk-hero-hub' ) }
			</a>
			<div className="hdh-pagehead">
				<div>
					<h1 className="hdh-pagehead__title">
						{ ticket.subject }{ ' ' }
						<button
							type="button"
							className={ `hdh-bookmark ${ ticket.bookmarked ? 'is-on' : '' }` }
							aria-pressed={ !! ticket.bookmarked }
							title={
								ticket.bookmarked
									? __( 'Remove bookmark', 'helpdesk-hero-hub' )
									: __( 'Bookmark', 'helpdesk-hero-hub' )
							}
							onClick={ () =>
								send( `admin/tickets/${ ticket.id }/bookmark`, 'POST', {
									on: ! ticket.bookmarked,
								} )
									.then( ( r ) =>
										setOverride( { ...ticket, bookmarked: r.bookmarked } )
									)
									.catch( ( e ) => toast( message( e ), 'alert' ) )
							}
						>
							<Icon name="bookmark" size={ 18 } />
						</button>
					</h1>
					<p className="hdh-pagehead__lede hdh-row">
						<TicketStatus
							status={ ticket.status }
							labels={
								ticket.custom_status
									? {
											...STATUS_LABELS,
											[ ticket.status ]:
												ticket.custom_status.label,
									  }
									: STATUS_LABELS
							}
						/>
						<span>#{ ticket.id }</span>
						<span>·</span>
						<span>{ ticket.site ? ticket.site.name : '' }</span>
						{ ticket.site && <TierBadge tier={ ticket.site.tier } /> }
						<SlaBadge sla={ ticket.sla } />
						{ ticket.billing && <BillingBadge state={ ticket.billing } /> }
						<span>·</span>
						<span title={ when( ticket.created_at ) }>
							{ ago( ticket.created_at ) }
						</span>
						{ ticket.channel === 'email' && (
							<span
								className="hdh-pill"
								title={ __(
									'The customer emailed this ticket themselves and then registered it here.',
									'helpdesk-hero-hub'
								) }
							>
								{ __(
									'Emailed by the customer',
									'helpdesk-hero-hub'
								) }
							</span>
						) }
						<TagPills tags={ ticket.tags } />
					</p>
				</div>
				<div className="hdh-toolbar">
					{ boot.canManage && (
						<Button
							variant="ghost"
							icon="trash"
							onClick={ async () => {
								const yes = await confirmAction(
									__(
										'Delete this ticket from the hub, with its conversation, history and files? The customer keeps their copy, shown as closed. This can’t be undone.',
										'helpdesk-hero-hub'
									),
									{
										confirmText: __(
											'Delete',
											'helpdesk-hero-hub'
										),
									}
								);
								if ( yes ) {
									send( `admin/tickets/${ id }`, 'DELETE' )
										.then( () => {
											toast(
												__(
													'Ticket deleted',
													'helpdesk-hero-hub'
												)
											);
											window.location.hash = '#/inbox';
										} )
										.catch( ( e ) =>
											toast( message( e ), 'alert' )
										);
								}
							} }
						>
							{ __( 'Delete', 'helpdesk-hero-hub' ) }
						</Button>
					) }
					{ ticket.helpdesk && (
						<a
							className="hdh-btn"
							href={ ticket.helpdesk.url }
							target="_blank"
							rel="noopener noreferrer"
						>
							<Icon name="external" size={ 15 } />
							{ sprintf(
								/* translators: %s: help desk name */
								__( 'Open in %s', 'helpdesk-hero-hub' ),
								ticket.helpdesk.label
							) }
						</a>
					) }
					{ ticket.helpdesk && (
						<Button
							icon="refresh"
							disabled={ busy === 'sync' }
							onClick={ () =>
								act( 'sync', 'sync', {}, ( r ) =>
									toast(
										r.relayed
											? __(
													'New replies sent to the customer’s dashboard.',
													'helpdesk-hero-hub'
											  )
											: __(
													'Up to date.',
													'helpdesk-hero-hub'
											  )
									)
								)
							}
						>
							{ __( 'Sync', 'helpdesk-hero-hub' ) }
						</Button>
					) }
				</div>
			</div>

			{ ticket.merged_into && (
				<div className="hdh-banner" role="status">
					<span className="hdh-banner__icon">
						<Icon name="merge" />
					</span>
					<p className="hdh-banner__text">
						{ __(
							'This ticket was merged. The conversation continues in:',
							'helpdesk-hero-hub'
						) }{ ' ' }
						<a href={ `#/ticket/${ ticket.merged_into.id }` }>
							#{ ticket.merged_into.id } { ticket.merged_into.subject }
						</a>
					</p>
				</div>
			) }
			<div className="hdh-split">
				<div className="hdh-split__main">
					<Card title={ __( 'Conversation', 'helpdesk-hero-hub' ) }>
						<Thread items={ thread } />
					</Card>
					<Reply
						ticket={ ticket }
						busy={ busy === 'reply' || busy === 'note' }
						onSend={ ( body, done, extra = {} ) =>
							act( 'reply', 'reply', { body, ...extra }, () => {
								toast(
									__( 'Reply sent', 'helpdesk-hero-hub' )
								);
								done();
							} )
						}
						onNote={ ( body, done ) =>
							act( 'note', 'note', { body }, () => {
								toast(
									__( 'Note added', 'helpdesk-hero-hub' )
								);
								done();
							} )
						}
					/>
					<Card
						title={ __( 'Health check', 'helpdesk-hero-hub' ) }
						sub={ __(
							'Found on the site when the ticket was sent.',
							'helpdesk-hero-hub'
						) }
					>
						<FlagList flags={ ticket.flags } />
						<details style={ { marginTop: 14 } }>
							<summary
								className="hdh-section-title"
								style={ { cursor: 'pointer', margin: 0 } }
							>
								{ __(
									'Full diagnostics',
									'helpdesk-hero-hub'
								) }
							</summary>
							<div style={ { marginTop: 10 } }>
								<CodeBlock text={ ticket.diagnostics } />
							</div>
						</details>
					</Card>
				</div>

				<aside className="hdh-split__side hdh-panels">
					{ applyFilters( 'helpdeskHeroHub.ticketPanels', [] ).map(
						( Panel, i ) => (
							<ErrorBoundary key={ i }>
								<Panel ticket={ ticket } />
							</ErrorBoundary>
						)
					) }
					<AssigneeCard
						ticket={ ticket }
						busy={ busy === 'assign' }
						onAssign={ ( user ) =>
							act( 'assign', 'assign', { user }, () =>
								toast(
									user
										? __(
												'Ticket assigned',
												'helpdesk-hero-hub'
										  )
										: __(
												'Ticket unassigned',
												'helpdesk-hero-hub'
										  )
								)
							)
						}
					/>
					{ ( ticket.pinpoints || [] ).length > 0 && (
						<ProblemSpots pins={ ticket.pinpoints } access={ ticket.access } />
					) }
					<AccessCard ticket={ ticket } act={ act } busy={ busy } />
					<Card title={ __( 'Status', 'helpdesk-hero-hub' ) }>
						{ ( boot.customStatuses || [] ).length ? (
							<select
								className="hdh-input hdh-select"
								aria-label={ __( 'Status', 'helpdesk-hero-hub' ) }
								style={ { width: '100%' } }
								value={
									pendingStatus ||
									( ticket.custom_status
										? `custom:${ ticket.custom_status.id }`
										: ticket.status )
								}
								onChange={ ( e ) =>
									changeStatus(
										e.target.value,
										ticket.custom_status
											? `custom:${ ticket.custom_status.id }`
											: ticket.status
									)
								}
							>
								{ Object.keys( STATUS_LABELS ).map( ( k ) => (
									<optgroup key={ k } label={ STATUS_LABELS[ k ] }>
										<option value={ k }>
											{ STATUS_LABELS[ k ] }
										</option>
										{ boot.customStatuses
											.filter( ( c ) => c.base === k )
											.map( ( c ) => (
												<option
													key={ c.id }
													value={ `custom:${ c.id }` }
												>
													{ c.label }
												</option>
											) ) }
									</optgroup>
								) ) }
							</select>
						) : (
							<Segmented
								label={ __( 'Status', 'helpdesk-hero-hub' ) }
								value={ pendingStatus || ticket.status }
								onChange={ ( status ) =>
									changeStatus( status, ticket.status )
								}
								options={ Object.keys( STATUS_LABELS ).map(
									( k ) => ( {
										value: k,
										label: STATUS_LABELS[ k ],
									} )
								) }
							/>
						) }
						{ ticket.sla && (
							<div style={ { marginTop: 10 } }>
								<SlaBadge sla={ ticket.sla } />
							</div>
						) }
					</Card>
					{ applyFilters( 'helpdeskHeroHub.ticketPanelsLower', [] ).map(
						( Panel, i ) => (
							<ErrorBoundary key={ i }>
								<Panel ticket={ ticket } />
							</ErrorBoundary>
						)
					) }
					<NoticeCard
						onSend={ ( body, done ) =>
							act( 'notice', 'notice', body, () => {
								toast(
									__(
										'Message sent to the customer’s dashboard',
										'helpdesk-hero-hub'
									)
								);
								done();
							} )
						}
						busy={ busy === 'notice' }
					/>
					{ ticket.rating && (
						<Card
							title={ __(
								'Customer rating',
								'helpdesk-hero-hub'
							) }
						>
							<div className="hdh-stack" style={ { gap: 8 } }>
								<Stars
									value={ ticket.rating.stars }
									size={ 18 }
								/>
								{ ticket.rating.comment && (
									<p style={ { whiteSpace: 'pre-wrap' } }>
										“{ ticket.rating.comment }”
									</p>
								) }
								<span className="hdh-muted">
									{ ago( ticket.rating.time ) }
								</span>
							</div>
						</Card>
					) }
					<TagsCard
						ticket={ ticket }
						onSave={ ( tags ) =>
							act( 'tags', 'tags', {
								tags: tags.map( ( t ) => t.id ),
							} )
						}
						busy={ busy === 'tags' }
					/>
					<Card title={ __( 'Details', 'helpdesk-hero-hub' ) }>
						<KeyValues
							rows={ [
								[
									__( 'Customer', 'helpdesk-hero-hub' ),
									<>
										{ ticket.customer.name }
										<div className="hdh-muted">
											{ ticket.customer.email }
										</div>
									</>,
								],
								[
									__( 'Site', 'helpdesk-hero-hub' ),
									ticket.site ? (
										<a
											href={ ticket.site.url }
											target="_blank"
											rel="noopener noreferrer"
										>
											{ ticket.site.name }
										</a>
									) : null,
								],
								ticket.site && ticket.site.tier
									? [
											__( 'Tier', 'helpdesk-hero-hub' ),
											<TierBadge
												key="tier"
												tier={ ticket.site.tier }
											/>,
									  ]
									: null,
								ticket.site && ticket.site.notes
									? [
											__(
												'Site notes',
												'helpdesk-hero-hub'
											),
											<span
												key="notes"
												style={ {
													whiteSpace: 'pre-wrap',
												} }
											>
												{ ticket.site.notes }
											</span>,
									  ]
									: null,
								[
									__( 'Priority', 'helpdesk-hero-hub' ),
									PRIORITY_LABELS[ ticket.priority ] ||
										ticket.priority,
								],
								...( ticket.fields || [] ).map( ( f ) => [
									f.label,
									<span
										key={ f.id }
										style={ { whiteSpace: 'pre-wrap' } }
									>
										{ f.value }
									</span>,
								] ),
								( ticket.merged_from || [] ).length
									? [
											__(
												'Merged in',
												'helpdesk-hero-hub'
											),
											<span key="merged">
												{ ticket.merged_from.map(
													( m, i ) => (
														<span key={ m.id }>
															{ i ? ', ' : '' }#
															{ m.id }{ ' ' }
															{ m.subject }
														</span>
													)
												) }
											</span>,
									  ]
									: null,
								ticket.category
									? [
											__(
												'Category',
												'helpdesk-hero-hub'
											),
											ticket.category,
									  ]
									: null,
								ticket.environment
									? [
											'WordPress',
											ticket.environment.wordpress,
									  ]
									: null,
								ticket.environment
									? [ 'PHP', ticket.environment.php ]
									: null,
								ticket.helpdesk
									? [
											__(
												'Help desk',
												'helpdesk-hero-hub'
											),
											ticket.helpdesk.reference,
									  ]
									: null,
							] }
						/>
					</Card>
				</aside>
			</div>
		</>
	);
}

function Reply( { ticket, onSend, onNote, busy } ) {
	const [ body, setBody ] = useState( '' );
	const [ mode, setMode ] = useState(
		boot.canSupport === false ? 'note' : 'reply'
	);
	// Add-ons can add data to the reply (Pro: attachments) through setExtra.
	const [ extra, setExtra ] = useState( {} );
	const tools = useMemo(
		() => applyFilters( 'helpdeskHeroHub.replyTools', [] ),
		[]
	);
	const isNote = mode === 'note';
	const reset = () => {
		setBody( '' );
		setExtra( {} );
	};
	return (
		<Card
			title={
				isNote
					? __( 'Internal note', 'helpdesk-hero-hub' )
					: __( 'Reply to the customer', 'helpdesk-hero-hub' )
			}
			sub={ ( () => {
				if ( isNote ) {
					return __(
						'Only your team sees notes. The customer never does.',
						'helpdesk-hero-hub'
					);
				}
				if ( ticket.helpdesk ) {
					return sprintf(
						/* translators: %s: help desk */
						__(
							'Sent through %s and shown in the customer’s dashboard.',
							'helpdesk-hero-hub'
						),
						ticket.helpdesk.label
					);
				}
				return __(
					'Shown in the customer’s dashboard; they also get an email.',
					'helpdesk-hero-hub'
				);
			} )() }
			action={
				<Segmented
					label={ __( 'Write', 'helpdesk-hero-hub' ) }
					value={ mode }
					onChange={ setMode }
					options={ [
						...( boot.canSupport === false
							? []
							: [
									{
										value: 'reply',
										label: __(
											'Reply',
											'helpdesk-hero-hub'
										),
									},
							  ] ),
						{
							value: 'note',
							label: __( 'Internal note', 'helpdesk-hero-hub' ),
						},
					] }
				/>
			}
		>
			<div className="hdh-stack">
				{ boot.canSupport === false && (
					<p className="hdh-muted">
						{ __(
							'Your team has chosen who replies to customers and logs in to their sites (Pro → Supporters). You can add internal notes; replies come from a supporter.',
							'helpdesk-hero-hub'
						) }
					</p>
				) }
				<TextArea
					formatting
					label={
						isNote
							? __( 'Note', 'helpdesk-hero-hub' )
							: __( 'Message', 'helpdesk-hero-hub' )
					}
					value={ body }
					onChange={ ( v ) => {
						setBody( v );
						working( ticket.id, isNote ? 'note' : 'typing' );
					} }
					rows={ isNote ? 4 : 6 }
					className={ isNote ? 'hdh-note-input' : undefined }
				/>
				{ ! isNote &&
					tools.map( ( Tool, i ) => (
						<Tool
							key={ i }
							ticket={ ticket }
							body={ body }
							setBody={ setBody }
							extra={ extra }
							setExtra={ setExtra }
						/>
					) ) }
				<div className="hdh-row">
					<Button
						variant="primary"
						icon={ isNote ? 'edit' : 'send' }
						disabled={ busy || ! body.trim() }
						onClick={ () =>
							isNote
								? onNote( body, reset )
								: onSend( body, reset, extra )
						}
					>
						{ ( () => {
							if ( busy ) {
								return __( 'Saving…', 'helpdesk-hero-hub' );
							}
							return isNote
								? __( 'Add note', 'helpdesk-hero-hub' )
								: __( 'Send reply', 'helpdesk-hero-hub' );
						} )() }
					</Button>
				</div>
			</div>
		</Card>
	);
}

function AssigneeCard( { ticket, onAssign, busy } ) {
	const team = useApi( 'admin/team' );
	const [ auto, setAuto ] = useState( boot.assignOnReply !== false );
	const current = ticket.assignee ? String( ticket.assignee.id ) : '0';
	const mine = ticket.assignee && ticket.assignee.id === boot.userId;
	return (
		<Card title={ __( 'Assigned to', 'helpdesk-hero-hub' ) }>
			<div className="hdh-stack" style={ { gap: 8 } }>
				<select
					className="hdh-input hdh-select"
					aria-label={ __( 'Assigned to', 'helpdesk-hero-hub' ) }
					value={ current }
					disabled={ busy || ! team.data }
					onChange={ ( e ) =>
						onAssign( parseInt( e.target.value, 10 ) )
					}
				>
					<option value="0">
						{ __( 'Nobody', 'helpdesk-hero-hub' ) }
					</option>
					{ ( team.data ? team.data.team : [] ).map( ( u ) => (
						<option key={ u.id } value={ String( u.id ) }>
							{ u.name }
						</option>
					) ) }
				</select>
				{ ! mine && (
					<div>
						<Button
							size="sm"
							variant="ghost"
							icon="user"
							disabled={ busy }
							onClick={ () => onAssign( 'me' ) }
						>
							{ __( 'Assign to me', 'helpdesk-hero-hub' ) }
						</Button>
					</div>
				) }
				<Check
					label={ __( 'Assign tickets to me when I reply', 'helpdesk-hero-hub' ) }
					desc={ __( 'Only tickets nobody has yet. Your own setting, for every ticket.', 'helpdesk-hero-hub' ) }
					checked={ auto }
					onChange={ ( v ) => {
						setAuto( v );
						boot.assignOnReply = v;
						send( 'admin/me', 'POST', { assign_on_reply: v } );
					} }
				/>
			</div>
		</Card>
	);
}

/**
 * Pinpoint: the spot the customer picked, with "Open and highlight".
 *
 * @param {Object} props        Props.
 * @param {Object} props.pin    Pin.
 * @param {Object} props.access Ticket access.
 * @return {JSX.Element} Card.
 */
function SpotDetails( { pin, access } ) {
	const admin = pin.area === 'admin';
	return (
			<div className="hdh-stack">
				<KeyValues
					rows={ [
						[
							__( 'Page', 'helpdesk-hero-hub' ),
							<a key="p" href={ pin.url } target="_blank" rel="noopener noreferrer">
								{ pin.title || pin.url }
							</a>,
						],
						[
							__( 'Picked', 'helpdesk-hero-hub' ),
							pin.kind === 'area'
								? __( 'An area (a box drawn on the page)', 'helpdesk-hero-hub' )
								: __( 'An element', 'helpdesk-hero-hub' ),
						],
						pin.text ? [ __( 'Element', 'helpdesk-hero-hub' ), `“${ pin.text }”` ] : null,
						[
							__( 'Selector', 'helpdesk-hero-hub' ),
							<code key="s" className="hdh-error-detail">
								{ pin.selector || '—' }
							</code>,
						],
						[
							__( 'Screen', 'helpdesk-hero-hub' ),
							`${ pin.viewport.w } × ${ pin.viewport.h } @${ pin.viewport.dpr }x`,
						],
						[ __( 'Browser', 'helpdesk-hero-hub' ), pin.browser ],
						[
							__( 'JavaScript errors', 'helpdesk-hero-hub' ),
							pin.errors.length ? (
								<ul key="e" className="hdh-plain-list">
									{ pin.errors.map( ( e, i ) => (
										<li key={ i }>
											{ e.message }
											{ e.source && (
												<span className="hdh-muted">
													{ ` ${ e.source }:${ e.line }` }
												</span>
											) }
										</li>
									) ) }
								</ul>
							) : (
								__( 'None', 'helpdesk-hero-hub' )
							),
						],
						[
							__( 'Failed requests', 'helpdesk-hero-hub' ),
							pin.requests.length ? (
								<ul key="r" className="hdh-plain-list">
									{ pin.requests.map( ( r, i ) => (
										<li key={ i }>
											{ `${ r.method } ${ r.url } → ${
												r.status || __( 'no response', 'helpdesk-hero-hub' )
											}` }
										</li>
									) ) }
								</ul>
							) : (
								__( 'None', 'helpdesk-hero-hub' )
							),
						],
					] }
				/>
				<a
					className="hdh-btn is-sm"
					href={ pin.highlight_url }
					target="_blank"
					rel="noopener noreferrer"
				>
					<Icon name="eye" size={ 15 } />
					{ __( 'Open and highlight', 'helpdesk-hero-hub' ) }
				</a>
				{ admin && (
					<p className="hdh-muted" style={ { fontSize: 12.5, margin: 0 } }>
						{ access.active
							? __(
									'This is in the site’s admin: log in to the site first (Site access below), then open and highlight.',
									'helpdesk-hero-hub'
							  )
							: __(
									'This is in the site’s admin, and the customer hasn’t given access. Ask them to, then log in and open it.',
									'helpdesk-hero-hub'
							  ) }
					</p>
				) }
			</div>
	);
}

/**
 * Problem spots (Pinpoint): every spot the customer picked, from the ticket and from replies,
 * newest first. Each folds open for its details and "Open and highlight".
 *
 * @param {Object} props        Props.
 * @param {Array}  props.pins   Spots.
 * @param {Object} props.access Ticket access.
 * @return {JSX.Element} Card.
 */
function ProblemSpots( { pins, access } ) {
	const list = [ ...pins ].reverse();
	return (
		<Card
			id="hdh-spots"
			title={ __( 'Problem spots', 'helpdesk-hero-hub' ) }
			sub={ __( 'Where the customer pointed on their site.', 'helpdesk-hero-hub' ) }
		>
			<div className="hdh-spots">
				{ list.map( ( pin, i ) => (
					<details key={ `${ pin.id }-${ i }` } className="hdh-spot" open={ i === 0 }>
						<summary>
							<Icon name="pin" size={ 14 } />
							<span className="hdh-spot__title">
								{ pin.label || pin.title || pin.url }
							</span>
							<span className="hdh-muted" title={ when( pin.time ) }>
								{ pin.from
									? sprintf(
											/* translators: %d: ticket number */
											__( 'from #%d', 'helpdesk-hero-hub' ),
											pin.from
									  ) + ' · '
									: '' }
								{ pin.time ? ago( pin.time ) : '' }
							</span>
						</summary>
						<SpotDetails pin={ pin } access={ access } />
					</details>
				) ) }
			</div>
		</Card>
	);
}

function AccessCard( { ticket, act, busy } ) {
	const toast = useToast();
	const [ hours, setHours ] = useState( '24' );
	const [ reason, setReason ] = useState( '' );
	const [ asking, setAsking ] = useState( false );
	const [ activity, setActivity ] = useState( null );
	const a = ticket.access;

	const login = () => {
		working( ticket.id, 'login' );
		// Open the tab now (popup blockers allow it), then point it at the fresh link. Some
		// setups (such as the WordPress Playground demo) open it in this tab instead.
		const tab = boot.loginSameTab ? null : window.open( '', '_blank' );
		act( 'login', 'login', {}, ( r ) => {
			if ( tab ) {
				tab.location.href = r.url;
			} else {
				window.location.href = r.url;
			}
		} ).then( ( r ) => {
			if ( ! r && tab ) {
				tab.close();
			}
		} );
	};

	const loadActivity = () => {
		send( `admin/tickets/${ ticket.id }/activity` )
			.then( ( r ) => setActivity( r.entries ) )
			.catch( ( e ) => toast( message( e ), 'alert' ) );
	};

	return (
		<Card title={ __( 'Site access', 'helpdesk-hero-hub' ) }>
			<div className="hdh-stack">
				<AccessState
					active={ a.active }
					expires={ a.expires_at }
					emptyLabel={ __(
						'The customer has not granted access',
						'helpdesk-hero-hub'
					) }
				/>
				{ a.active && boot.canSupport !== false && (
					<>
						<Button
							variant="primary"
							icon="login"
							onClick={ login }
							disabled={ busy === 'login' }
						>
							{ busy === 'login'
								? __(
										'Getting a login link…',
										'helpdesk-hero-hub'
								  )
								: sprintf(
										/* translators: %s: site name */
										__(
											'Log in to %s',
											'helpdesk-hero-hub'
										),
										ticket.site ? ticket.site.name : ''
								  ) }
						</Button>
						<p className="hdh-muted" style={ { fontSize: 12.5 } }>
							{ __(
								'Each click creates a fresh one-time link. Everything you do on the site is logged for the customer.',
								'helpdesk-hero-hub'
							) }
						</p>
						{ isPermanentAccess( a.expires_at ) ? null : ! asking ? (
							<Button
								size="sm"
								variant="ghost"
								icon="clock"
								onClick={ () => setAsking( true ) }
							>
								{ __(
									'Ask for more time',
									'helpdesk-hero-hub'
								) }
							</Button>
						) : (
							<div className="hdh-stack">
								<SelectField
									label={ __(
										'Extra time',
										'helpdesk-hero-hub'
									) }
									value={ hours }
									onChange={ setHours }
									options={ HOURS }
								/>
								<TextField
									label={ __(
										'Why (shown to the customer)',
										'helpdesk-hero-hub'
									) }
									value={ reason }
									onChange={ setReason }
								/>
								<p
									className="hdh-muted"
									style={ { fontSize: 12.5 } }
								>
									{ a.extension === 'auto'
										? __(
												'Your policy extends access automatically, up to its maximum length.',
												'helpdesk-hero-hub'
										  )
										: __(
												'The customer approves or declines the request in their dashboard.',
												'helpdesk-hero-hub'
										  ) }
								</p>
								<div className="hdh-row">
									<Button
										size="sm"
										variant="primary"
										disabled={ busy === 'extend' }
										onClick={ () =>
											act(
												'extend',
												'extend',
												{
													hours: parseInt(
														hours,
														10
													),
													reason,
												},
												() => {
													setAsking( false );
													toast(
														__(
															'Request sent',
															'helpdesk-hero-hub'
														)
													);
												}
											)
										}
									>
										{ __(
											'Send request',
											'helpdesk-hero-hub'
										) }
									</Button>
									<Button
										size="sm"
										variant="ghost"
										onClick={ () => setAsking( false ) }
									>
										{ __( 'Cancel', 'helpdesk-hero-hub' ) }
									</Button>
								</div>
							</div>
						) }
						<Button
							size="sm"
							variant="ghost"
							icon="ban"
							disabled={ busy === 'end-access' }
							onClick={ async () => {
								if (
									! ( await confirmAction(
										__(
											'End access to this site now? It ends for every ticket from this site, and every support account on the site is deleted. The customer can grant access again.',
											'helpdesk-hero-hub'
										),
										{
											confirmText: __(
												'End access',
												'helpdesk-hero-hub'
											),
										}
									) )
								) {
									return;
								}
								act( 'end-access', 'end-access', {}, ( r ) =>
									r && r.end_access_delivered
										? toast(
												__(
													'Access ended. The support accounts on the site were deleted.',
													'helpdesk-hero-hub'
												)
										  )
										: toast(
												__(
													'The site couldn’t be reached right now. Access ends as soon as it’s back online and checks in; until then it can still expire on its own.',
													'helpdesk-hero-hub'
												),
												'alert',
												{ duration: 9000 }
										  )
								);
							} }
						>
							{ __( 'End access now', 'helpdesk-hero-hub' ) }
						</Button>
					</>
				) }
				<div>
					{ activity === null ? (
						<Button
							size="sm"
							variant="ghost"
							icon="activity"
							onClick={ loadActivity }
						>
							{ __(
								'What support did on the site',
								'helpdesk-hero-hub'
							) }
						</Button>
					) : (
						<>
							<div className="hdh-section-title">
								{ __(
									'Activity on the site',
									'helpdesk-hero-hub'
								) }
							</div>
							{ ! activity.length ? (
								<p className="hdh-muted">
									{ __(
										'Nothing recorded yet.',
										'helpdesk-hero-hub'
									) }
								</p>
							) : (
								<ul
									className="hdh-feed"
									style={ {
										maxHeight: 320,
										overflow: 'auto',
									} }
								>
									{ activity.map( ( e, i ) => (
										<li
											key={ i }
											className="hdh-feed__item"
											style={ {
												gridTemplateColumns:
													'minmax(0,1fr) auto',
												padding: '8px 0',
											} }
										>
											<span>
												{ e.text }
												{ e.by && (
													<span className="hdh-muted">
														{ ' · ' }
														{ e.by }
													</span>
												) }
											</span>
											<span
												className="hdh-muted"
												title={ when( e.time ) }
											>
												{ ago( e.time ) }
											</span>
										</li>
									) ) }
								</ul>
							) }
						</>
					) }
				</div>
			</div>
		</Card>
	);
}

function TagsCard( { ticket, onSave, busy } ) {
	const { data } = useApi( 'admin/tags' );
	const [ picked, setPicked ] = useState( null );
	if ( ! data ) {
		return null;
	}
	const current = picked || ticket.tags.map( ( t ) => t.id );
	const dirty =
		picked !== null &&
		JSON.stringify( [ ...picked ].sort() ) !==
			JSON.stringify( ticket.tags.map( ( t ) => t.id ).sort() );
	return (
		<Card
			title={ __( 'Tags', 'helpdesk-hero-hub' ) }
			sub={ __(
				'Shown to the customer on their ticket.',
				'helpdesk-hero-hub'
			) }
		>
			{ ! data.tags.length ? (
				<p className="hdh-muted">
					{ __( 'Create tags under Settings.', 'helpdesk-hero-hub' ) }
				</p>
			) : (
				<div className="hdh-stack" style={ { gap: 10 } }>
					<div className="hdh-tagpills">
						{ data.tags.map( ( t ) => {
							const on = current.includes( t.id );
							return (
								<button
									key={ t.id }
									type="button"
									className={ `hdh-tagpill is-toggle ${
										on ? 'is-on' : ''
									}` }
									style={ { '--tag': t.color } }
									aria-pressed={ on }
									onClick={ () =>
										setPicked(
											on
												? current.filter(
														( x ) => x !== t.id
												  )
												: [ ...current, t.id ]
										)
									}
								>
									{ t.name }
								</button>
							);
						} ) }
					</div>
					{ dirty && (
						<div>
							<Button
								size="sm"
								variant="primary"
								disabled={ busy }
								onClick={ () => {
									onSave(
										data.tags.filter( ( t ) =>
											picked.includes( t.id )
										)
									);
									setPicked( null );
								} }
							>
								{ __( 'Save tags', 'helpdesk-hero-hub' ) }
							</Button>
						</div>
					) }
				</div>
			) }
		</Card>
	);
}
