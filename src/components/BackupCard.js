import { __, sprintf } from '@wordpress/i18n';
import { useState, useRef } from '@wordpress/element';
import { send } from '../ui/lib/hooks';
import { Card, Button, useToast } from '../ui/components/ui';
import { Check, confirmAction } from '../ui/components/kit';
import Icon from '../ui/components/Icon';
import { message } from './common';

/**
 * Download the whole hub as a JSON file, or restore one (after a reinstall or a move).
 *
 * @param {Object}   props           Props.
 * @param {Function} props.onRestore Called after a successful restore.
 * @return {JSX.Element} Card.
 */
export default function BackupCard( { onRestore } ) {
	const [ secrets, setSecrets ] = useState( true );
	const [ busy, setBusy ] = useState( '' );
	const [ result, setResult ] = useState( null );
	const file = useRef( null );
	const toast = useToast();

	const download = () => {
		setBusy( 'export' );
		send( `admin/backup?secrets=${ secrets ? 1 : 0 }`, 'GET' )
			.then( ( data ) => {
				const blob = new window.Blob(
					[ JSON.stringify( data, null, '\t' ) ],
					{ type: 'application/json' }
				);
				const url = window.URL.createObjectURL( blob );
				const a = document.createElement( 'a' );
				a.href = url;
				a.download = `helpdesk-hero-hub-backup-${ new Date()
					.toISOString()
					.slice( 0, 10 ) }.json`;
				document.body.appendChild( a );
				a.click();
				a.remove();
				window.URL.revokeObjectURL( url );
			} )
			.catch( ( e ) => toast( message( e ), 'alert' ) )
			.finally( () => setBusy( '' ) );
	};

	const restore = ( event ) => {
		const picked = event.target.files && event.target.files[ 0 ];
		event.target.value = '';
		if ( ! picked ) {
			return;
		}
		const reader = new window.FileReader();
		reader.onload = async () => {
			let backup;
			try {
				backup = JSON.parse( String( reader.result ) );
			} catch ( e ) {
				toast(
					__(
						'This file isn’t a Helpdesk Hero Hub backup.',
						'helpdesk-hero-hub'
					),
					'alert'
				);
				return;
			}
			if (
				! ( await confirmAction(
					__(
						'Replace everything in this hub (settings, policies, sites and tickets) with this backup?',
						'helpdesk-hero-hub'
					)
				) )
			) {
				return;
			}
			setBusy( 'import' );
			send( 'admin/backup', 'POST', { backup } )
				.then( ( r ) => {
					setResult( r );
					toast( __( 'Backup restored', 'helpdesk-hero-hub' ) );
					if ( onRestore ) {
						onRestore( r );
					}
				} )
				.catch( ( e ) => toast( message( e ), 'alert' ) )
				.finally( () => setBusy( '' ) );
		};
		reader.readAsText( picked );
	};

	return (
		<Card
			title={ __( 'Backup and restore', 'helpdesk-hero-hub' ) }
			sub={ __(
				'One file with your settings, policies, templates, tags, connected sites and every ticket. Restore it after reinstalling the hub, or to move it to a new site.',
				'helpdesk-hero-hub'
			) }
		>
			<div className="hdh-stack" style={ { gap: 14 } }>
				<Check
					label={ __(
						'Include connection keys',
						'helpdesk-hero-hub'
					) }
					desc={ __(
						'Lets sites reconnect on their own after a restore, and keeps help desk keys. Keep the file somewhere safe: anyone with it could act as your hub.',
						'helpdesk-hero-hub'
					) }
					checked={ secrets }
					onChange={ setSecrets }
				/>
				<div className="hdh-row">
					<Button
						variant="primary"
						onClick={ download }
						disabled={ !! busy }
					>
						<Icon name="download" size={ 15 } />
						{ busy === 'export'
							? __( 'Preparing…', 'helpdesk-hero-hub' )
							: __( 'Download backup', 'helpdesk-hero-hub' ) }
					</Button>
					<Button
						onClick={ () => file.current && file.current.click() }
						disabled={ !! busy }
					>
						<Icon name="upload" size={ 15 } />
						{ busy === 'import'
							? __( 'Restoring…', 'helpdesk-hero-hub' )
							: __(
									'Restore from a file…',
									'helpdesk-hero-hub'
							  ) }
					</Button>
					<input
						ref={ file }
						type="file"
						accept="application/json,.json"
						className="hdh-sr"
						tabIndex={ -1 }
						aria-hidden="true"
						onChange={ restore }
					/>
				</div>
				{ result && (
					<div className="hdh-banner" role="status">
						<span className="hdh-banner__icon">
							<Icon name="check" />
						</span>
						<p className="hdh-banner__text">
							{ sprintf(
								/* translators: 1: sites, 2: tickets */
								__(
									'Restored %1$d sites and %2$d tickets.',
									'helpdesk-hero-hub'
								),
								result.sites,
								result.tickets
							) }{ ' ' }
							{ ! result.secrets &&
								__(
									'The backup had no connection keys, so each site needs a new connection code (Sites → Connect a site).',
									'helpdesk-hero-hub'
								) }{ ' ' }
							{ result.secrets &&
								result.moved &&
								__(
									'This hub’s address is different from the one in the backup. Connected sites still call the old address, so send each of them a new connection code.',
									'helpdesk-hero-hub'
								) }
						</p>
					</div>
				) }
			</div>
		</Card>
	);
}
