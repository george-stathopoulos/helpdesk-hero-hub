import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { Card, Button } from '../ui/components/ui';
import { TextArea, TextField, SelectField } from '../ui/components/kit';

/**
 * Form for a notice shown in a customer's wp-admin.
 *
 * @param {Object}   props        Props.
 * @param {Function} props.onSend Called with ( notice, reset ).
 * @param {boolean}  props.busy   Sending.
 * @param {string}   props.title  Card title (optional).
 * @return {JSX.Element} Card.
 */
export default function NoticeCard( { onSend, busy, title } ) {
	const empty = {
		title: '',
		body: '',
		level: 'info',
		action_label: '',
		action_url: '',
	};
	const [ n, setN ] = useState( empty );
	const [ open, setOpen ] = useState( false );
	const set = ( k ) => ( v ) => setN( ( s ) => ( { ...s, [ k ]: v } ) );
	return (
		<Card
			title={
				title || __( 'Message in their dashboard', 'helpdesk-hero-hub' )
			}
			sub={ __(
				'A notice in the customer’s wp-admin, with an optional button.',
				'helpdesk-hero-hub'
			) }
		>
			{ ! open ? (
				<Button
					size="sm"
					icon="message"
					onClick={ () => setOpen( true ) }
				>
					{ __( 'Write a message', 'helpdesk-hero-hub' ) }
				</Button>
			) : (
				<div className="hdh-stack">
					<TextField
						label={ __( 'Title', 'helpdesk-hero-hub' ) }
						value={ n.title }
						onChange={ set( 'title' ) }
						placeholder={ __(
							'Please update WooCommerce',
							'helpdesk-hero-hub'
						) }
					/>
					<TextArea
						label={ __( 'Message', 'helpdesk-hero-hub' ) }
						value={ n.body }
						onChange={ set( 'body' ) }
						rows={ 3 }
					/>
					<SelectField
						label={ __( 'Type', 'helpdesk-hero-hub' ) }
						value={ n.level }
						onChange={ set( 'level' ) }
						options={ [
							{
								value: 'info',
								label: __( 'Information', 'helpdesk-hero-hub' ),
							},
							{
								value: 'success',
								label: __( 'Good news', 'helpdesk-hero-hub' ),
							},
							{
								value: 'warning',
								label: __(
									'Action needed',
									'helpdesk-hero-hub'
								),
							},
							{
								value: 'error',
								label: __( 'Urgent', 'helpdesk-hero-hub' ),
							},
						] }
					/>
					<div className="hdh-form-grid">
						<TextField
							label={ __( 'Button text', 'helpdesk-hero-hub' ) }
							value={ n.action_label }
							onChange={ set( 'action_label' ) }
							placeholder={ __(
								'Update plugins',
								'helpdesk-hero-hub'
							) }
						/>
						<TextField
							label={ __( 'Button link', 'helpdesk-hero-hub' ) }
							value={ n.action_url }
							onChange={ set( 'action_url' ) }
							placeholder="admin:plugins.php"
							help={ __(
								'admin: for a page in their dashboard, or https://',
								'helpdesk-hero-hub'
							) }
						/>
					</div>
					<div className="hdh-row">
						<Button
							variant="primary"
							size="sm"
							icon="send"
							disabled={ busy || ! n.title.trim() }
							onClick={ () =>
								onSend( n, () => {
									setN( empty );
									setOpen( false );
								} )
							}
						>
							{ __( 'Send message', 'helpdesk-hero-hub' ) }
						</Button>
						<Button
							size="sm"
							variant="ghost"
							onClick={ () => setOpen( false ) }
						>
							{ __( 'Cancel', 'helpdesk-hero-hub' ) }
						</Button>
					</div>
				</div>
			) }
		</Card>
	);
}
