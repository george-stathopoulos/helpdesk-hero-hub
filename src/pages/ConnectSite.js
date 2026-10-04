/**
 * Connect a site: who it is, how it gets connected (a link for the customer, or your own
 * site), and a live wait until it connects. One flow for the first site and every one after,
 * opened from the setup guide, Sites and the quick switcher (#/connect, or #/connect/<id> to
 * pick up a site that hasn't connected yet).
 */
import { __, sprintf } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import { useApi, send } from '../ui/lib/hooks';
import { ago } from '../ui/lib/time';
import { Button, Loading, Segmented, useToast } from '../ui/components/ui';
import {
	TextField,
	TextArea,
	SelectField,
	CopyBox,
	confirmAction,
} from '../ui/components/kit';
import Icon from '../ui/components/Icon';
import { message } from '../components/common';
import { policyOptions, tierOptions } from './Sites';

const boot = window.hdhBoot || {};

/**
 * The email to the customer, as a mailto: link.
 *
 * @param {string} to   Address.
 * @param {string} link Connection link.
 * @return {string} URL.
 */
function mailto( to, link ) {
	const subject = sprintf(
		/* translators: %s: support team name */
		__( 'Connect your site to %s', 'helpdesk-hero-hub' ),
		boot.teamName
	);
	const body = sprintf(
		/* translators: 1: connection link, 2: support team name */
		__(
			'Hi,\n\nSo you can ask us for help right from your WordPress dashboard, please connect your site to us. It takes about a minute:\n\n%1$s\n\nThe link explains each step. It works once and expires in 7 days.\n\nThanks,\n%2$s',
			'helpdesk-hero-hub'
		),
		link,
		boot.teamName
	);
	return `mailto:${ encodeURIComponent( to ) }?subject=${ encodeURIComponent(
		subject
	) }&body=${ encodeURIComponent( body ) }`;
}

