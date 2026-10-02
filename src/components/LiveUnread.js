import { __, _n, sprintf } from '@wordpress/i18n';
import { useEffect, useRef } from '@wordpress/element';
import { send } from '../ui/lib/hooks';
import { useToast } from '../ui/components/ui';

const EVERY = 60 * 1000;

/**
 * Keeps an eye on new tickets and customer replies while the hub is open: updates the count
 * (Inbox tab, WordPress menu badge, page title) and says when something new arrives.
 *
 * @param {Object}   props         Props.
 * @param {Function} props.onCount Called with the number of unread tickets.
 * @param {string}   props.route   Current route (rechecked when it changes, e.g. after opening a ticket).
 * @return {null} Nothing rendered.
 */
export default function LiveUnread( { onCount, route } ) {
	const toast = useToast();
	const newest = useRef( null );
	const title = useRef( window.document.title.replace( /^\(\d+\)\s*/, '' ) );

	useEffect( () => {
		let stopped = false;
		const check = () =>
			send( 'admin/unread', 'GET' )
				.then( ( u ) => {
					if ( stopped ) {
						return;
					}
					onCount( u.count );
					badge( u.count );
					window.document.title =
						( u.count ? `(${ u.count }) ` : '' ) + title.current;
					const top = u.latest[ 0 ];
					// Announce only what arrived while the hub was open.
					if (
						top &&
						newest.current !== null &&
						top.at > newest.current
					) {
						toast(
							'reply' === top.kind
								? sprintf(
										/* translators: 1: customer site, 2: ticket subject */
										__(
											'%1$s replied to “%2$s”',
											'helpdesk-hero-hub'
										),
										top.site,
										top.subject
								  )
								: sprintf(
										/* translators: 1: customer site, 2: ticket subject */
										__(
											'New ticket from %1$s: “%2$s”',
											'helpdesk-hero-hub'
										),
										top.site,
										top.subject
								  )
						);
					}
					newest.current = top ? top.at : newest.current || '';
				} )
				.catch( () => {} );
		check();
		const timer = window.setInterval( check, EVERY );
		return () => {
			stopped = true;
			window.clearInterval( timer );
		};
	}, [ route ] ); // eslint-disable-line react-hooks/exhaustive-deps

	return null;
}

/**
 * Keep the count bubble on WordPress's "Support Hub" menu item in sync.
 *
 * @param {number} count Unread tickets.
 */
function badge( count ) {
	const item = window.document.querySelector(
		'#toplevel_page_helpdesk-hero-hub .wp-menu-name'
	);
	if ( ! item ) {
		return;
	}
	let bubble = item.querySelector( '.awaiting-mod' );
	if ( ! count ) {
		bubble?.remove();
		return;
	}
	if ( ! bubble ) {
		bubble = window.document.createElement( 'span' );
		bubble.className = 'awaiting-mod';
		bubble.innerHTML = '<span class="pending-count"></span>';
		item.append( ' ', bubble );
	}
	bubble.className = `awaiting-mod count-${ count }`;
	bubble.querySelector( '.pending-count' ).textContent = String( count );
	bubble.setAttribute(
		'aria-label',
		sprintf(
			/* translators: %d: number of tickets */
			_n(
				'%d ticket needs a look',
				'%d tickets need a look',
				count,
				'helpdesk-hero-hub'
			),
			count
		)
	);
}
