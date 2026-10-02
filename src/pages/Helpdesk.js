import { __ } from '@wordpress/i18n';
import { Card, PageHead } from '../ui/components/ui';
import Icon from '../ui/components/Icon';

/**
 * Where tickets are answered. The free hub has its own inbox and emails the team;
 * Helpdesk Hero Pro replaces this page with Help Scout and Zendesk settings.
 *
 * @return {JSX.Element} Page.
 */
export default function Helpdesk() {
	return (
		<>
			<PageHead
				title={ __( 'Help desk', 'helpdesk-hero-hub' ) }
				lede={ __(
					'Where your team answers tickets.',
					'helpdesk-hero-hub'
				) }
			/>
			<div
				className="hdh-choices"
				style={ { gridTemplateColumns: 'repeat(2, minmax(0, 1fr))' } }
			>
				<div className="hdh-choice is-active">
					<Icon name="inbox" size={ 22 } />
					<strong>
						{ __( 'Hub inbox and email', 'helpdesk-hero-hub' ) }
					</strong>
					<span>
						{ __(
							'In use. New tickets and customer replies are emailed to your team (Settings › Team email), and you answer in the Inbox. Customers see your replies in their dashboard and get an email.',
							'helpdesk-hero-hub'
						) }
					</span>
				</div>
				<div className="hdh-choice is-disabled" aria-disabled="true">
					<Icon name="plug" size={ 22 } />
					<strong>
						{ __( 'Help Scout and Zendesk', 'helpdesk-hero-hub' ) }
						<span className="hdh-soon">
							{ __( 'Coming soon', 'helpdesk-hero-hub' ) }
						</span>
					</strong>
					<span>
						{ __(
							'Tickets will become Help Scout conversations or Zendesk tickets with the diagnostics attached, and replies will sync both ways.',
							'helpdesk-hero-hub'
						) }
					</span>
				</div>
			</div>
			<Card style={ { marginTop: 18 } }>
				<p className="hdh-muted">
					{ __(
						'Whichever you use, customers keep the same experience: tickets and replies in their dashboard, and your team logs in from the hub.',
						'helpdesk-hero-hub'
					) }
				</p>
			</Card>
		</>
	);
}