export default function ConnectSite( { go, param } ) {
	const templates = useApi( 'admin/templates' );
	const tiersApi = useApi( 'admin/tiers' );
	const toast = useToast();
	const resumeId = parseInt( param, 10 ) || 0;
	const [ form, setForm ] = useState( {
		label: '',
		email: '',
		policy: 'default',
		tier: '',
		notes: '',
	} );
	// The site being connected: { id, label, email, status, invite_expires }.
	const [ site, setSite ] = useState( null );
	// Code and link exist only right after they are made (the hub keeps no copy).
	const [ invite, setInvite ] = useState( null );
	const [ how, setHow ] = useState( 'customer' );
	const [ busy, setBusy ] = useState( false );

	// Picking up a site that hasn't connected yet.
	useEffect( () => {
		if ( ! resumeId ) {
			return;
		}
		send( `admin/sites/${ resumeId }` )
			.then( ( s ) =>
				setSite( {
					id: s.id,
					label: s.label,
					email: s.contact_email,
					status: s.status,
					invite_expires: s.invite_expires,
				} )
			)
			.catch( ( e ) => toast( message( e ), 'alert' ) );
	}, [ resumeId ] ); // eslint-disable-line react-hooks/exhaustive-deps

	// Watch for the site to connect.
	useEffect( () => {
		if ( ! site || site.status === 'active' ) {
			return undefined;
		}
		const timer = setInterval( () => {
			send( `admin/sites/${ site.id }` )
				.then( ( s ) => {
					if ( s.status === 'active' ) {
						setSite( ( cur ) => ( { ...cur, status: 'active', name: s.name } ) );
					}
				} )
				.catch( () => {} );
		}, 5000 );
		return () => clearInterval( timer );
	}, [ site ] );

	if ( resumeId && ! site ) {
		return <Loading />;
	}

	const set = ( k ) => ( v ) => setForm( ( f ) => ( { ...f, [ k ]: v } ) );
	const tiers = tiersApi.data ? tiersApi.data.tiers : [];
	const step = ! site ? 0 : site.status === 'active' ? 2 : 1;
	const steps = [
		__( 'Who', 'helpdesk-hero-hub' ),
		__( 'Send', 'helpdesk-hero-hub' ),
		__( 'Connected', 'helpdesk-hero-hub' ),
	];

	const create = () => {
		setBusy( true );
		send( 'admin/sites', 'POST', form )
			.then( ( r ) => {
				setInvite( { code: r.code, link: r.link } );
				setSite( {
					id: r.site.id,
					label: r.site.label,
					email: form.email,
					status: r.site.status,
					invite_expires: r.site.invite_expires,
				} );
			} )
			.catch( ( e ) => toast( message( e ), 'alert' ) )
			.finally( () => setBusy( false ) );
	};

	const renew = async () => {
		if (
			invite &&
			! ( await confirmAction(
				__(
					'Make a new link? The one you already sent stops working.',
					'helpdesk-hero-hub'
				),
				{ confirmText: __( 'Make a new link', 'helpdesk-hero-hub' ) }
			) )
		) {
			return;
		}
		setBusy( true );
		send( `admin/sites/${ site.id }/invite`, 'POST' )
			.then( ( r ) => {
				setInvite( { code: r.code, link: r.link } );
				setSite( ( s ) => ( { ...s, invite_expires: r.site.invite_expires } ) );
			} )
			.catch( ( e ) => toast( message( e ), 'alert' ) )
			.finally( () => setBusy( false ) );
	};

	const restart = () => {
		setSite( null );
		setInvite( null );
		setForm( { label: '', email: '', policy: 'default', tier: '', notes: '' } );
		go( 'connect' );
	};

	return (
		<div className="hdh-wizard">
			<ol
				className="hdh-wizard__steps"
				aria-label={ __( 'Steps', 'helpdesk-hero-hub' ) }
			>
				{ steps.map( ( label, i ) => (
					<li
						key={ label }
						className={ i === step ? 'is-current' : i < step ? 'is-done' : '' }
						aria-current={ i === step ? 'step' : undefined }
					>
						<span className="hdh-wizard__dot">
							{ i < step ? <Icon name="check" size={ 13 } /> : i + 1 }
						</span>
						<span className="hdh-wizard__label">{ label }</span>
					</li>
				) ) }
			</ol>

			<section className="hdh-card hdh-wizard__card">
				{ step === 0 && (
					<div className="hdh-wizard__body">
						<h1 className="hdh-wizard__title">
							{ __( 'Connect a site', 'helpdesk-hero-hub' ) }
						</h1>
						<p className="hdh-wizard__lede">
							{ __(
								'Who is it for? Next you get a link to send them (or to open yourself, for a site of your own).',
								'helpdesk-hero-hub'
							) }
						</p>
						<div className="hdh-stack">
							<div className="hdh-form-grid">
								<TextField
									label={ __( 'Customer or site name', 'helpdesk-hero-hub' ) }
									placeholder={ __( 'For example: Northwind Coffee', 'helpdesk-hero-hub' ) }
									value={ form.label }
									onChange={ set( 'label' ) }
								/>
								<TextField
									label={ __( 'Customer email (optional)', 'helpdesk-hero-hub' ) }
									help={ __( 'To email them the link in one click.', 'helpdesk-hero-hub' ) }
									type="email"
									value={ form.email }
									onChange={ set( 'email' ) }
								/>
							</div>
							{ boot.canManage && (
								<div className="hdh-form-grid">
									<SelectField
										label={ __( 'Policy', 'helpdesk-hero-hub' ) }
										value={ form.policy }
										onChange={ set( 'policy' ) }
										options={ policyOptions(
											templates.data ? templates.data.templates : []
										) }
										help={ __( 'You can change it any time.', 'helpdesk-hero-hub' ) }
									/>
									{ tiers.length > 0 && (
										<SelectField
											label={ __( 'Customer tier (optional)', 'helpdesk-hero-hub' ) }
											value={ form.tier }
											onChange={ set( 'tier' ) }
											options={ tierOptions( tiers ) }
										/>
									) }
								</div>
							) }
							<TextArea
								label={ __( 'Notes (optional)', 'helpdesk-hero-hub' ) }
								value={ form.notes }
								onChange={ set( 'notes' ) }
								rows={ 2 }
								help={ __( 'For your team only. Customers never see them.', 'helpdesk-hero-hub' ) }
							/>
						</div>
					</div>
				) }

				{ step === 1 && (
					<div className="hdh-wizard__body">
						<h1 className="hdh-wizard__title">
							{ sprintf(
								/* translators: %s: customer name */
								__( 'Connect %s', 'helpdesk-hero-hub' ),
								site.label
							) }
						</h1>
						<div className="hdh-stack">
							<Segmented
								label={ __( 'Whose site is it?', 'helpdesk-hero-hub' ) }
								value={ how }
								onChange={ setHow }
								options={ [
									{ value: 'customer', label: __( 'My customer’s', 'helpdesk-hero-hub' ) },
									{ value: 'own', label: __( 'Mine (to try it)', 'helpdesk-hero-hub' ) },
								] }
							/>

							{ ! invite ? (
								<div className="hdh-banner" style={ { margin: 0 } }>
									<span className="hdh-banner__icon">
										<Icon name="link" />
									</span>
									<div className="hdh-banner__text">
										{ sprintf(
											/* translators: %s: relative time */
											__(
												'The link you sent earlier works until it expires %s. Lost it? Make a new one; the old one then stops working.',
												'helpdesk-hero-hub'
											),
											ago( site.invite_expires )
										) }
									</div>
									<Button size="sm" variant="primary" disabled={ busy } onClick={ renew }>
										{ __( 'Make a new link', 'helpdesk-hero-hub' ) }
									</Button>
								</div>
							) : (
								<>
									<p className="hdh-wizard__lede" style={ { margin: 0 } }>
										{ how === 'customer'
											? __(
													'Send them this link. It walks them through it: install the free plugin, enter their site address, click Connect.',
													'helpdesk-hero-hub'
											  )
											: __(
													'Open this link and enter your site’s address. It takes you to that site’s dashboard with everything filled in; install the free Helpdesk Hero plugin there first.',
													'helpdesk-hero-hub'
											  ) }
									</p>
									<CopyBox
										value={ invite.link }
										label={ __( 'Copy link', 'helpdesk-hero-hub' ) }
										note={ __( 'Works once and expires in 7 days.', 'helpdesk-hero-hub' ) }
									/>
									<div className="hdh-row">
										{ how === 'customer' && site.email && (
											<a className="hdh-btn is-primary" href={ mailto( site.email, invite.link ) }>
												<Icon name="mail" size={ 15 } />
												{ sprintf(
													/* translators: %s: email address */
													__( 'Email it to %s', 'helpdesk-hero-hub' ),
													site.email
												) }
											</a>
										) }
										{ how === 'own' && (
											<a
												className="hdh-btn is-primary"
												href={ invite.link }
												target="_blank"
												rel="noopener noreferrer"
											>
												<Icon name="external" size={ 15 } />
												{ __( 'Open the link', 'helpdesk-hero-hub' ) }
											</a>
										) }
									</div>
									<details className="hdh-details">
										<summary>
											{ __( 'Prefer a code to paste?', 'helpdesk-hero-hub' ) }
										</summary>
										<p className="hdh-muted" style={ { fontSize: 13 } }>
											{ __(
												'Same thing, without the link: in the site’s dashboard, open Get Help and paste this code.',
												'helpdesk-hero-hub'
											) }
										</p>
										<CopyBox
											value={ invite.code }
											label={ __( 'Copy code', 'helpdesk-hero-hub' ) }
											rows={ 3 }
										/>
									</details>
								</>
							) }

							<div className="hdh-wait" role="status">
								<span className="hdh-wait__dot" aria-hidden="true" />
								{ __(
									'Waiting for the site to connect. This page updates by itself; you can also leave it. The site waits under Sites.',
									'helpdesk-hero-hub'
								) }
							</div>
						</div>
					</div>
				) }

				{ step === 2 && (
					<div className="hdh-wizard__body" style={ { textAlign: 'center' } }>
						<span
							className="hdh-savings__icon"
							style={ { width: 52, height: 52, margin: '0 auto 14px' } }
						>
							<Icon name="check" size={ 24 } />
						</span>
						<h1 className="hdh-wizard__title">
							{ sprintf(
								/* translators: %s: site name */
								__( '%s is connected', 'helpdesk-hero-hub' ),
								site.name || site.label
							) }
						</h1>
						<p className="hdh-wizard__lede" style={ { margin: '0 auto' } }>
							{ __(
								'Their tickets arrive in your Inbox, with their site’s details attached. Your policy applies to the site now.',
								'helpdesk-hero-hub'
							) }
						</p>
					</div>
				) }

				<footer className="hdh-wizard__foot">
					{ step === 0 && (
						<>
							<Button variant="ghost" onClick={ () => go( 'sites' ) } disabled={ busy }>
								{ __( 'Cancel', 'helpdesk-hero-hub' ) }
							</Button>
							<span className="hdh-spacer" />
							<Button
								variant="primary"
								icon="link"
								onClick={ create }
								disabled={ busy || ! form.label.trim() }
							>
								{ __( 'Create connection link', 'helpdesk-hero-hub' ) }
							</Button>
						</>
					) }
					{ step === 1 && (
						<>
							<span className="hdh-spacer" />
							<Button onClick={ () => go( 'sites' ) }>
								{ __( 'Done for now', 'helpdesk-hero-hub' ) }
							</Button>
						</>
					) }
					{ step === 2 && (
						<>
							<Button variant="ghost" onClick={ restart }>
								{ __( 'Connect another site', 'helpdesk-hero-hub' ) }
							</Button>
							<span className="hdh-spacer" />
							<Button onClick={ () => go( `sites/${ site.id }` ) }>
								{ __( 'Open site', 'helpdesk-hero-hub' ) }
							</Button>
							<Button variant="primary" onClick={ () => go( 'inbox' ) }>
								{ __( 'Go to the Inbox', 'helpdesk-hero-hub' ) }
							</Button>
						</>
					) }
				</footer>
			</section>
		</div>
	);
}
