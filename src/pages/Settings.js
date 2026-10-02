import { __ } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import { useApi, send } from '../ui/lib/hooks';
import {
	Card,
	PageHead,
	Setting,
	ErrorNotice,
	Loading,
	useToast,
	Button,
} from '../ui/components/ui';
import { SaveBar } from '../ui/lib/settings';
import { message } from '../components/common';
import BackupCard from '../components/BackupCard';

const boot = window.hdhBoot || {};

export default function Settings() {
	const { data, error, reload } = useApi( 'admin/settings' );
	const [ draft, setDraft ] = useState( null );
	const [ saving, setSaving ] = useState( false );
	const toast = useToast();

	useEffect( () => {
		if ( data ) {
			setDraft( data.settings );
		}
	}, [ data ] );

	if ( error && ! data ) {
		return <ErrorNotice error={ error } onRetry={ reload } />;
	}
	if ( ! data || ! draft ) {
		return <Loading />;
	}
	const dirty = JSON.stringify( draft ) !== JSON.stringify( data.settings );
	const save = () => {
		setSaving( true );
		send( 'admin/settings', 'POST', draft )
			.then( () => {
				toast( __( 'Settings saved', 'helpdesk-hero-hub' ) );
				reload();
			} )
			.catch( ( e ) => toast( message( e ), 'alert' ) )
			.finally( () => setSaving( false ) );
	};

	return (
		<>
			<PageHead title={ __( 'Settings', 'helpdesk-hero-hub' ) } />
			<Card
				title={ __( 'Your team', 'helpdesk-hero-hub' ) }
				bodyClass={ null }
			>
				<Setting
					title={ __(
						'Name shown to customers',
						'helpdesk-hero-hub'
					) }
					desc={ __(
						'In their Get Help screens and notifications.',
						'helpdesk-hero-hub'
					) }
				>
					<input
						className="hdh-input"
						style={ { minWidth: 280 } }
						aria-label={ __( 'Team name', 'helpdesk-hero-hub' ) }
						placeholder={ data.defaults.team_name }
						value={ draft.team_name }
						onChange={ ( e ) =>
							setDraft( { ...draft, team_name: e.target.value } )
						}
					/>
				</Setting>
				<Setting
					title={ __( 'Team email', 'helpdesk-hero-hub' ) }
					desc={ __(
						'Receives new tickets and replies when no help desk is connected, and help desk errors.',
						'helpdesk-hero-hub'
					) }
				>
					<input
						type="email"
						className="hdh-input"
						style={ { minWidth: 280 } }
						aria-label={ __( 'Team email', 'helpdesk-hero-hub' ) }
						placeholder={ data.defaults.notify_email }
						value={ draft.notify_email }
						onChange={ ( e ) =>
							setDraft( {
								...draft,
								notify_email: e.target.value,
							} )
						}
					/>
				</Setting>
			</Card>
			<TagsManager />
			{ boot.canManage !== false && (
				<BackupCard onRestore={ () => reload() } />
			) }
			<SaveBar
				dirty={ dirty }
				saving={ saving }
				save={ save }
				discard={ () => setDraft( data.settings ) }
			/>
		</>
	);
}

function TagsManager() {
	const { data, reload } = useApi( 'admin/tags' );
	const [ tags, setTags ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const toast = useToast();
	useEffect( () => {
		if ( data ) {
			setTags( data.tags );
		}
	}, [ data ] );
	if ( ! data || ! tags ) {
		return null;
	}
	const dirty = JSON.stringify( tags ) !== JSON.stringify( data.tags );
	const set = ( i, patch ) =>
		setTags( tags.map( ( t, n ) => ( n === i ? { ...t, ...patch } : t ) ) );
	const save = () => {
		setBusy( true );
		send( 'admin/tags', 'POST', { tags } )
			.then( () => {
				toast( __( 'Tags saved', 'helpdesk-hero-hub' ) );
				reload();
			} )
			.catch( ( e ) => toast( message( e ), 'alert' ) )
			.finally( () => setBusy( false ) );
	};
	return (
		<Card
			title={ __( 'Ticket tags', 'helpdesk-hero-hub' ) }
			sub={ __(
				'Label tickets for your team and for reports. Customers see a ticket’s tags too, so keep names friendly.',
				'helpdesk-hero-hub'
			) }
			style={ { marginTop: 18 } }
		>
			<div className="hdh-stack" style={ { gap: 8 } }>
				{ tags.map( ( t, i ) => (
					<div key={ t.id || i } className="hdh-row">
						<input
							type="color"
							aria-label={ __( 'Colour', 'helpdesk-hero-hub' ) }
							value={ t.color }
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
							aria-label={ __( 'Tag name', 'helpdesk-hero-hub' ) }
							value={ t.name }
							onChange={ ( e ) =>
								set( i, { name: e.target.value } )
							}
							style={ { flex: 1, maxWidth: 320 } }
						/>
						<Button
							size="sm"
							variant="ghost"
							icon="trash"
							aria-label={ __(
								'Delete tag',
								'helpdesk-hero-hub'
							) }
							onClick={ () =>
								setTags( tags.filter( ( _, n ) => n !== i ) )
							}
						/>
					</div>
				) ) }
				<div className="hdh-row">
					<Button
						size="sm"
						icon="plus"
						onClick={ () =>
							setTags( [
								...tags,
								{
									id: '',
									name: '',
									color: data.colors[
										tags.length % data.colors.length
									],
								},
							] )
						}
					>
						{ __( 'Add tag', 'helpdesk-hero-hub' ) }
					</Button>
					{ dirty && (
						<Button
							size="sm"
							variant="primary"
							disabled={ busy }
							onClick={ save }
						>
							{ __( 'Save tags', 'helpdesk-hero-hub' ) }
						</Button>
					) }
				</div>
			</div>
		</Card>
	);
}
