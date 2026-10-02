import { __, sprintf } from '@wordpress/i18n';
import { applyFilters } from '@wordpress/hooks';
import { useState } from '@wordpress/element';
import { useApi, send } from '../ui/lib/hooks';
import {
	Card,
	PageHead,
	Button,
	Empty,
	ErrorNotice,
	Loading,
} from '../ui/components/ui';
import { StackedColumns, BarList } from '../ui/components/charts';
import { Donut, KeyValues, ProBadge } from '../ui/components/kit';
import Icon from '../ui/components/Icon';
import { Stat } from '../components/common';

const boot = window.hdhBoot || {};

/**
 * Hours as "45 min", "3.5 h" or "2.1 days".
 *
 * @param {number|null} h Hours.
 * @return {string} Label.
 */
export function hoursText( h ) {
	if ( h === null || h === undefined ) {
		return '—';
	}
	if ( h < 1 ) {
		return sprintf(
			/* translators: %d: minutes */
			__( '%d min', 'helpdesk-hero-hub' ),
			Math.max( 1, Math.round( h * 60 ) )
		);
	}
	if ( h < 48 ) {
		return sprintf(
			/* translators: %s: hours */
			__( '%s h', 'helpdesk-hero-hub' ),
			Math.round( h * 10 ) / 10
		);
	}
	return sprintf(
		/* translators: %s: days */
		__( '%s days', 'helpdesk-hero-hub' ),
		Math.round( ( h / 24 ) * 10 ) / 10
	);
}

const LEVEL_COLOR = {
	critical: 'var(--hdh-critical)',
	warning: 'var(--hdh-warning)',
};

export function FeedbackInvite() {
	const [ open, setOpen ] = useState( !! boot.feedback );
	const ageDays = ( Date.now() / 1000 - ( boot.installed || 0 ) ) / 86400;
	if ( ! open || ageDays < 2 ) {
		return null;
	}
	const dismiss = () => {
		setOpen( false );
		send( 'admin/feedback', 'POST' );
	};
	return (
		<div
			className="hdh-banner"
			role="region"
			aria-label={ __( 'Feedback', 'helpdesk-hero-hub' ) }
		>
			<span className="hdh-banner__icon">
				<Icon name="message" />
			</span>
			<div className="hdh-banner__text">
				<strong>
					{ __(
						'How is the hub working for you?',
						'helpdesk-hero-hub'
					) }
				</strong>{ ' ' }
				{ __(
					'Tell us what matters most when you support customers in WordPress. It decides what we build next.',
					'helpdesk-hero-hub'
				) }
			</div>
			<a className="hdh-btn is-primary is-sm" href="#/feedback">
				{ __( 'Share feedback', 'helpdesk-hero-hub' ) }
			</a>
			<Button size="sm" variant="ghost" onClick={ dismiss }>
				{ __( 'Not now', 'helpdesk-hero-hub' ) }
			</Button>
		</div>
	);
}

