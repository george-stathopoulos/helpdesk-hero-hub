import { __, _n, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { Card, Setting, Switch, Segmented, Button } from '../ui/components/ui';
import { Check, ProBadge } from '../ui/components/kit';
import Icon from '../ui/components/Icon';

const HOURS = [ 1, 4, 8, 24, 48, 72, 168, 336, 720 ];

export function hoursLabel( h ) {
	if ( h < 24 ) {
		return sprintf(
			/* translators: %d: number of hours */
			_n( '%d hour', '%d hours', h, 'helpdesk-hero-hub' ),
			h
		);
	}
	const days = Math.round( h / 24 );
	return sprintf(
		/* translators: %d: number of days */
		_n( '%d day', '%d days', days, 'helpdesk-hero-hub' ),
		days
	);
}

function HoursSelect( { value, onChange, max, label } ) {
	return (
		<select
			className="hdh-input hdh-select"
			aria-label={ label }
			value={ value }
			onChange={ ( e ) => onChange( parseInt( e.target.value, 10 ) ) }
		>
			{ HOURS.filter( ( h ) => ! max || h <= max ).map( ( h ) => (
				<option key={ h } value={ h }>
					{ hoursLabel( h ) }
				</option>
			) ) }
		</select>
	);
}

function Categories( { value, onChange } ) {
	const [ draft, setDraft ] = useState( '' );
	const add = () => {
		const v = draft.trim();
		if ( v && ! value.includes( v ) ) {
			onChange( [ ...value, v ] );
		}
		setDraft( '' );
	};
	return (
		<div className="hdh-stack" style={ { gap: 8, minWidth: 260 } }>
			<div className="hdh-tag-input">
				{ value.map( ( c ) => (
					<span key={ c } className="hdh-chip">
						{ c }
						<button
							type="button"
							aria-label={ __( 'Remove', 'helpdesk-hero-hub' ) }
							onClick={ () =>
								onChange( value.filter( ( x ) => x !== c ) )
							}
						>
							<Icon name="x" size={ 12 } />
						</button>
					</span>
				) ) }
			</div>
			<div className="hdh-row">
				<input
					className="hdh-input"
					value={ draft }
					placeholder={ __( 'Add a category', 'helpdesk-hero-hub' ) }
					aria-label={ __( 'New category', 'helpdesk-hero-hub' ) }
					onChange={ ( e ) => setDraft( e.target.value ) }
					onKeyDown={ ( e ) => {
						if ( e.key === 'Enter' ) {
							e.preventDefault();
							add();
						}
					} }
				/>
				<Button
					size="sm"
					icon="plus"
					onClick={ add }
					disabled={ ! draft.trim() }
				>
					{ __( 'Add', 'helpdesk-hero-hub' ) }
				</Button>
			</div>
		</div>
	);
}

/**
 * Edits a support policy. Controlled: `policy` + `onChange( next )`.
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.policy   Policy.
 * @param {Function} props.onChange Change handler.
 * @param {Object}   props.roles    Role key => label.
 * @param {Object}   props.sections Diagnostics section key => label.
 * @param {boolean}  props.ratings  Whether ratings are available (Pro).
 * @return {JSX.Element} Editor.
 */
export default function PolicyEditor( {
	policy,
	onChange,
	roles,
	sections,
	ratings,
} ) {
	const a = policy.access;
	const t = policy.tickets;
	const setA = ( patch ) =>
		onChange( { ...policy, access: { ...a, ...patch } } );
	const setT = ( patch ) =>
		onChange( { ...policy, tickets: { ...t, ...patch } } );
	const setD = ( key, value ) =>
		onChange( {
			...policy,
			diagnostics: { ...policy.diagnostics, [ key ]: value },
		} );
	const off = a.mode === 'off';
	const modeDesc = {
		off: __(
			'Your team never gets access to customer sites.',
			'helpdesk-hero-hub'
		),
		always: __(
			'Access comes with every ticket. Customers are told before they send.',
			'helpdesk-hero-hub'
		),
		ask: __(
			'Customers choose; the option is ticked for them.',
			'helpdesk-hero-hub'
		),
	};

	const toggleRole = ( role, on ) => {
		const next = on
			? [ ...a.roles, role ]
			: a.roles.filter( ( r ) => r !== role );
		if ( ! next.length ) {
			return;
		}
		setA( {
			roles: next,
			default_role: next.includes( a.default_role )
				? a.default_role
				: next[ 0 ],
		} );
	};

	return (
		<div className="hdh-stack" style={ { gap: 18 } }>
			<Card
				title={ __( 'Site access', 'helpdesk-hero-hub' ) }
				sub={ __(
					'How your team gets into customer sites. Customers only see the choices you allow.',
					'helpdesk-hero-hub'
				) }
				bodyClass={ null }
			>
				<Setting
					title={ __(
						'When customers open a ticket',
						'helpdesk-hero-hub'
					) }
					desc={ modeDesc[ a.mode ] }
				>
					<Segmented
						label={ __( 'Access mode', 'helpdesk-hero-hub' ) }
						value={ a.mode }
						onChange={ ( mode ) => setA( { mode } ) }
						options={ [
							{
								value: 'ask',
								label: __( 'Ask', 'helpdesk-hero-hub' ),
							},
							{
								value: 'always',
								label: __( 'Always', 'helpdesk-hero-hub' ),
							},
							{
								value: 'off',
								label: __( 'Never', 'helpdesk-hero-hub' ),
							},
						] }
					/>
				</Setting>
				{ ! off && (
					<>
						<Setting
							title={ __(
								'Access levels your team accepts',
								'helpdesk-hero-hub'
							) }
							desc={ __(
								'The restricted administrator can’t manage users, edit code, or switch Helpdesk Hero off.',
								'helpdesk-hero-hub'
							) }
						>
							<div style={ { minWidth: 280 } }>
								{ Object.keys( roles ).map( ( r ) => (
									<Check
										key={ r }
										label={ roles[ r ] }
										checked={ a.roles.includes( r ) }
										onChange={ ( on ) =>
											toggleRole( r, on )
										}
									/>
								) ) }
							</div>
						</Setting>
						<Setting
							title={ __(
								'Default access level',
								'helpdesk-hero-hub'
							) }
						>
							<select
								className="hdh-input hdh-select"
								aria-label={ __(
									'Default access level',
									'helpdesk-hero-hub'
								) }
								value={ a.default_role }
								onChange={ ( e ) =>
									setA( { default_role: e.target.value } )
								}
							>
								{ a.roles.map( ( r ) => (
									<option key={ r } value={ r }>
										{ roles[ r ] }
									</option>
								) ) }
							</select>
						</Setting>
						<Setting
							title={ __(
								'Customers can choose the access level',
								'helpdesk-hero-hub'
							) }
							desc={
								a.roles.length < 2
									? __(
											'Accept more than one level to offer a choice.',
											'helpdesk-hero-hub'
									  )
									: __(
											'Off: every grant uses the default level.',
											'helpdesk-hero-hub'
									  )
							}
						>
							<Switch
								label={ __(
									'Customers can choose the access level',
									'helpdesk-hero-hub'
								) }
								checked={
									a.customer_role && a.roles.length > 1
								}
								disabled={ a.roles.length < 2 }
								onChange={ ( v ) =>
									setA( { customer_role: v } )
								}
							/>
						</Setting>
						<Setting
							title={ __(
								'Length of access',
								'helpdesk-hero-hub'
							) }
							desc={ __(
								'Default length, and the longest access can ever last (including extensions).',
								'helpdesk-hero-hub'
							) }
						>
							<div className="hdh-row">
								<HoursSelect
									label={ __(
										'Default length',
										'helpdesk-hero-hub'
									) }
									value={ a.default_hours }
									max={ a.max_hours }
									onChange={ ( v ) =>
										setA( { default_hours: v } )
									}
								/>
								<span className="hdh-muted">
									{ __( 'up to', 'helpdesk-hero-hub' ) }
								</span>
								<HoursSelect
									label={ __(
										'Maximum length',
										'helpdesk-hero-hub'
									) }
									value={ a.max_hours }
									onChange={ ( v ) =>
										setA( {
											max_hours: v,
											default_hours: Math.min(
												a.default_hours,
												v
											),
										} )
									}
								/>
							</div>
						</Setting>
						<Setting
							title={ __(
								'Customers can choose the length',
								'helpdesk-hero-hub'
							) }
							desc={ __(
								'Within the maximum. Off: every grant uses the default length.',
								'helpdesk-hero-hub'
							) }
						>
							<Switch
								label={ __(
									'Customers can choose the length',
									'helpdesk-hero-hub'
								) }
								checked={ a.customer_duration }
								onChange={ ( v ) =>
									setA( { customer_duration: v } )
								}
							/>
						</Setting>
						<Setting
							title={ __(
								'More time when your team asks',
								'helpdesk-hero-hub'
							) }
							desc={
								a.extension === 'auto'
									? __(
											'Granted at once, up to the maximum length. The customer is told.',
											'helpdesk-hero-hub'
									  )
									: __(
											'The customer approves or declines each request.',
											'helpdesk-hero-hub'
									  )
							}
						>
							<Segmented
								label={ __(
									'Extensions',
									'helpdesk-hero-hub'
								) }
								value={ a.extension }
								onChange={ ( extension ) =>
									setA( { extension } )
								}
								options={ [
									{
										value: 'approve',
										label: __(
											'Customer approves',
											'helpdesk-hero-hub'
										),
									},
									{
										value: 'auto',
										label: __(
											'Automatic',
											'helpdesk-hero-hub'
										),
									},
								] }
							/>
						</Setting>
						<Setting
							title={ __(
								'Customers can extend access themselves',
								'helpdesk-hero-hub'
							) }
							desc={ __(
								'Within the maximum length.',
								'helpdesk-hero-hub'
							) }
						>
							<Switch
								label={ __(
									'Customers can extend access',
									'helpdesk-hero-hub'
								) }
								checked={ a.customer_extend }
								onChange={ ( v ) =>
									setA( { customer_extend: v } )
								}
							/>
						</Setting>
						<Setting
							title={ __(
								'Your team can install and delete plugins',
								'helpdesk-hero-hub'
							) }
							desc={ __(
								'Only applies to the restricted administrator level.',
								'helpdesk-hero-hub'
							) }
						>
							<Switch
								label={ __(
									'Plugin installs',
									'helpdesk-hero-hub'
								) }
								checked={ a.plugin_installs }
								onChange={ ( v ) =>
									setA( { plugin_installs: v } )
								}
							/>
						</Setting>
						<Setting
							title={ __(
								'Troubleshooting mode',
								'helpdesk-hero-hub'
							) }
							desc={ __(
								'Lets your team switch plugins off for their own browser session only, to find conflicts.',
								'helpdesk-hero-hub'
							) }
						>
							<Switch
								label={ __(
									'Troubleshooting mode',
									'helpdesk-hero-hub'
								) }
								checked={ a.troubleshooting }
								onChange={ ( v ) =>
									setA( { troubleshooting: v } )
								}
							/>
						</Setting>
						<Setting
							title={ __(
								'Log the pages your team visits',
								'helpdesk-hero-hub'
							) }
							desc={ __(
								'Changes, logins and content edits are always logged for the customer.',
								'helpdesk-hero-hub'
							) }
						>
							<Switch
								label={ __(
									'Log page views',
									'helpdesk-hero-hub'
								) }
								checked={ a.log_page_views }
								onChange={ ( v ) =>
									setA( { log_page_views: v } )
								}
							/>
						</Setting>
						<Setting
							title={ __(
								'End access when the ticket closes',
								'helpdesk-hero-hub'
							) }
						>
							<Switch
								label={ __(
									'End access on close',
									'helpdesk-hero-hub'
								) }
								checked={ a.end_on_close }
								onChange={ ( v ) =>
									setA( { end_on_close: v } )
								}
							/>
						</Setting>
					</>
				) }
			</Card>

			<Card
				title={ __( 'Tickets', 'helpdesk-hero-hub' ) }
				sub={ __(
					'What customers can do from their dashboard.',
					'helpdesk-hero-hub'
				) }
				bodyClass={ null }
			>
				<Setting
					title={ __(
						'Customers can reply from their dashboard',
						'helpdesk-hero-hub'
					) }
					desc={ __(
						'Off: they read your replies there, and contact you another way.',
						'helpdesk-hero-hub'
					) }
				>
					<Switch
						label={ __( 'Customer replies', 'helpdesk-hero-hub' ) }
						checked={ t.customer_replies }
						onChange={ ( v ) => setT( { customer_replies: v } ) }
					/>
				</Setting>
				<Setting
					title={ __(
						'Customers can close and reopen tickets',
						'helpdesk-hero-hub'
					) }
				>
					<Switch
						label={ __(
							'Customer closes tickets',
							'helpdesk-hero-hub'
						) }
						checked={ t.customer_close }
						onChange={ ( v ) => setT( { customer_close: v } ) }
					/>
				</Setting>
				<Setting
					title={ __( 'Ask for a priority', 'helpdesk-hero-hub' ) }
				>
					<Switch
						label={ __( 'Priorities', 'helpdesk-hero-hub' ) }
						checked={ t.priorities }
						onChange={ ( v ) => setT( { priorities: v } ) }
					/>
				</Setting>
				<Setting
					title={ __( 'Categories', 'helpdesk-hero-hub' ) }
					desc={ __(
						'For example your products or topics. Added to help desk tickets as a tag. Leave empty to skip.',
						'helpdesk-hero-hub'
					) }
				>
					<Categories
						value={ t.categories }
						onChange={ ( categories ) => setT( { categories } ) }
					/>
				</Setting>
				<Setting
					title={ __(
						'Note on the New ticket screen',
						'helpdesk-hero-hub'
					) }
					desc={ __(
						'Support hours, what to include, response times…',
						'helpdesk-hero-hub'
					) }
				>
					<textarea
						className="hdh-input hdh-textarea"
						style={ { minHeight: 80, minWidth: 300 } }
						aria-label={ __( 'Note', 'helpdesk-hero-hub' ) }
						value={ t.intro }
						onChange={ ( e ) => setT( { intro: e.target.value } ) }
					/>
				</Setting>
				<Setting
					title={ __(
						'“Email it myself” fallback',
						'helpdesk-hero-hub'
					) }
					desc={ __(
						'An address customers can email the ticket to themselves, for example when their site can’t reach the hub. Leave empty to hide the option.',
						'helpdesk-hero-hub'
					) }
				>
					<input
						type="email"
						className="hdh-input"
						style={ { minWidth: 260 } }
						aria-label={ __(
							'Support email',
							'helpdesk-hero-hub'
						) }
						placeholder="support@example.com"
						value={ t.manual_email }
						onChange={ ( e ) =>
							setT( { manual_email: e.target.value } )
						}
					/>
				</Setting>
				<Setting
					title={ __( 'Writing assistant', 'helpdesk-hero-hub' ) }
					desc={ __(
						'“Help me describe this” on the customer’s side. Uses the customer’s own AI provider (WordPress 7.0+), only when they press it.',
						'helpdesk-hero-hub'
					) }
				>
					<Switch
						label={ __( 'Writing assistant', 'helpdesk-hero-hub' ) }
						checked={ t.ai_assistant }
						onChange={ ( v ) => setT( { ai_assistant: v } ) }
					/>
				</Setting>
				<Setting
					title={
						<>
							{ __(
								'Ask customers to rate support',
								'helpdesk-hero-hub'
							) }
							{ ! ratings && <ProBadge /> }
						</>
					}
					desc={ __(
						'When a ticket is closed or support access ends, customers rate it from 1 to 5 stars with a short comment. Ratings appear on tickets and in analytics.',
						'helpdesk-hero-hub'
					) }
				>
					<Switch
						label={ __( 'Ratings', 'helpdesk-hero-hub' ) }
						checked={ !! ratings && t.ratings }
						disabled={ ! ratings }
						onChange={ ( v ) => setT( { ratings: v } ) }
					/>
				</Setting>
			</Card>

			<Card
				title={ __(
					'Site details sent with tickets',
					'helpdesk-hero-hub'
				) }
				sub={ __(
					'Emails, passwords and keys are always removed. “Required” sections can’t be unticked; customers see them before sending.',
					'helpdesk-hero-hub'
				) }
				bodyClass={ null }
			>
				{ Object.keys( sections ).map( ( key ) => (
					<Setting key={ key } title={ sections[ key ] }>
						<Segmented
							label={ sections[ key ] }
							value={ policy.diagnostics[ key ] }
							onChange={ ( v ) => setD( key, v ) }
							options={ [
								{
									value: 'required',
									label: __(
										'Required',
										'helpdesk-hero-hub'
									),
								},
								{
									value: 'on',
									label: __( 'Ticked', 'helpdesk-hero-hub' ),
								},
								{
									value: 'off',
									label: __(
										'Unticked',
										'helpdesk-hero-hub'
									),
								},
								{
									value: 'never',
									label: __( 'Never', 'helpdesk-hero-hub' ),
								},
							] }
						/>
					</Setting>
				) ) }
			</Card>
		</div>
	);
}
