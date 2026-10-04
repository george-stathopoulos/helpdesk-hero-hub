import { __, _n, sprintf } from '@wordpress/i18n';
import { useState, useEffect, useRef } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { useApi, send } from '../ui/lib/hooks';
import { ago, when } from '../ui/lib/time';
import { Card, Button, Loading, useToast } from '../ui/components/ui';
import { Check, confirmAction, fileSize } from '../ui/components/kit';
import { message } from './common';

const boot = window.hdhBoot || {};

/**
 * Cleanup: export and delete tickets closed longer than a set period, by hand or every day;
 * and import a CSV export to bring tickets back.
 *
 * @return {JSX.Element} Cards.
 */
export default function CleanupCard() {
	const { data, reload } = useApi( 'admin/cleanup' );
	const [ draft, setDraft ] = useState( null );
	const [ busy, setBusy ] = useState( '' );
	const [ result, setResult ] = useState( null );
	const file = useRef( null );
	const toast = useToast();
	useEffect( () => {
		if ( data ) {
			setDraft( data.settings );
		}
	}, [ data ] );
	if ( ! data || ! draft ) {
		return <Loading />;
	}
	const dirty = JSON.stringify( draft ) !== JSON.stringify( data.settings );
	const filters = { older_than_months: data.settings.months };
	const exportUrl = `${ boot.restUrl }admin/tickets/export?${ new URLSearchParams(
		{
			_wpnonce: boot.restNonce,
			'filters[older_than_months]': data.settings.months,
		}
	).toString() }`;

	const save = () => {
		setBusy( 'save' );
		send( 'admin/cleanup', 'POST', draft )
			.then( () => {
				toast( __( 'Cleanup settings saved', 'helpdesk-hero-hub' ) );
				reload();
			} )
			.catch( ( e ) => toast( message( e ), 'alert' ) )
			.finally( () => setBusy( '' ) );
	};
	const deleteNow = async () => {
		const yes = await confirmAction(
			sprintf(
				/* translators: %d: number of tickets */
				_n(
					'Delete %d closed ticket, with its conversation, history and files? Download the CSV first if you might need it. This can’t be undone.',
					'Delete %d closed tickets, with their conversations, history and files? Download the CSV first if you might need them. This can’t be undone.',
					data.matching,
					'helpdesk-hero-hub'
				),
				data.matching
			),
			{ confirmText: __( 'Delete', 'helpdesk-hero-hub' ) }
		);
		if ( ! yes ) {
			return;
		}
		setBusy( 'delete' );
		let total = 0;
		try {
			let remaining = data.matching;
			while ( remaining > 0 ) {
				const r = await send( 'admin/tickets/delete', 'POST', { filters } );
				total += r.deleted;
				remaining = r.deleted ? r.remaining : 0;
			}
			toast(
				sprintf(
					/* translators: %d: number of tickets */
					_n( '%d ticket deleted', '%d tickets deleted', total, 'helpdesk-hero-hub' ),
					total
				)
			);
		} catch ( e ) {
			toast( message( e ), 'alert' );
		}
		setBusy( '' );
		reload();
	};
	const importCsv = async ( e ) => {
		const picked = e.target.files && e.target.files[ 0 ];
		e.target.value = '';
		if ( ! picked ) {
			return;
		}
		const body = new window.FormData();
		body.append( 'file', picked );
		setBusy( 'import' );
		try {
			const r = await apiFetch( {
				path: '/helpdesk-hero-hub/v1/admin/tickets/import',
				method: 'POST',
				body,
			} );
			setResult( r );
			toast( __( 'Import finished', 'helpdesk-hero-hub' ) );
		} catch ( err ) {
			toast( message( err ), 'alert' );
		}
		setBusy( '' );
	};

	return (
		<>
			<Card
				title={ __( 'Cleanup', 'helpdesk-hero-hub' ) }
				sub={ __(
					'Keep tickets only as long as you need them. Tickets closed longer than the period below can be exported and deleted, by hand or automatically. Customers keep their own copies on their sites.',
					'helpdesk-hero-hub'
				) }
			>
				<div className="hdh-stack" style={ { gap: 14 } }>
					<label className="hdh-inline-field" htmlFor="hdh-cleanup-months">
						<span>
							{ __( 'Tickets closed more than', 'helpdesk-hero-hub' ) }
						</span>
						<input
							id="hdh-cleanup-months"
							type="number"
							min="1"
							max="120"
							className="hdh-input"
							style={ { width: 70 } }
							value={ draft.months }
							onChange={ ( e ) =>
								setDraft( {
									...draft,
									months: parseInt( e.target.value, 10 ) || '',
								} )
							}
						/>
						<span>{ __( 'months ago', 'helpdesk-hero-hub' ) }</span>
					</label>
					<Check
						label={ __(
							'Clean up automatically every day',
							'helpdesk-hero-hub'
						) }
						desc={ __(
							'Before deleting, a CSV of the removed tickets is saved and kept for 90 days (below), so you can import them again.',
							'helpdesk-hero-hub'
						) }
						checked={ draft.auto }
						onChange={ ( on ) => setDraft( { ...draft, auto: on } ) }
					/>
					{ dirty ? (
						<div>
							<Button
								variant="primary"
								disabled={ busy === 'save' }
								onClick={ save }
							>
								{ __( 'Save', 'helpdesk-hero-hub' ) }
							</Button>
						</div>
					) : (
						<div className="hdh-row">
							<span>
								{ sprintf(
									/* translators: %d: number of tickets */
									_n(
										'%d ticket matches right now.',
										'%d tickets match right now.',
										data.matching,
										'helpdesk-hero-hub'
									),
									data.matching
								) }
							</span>
							{ data.matching > 0 && (
								<>
									<a className="hdh-btn is-sm" href={ exportUrl }>
										{ __( 'Export as CSV', 'helpdesk-hero-hub' ) }
									</a>
									<Button
										size="sm"
										variant="ghost"
										icon="trash"
										disabled={ busy === 'delete' }
										onClick={ deleteNow }
									>
										{ busy === 'delete'
											? __( 'Deleting…', 'helpdesk-hero-hub' )
											: __( 'Delete now…', 'helpdesk-hero-hub' ) }
									</Button>
								</>
							) }
						</div>
					) }
					{ data.files.length > 0 && (
						<div className="hdh-stack" style={ { gap: 6 } }>
							<div className="hdh-section-title">
								{ __( 'Saved by automatic cleanup', 'helpdesk-hero-hub' ) }
							</div>
							<ul className="hdh-time-list">
								{ data.files.map( ( f ) => (
									<li key={ f.name }>
										<a href={ f.url }>{ f.name }</a>
										<span className="hdh-muted">
											{ ' · ' }
											{ fileSize( f.size ) } ·{ ' ' }
											<span title={ when( f.time ) }>{ ago( f.time ) }</span>
										</span>
									</li>
								) ) }
							</ul>
						</div>
					) }
				</div>
			</Card>
			<Card
				title={ __( 'Import tickets', 'helpdesk-hero-hub' ) }
				sub={ __(
					'Bring back tickets from a CSV exported here (Inbox or Cleanup). Tickets that still exist are skipped. Their history is for reading: nothing is sent to customers again. Attached files aren’t in exports.',
					'helpdesk-hero-hub'
				) }
			>
				<div className="hdh-stack" style={ { gap: 10 } }>
					<div>
						<Button
							icon="upload"
							disabled={ busy === 'import' }
							onClick={ () => file.current && file.current.click() }
						>
							{ busy === 'import'
								? __( 'Importing…', 'helpdesk-hero-hub' )
								: __( 'Import a CSV…', 'helpdesk-hero-hub' ) }
						</Button>
						<input
							ref={ file }
							type="file"
							accept=".csv,text/csv"
							className="hdh-sr"
							tabIndex={ -1 }
							aria-hidden="true"
							onChange={ importCsv }
						/>
					</div>
					{ result && (
						<p role="status" style={ { margin: 0 } }>
							{ sprintf(
								/* translators: 1: tickets imported, 2: tickets skipped */
								__(
									'%1$d tickets imported, %2$d skipped (already here or unreadable).',
									'helpdesk-hero-hub'
								),
								result.imported,
								result.skipped
							) }
						</p>
					) }
				</div>
			</Card>
		</>
	);
}
