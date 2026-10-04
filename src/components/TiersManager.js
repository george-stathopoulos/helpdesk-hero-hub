import { __, sprintf } from '@wordpress/i18n';
import { applyFilters } from '@wordpress/hooks';
import { useState, useEffect } from '@wordpress/element';
import { useApi, send } from '../ui/lib/hooks';
import { Card, Button, useToast } from '../ui/components/ui';
import { message } from './common';

const COLORS = [
	'#eda100',
	'#4a3aa7',
	'#2a78d6',
	'#1baf7a',
	'#e87ba4',
	'#eb6834',
];

/**
 * Customer tiers ("VIP", "Pro customer"…): name, colour, a short description and their order
 * (first = most important). Pro adds a service level agreement to each tier through the
 * `helpdeskHeroHub.tierFields` filter: an array of components that get { tier, onChange }.
 *
 * @return {JSX.Element|null} Card.
 */
export default function TiersManager() {
	const { data, reload } = useApi( 'admin/tiers' );
	const templates = useApi( 'admin/templates' );
	const [ tiers, setTiers ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const toast = useToast();
	useEffect( () => {
		if ( data ) {
			setTiers( data.tiers );
		}
	}, [ data ] );
	if ( ! data || ! tiers ) {
		return null;
	}
	const fields = applyFilters( 'helpdeskHeroHub.tierFields', [] );
	const dirty = JSON.stringify( tiers ) !== JSON.stringify( data.tiers );
	const set = ( i, patch ) =>
		setTiers(
			tiers.map( ( t, n ) => ( n === i ? { ...t, ...patch } : t ) )
		);
	const move = ( i, by ) => {
		const next = [ ...tiers ];
		const [ item ] = next.splice( i, 1 );
		next.splice( i + by, 0, item );
		setTiers( next );
	};
	const save = () => {
		setBusy( true );
		send( 'admin/tiers', 'POST', { tiers } )
			.then( () => {
				toast( __( 'Customer tiers saved', 'helpdesk-hero-hub' ) );
				reload();
			} )
			.catch( ( e ) => toast( message( e ), 'alert' ) )
			.finally( () => setBusy( false ) );
	};
	return (
		<Card
			title={ __( 'Customer tiers', 'helpdesk-hero-hub' ) }
			sub={ __(
				'Group customers by how they’re served, for example “VIP” or “Pro customer”. The first tier is the most important; sort the Inbox by tier to see those tickets first. A tier can come with a support policy (for example longer access for VIPs); a site’s own policy still wins. Customers don’t see tiers.',
				'helpdesk-hero-hub'
			) }
		>
			<div className="hdh-stack" style={ { gap: 14 } }>
				{ ! tiers.length && (
					<p className="hdh-muted">
						{ __(
							'No tiers yet. Add one, then choose it for sites under Sites.',
							'helpdesk-hero-hub'
						) }
					</p>
				) }
				{ tiers.map( ( t, i ) => (
					<div key={ t.id || `new-${ i }` } className="hdh-tier-edit">
						<div className="hdh-row">
							<span className="hdh-tier-edit__rank">
								{ i + 1 }
							</span>
							<input
								type="color"
								aria-label={ __(
									'Colour',
									'helpdesk-hero-hub'
								) }
								value={ t.color || COLORS[ 0 ] }
								onChange={ ( e ) =>
									set( i, { color: e.target.value } )
								}
								style={ {
									width: 36,
									height: 30,
									border: 0,
									background: 'none',
								} }
							/>
							<input
								className="hdh-input"
								aria-label={ __(
									'Tier name',
									'helpdesk-hero-hub'
								) }
								placeholder={ __(
									'For example: VIP',
									'helpdesk-hero-hub'
								) }
								value={ t.name }
								maxLength={ 40 }
								onChange={ ( e ) =>
									set( i, { name: e.target.value } )
								}
								style={ { flex: 1, maxWidth: 260 } }
							/>
							<Button
								size="sm"
								variant="ghost"
								disabled={ i === 0 }
								onClick={ () => move( i, -1 ) }
								aria-label={ sprintf(
									/* translators: %s: tier name */
									__( 'Move %s up', 'helpdesk-hero-hub' ),
									t.name
								) }
							>
								↑
							</Button>
							<Button
								size="sm"
								variant="ghost"
								disabled={ i === tiers.length - 1 }
								onClick={ () => move( i, 1 ) }
								aria-label={ sprintf(
									/* translators: %s: tier name */
									__( 'Move %s down', 'helpdesk-hero-hub' ),
									t.name
								) }
							>
								↓
							</Button>
							<Button
								size="sm"
								variant="ghost"
								icon="trash"
								aria-label={ sprintf(
									/* translators: %s: tier name */
									__( 'Delete %s', 'helpdesk-hero-hub' ),
									t.name
								) }
								onClick={ () =>
									setTiers(
										tiers.filter( ( _, n ) => n !== i )
									)
								}
							/>
						</div>
						<input
							className="hdh-input"
							aria-label={ __(
								'Short description',
								'helpdesk-hero-hub'
							) }
							placeholder={ __(
								'Short description, for your team (optional)',
								'helpdesk-hero-hub'
							) }
							value={ t.description || '' }
							maxLength={ 300 }
							onChange={ ( e ) =>
								set( i, { description: e.target.value } )
							}
						/>
						<label className="hdh-inline-field" htmlFor={ `tier-policy-${ i }` }>
							<span>{ __( 'Support policy', 'helpdesk-hero-hub' ) }</span>
							<select
								id={ `tier-policy-${ i }` }
								className="hdh-input hdh-select"
								value={ t.policy || '' }
								onChange={ ( e ) => set( i, { policy: e.target.value } ) }
							>
								<option value="">
									{ __( 'Team default', 'helpdesk-hero-hub' ) }
								</option>
								{ ( templates.data ? templates.data.templates : [] ).map( ( tpl ) => (
									<option key={ tpl.id } value={ tpl.id }>
										{ tpl.name }
									</option>
								) ) }
							</select>
						</label>
						{ fields.map( ( Field, n ) => (
							<Field
								key={ n }
								tier={ t }
								onChange={ ( patch ) => set( i, patch ) }
							/>
						) ) }
					</div>
				) ) }
				<div className="hdh-row">
					<Button
						size="sm"
						icon="plus"
						onClick={ () =>
							setTiers( [
								...tiers,
								{
									id: '',
									name: '',
									description: '',
									color: COLORS[ tiers.length % COLORS.length ],
								},
							] )
						}
					>
						{ __( 'Add tier', 'helpdesk-hero-hub' ) }
					</Button>
					{ dirty && (
						<Button
							size="sm"
							variant="primary"
							disabled={ busy }
							onClick={ save }
						>
							{ __( 'Save tiers', 'helpdesk-hero-hub' ) }
						</Button>
					) }
				</div>
			</div>
		</Card>
	);
}
