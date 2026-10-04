import { __, sprintf } from '@wordpress/i18n';
import { useState, useEffect, useRef } from '@wordpress/element';
import { Button } from '../ui/components/ui';
import { PRIORITY_LABELS, STATUS_LABELS } from './common';

const boot = window.hdhBoot || {};

/**
 * Every Inbox filter (besides search and the status tabs): key => { label, options }.
 * Options are value => label. Lists that need data (team, tags, tiers) come in `lists`.
 *
 * @param {Object} lists { team, tags, tiers }.
 * @return {Object} Definitions.
 */
export function filterDefs( lists ) {
	const defs = {
		assignee: {
			label: __( 'Assigned to', 'helpdesk-hero-hub' ),
			options: {
				me: __( 'Me', 'helpdesk-hero-hub' ),
				0: __( 'Nobody', 'helpdesk-hero-hub' ),
				// Links that name you by ID (Reports › Team) still read "Me".
				...( boot.userId ? { [ String( boot.userId ) ]: __( 'Me', 'helpdesk-hero-hub' ) } : {} ),
				...Object.fromEntries(
					( lists.team || [] )
						.filter( ( u ) => u.id !== boot.userId )
						.map( ( u ) => [ String( u.id ), u.name ] )
				),
			},
		},
		priority: {
			label: __( 'Priority', 'helpdesk-hero-hub' ),
			options: PRIORITY_LABELS,
		},
		tag: {
			label: __( 'Tag', 'helpdesk-hero-hub' ),
			options: Object.fromEntries(
				( lists.tags || [] ).map( ( t ) => [ t.id, t.name ] )
			),
		},
		rating_max: {
			label: __( 'Rating', 'helpdesk-hero-hub' ),
			options: {
				1: __( '1 star', 'helpdesk-hero-hub' ),
				2: __( '2 stars or fewer', 'helpdesk-hero-hub' ),
				3: __( '3 stars or fewer', 'helpdesk-hero-hub' ),
				4: __( '4 stars or fewer', 'helpdesk-hero-hub' ),
			},
		},
		access: {
			label: __( 'Site access', 'helpdesk-hero-hub' ),
			options: {
				1: __( 'Access granted now', 'helpdesk-hero-hub' ),
				ending: __( 'Access ending within a day', 'helpdesk-hero-hub' ),
			},
		},
		bookmarked: {
			label: __( 'Bookmarks', 'helpdesk-hero-hub' ),
			options: { 1: __( 'My bookmarks', 'helpdesk-hero-hub' ) },
		},
		unread: {
			label: __( 'New activity', 'helpdesk-hero-hub' ),
			options: {
				1: __( 'New or customer replied', 'helpdesk-hero-hub' ),
			},
		},
	};
	if ( ( lists.tiers || [] ).length ) {
		defs.tier = {
			label: __( 'Customer tier', 'helpdesk-hero-hub' ),
			options: Object.fromEntries(
				lists.tiers.map( ( t ) => [ t.id, t.name ] )
			),
		};
	}
	if ( ( boot.services || [] ).length ) {
		defs.service = {
			label: __( 'Service', 'helpdesk-hero-hub' ),
			options: {
				...Object.fromEntries(
					boot.services.map( ( x ) => [ x.id, x.name ] )
				),
				none: __( 'No service set', 'helpdesk-hero-hub' ),
			},
		};
	}
	if ( ( boot.customStatuses || [] ).length ) {
		defs.custom_status = {
			label: __( 'Detailed status', 'helpdesk-hero-hub' ),
			options: Object.fromEntries(
				boot.customStatuses.map( ( c ) => [
					c.id,
					`${ STATUS_LABELS[ c.base ] } › ${ c.label }`,
				] )
			),
		};
	}
	if ( boot.sla ) {
		defs.sla = {
			label: __( 'SLA', 'helpdesk-hero-hub' ),
			options: {
				overdue: __( 'Overdue', 'helpdesk-hero-hub' ),
				soon: __( 'Due within the hour', 'helpdesk-hero-hub' ),
			},
		};
	}
	return defs;
}

/**
 * Chip text for an active filter.
 *
 * @param {Object} defs  Definitions.
 * @param {string} key   Filter.
 * @param {string} value Value.
 * @return {string} Text.
 */
function chipText( defs, key, value ) {
	if ( key === 'from' ) {
		/* translators: %s: date */
		return sprintf( __( 'From %s', 'helpdesk-hero-hub' ), value );
	}
	if ( key === 'to' ) {
		/* translators: %s: date */
		return sprintf( __( 'Until %s', 'helpdesk-hero-hub' ), value );
	}
	if ( key === 'site' ) {
		return __( 'One site', 'helpdesk-hero-hub' );
	}
	if ( key === 'category' ) {
		/* translators: %s: category */
		return sprintf( __( 'Category: %s', 'helpdesk-hero-hub' ), value === 'none' ? __( 'none', 'helpdesk-hero-hub' ) : value );
	}
	const def = defs[ key ];
	if ( ! def ) {
		return `${ key }: ${ value }`;
	}
	const label = def.options[ value ] || value;
	return [ 'access', 'unread', 'bookmarked' ].includes( key ) ? label : `${ def.label }: ${ label }`;
}

/**
 * Active filters as removable chips.
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.filters  Filters.
 * @param {Object}   props.defs     Definitions.
 * @param {Function} props.onRemove ( key ) => void.
 * @param {Function} props.onClear  Remove all.
 * @return {JSX.Element|null} Chips.
 */
