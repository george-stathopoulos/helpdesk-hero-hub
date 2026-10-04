import { __, sprintf } from '@wordpress/i18n';
import { Card } from '../ui/components/ui';
import Icon from '../ui/components/Icon';
import { ago, when } from '../ui/lib/time';

export const STATUS_LABELS = {
	open: __( 'Open', 'helpdesk-hero-hub' ),
	pending: __( 'Waiting on customer', 'helpdesk-hero-hub' ),
	closed: __( 'Closed', 'helpdesk-hero-hub' ),
};

export const PRIORITY_LABELS = {
	low: __( 'Low', 'helpdesk-hero-hub' ),
	normal: __( 'Normal', 'helpdesk-hero-hub' ),
	high: __( 'High', 'helpdesk-hero-hub' ),
	urgent: __( 'Urgent', 'helpdesk-hero-hub' ),
};

export function Stat( { icon, label, value, foot, onClick } ) {
	const inner = (
		<>
				<div className="hdh-stat__label">
					<Icon name={ icon } size={ 14 } />
					{ label }
				</div>
				<div className="hdh-stat__value">{ value }</div>
				{ foot && (
					<div className="hdh-stat__foot hdh-muted">{ foot }</div>
				) }
		</>
	);
	return (
		<Card bodyClass={ null } className={ onClick ? 'is-clickable' : '' }>
			{ onClick ? (
				<button type="button" className="hdh-stat hdh-stat--button" onClick={ onClick }>
					{ inner }
				</button>
			) : (
				<div className="hdh-stat">{ inner }</div>
			) }
		</Card>
	);
}

/**
 * Error message from an apiFetch rejection.
 *
 * @param {Object} e Error.
 * @return {string} Message.
 */
export function message( e ) {
	return (
		( e && e.message ) || __( 'Something went wrong.', 'helpdesk-hero-hub' )
	);
}

/**
 * A site's customer tier ("VIP"…).
 *
 * @param {Object} props      Props.
 * @param {Object} props.tier { name, color, description } or null.
 * @return {JSX.Element|null} Badge.
 */
export function TierBadge( { tier } ) {
	if ( ! tier ) {
		return null;
	}
	return (
		<span
			className="hdh-tagpill is-tier"
			style={ { '--tag': tier.color } }
			title={ tier.description || undefined }
		>
			{ tier.name }
		</span>
	);
}

/**
 * Where a ticket stands against its SLA.
 *
 * @param {Object}  props         Props.
 * @param {Object}  props.sla     { stage, due, state } or null.
 * @param {boolean} props.compact Short text for lists.
 * @return {JSX.Element|null} Badge.
 */
export function SlaBadge( { sla, compact = false } ) {
	if ( ! sla ) {
		return null;
	}
	const stage =
		sla.stage === 'respond'
			? __( 'First reply', 'helpdesk-hero-hub' )
			: __( 'Resolution', 'helpdesk-hero-hub' );
	let text;
	switch ( sla.state ) {
		case 'breached':
			text = sprintf(
				/* translators: 1: "First reply" or "Resolution", 2: relative time */
				__( '%1$s overdue (%2$s)', 'helpdesk-hero-hub' ),
				stage,
				ago( sla.due )
			);
			break;
		case 'met':
			text = __( 'SLA met', 'helpdesk-hero-hub' );
			break;
		case 'paused':
			text = __( 'SLA paused: waiting on customer', 'helpdesk-hero-hub' );
			break;
		default:
			text = sprintf(
				/* translators: 1: "First reply" or "Resolution", 2: relative time */
				__( '%1$s due %2$s', 'helpdesk-hero-hub' ),
				stage,
				ago( sla.due )
			);
	}
	if ( compact && sla.state === 'met' ) {
		return null;
	}
	return (
		<span
			className={ `hdh-sla is-${ sla.state }` }
			title={ sla.due ? when( sla.due ) : undefined }
		>
			<Icon name="clock" size={ 12 } />
			{ text }
		</span>
	);
}
