import { __, sprintf } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import { addQueryArgs } from '@wordpress/url';
import { useApi } from '../ui/lib/hooks';
import { ago, when } from '../ui/lib/time';
import {
	Card,
	PageHead,
	Segmented,
	Empty,
	ErrorNotice,
	Loading,
	Pill,
} from '../ui/components/ui';
import {
	TicketStatus,
	AccessState,
	TagPills,
	Stars,
} from '../ui/components/kit';
import Icon from '../ui/components/Icon';
import { Stat, STATUS_LABELS, PRIORITY_LABELS } from '../components/common';

const boot = window.hdhBoot || {};

export default function Inbox( { go } ) {
	const [ status, setStatus ] = useState( 'active' );
	const [ search, setSearch ] = useState( '' );
	const [ query, setQuery ] = useState( '' );
	const site = new URLSearchParams(
		window.location.hash.split( '?' )[ 1 ] || ''
	).get( 'site' );

	useEffect( () => {
		const t = window.setTimeout( () => setQuery( search ), 300 );
		return () => window.clearTimeout( t );
	}, [ search ] );

	const { data, error, loading, reload } = useApi(
		addQueryArgs( 'admin/tickets', {
			status,
			search: query || undefined,
			site: site || undefined,
		} )
	);

	if ( error && ! data ) {
		return <ErrorNotice error={ error } onRetry={ reload } />;
	}
	if ( ! data ) {
		return <Loading />;
	}
	const { counts, tickets } = data;

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

			<div className="hdh-kpis is-4">
				<Stat
					icon="inbox"
					label={ __( 'Open', 'helpdesk-hero-hub' ) }
					value={ counts.open }
				/>
				<Stat
					icon="clock"
					label={ __( 'Waiting on customer', 'helpdesk-hero-hub' ) }
					value={ counts.pending }
				/>
				<Stat
					icon="key"
					label={ __( 'With site access', 'helpdesk-hero-hub' ) }
					value={ counts.access }
				/>
				<Stat
					icon="globe"
					label={ __( 'Connected sites', 'helpdesk-hero-hub' ) }
					value={ counts.sites }
				/>
			</div>

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
							<a className="hdh-step" href="#/sites">
								<span className="hdh-step__n">3</span>
								<strong className="hdh-step__title">
									{ __(
										'Connect a customer site',
										'helpdesk-hero-hub'
									) }
								</strong>
								<span className="hdh-step__text">
									{ __(
										'Create a connection code and send it to your customer.',
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
									'Search subject, customer or site',
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
								style={ { minWidth: 240 } }
							/>
							<Segmented
								label={ __( 'Status', 'helpdesk-hero-hub' ) }
								value={ status }
								onChange={ setStatus }
								options={ [
									{
										value: 'active',
										label: __(
											'Active',
											'helpdesk-hero-hub'
										),
									},
									{
										value: 'open',
										label: __(
											'Open',
											'helpdesk-hero-hub'
										),
									},
									{
										value: 'pending',
										label: __(
											'Waiting',
											'helpdesk-hero-hub'
										),
									},
									{
										value: 'closed',
										label: __(
											'Closed',
											'helpdesk-hero-hub'
										),
									},
									{
										value: 'all',
										label: __( 'All', 'helpdesk-hero-hub' ),
									},
								] }
							/>
						</div>
					}
					bodyClass={ null }
				>
					{ site && (
						<div
							className="hdh-card__body"
							style={ { paddingBottom: 0 } }
						>
							<Pill tone="accent" icon="globe">
								{ __(
									'Showing one site',
									'helpdesk-hero-hub'
								) }
							</Pill>{ ' ' }
							<a href="#/inbox">
								{ __( 'Show all', 'helpdesk-hero-hub' ) }
							</a>
						</div>
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
												<div className="hdh-muted">
													#{ t.id } · { t.customer } ·{ ' ' }
													{ PRIORITY_LABELS[
														t.priority
													] || t.priority }
													{ t.reference
														? ` · ${ t.reference }`
														: '' }
												</div>
												{ ( t.tags.length > 0 ||
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
												{ t.site_name }
												<div className="hdh-muted">
													{ t.site_host }
												</div>
											</td>
											<td>
												<TicketStatus
													status={ t.status }
													labels={ STATUS_LABELS }
												/>
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
						</div>
					) }
				</Card>
			) }
		</>
	);
}
