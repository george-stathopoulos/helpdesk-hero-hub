import { __ } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import { useApi, send } from '../ui/lib/hooks';
import { Button, Loading, ErrorNotice, useToast } from '../ui/components/ui';
import { TextField, CopyBox } from '../ui/components/kit';
import Icon from '../ui/components/Icon';
import { message } from '../components/common';
import BackupCard from '../components/BackupCard';

function stepClass( i, step ) {
	if ( i === step ) {
		return 'is-current';
	}
	return i < step ? 'is-done' : '';
}

/**
 * Setup guide shown after activation: team, policy, help desk, first site.
 *
 * @param {Object}   props    Props.
 * @param {Function} props.go Navigate.
 * @return {JSX.Element} Wizard.
 */
export default function Welcome( { go } ) {
	const settings = useApi( 'admin/settings' );
	const templates = useApi( 'admin/templates' );
	const [ step, setStep ] = useState( 0 );
	const [ team, setTeam ] = useState( null );
	const [ template, setTemplate ] = useState( 'standard' );
	const [ site, setSite ] = useState( { label: '', email: '' } );
	const [ code, setCode ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const toast = useToast();

	useEffect( () => {
		if ( settings.data && ! team ) {
			setTeam( {
				team_name:
					settings.data.settings.team_name ||
					settings.data.defaults.team_name,
				notify_email:
					settings.data.settings.notify_email ||
					settings.data.defaults.notify_email,
			} );
		}
	}, [ settings.data, team ] );

	if ( settings.error || templates.error ) {
		return (
			<ErrorNotice
				error={ settings.error || templates.error }
				onRetry={ () => window.location.reload() }
			/>
		);
	}
	if ( ! team || ! templates.data ) {
		return <Loading />;
	}

	const steps = [
		{ id: 'welcome', label: __( 'Welcome', 'helpdesk-hero-hub' ) },
		{ id: 'team', label: __( 'Your team', 'helpdesk-hero-hub' ) },
		{ id: 'policy', label: __( 'Support policy', 'helpdesk-hero-hub' ) },
		{ id: 'helpdesk', label: __( 'Help desk', 'helpdesk-hero-hub' ) },
		{ id: 'site', label: __( 'First site', 'helpdesk-hero-hub' ) },
		{ id: 'done', label: __( 'Done', 'helpdesk-hero-hub' ) },
	];
	const current = steps[ step ];
	const last = current.id === 'done';

	const finish = ( to ) => {
		setBusy( true );
		send( 'admin/onboarding', 'POST', { done: true } )
			.then( () => {
				window.hdhBoot.onboarded = true;
				go( to || 'overview' );
			} )
			.catch( ( e ) => toast( message( e ), 'alert' ) )
			.finally( () => setBusy( false ) );
	};

	const next = () => {
		if ( current.id === 'team' ) {
			setBusy( true );
			send( 'admin/settings', 'POST', team )
				.then( () => {
					window.hdhBoot.teamName = team.team_name;
					setStep( step + 1 );
				} )
				.catch( ( e ) => toast( message( e ), 'alert' ) )
				.finally( () => setBusy( false ) );
			return;
		}
		if ( current.id === 'policy' ) {
			const tpl = templates.data.templates.find(
				( t ) => t.id === template
			);
			setBusy( true );
			send( 'admin/policy', 'POST', tpl.policy )
				.then( () => setStep( step + 1 ) )
				.catch( ( e ) => toast( message( e ), 'alert' ) )
				.finally( () => setBusy( false ) );
			return;
		}
		setStep( step + 1 );
	};

	const createCode = () => {
		setBusy( true );
		send( 'admin/sites', 'POST', { label: site.label, email: site.email } )
			.then( ( r ) => setCode( r.code ) )
			.catch( ( e ) => toast( message( e ), 'alert' ) )
			.finally( () => setBusy( false ) );
	};

	const points = [
		{
			icon: 'inbox',
			title: __(
				'Tickets with everything you need',
				'helpdesk-hero-hub'
			),
			text: __(
				'Customers send tickets from their dashboard with versions, plugins, errors and recent changes attached, and a health check that points at the likely cause.',
				'helpdesk-hero-hub'
			),
		},
		{
			icon: 'login',
			title: __( 'Log in with one click', 'helpdesk-hero-hub' ),
			text: __(
				'No shared passwords: each login is a one-time link to a temporary account that ends on its own, and everything you do is logged for the customer.',
				'helpdesk-hero-hub'
			),
		},
		{
			icon: 'sliders',
			title: __( 'Your rules', 'helpdesk-hero-hub' ),
			text: __(
				'Your support policy decides what access you get, for how long, and what customers can choose and send.',
				'helpdesk-hero-hub'
			),
		},
	];

	return (
		<div className="hdh-wizard">
			<ol
				className="hdh-wizard__steps"
				aria-label={ __( 'Setup steps', 'helpdesk-hero-hub' ) }
			>
				{ steps.map( ( s, i ) => (
					<li
						key={ s.id }
						className={ stepClass( i, step ) }
						aria-current={ i === step ? 'step' : undefined }
					>
						<span className="hdh-wizard__dot">
							{ i < step ? (
								<Icon name="check" size={ 13 } />
							) : (
								i + 1
							) }
						</span>
						<span className="hdh-wizard__label">{ s.label }</span>
					</li>
				) ) }
			</ol>

			<section className="hdh-card hdh-wizard__card">
				{ current.id === 'welcome' && (
					<div className="hdh-wizard__body">
						<h1 className="hdh-wizard__title">
							{ __(
								'Welcome to your Support Hub',
								'helpdesk-hero-hub'
							) }
						</h1>
						<p className="hdh-wizard__lede">
							{ __(
								'Your customers install the free Helpdesk Hero plugin and connect their sites to this hub with a code. This guide takes about two minutes.',
								'helpdesk-hero-hub'
							) }
						</p>
						<div className="hdh-wizard__points">
							{ points.map( ( p ) => (
								<div
									key={ p.title }
									className="hdh-wizard__point"
								>
									<span
										className="hdh-savings__icon"
										style={ { width: 36, height: 36 } }
									>
										<Icon name={ p.icon } size={ 18 } />
									</span>
									<div>
										<div style={ { fontWeight: 620 } }>
											{ p.title }
										</div>
										<div
											className="hdh-muted"
											style={ { fontSize: 13 } }
										>
											{ p.text }
										</div>
									</div>
								</div>
							) ) }
						</div>
						<details style={ { marginTop: 18 } }>
							<summary
								style={ { cursor: 'pointer', fontWeight: 600 } }
							>
								{ __(
									'Reinstalling or moving your hub? Restore a backup instead',
									'helpdesk-hero-hub'
								) }
							</summary>
							<div style={ { marginTop: 12 } }>
								<BackupCard
									onRestore={ () => {
										window.location.hash = '#/overview';
										window.location.reload();
									} }
								/>
							</div>
						</details>
					</div>
				) }

				{ current.id === 'team' && (
					<div className="hdh-wizard__body">
						<h1 className="hdh-wizard__title">
							{ __( 'Your team', 'helpdesk-hero-hub' ) }
						</h1>
						<p className="hdh-wizard__lede">
							{ __(
								'Customers see this name in their dashboard and in notifications.',
								'helpdesk-hero-hub'
							) }
						</p>
						<div className="hdh-form-grid">
							<TextField
								label={ __( 'Team name', 'helpdesk-hero-hub' ) }
								value={ team.team_name }
								onChange={ ( v ) =>
									setTeam( { ...team, team_name: v } )
								}
							/>
							<TextField
								label={ __(
									'Team email',
									'helpdesk-hero-hub'
								) }
								type="email"
								value={ team.notify_email }
								onChange={ ( v ) =>
									setTeam( { ...team, notify_email: v } )
								}
								help={ __(
									'Gets new tickets and replies by email.',
									'helpdesk-hero-hub'
								) }
							/>
						</div>
					</div>
				) }

				{ current.id === 'policy' && (
					<div className="hdh-wizard__body">
						<h1 className="hdh-wizard__title">
							{ __(
								'How do you want to work?',
								'helpdesk-hero-hub'
							) }
						</h1>
						<p className="hdh-wizard__lede">
							{ __(
								'Pick a starting point for your support policy. You can fine-tune every rule, save your own templates and give sites different policies later.',
								'helpdesk-hero-hub'
							) }
						</p>
						<div
							className="hdh-choices"
							style={ {
								gridTemplateColumns:
									'repeat(2, minmax(0, 1fr))',
							} }
						>
							{ templates.data.templates.map( ( t ) => (
								<button
									key={ t.id }
									type="button"
									className={ `hdh-choice ${
										template === t.id ? 'is-active' : ''
									}` }
									aria-pressed={ template === t.id }
									onClick={ () => setTemplate( t.id ) }
								>
									<Icon
										name={
											{
												standard: 'shield',
												'hands-off': 'message',
												strict: 'lock',
												'full-service': 'wrench',
											}[ t.id ] || 'sliders'
										}
										size={ 22 }
									/>
									<strong>{ t.name }</strong>
									<span>{ t.description }</span>
								</button>
							) ) }
						</div>
					</div>
				) }

				{ current.id === 'helpdesk' && (
					<div className="hdh-wizard__body">
						<h1 className="hdh-wizard__title">
							{ __(
								'Where do you answer tickets?',
								'helpdesk-hero-hub'
							) }
						</h1>
						<p className="hdh-wizard__lede">
							{ __(
								'Tickets arrive in this hub’s Inbox and by email to your team. Reply from the Inbox and customers see it in their dashboard.',
								'helpdesk-hero-hub'
							) }
						</p>
						<div
							className="hdh-choices"
							style={ {
								gridTemplateColumns:
									'repeat(2, minmax(0, 1fr))',
							} }
						>
							<div className="hdh-choice is-active">
								<Icon name="inbox" size={ 22 } />
								<strong>
									{ __(
										'Inbox and email',
										'helpdesk-hero-hub'
									) }
								</strong>
								<span>
									{ __(
										'Built in. Nothing to set up.',
										'helpdesk-hero-hub'
									) }
								</span>
							</div>
							<div
								className="hdh-choice is-disabled"
								aria-disabled="true"
							>
								<Icon name="plug" size={ 22 } />
								<strong>
									{ __(
										'Help Scout or Zendesk',
										'helpdesk-hero-hub'
									) }
									<span className="hdh-soon">
										{ __(
											'Coming soon',
											'helpdesk-hero-hub'
										) }
									</span>
								</strong>
								<span>
									{ __(
										'Tickets will become conversations in your help desk, with replies syncing both ways.',
										'helpdesk-hero-hub'
									) }
								</span>
							</div>
						</div>
					</div>
				) }

				{ current.id === 'site' && (
					<div className="hdh-wizard__body">
						<h1 className="hdh-wizard__title">
							{ __(
								'Connect your first customer',
								'helpdesk-hero-hub'
							) }
						</h1>
						<p className="hdh-wizard__lede">
							{ __(
								'Create a connection code and send it to them. They install the free Helpdesk Hero plugin and paste it in Get Help. You can also do this later under Sites.',
								'helpdesk-hero-hub'
							) }
						</p>
						{ ! code ? (
							<div className="hdh-stack">
								<div className="hdh-form-grid">
									<TextField
										label={ __(
											'Customer or site name',
											'helpdesk-hero-hub'
										) }
										value={ site.label }
										onChange={ ( v ) =>
											setSite( { ...site, label: v } )
										}
									/>
									<TextField
										label={ __(
											'Customer email (optional)',
											'helpdesk-hero-hub'
										) }
										type="email"
										value={ site.email }
										onChange={ ( v ) =>
											setSite( { ...site, email: v } )
										}
									/>
								</div>
								<div>
									<Button
										icon="key"
										onClick={ createCode }
										disabled={ busy || ! site.label.trim() }
									>
										{ __(
											'Create connection code',
											'helpdesk-hero-hub'
										) }
									</Button>
								</div>
							</div>
						) : (
							<div className="hdh-stack">
								<CopyBox
									value={ code }
									label={ __(
										'Copy code',
										'helpdesk-hero-hub'
									) }
									rows={ 4 }
									note={ __(
										'Works once, expires in 7 days.',
										'helpdesk-hero-hub'
									) }
								/>
							</div>
						) }
					</div>
				) }

				{ current.id === 'done' && (
					<div
						className="hdh-wizard__body"
						style={ { textAlign: 'center' } }
					>
						<span
							className="hdh-savings__icon"
							style={ {
								width: 52,
								height: 52,
								margin: '0 auto 14px',
							} }
						>
							<Icon name="check" size={ 24 } />
						</span>
						<h1 className="hdh-wizard__title">
							{ __( 'Your hub is ready', 'helpdesk-hero-hub' ) }
						</h1>
						<p
							className="hdh-wizard__lede"
							style={ { margin: '0 auto' } }
						>
							{ __(
								'Tickets from connected sites appear in the Inbox, and the Overview shows trends and recurring issues as they come in.',
								'helpdesk-hero-hub'
							) }
						</p>
						<div className="hdh-wizard__choices">
							<button
								type="button"
								className="hdh-choice"
								onClick={ () => finish( 'overview' ) }
								disabled={ busy }
							>
								<Icon name="overview" size={ 22 } />
								<strong>
									{ __(
										'Go to the hub',
										'helpdesk-hero-hub'
									) }
								</strong>
								<span>
									{ __(
										'Overview, Inbox and Sites.',
										'helpdesk-hero-hub'
									) }
								</span>
							</button>
							<button
								type="button"
								className="hdh-choice"
								onClick={ () => finish( 'feedback' ) }
								disabled={ busy }
							>
								<Icon name="message" size={ 22 } />
								<strong>
									{ __(
										'Tell us what you need',
										'helpdesk-hero-hub'
									) }
								</strong>
								<span>
									{ __(
										'Thirty seconds: what matters most when you handle tickets for customers in WordPress?',
										'helpdesk-hero-hub'
									) }
								</span>
							</button>
						</div>
						<p
							className="hdh-muted"
							style={ { fontSize: 12.5, marginTop: 18 } }
						>
							{ __(
								'You can open this guide again from the Overview.',
								'helpdesk-hero-hub'
							) }
						</p>
					</div>
				) }

				{ ! last && (
					<footer className="hdh-wizard__foot">
						<Button
							variant="ghost"
							onClick={ () => finish( 'overview' ) }
							disabled={ busy }
						>
							{ __( 'Skip setup', 'helpdesk-hero-hub' ) }
						</Button>
						<span className="hdh-spacer" />
						{ step > 0 && (
							<Button
								onClick={ () => setStep( step - 1 ) }
								disabled={ busy }
							>
								{ __( 'Back', 'helpdesk-hero-hub' ) }
							</Button>
						) }
						<Button
							variant="primary"
							onClick={ next }
							disabled={ busy }
						>
							{ step === 0
								? __( 'Get started', 'helpdesk-hero-hub' )
								: __( 'Continue', 'helpdesk-hero-hub' ) }
							<Icon name="arrowRight" size={ 14 } />
						</Button>
					</footer>
				) }
			</section>
		</div>
	);
}
