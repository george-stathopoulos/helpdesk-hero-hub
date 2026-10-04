import { __ } from '@wordpress/i18n';
import Icon from '../ui/components/Icon';

const boot = window.hdhBoot || {};

/**
 * "My work": one-click shortcuts at the top of the Inbox, with live counts. Each sets the
 * Inbox filters; clicking the active one again clears it.
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.counts   Counts from the tickets endpoint (`mywork`) plus `access`.
 * @param {Object}   props.filters  Current filters.
 * @param {Function} props.onApply  ( filters ) => void.
 * @return {JSX.Element|null} Bar.
 */
export default function MyWork( { counts, filters, onApply } ) {
	if ( ! counts ) {
		return null;
	}
	const items = [
		{
			id: 'mine',
			icon: 'user',
			label: __( 'Assigned to me', 'helpdesk-hero-hub' ),
			filters: { status: 'active', assignee: 'me' },
		},
		{
			id: 'unread',
			icon: 'message',
			label: __( 'Customer replied', 'helpdesk-hero-hub' ),
			filters: { status: 'active', unread: '1' },
			alert: true,
		},
		boot.sla && {
			id: 'overdue',
			icon: 'alert',
			label: __( 'SLA overdue', 'helpdesk-hero-hub' ),
			filters: { status: 'active', sla: 'overdue' },
			alert: true,
		},
		boot.sla && {
			id: 'soon',
			icon: 'clock',
			label: __( 'SLA due within the hour', 'helpdesk-hero-hub' ),
			filters: { status: 'active', sla: 'soon' },
			alert: true,
		},
		{
			id: 'access',
			icon: 'key',
			label: __( 'With site access', 'helpdesk-hero-hub' ),
			filters: { status: 'active', access: '1' },
		},
		{
			id: 'ending',
			icon: 'timer',
			label: __( 'Access ending today', 'helpdesk-hero-hub' ),
			filters: { status: 'active', access: 'ending' },
			alert: true,
		},
		{
			id: 'bookmarked',
			icon: 'bookmark',
			label: __( 'Bookmarked', 'helpdesk-hero-hub' ),
			filters: { status: 'active', bookmarked: '1' },
		},
		{
			id: 'unassigned',
			icon: 'users',
			label: __( 'Unassigned', 'helpdesk-hero-hub' ),
			filters: { status: 'active', assignee: '0' },
		},
	].filter( Boolean );
	const same = ( a, b ) =>
		JSON.stringify( Object.entries( a ).filter( ( [ , v ] ) => v !== '' ).sort() ) ===
		JSON.stringify( Object.entries( b ).filter( ( [ , v ] ) => v !== '' ).sort() );
	return (
		<div className="hdh-mywork" role="toolbar" aria-label={ __( 'My work', 'helpdesk-hero-hub' ) }>
			<span className="hdh-mywork__title">{ __( 'My work', 'helpdesk-hero-hub' ) }</span>
			{ items.map( ( item ) => {
				const n = counts[ item.id ] || 0;
				const active = same( filters, item.filters );
				return (
					<button
						key={ item.id }
						type="button"
						aria-pressed={ active }
						className={ `hdh-mywork__item ${ active ? 'is-active' : '' } ${
							item.alert && n > 0 ? 'is-alert' : ''
						} ${ n ? '' : 'is-zero' }` }
						onClick={ () => onApply( active ? { status: 'active' } : item.filters ) }
					>
						<Icon name={ item.icon } size={ 14 } />
						{ item.label }
						<span className="hdh-mywork__count">{ n }</span>
					</button>
				);
			} ) }
		</div>
	);
}