export default function Overview() {
	const { data, error, reload } = useApi( 'admin/stats' );
	if ( error && ! data ) {
		return <ErrorNotice error={ error } onRetry={ reload } />;
	}
	if ( ! data ) {
		return <Loading />;
	}
	const { now, totals, series, categories, issues } = data;
	const empty = ! now.sites && ! totals.tickets;

	return (
		<>
			<PageHead
				title={ __( 'Overview', 'helpdesk-hero-hub' ) }
				lede={ __(
					'The last 30 days across all connected sites.',
					'helpdesk-hero-hub'
				) }
			>
				{ ! boot.pro && (
					<a
						className="hdh-btn is-ghost is-sm"
						href={ boot.proUrl }
						target="_blank"
						rel="noopener noreferrer"
					>
						<Icon name="chart" size={ 15 } />
						{ __( 'Advanced analytics', 'helpdesk-hero-hub' ) }
						<ProBadge />
					</a>
				) }
			</PageHead>

			<FeedbackInvite />

			<div className="hdh-kpis is-4">
				<Stat
					icon="inbox"
					label={ __( 'Open', 'helpdesk-hero-hub' ) }
					value={ now.open }
				/>
				<Stat
					icon="clock"
					label={ __( 'Waiting on customer', 'helpdesk-hero-hub' ) }
					value={ now.pending }
				/>
				<Stat
					icon="key"
					label={ __( 'With site access', 'helpdesk-hero-hub' ) }
					value={ now.access }
				/>
				<Stat
					icon="globe"
					label={ __( 'Connected sites', 'helpdesk-hero-hub' ) }
					value={ now.sites }
				/>
			</div>

			{ empty ? (
				<Card>
					<Empty
						title={ __(
							'Nothing to show yet',
							'helpdesk-hero-hub'
						) }
						text={ __(
							'Connect your first customer site and its tickets will show up here.',
							'helpdesk-hero-hub'
						) }
					>
						<div
							className="hdh-row"
							style={ { justifyContent: 'center' } }
						>
							<a className="hdh-btn is-primary" href="#/sites">
								<Icon name="plus" size={ 15 } />
								{ __( 'Connect a site', 'helpdesk-hero-hub' ) }
							</a>
							{ boot.canManage && (
								<a className="hdh-btn" href="#/welcome">
									{ __(
										'Open the setup guide',
										'helpdesk-hero-hub'
									) }
								</a>
							) }
						</div>
					</Empty>
				</Card>
			) : (
				<div className="hdh-grid">
					<Card
						className="hdh-span-8"
						title={ __( 'Tickets', 'helpdesk-hero-hub' ) }
						sub={ __(
							'Opened and closed per day',
							'helpdesk-hero-hub'
						) }
					>
						<StackedColumns
							dates={ series.dates }
							series={ [
								{
									id: 'opened',
									label: __( 'Opened', 'helpdesk-hero-hub' ),
									values: series.opened,
									color: 'var(--hdh-s1)',
								},
								{
									id: 'closed',
									label: __( 'Closed', 'helpdesk-hero-hub' ),
									values: series.closed,
									color: 'var(--hdh-s3)',
								},
							] }
							format={ ( v ) => String( Math.round( v ) ) }
							height={ 220 }
							title={ __(
								'Tickets opened and closed per day',
								'helpdesk-hero-hub'
							) }
						/>
					</Card>
					<Card
						className="hdh-span-4"
						title={ __( 'Categories', 'helpdesk-hero-hub' ) }
					>
						{ categories.length ? (
							<Donut
								items={ categories }
								title={ __(
									'Tickets by category',
									'helpdesk-hero-hub'
								) }
							/>
						) : (
							<p className="hdh-muted">
								{ __(
									'No tickets in this period.',
									'helpdesk-hero-hub'
								) }
							</p>
						) }
					</Card>
					<Card
						className="hdh-span-7"
						title={ __( 'Recurring issues', 'helpdesk-hero-hub' ) }
						sub={ __(
							'Health check findings on the most tickets: candidates for a help article, a fix, or a word with a plugin vendor.',
							'helpdesk-hero-hub'
						) }
					>
						{ issues.length ? (
							<BarList
								items={ issues.map( ( i ) => ( {
									id: i.label,
									label: i.label,
									value: i.value,
									color:
										LEVEL_COLOR[ i.level ] ||
										'var(--hdh-s1)',
								} ) ) }
								format={ ( v ) =>
									sprintf(
										/* translators: %d: number of tickets */
										__( '%d tickets', 'helpdesk-hero-hub' ),
										v
									)
								}
							/>
						) : (
							<p className="hdh-muted">
								{ __(
									'No repeated problems found.',
									'helpdesk-hero-hub'
								) }
							</p>
						) }
					</Card>
					<Card
						className="hdh-span-5"
						title={ __( 'Response', 'helpdesk-hero-hub' ) }
						sub={ __(
							'Medians over tickets opened in this period',
							'helpdesk-hero-hub'
						) }
					>
						<KeyValues
							rows={ [
								[
									__( 'Tickets', 'helpdesk-hero-hub' ),
									totals.tickets,
								],
								[
									__( 'Closed', 'helpdesk-hero-hub' ),
									totals.closed,
								],
								[
									__( 'First reply', 'helpdesk-hero-hub' ),
									hoursText( totals.first_response ),
								],
								[
									__( 'Time to close', 'helpdesk-hero-hub' ),
									hoursText( totals.resolution ),
								],
							] }
						/>
					</Card>
					{ applyFilters( 'helpdeskHeroHub.overviewPanels', [] ).map(
						( Panel, i ) => (
							<Panel key={ i } data={ data } />
						)
					) }
					{ ! boot.pro && (
						<Card className="hdh-span-12 hdh-upsell">
							<div
								className="hdh-row"
								style={ { justifyContent: 'space-between' } }
							>
								<div>
									<strong>
										{ __(
											'Advanced analytics',
											'helpdesk-hero-hub'
										) }
										<ProBadge />
									</strong>
									<p
										className="hdh-muted"
										style={ { marginTop: 4 } }
									>
										{ __(
											'Per-site statistics, any date range, tags and priorities, customer ratings and reviews, busiest sites, and PDF reports for clients.',
											'helpdesk-hero-hub'
										) }
									</p>
								</div>
								<a
									className="hdh-btn"
									href={ boot.proUrl }
									target="_blank"
									rel="noopener noreferrer"
								>
									{ __( 'About Pro', 'helpdesk-hero-hub' ) }
								</a>
							</div>
						</Card>
					) }
				</div>
			) }
		</>
	);
}
