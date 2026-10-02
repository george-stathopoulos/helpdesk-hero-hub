import { __ } from '@wordpress/i18n';
import { Card } from '../ui/components/ui';
import Icon from '../ui/components/Icon';

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

export function Stat( { icon, label, value, foot } ) {
	return (
		<Card bodyClass={ null }>
			<div className="hdh-stat">
				<div className="hdh-stat__label">
					<Icon name={ icon } size={ 14 } />
					{ label }
				</div>
				<div className="hdh-stat__value">{ value }</div>
				{ foot && (
					<div className="hdh-stat__foot hdh-muted">{ foot }</div>
				) }
			</div>
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
