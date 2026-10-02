import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { send } from '../ui/lib/hooks';
import { Card, PageHead, Button } from '../ui/components/ui';
import { Check, TextArea } from '../ui/components/kit';
import Icon from '../ui/components/Icon';

const boot = window.hdhBoot || {};

const TOPICS = [
	__( 'Faster replies (saved replies, macros)', 'helpdesk-hero-hub' ),
	__( 'Better conflict detection', 'helpdesk-hero-hub' ),
	__(
		'More help desks (Freshdesk, Intercom, Gorgias…)',
		'helpdesk-hero-hub'
	),
	__( 'Slack or Teams notifications', 'helpdesk-hero-hub' ),
	__( 'SLAs and response-time targets', 'helpdesk-hero-hub' ),
	__( 'Client reports and billing hours', 'helpdesk-hero-hub' ),
	__( 'Screen recordings and screenshots in tickets', 'helpdesk-hero-hub' ),
	__( 'Knowledge base / self-help before tickets', 'helpdesk-hero-hub' ),
	__( 'Uptime and site health monitoring', 'helpdesk-hero-hub' ),
	__( 'Multisite networks', 'helpdesk-hero-hub' ),
];

/**
 * Asks for a review and feature requests. Nothing is sent from the site: the request opens as a
 * pre-filled GitHub issue that the person can read, edit and submit themselves.
 *
 * @return {JSX.Element} Page.
 */
export default function Feedback() {
	const [ picked, setPicked ] = useState( [] );
	const [ note, setNote ] = useState( '' );
	const [ role, setRole ] = useState( '' );

	const issueUrl = () => {
		const body = [
			'**What matters most when I handle tickets for customers in WordPress**',
			'',
			...picked.map( ( p ) => `- [x] ${ p }` ),
			'',
			note ? `**In my own words**\n\n${ note }\n` : '',
			role ? `**About me:** ${ role }` : '',
			`_Helpdesk Hero Hub ${ boot.version }_`,
		].join( '\n' );
		const params = new URLSearchParams( {
			title:
				__( 'Feature request:', 'helpdesk-hero-hub' ) +
				( picked[ 0 ] || __( 'my wish list', 'helpdesk-hero-hub' ) ),
			labels: 'feedback',
			body,
		} );
		return `${ boot.issuesUrl }?${ params.toString() }`;
	};

	return (
		<>
			<PageHead
				title={ __( 'Feedback', 'helpdesk-hero-hub' ) }
				lede={ __(
					'Helpdesk Hero is young, and what support teams ask for decides what comes next. Thank you for taking a minute.',
					'helpdesk-hero-hub'
				) }
			/>
			<div className="hdh-split">
				<div className="hdh-split__main">
					<Card
						title={ __(
							'What would help you most?',
							'helpdesk-hero-hub'
						) }
						sub={ __(
							'Tick everything that matters when you handle tickets for your customers’ sites.',
							'helpdesk-hero-hub'
						) }
					>
						<div className="hdh-stack" style={ { gap: 0 } }>
							{ TOPICS.map( ( t ) => (
								<Check
									key={ t }
									label={ t }
									checked={ picked.includes( t ) }
									onChange={ ( on ) =>
										setPicked(
											on
												? [ ...picked, t ]
												: picked.filter(
														( x ) => x !== t
												  )
										)
									}
								/>
							) ) }
						</div>
						<div className="hdh-stack" style={ { marginTop: 14 } }>
							<TextArea
								label={ __(
									'Anything else? What slows you down today?',
									'helpdesk-hero-hub'
								) }
								value={ note }
								onChange={ setNote }
								rows={ 4 }
							/>
							<div className="hdh-field">
								<label htmlFor="hdh-role">
									{ __(
										'You are… (optional)',
										'helpdesk-hero-hub'
									) }
								</label>
								<select
									id="hdh-role"
									className="hdh-input hdh-select"
									value={ role }
									onChange={ ( e ) =>
										setRole( e.target.value )
									}
								>
									<option value="">—</option>
									<option>
										{ __(
											'An agency',
											'helpdesk-hero-hub'
										) }
									</option>
									<option>
										{ __(
											'A freelancer',
											'helpdesk-hero-hub'
										) }
									</option>
									<option>
										{ __(
											'A plugin or theme vendor',
											'helpdesk-hero-hub'
										) }
									</option>
									<option>
										{ __( 'A host', 'helpdesk-hero-hub' ) }
									</option>
									<option>
										{ __(
											'An in-house team',
											'helpdesk-hero-hub'
										) }
									</option>
								</select>
							</div>
							<div className="hdh-row">
								<a
									className="hdh-btn is-primary"
									href={ issueUrl() }
									target="_blank"
									rel="noopener noreferrer"
									onClick={ () =>
										send( 'admin/feedback', 'POST' )
									}
								>
									<Icon name="send" size={ 15 } />
									{ __(
										'Send as a GitHub request',
										'helpdesk-hero-hub'
									) }
								</a>
							</div>
							<p
								className="hdh-muted"
								style={ { fontSize: 12.5 } }
							>
								{ __(
									'Opens a pre-filled request on GitHub that you can read and edit first. Nothing is sent from your site.',
									'helpdesk-hero-hub'
								) }
							</p>
						</div>
					</Card>
				</div>
				<aside className="hdh-split__side">
					<Card
						title={ __( 'Enjoying the hub?', 'helpdesk-hero-hub' ) }
					>
						<p>
							{ __(
								'A short review on WordPress.org helps other support teams find it, and keeps it free.',
								'helpdesk-hero-hub'
							) }
						</p>
						<p style={ { marginTop: 12 } }>
							<a
								className="hdh-btn"
								href={ boot.reviewUrl }
								target="_blank"
								rel="noopener noreferrer"
								onClick={ () =>
									send( 'admin/feedback', 'POST' )
								}
							>
								<Icon name="spark" size={ 15 } />
								{ __( 'Write a review', 'helpdesk-hero-hub' ) }
							</a>
						</p>
					</Card>
					<Card
						title={ __(
							'Something not working?',
							'helpdesk-hero-hub'
						) }
					>
						<p>
							{ __(
								'Ask in the support forum and we’ll help.',
								'helpdesk-hero-hub'
							) }
						</p>
						<p style={ { marginTop: 12 } }>
							<a
								className="hdh-btn is-ghost"
								href={ boot.supportUrl }
								target="_blank"
								rel="noopener noreferrer"
							>
								{ __( 'Support forum', 'helpdesk-hero-hub' ) }
							</a>
						</p>
					</Card>
					<Button
						variant="ghost"
						size="sm"
						onClick={ () => {
							send( 'admin/feedback', 'POST' );
							window.location.hash = '#/overview';
						} }
					>
						{ __( 'Don’t ask me again', 'helpdesk-hero-hub' ) }
					</Button>
				</aside>
			</div>
		</>
	);
}
