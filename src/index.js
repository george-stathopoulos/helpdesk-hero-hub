import { createRoot } from '@wordpress/element';
import App from './App';
import * as ui from './ui/components/ui';
import * as kit from './ui/components/kit';
import Icon from './ui/components/Icon';
import * as hooks from './ui/lib/hooks';
import * as time from './ui/lib/time';
import PolicyEditor from './components/PolicyEditor';
import './ui/style.scss';

/**
 * Building blocks for add-ons (Helpdesk Hero Pro). An add-on script that depends on the
 * `helpdesk-hero-hub-admin` handle can use these, and register pages and panels with the
 * `helpdeskHeroHub.*` filters from `@wordpress/hooks`.
 */
window.helpdeskHeroHub = { ui, kit, Icon, hooks, time, PolicyEditor };

function mount() {
	const root = document.getElementById( 'hdh-root' );
	if ( ! root ) {
		return;
	}
	root.classList.remove( 'hdh-root' );
	createRoot( root ).render( <App /> );
}

// Render after every footer script has run, so add-ons can register first.
if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', mount );
} else {
	mount();
}
