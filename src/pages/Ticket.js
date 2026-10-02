import { __, sprintf } from '@wordpress/i18n';
import { applyFilters } from '@wordpress/hooks';
import { useState, useMemo } from '@wordpress/element';
import { useApi, send } from '../ui/lib/hooks';
import { ago, when } from '../ui/lib/time';
import {
	Card,
	Button,
	Segmented,
	ErrorNotice,
	Loading,
	useToast,
} from '../ui/components/ui';
import {
	Thread,
	FlagList,
	CodeBlock,
	TicketStatus,
	AccessState,
	KeyValues,
	TextArea,
	TextField,
	SelectField,
	TagPills,
	Stars,
} from '../ui/components/kit';
import Icon from '../ui/components/Icon';
import { STATUS_LABELS, PRIORITY_LABELS, message } from '../components/common';
import NoticeCard from '../components/NoticeCard';

const boot = window.hdhBoot || {};

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

	if ( error && ! ticket ) {
		return <ErrorNotice error={ error } onRetry={ reload } />;
	}
	if ( ! ticket ) {
		return <Loading />;
	}

	const thread = ticket.thread.map( ( m ) => ( {
		...m,
		from: { support: 'support', customer: 'you' }[ m.kind ] || 'event',
	} ) );

	return (
		<>
			<a className="hdh-back" href="#/inbox">
				<Icon name="arrowLeft" size={ 14 } />
				{ __( 'Inbox', 'helpdesk-hero-hub' ) }
			</a>
			<div className="hdh-pagehead">
				<div>
					<h1 className="hdh-pagehead__title">{ ticket.subject }</h1>
					<p className="hdh-pagehead__lede hdh-row">
						<TicketStatus
							status={ ticket.status }
							labels={ STATUS_LABELS }
						/>
						<span>#{ ticket.id }</span>
						<span>·</span>
						<span>{ ticket.site ? ticket.site.name : '' }</span>
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

			<div className="hdh-split">
				<div className="hdh-split__main">
					<Card title={ __( 'Conversation', 'helpdesk-hero-hub' ) }>
						<Thread items={ thread } />
					</Card>
					<Reply
						ticket={ ticket }
						busy={ busy === 'reply' }
						onSend={ ( body, done ) =>
							act( 'reply', 'reply', { body }, () => {
								toast(
									__( 'Reply sent', 'helpdesk-hero-hub' )
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

				<aside className="hdh-split__side">
					{ applyFilters( 'helpdeskHeroHub.ticketPanels', [] ).map(
						( Panel, i ) => (
							<Panel key={ i } ticket={ ticket } />
						)
					) }
					<AccessCard ticket={ ticket } act={ act } busy={ busy } />
					<Card title={ __( 'Status', 'helpdesk-hero-hub' ) }>
						<Segmented
							label={ __( 'Status', 'helpdesk-hero-hub' ) }
							value={ ticket.status }
							onChange={ ( status ) =>
								act( 'status', 'status', { status } )
							}
							options={ Object.keys( STATUS_LABELS ).map(
								( k ) => ( {
									value: k,
									label: STATUS_LABELS[ k ],
								} )
							) }
						/>
					</Card>
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
								[
									__( 'Priority', 'helpdesk-hero-hub' ),
									PRIORITY_LABELS[ ticket.priority ] ||
										ticket.priority,
								],
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

function Reply( { ticket, onSend, busy } ) {
	const [ body, setBody ] = useState( '' );
	const tools = useMemo(
		() => applyFilters( 'helpdeskHeroHub.replyTools', [] ),
		[]
	);
	if ( boot.canSupport === false ) {
		return (
			<Card title={ __( 'Reply to the customer', 'helpdesk-hero-hub' ) }>
				<p className="hdh-muted">
					{ __(
						'Your team has chosen who replies to customers and logs in to their sites (Pro → Supporters). You can read tickets, but replies come from a supporter.',
						'helpdesk-hero-hub'
					) }
				</p>
			</Card>
		);
	}
	return (
		<Card
			title={ __( 'Reply to the customer', 'helpdesk-hero-hub' ) }
			sub={
				ticket.helpdesk
					? sprintf(
							/* translators: %s: help desk */
							__(
								'Sent through %s and shown in the customer’s dashboard.',
								'helpdesk-hero-hub'
							),
							ticket.helpdesk.label
					  )
					: __(
							'Shown in the customer’s dashboard; they also get an email.',
							'helpdesk-hero-hub'
					  )
			}
		>
			<div className="hdh-stack">
				<TextArea
					label={ __( 'Message', 'helpdesk-hero-hub' ) }
					value={ body }
					onChange={ setBody }
					rows={ 6 }
				/>
				{ tools.map( ( Tool, i ) => (
					<Tool key={ i } ticket={ ticket } setBody={ setBody } />
				) ) }
				<div className="hdh-row">
					<Button
						variant="primary"
						icon="send"
						disabled={ busy || ! body.trim() }
						onClick={ () => onSend( body, () => setBody( '' ) ) }
					>
						{ busy
							? __( 'Sending…', 'helpdesk-hero-hub' )
							: __( 'Send reply', 'helpdesk-hero-hub' ) }
					</Button>
				</div>
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
						{ ! asking ? (
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
