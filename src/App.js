import { __, sprintf } from '@wordpress/i18n';
import { applyFilters } from '@wordpress/hooks';
import { useEffect, useMemo } from '@wordpress/element';
import { useRoute, useTheme } from './ui/lib/hooks';
import { ToastProvider } from './ui/components/ui';
import Icon from './ui/components/Icon';
import Overview from './pages/Overview';
import Inbox from './pages/Inbox';
import Ticket from './pages/Ticket';
import Sites from './pages/Sites';
import Policies from './pages/Policies';
import Helpdesk from './pages/Helpdesk';
import Settings from './pages/Settings';
import Welcome from './pages/Welcome';
import Feedback from './pages/Feedback';

const boot = window.hdhBoot || {};

const ROUTES = [
	{
		id: 'overview',
		label: __( 'Overview', 'helpdesk-hero-hub' ),
		icon: 'overview',
		Page: Overview,
	},
	{
		id: 'inbox',
		label: __( 'Inbox', 'helpdesk-hero-hub' ),
		icon: 'inbox',
		Page: Inbox,
	},
	{
		id: 'sites',
		label: __( 'Sites', 'helpdesk-hero-hub' ),
		icon: 'globe',
		Page: Sites,
	},
	{
		id: 'policies',
		label: __( 'Policies', 'helpdesk-hero-hub' ),
		icon: 'sliders',
		Page: Policies,
		admin: true,
	},
	{
		id: 'helpdesk',
		label: __( 'Help desk', 'helpdesk-hero-hub' ),
		icon: 'plug',
		Page: Helpdesk,
		admin: true,
	},
	{
		id: 'settings',
		label: __( 'Settings', 'helpdesk-hero-hub' ),
		icon: 'settings',
		Page: Settings,
		admin: true,
	},
];

const THEMES = [
	{
		id: 'system',
		icon: 'monitor',
		label: __( 'Theme: match system', 'helpdesk-hero-hub' ),
	},
	{
		id: 'light',
		icon: 'sun',
		label: __( 'Theme: light', 'helpdesk-hero-hub' ),
	},
	{
		id: 'dark',
		icon: 'moon',
		label: __( 'Theme: dark', 'helpdesk-hero-hub' ),
	},
];

export default function App() {
	const [ route, go ] = useRoute( 'overview' );
	const [ theme, setTheme ] = useTheme();
	/**
	 * Filters the hub pages. Add-ons append (or replace by id) `{ id, label, icon, Page, admin }`.
	 */
	const routes = useMemo(
		() =>
			applyFilters( 'helpdeskHeroHub.routes', ROUTES ).filter(
				( r ) => ! r.admin || boot.canManage
			),
		[]
	);
	const [ section, param ] = route.split( '/' );
	const hidden = {
		ticket: { id: 'inbox', Page: Ticket },
		welcome: { id: 'welcome', Page: Welcome },
		feedback: { id: 'feedback', Page: Feedback },
	};
	const current =
		hidden[ section ] ||
		routes.find( ( r ) => r.id === section ) ||
		routes[ 0 ];
	const { Page } = current;

	// First visit after installing: open the setup guide until it's finished or skipped.
	useEffect( () => {
		if ( ! boot.onboarded && boot.canManage && section !== 'welcome' ) {
			go( 'welcome' );
		}
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	const themeIndex = Math.max(
		0,
		THEMES.findIndex( ( t ) => t.id === theme )
	);
	const nextTheme = THEMES[ ( themeIndex + 1 ) % THEMES.length ];

	return (
		<div
			className="hdh-root"
			data-theme={ theme === 'system' ? undefined : theme }
		>
			<ToastProvider>
				<header className="hdh-header">
					<div className="hdh-header__inner">
						<div className="hdh-brand">
							<span className="hdh-brand__mark">
								<Icon name="ring" size={ 20 } />
							</span>
							<span>
								<div className="hdh-brand__name">
									{ __( 'Support Hub', 'helpdesk-hero-hub' ) }
								</div>
								<div className="hdh-brand__sub">
									{ boot.teamName }
								</div>
							</span>
						</div>
						<nav
							className="hdh-nav"
							aria-label={ __(
								'Support Hub sections',
								'helpdesk-hero-hub'
							) }
						>
							{ routes.map( ( r ) => (
								<a
									key={ r.id }
									href={ `#/${ r.id }` }
									className={ `hdh-nav__item ${
										r.id === current.id ? 'is-active' : ''
									}` }
									aria-current={
										r.id === current.id ? 'page' : undefined
									}
								>
									<Icon name={ r.icon } size={ 15 } />
									{ r.label }
								</a>
							) ) }
						</nav>
						<div className="hdh-header__end">
							<a
								href="#/feedback"
								className={ `hdh-btn is-ghost is-sm ${
									current.id === 'feedback' ? 'is-active' : ''
								}` }
							>
								<Icon name="message" size={ 15 } />
								{ __( 'Feedback', 'helpdesk-hero-hub' ) }
							</a>
							<button
								type="button"
								className="hdh-btn is-ghost is-icon"
								onClick={ () => setTheme( nextTheme.id ) }
								title={ THEMES[ themeIndex ].label }
								aria-label={ `${
									THEMES[ themeIndex ].label
								}. ${ __(
									'Click to change.',
									'helpdesk-hero-hub'
								) }` }
							>
								<Icon
									name={ THEMES[ themeIndex ].icon }
									size={ 17 }
								/>
							</button>
						</div>
					</div>
				</header>
				<main className="hdh-shell" key={ route }>
					<Page go={ go } param={ param } />
				</main>
				<footer className="hdh-footer">
					<span>
						{ __( 'Helpdesk Hero Hub', 'helpdesk-hero-hub' ) }
					</span>
					<span className="hdh-footer__links">
						<a
							href={ boot.docsUrl }
							target="_blank"
							rel="noopener noreferrer"
						>
							{ __( 'Help center', 'helpdesk-hero-hub' ) }
						</a>
						<a
							href={ boot.customerPluginUrl }
							target="_blank"
							rel="noopener noreferrer"
						>
							{ __( 'Customer plugin', 'helpdesk-hero-hub' ) }
						</a>
						<a
							href={ boot.supportUrl }
							target="_blank"
							rel="noopener noreferrer"
						>
							{ __( 'Support', 'helpdesk-hero-hub' ) }
						</a>
						<span>
							{ sprintf(
								/* translators: %s: plugin version number. */
								__( 'Version %s', 'helpdesk-hero-hub' ),
								boot.version
							) }
						</span>
					</span>
				</footer>
			</ToastProvider>
		</div>
	);
}
