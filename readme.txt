=== Helpdesk Hero Hub ===
Contributors: mindanticipation
Tags: support, helpdesk, tickets, client sites, remote access
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Support hub for agencies, freelancers and plugin vendors: tickets with diagnostics from customer sites, one-click logins without passwords.

== Description ==

Your customers install the free **Helpdesk Hero** plugin and connect it to your hub with a code. From then on:

* Tickets arrive with everything you'd otherwise ask for: WordPress, PHP and server versions, plugins and themes, recent errors, what changed recently, and a health check that points at likely causes.
* You log in to the customer's site with **one click** while they've granted access. Each click creates a fresh single-use link; no passwords are ever shared, and the temporary account is deleted when access ends.
* Everything your team does on the site is logged for the customer, which builds trust.

No limit on sites, tickets or team members, and nothing in this plugin is locked or time-limited.

**[Try the live demo](https://george-stathopoulos.github.io/helpdesk-hero/demo/)**: a hub and a customer site in your browser, with example tickets.

= You set the policy =

Your **support policy** decides what customers see and can choose:

* Site access: asked with each ticket, always included, or never used.
* Which access levels you accept (an administrator without user management or code editing, a full administrator, editor, shop manager), and whether customers can choose.
* Default and maximum length of access, and whether customers can change it.
* More time: the customer approves each request, or it's granted automatically within your maximum.
* Plugin installs, troubleshooting mode, logging of page views, ending access when a ticket closes.
* Whether customers can reply and close tickets from their dashboard, priorities, categories, a note on the New ticket screen, and an "email it myself" address.
* Which site details are required, ticked, unticked or never sent.

Save policies as **templates** (Standard, Hands-off, Strict and Full service to start from) and apply them to one site or many at once. Edit a template and every site using it follows.

= Also in the hub =

* A two-minute setup guide when you activate the hub.
* Inbox with search and filters; ticket pages with the conversation, health check and full diagnostics.
* Ticket **tags** with colours, shown to customers on their tickets too.
* **Overview** statistics: tickets per day, categories, recurring issues across all your customers, and response times.
* Ask for more time, send a message to the customer's dashboard (with a button such as "Update plugins"), see what your team did on the site.
* Sites list with versions, last contact, connection checks and bulk policy changes.
* Tickets customers email themselves still reach the hub, with their diagnostics, once they confirm they sent them.
* New tickets and replies are emailed to your team.
* **Backup and restore:** download the whole hub (settings, policies, sites with their connection keys, tickets and history) as one file, and restore it after a reinstall or a move. Sites reconnect on their own.
* WP-CLI commands and hooks for developers.

= Helpdesk Hero Pro (optional) =

The optional **Helpdesk Hero Pro** add-on adds **advanced analytics** (any date range, per site, ratings, PDF reports), **white label** for customers' help centers (name, logo, colour, links and text), **customer ratings**, **Supporters** (choose who replies and logs in, with personal accounts on customer sites), a Support Agent role, AI triage and messages to every site. Help Scout and Zendesk connections are coming soon. Pro is sold separately and isn't needed for anything described above.

== External services ==

The hub talks to one kind of outside service: **connected customer sites**. When you act in the hub (reply, log in, ask for more time, send a message, check a connection, change a policy), the hub sends a signed request to that customer's site. Customer sites also call the hub to send tickets and fetch updates. Only sites you created a connection code for can connect, and you can disconnect them at any time. Nothing is sent to the makers of Helpdesk Hero.

== Installation ==

1. Install and activate Helpdesk Hero Hub on your own (support team's) WordPress site.
2. The setup guide opens: enter your team name, choose a support policy, and create a connection code for your first customer.
3. Send the code to your customer. They install the free Helpdesk Hero plugin, open **Get Help** and paste it.

== Frequently Asked Questions ==

= What do my customers need? =

The free Helpdesk Hero plugin from WordPress.org, and the connection code you give them.

= Is there a server or account in the middle? =

No. Your hub and your customers' sites talk to each other directly, with signed requests.

= Do I need Help Scout or Zendesk? =

No. The hub has its own inbox and emails your team. Help Scout and Zendesk connections are coming soon in Helpdesk Hero Pro.

= Can agents use the hub without being administrators? =

In the free hub, the hub is for administrators. Helpdesk Hero Pro adds a Support Agent role and Supporters.

= What happens to customers if I uninstall the hub? =

Their sites can no longer send tickets to you, and you can no longer log in. Their own tickets and logs stay on their sites. Download a backup under Settings first: restore it after reinstalling and everything comes back, with sites reconnecting on their own.

== Screenshots ==

1. The Overview: tickets per day, categories, recurring issues and response times.
2. The Inbox with tickets from every connected site.
3. A ticket: the conversation, health check, one-click login and tags.
4. Connected sites, with policies and bulk changes.
5. Policies and templates.
6. Settings and ticket tags.

== Changelog ==

= 2.0.0 =
* First release as a separate plugin, with support policies.
* Policy templates, applied to one site or many at once.
* Overview statistics: categories, recurring issues and response times.
* Ticket tags, shown to customers.
* Emailed tickets reach the hub once the customer confirms they sent them.
* Setup guide, and an invitation to share feedback and ideas.
* Export and erase personal data with WordPress's privacy tools.
* Backup and restore of the whole hub.
* Confirmations open in the dashboard (no browser pop-ups), so they work everywhere, including WordPress Playground.
* Policy templates and site policies open in a wide editor.