export function FilterChips( { filters, defs, onRemove, onClear } ) {
	const keys = Object.keys( filters ).filter(
		( k ) => ! [ 'status', 'search' ].includes( k ) && filters[ k ] !== '' && filters[ k ] !== undefined
	);
	if ( ! keys.length ) {
		return null;
	}
	return (
		<div className="hdh-chips" role="group" aria-label={ __( 'Active filters', 'helpdesk-hero-hub' ) }>
			{ keys.map( ( k ) => (
				<span key={ k } className="hdh-filter-chip">
					{ chipText( defs, k, String( filters[ k ] ) ) }
					<button
						type="button"
						aria-label={ sprintf(
							/* translators: %s: filter */
							__( 'Remove filter: %s', 'helpdesk-hero-hub' ),
							chipText( defs, k, String( filters[ k ] ) )
						) }
						onClick={ () => onRemove( k ) }
					>
						×
					</button>
				</span>
			) ) }
			{ keys.length > 1 && (
				<button type="button" className="hdh-link-button" onClick={ onClear }>
					{ __( 'Clear all', 'helpdesk-hero-hub' ) }
				</button>
			) }
		</div>
	);
}

/**
 * The Filters button and its panel.
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.filters  Filters.
 * @param {Object}   props.defs     Definitions.
 * @param {Function} props.onChange ( key, value ) => void.
 * @param {boolean}  props.dates    Show the "opened from/until" fields (off where the page has its own).
 * @return {JSX.Element} Button and panel.
 */
export function FilterButton( { filters, defs, onChange, dates = true } ) {
	const [ open, setOpen ] = useState( false );
	const box = useRef( null );
	const count = Object.keys( filters ).filter(
		( k ) => ! [ 'status', 'search', 'site' ].includes( k ) && filters[ k ] !== '' && filters[ k ] !== undefined
	).length;
	useEffect( () => {
		if ( ! open ) {
			return undefined;
		}
		const close = ( e ) => {
			if ( box.current && ! box.current.contains( e.target ) ) {
				setOpen( false );
			}
		};
		const esc = ( e ) => e.key === 'Escape' && setOpen( false );
		document.addEventListener( 'mousedown', close );
		document.addEventListener( 'keydown', esc );
		return () => {
			document.removeEventListener( 'mousedown', close );
			document.removeEventListener( 'keydown', esc );
		};
	}, [ open ] );
	return (
		<div className="hdh-filter" ref={ box }>
			<Button
				size="sm"
				icon="filter"
				variant={ count ? 'primary' : '' }
				aria-expanded={ open }
				onClick={ () => setOpen( ! open ) }
			>
				{ count
					? sprintf(
							/* translators: %d: number of filters */
							__( 'Filters (%d)', 'helpdesk-hero-hub' ),
							count
					  )
					: __( 'Filters', 'helpdesk-hero-hub' ) }
			</Button>
			{ open && (
				<div className="hdh-filter__panel" role="dialog" aria-label={ __( 'Filters', 'helpdesk-hero-hub' ) }>
					{ Object.keys( defs ).map( ( k ) => (
						<label key={ k } className="hdh-filter__row">
							<span>{ defs[ k ].label }</span>
							<select
								className="hdh-input hdh-select"
								value={ filters[ k ] ?? '' }
								onChange={ ( e ) => onChange( k, e.target.value ) }
							>
								<option value="">{ __( 'Any', 'helpdesk-hero-hub' ) }</option>
								{ Object.keys( defs[ k ].options ).map( ( v ) => (
									<option key={ v } value={ v }>
										{ defs[ k ].options[ v ] }
									</option>
								) ) }
							</select>
						</label>
					) ) }
					{ dates && (
						<>
					<label className="hdh-filter__row">
						<span>{ __( 'Opened from', 'helpdesk-hero-hub' ) }</span>
						<input
							type="date"
							className="hdh-input"
							value={ filters.from || '' }
							onChange={ ( e ) => onChange( 'from', e.target.value ) }
						/>
					</label>
					<label className="hdh-filter__row">
						<span>{ __( 'Opened until', 'helpdesk-hero-hub' ) }</span>
						<input
							type="date"
							className="hdh-input"
							value={ filters.to || '' }
							onChange={ ( e ) => onChange( 'to', e.target.value ) }
						/>
					</label>
						</>
					) }
					<div className="hdh-row" style={ { justifyContent: 'flex-end' } }>
						<Button size="sm" variant="primary" onClick={ () => setOpen( false ) }>
							{ __( 'Done', 'helpdesk-hero-hub' ) }
						</Button>
					</div>
				</div>
			) }
		</div>
	);
}

/**
 * Status tabs with counts.
 *
 * @param {Object}   props          Props.
 * @param {string}   props.value    Status.
 * @param {Object}   props.counts   Counts per tab.
 * @param {Function} props.onChange ( status ) => void.
 * @return {JSX.Element} Tabs.
 */
export function StatusTabs( { value, counts, onChange } ) {
	const tabs = [
		[ 'active', __( 'Active', 'helpdesk-hero-hub' ) ],
		[ 'open', STATUS_LABELS.open ],
		[ 'pending', STATUS_LABELS.pending ],
		[ 'closed', STATUS_LABELS.closed ],
		[ 'all', __( 'All', 'helpdesk-hero-hub' ) ],
	];
	return (
		<div className="hdh-tabs" role="tablist" aria-label={ __( 'Status', 'helpdesk-hero-hub' ) }>
			{ tabs.map( ( [ id, label ] ) => (
				<button
					key={ id }
					type="button"
					role="tab"
					aria-selected={ value === id }
					className={ `hdh-tabs__tab ${ value === id ? 'is-active' : '' }` }
					onClick={ () => onChange( id ) }
				>
					{ label }
					{ counts && counts[ id ] !== undefined && (
						<span className="hdh-tabs__count">{ counts[ id ] }</span>
					) }
				</button>
			) ) }
		</div>
	);
}
