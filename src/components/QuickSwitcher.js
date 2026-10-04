import { __, sprintf } from '@wordpress/i18n';
import { useState, useEffect, useRef, useMemo } from '@wordpress/element';
import { addQueryArgs } from '@wordpress/url';
import { send } from '../ui/lib/hooks';
import Icon from '../ui/components/Icon';

const isMac =
	typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test( navigator.platform || '' );

/**
 * Whether a key press happened while typing in a field.
 *
 * @param {Event} e Event.
 * @return {boolean} Typing.
 */
function typing( e ) {
	const t = e.target;
	return (
		t &&
		( t.isContentEditable || [ 'INPUT', 'TEXTAREA', 'SELECT' ].includes( t.tagName ) )
	);
}

/**
 * Ctrl/⌘+K: jump to any page, settings section, site or ticket. Also "g" shortcuts:
 * g i Inbox, g s Sites, g r Reports, g b Time & billing, g , Settings; "?" lists them.
 *
 * @param {Object}   props        Props.
 * @param {Array}    props.routes Menu pages ({ id, label, icon }).
 * @param {Function} props.go     Navigate.
 * @return {JSX.Element|null} Dialog.
 */
export default function QuickSwitcher( { routes, go } ) {
	const [ open, setOpen ] = useState( false );
	const [ q, setQ ] = useState( '' );
	const [ index, setIndex ] = useState( 0 );
	const [ sites, setSites ] = useState( null );
	const [ tickets, setTickets ] = useState( [] );
	const input = useRef( null );
	const pending = useRef( '' );

	const shortcuts = useMemo(
		() =>
			[
				[ 'i', 'inbox' ],
				[ 's', 'sites' ],
				[ 'r', 'reports' ],
				[ 'b', 'time' ],
				[ ',', 'settings' ],
			].filter( ( [ , id ] ) => routes.some( ( r ) => r.id === id ) ),
		[ routes ]
	);

	useEffect( () => {
		const onKey = ( e ) => {
			if ( ( e.metaKey || e.ctrlKey ) && e.key.toLowerCase() === 'k' ) {
				// On hub pages this replaces WordPress's own command palette.
				e.preventDefault();
				e.stopImmediatePropagation();
				setOpen( ( o ) => ! o );
				return;
			}
			if ( typing( e ) || e.metaKey || e.ctrlKey || e.altKey ) {
				return;
			}
			if ( e.key === '?' ) {
				setQ( '?' );
				setOpen( true );
				return;
			}
			if ( pending.current === 'g' ) {
				pending.current = '';
				const hit = shortcuts.find( ( [ k ] ) => k === e.key );
				if ( hit ) {
					e.preventDefault();
					go( hit[ 1 ] );
				}
				return;
			}
			if ( e.key === 'g' ) {
				pending.current = 'g';
				setTimeout( () => ( pending.current = '' ), 1200 );
			}
		};
		window.addEventListener( 'keydown', onKey, true );
		return () => window.removeEventListener( 'keydown', onKey, true );
	}, [ go, shortcuts ] );

	useEffect( () => {
		if ( ! open ) {
			setQ( '' );
			setIndex( 0 );
			return;
		}
		setTimeout( () => input.current && input.current.focus(), 0 );
		if ( sites === null ) {
			send( 'admin/sites' )
				.then( ( r ) => setSites( r.sites || [] ) )
				.catch( () => setSites( [] ) );
		}
	}, [ open ] ); // eslint-disable-line react-hooks/exhaustive-deps

	// Tickets: search as you type (a number finds that ticket).
	useEffect( () => {
		const term = q.trim();
		if ( ! open || term.length < 2 || term === '?' ) {
			setTickets( [] );
			return undefined;
		}
		const timer = setTimeout( () => {
			send( addQueryArgs( 'admin/tickets', { search: term, status: 'all', per_page: 8 } ) )
				.then( ( r ) => setTickets( r.tickets || [] ) )
				.catch( () => setTickets( [] ) );
		}, 200 );
		return () => clearTimeout( timer );
	}, [ q, open ] );

	const items = useMemo( () => {
		const term = q.trim().toLowerCase();
		if ( term === '?' ) {
			return shortcuts.map( ( [ k, id ] ) => {
				const r = routes.find( ( x ) => x.id === id );
				return {
					key: `sc-${ id }`,
					icon: r.icon,
					label: r.label,
					hint: `g ${ k }`,
					to: id,
				};
			} );
		}
		const match = ( text ) => ! term || String( text ).toLowerCase().includes( term );
		const out = [];
		if ( match( __( 'Connect a site', 'helpdesk-hero-hub' ) ) || match( 'add site' ) || match( 'invite' ) ) {
			out.push( {
				key: 'action-connect',
				icon: 'plus',
				label: __( 'Connect a site', 'helpdesk-hero-hub' ),
				hint: __( 'Action', 'helpdesk-hero-hub' ),
				to: 'connect',
			} );
		}
		routes
			.filter( ( r ) => match( r.label ) )
			.forEach( ( r ) =>
				out.push( {
					key: `page-${ r.id }`,
					icon: r.icon,
					label: r.label,
					hint: r.inSettings
						? __( 'Settings', 'helpdesk-hero-hub' )
						: __( 'Page', 'helpdesk-hero-hub' ),
					to: r.inSettings ? `settings/${ r.id }` : r.id,
				} )
			);
		( sites || [] )
			.filter( ( s ) => term && ( match( s.name ) || match( s.url ) ) )
			.slice( 0, 6 )
			.forEach( ( s ) =>
				out.push( {
					key: `site-${ s.id }`,
					icon: 'globe',
					label: s.name,
					hint: s.url.replace( /^https?:\/\//, '' ),
					to: `sites/${ s.id }`,
				} )
			);
		tickets.forEach( ( t ) =>
			out.push( {
				key: `ticket-${ t.id }`,
				icon: 'inbox',
				label: `#${ t.id } ${ t.subject }`,
				hint: t.site_name || '',
				to: `ticket/${ t.id }`,
			} )
		);
		return out;
	}, [ q, routes, sites, tickets, shortcuts ] );

	useEffect( () => setIndex( 0 ), [ q ] );

	if ( ! open ) {
		return null;
	}
	const pick = ( item ) => {
		setOpen( false );
		go( item.to );
	};
	const onKeyDown = ( e ) => {
		if ( e.key === 'Escape' ) {
			setOpen( false );
		} else if ( e.key === 'ArrowDown' ) {
			e.preventDefault();
			setIndex( ( i ) => Math.min( items.length - 1, i + 1 ) );
		} else if ( e.key === 'ArrowUp' ) {
			e.preventDefault();
			setIndex( ( i ) => Math.max( 0, i - 1 ) );
		} else if ( e.key === 'Enter' && items[ index ] ) {
			e.preventDefault();
			pick( items[ index ] );
		}
	};
	return (
		<>
			<div className="hdh-scrim" onClick={ () => setOpen( false ) } aria-hidden="true" />
			<div
				className="hdh-switcher"
				role="dialog"
				aria-modal="true"
				aria-label={ __( 'Go to', 'helpdesk-hero-hub' ) }
			>
				<div className="hdh-switcher__search">
					<Icon name="filter" size={ 16 } />
					<input
						ref={ input }
						// eslint-disable-next-line jsx-a11y/no-autofocus
						autoFocus
						value={ q }
						onChange={ ( e ) => setQ( e.target.value ) }
						onKeyDown={ onKeyDown }
						placeholder={ __(
							'Go to a page, site or ticket (type ? for shortcuts)',
							'helpdesk-hero-hub'
						) }
						aria-label={ __( 'Search', 'helpdesk-hero-hub' ) }
						aria-activedescendant={
							items[ index ] ? `hdh-sw-${ items[ index ].key }` : undefined
						}
						aria-controls="hdh-switcher-list"
					/>
					<kbd>Esc</kbd>
				</div>
				<ul className="hdh-switcher__list" id="hdh-switcher-list" role="listbox">
					{ items.map( ( item, i ) => (
						<li
							key={ item.key }
							id={ `hdh-sw-${ item.key }` }
							role="option"
							aria-selected={ i === index }
							className={ `hdh-switcher__item ${ i === index ? 'is-active' : '' }` }
							onMouseEnter={ () => setIndex( i ) }
							onMouseDown={ ( e ) => {
								e.preventDefault();
								pick( item );
							} }
						>
							<Icon name={ item.icon || 'link' } size={ 15 } />
							<span className="hdh-switcher__label">{ item.label }</span>
							<span className="hdh-switcher__hint">{ item.hint }</span>
						</li>
					) ) }
					{ ! items.length && (
						<li className="hdh-switcher__empty">
							{ __( 'Nothing matches.', 'helpdesk-hero-hub' ) }
						</li>
					) }
				</ul>
				<div className="hdh-switcher__foot">
					{ sprintf(
						/* translators: %s: keyboard shortcut */
						__( '%s opens this anywhere in the hub · ↑↓ to move · Enter to open', 'helpdesk-hero-hub' ),
						isMac ? '⌘K' : 'Ctrl+K'
					) }
				</div>
			</div>
		</>
	);
}
