=== CoWeb Outgoing Mail Archive ===
Contributors: yanivsasson1
Tags: email, mail log, smtp, development, debugging
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.3.3
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Keep a private log of WordPress email attempts with search, bulk deletion and automatic retention limits.

== Description ==

CoWeb Outgoing Mail Archive is a local, bounded troubleshooting mailbox for site maintainers who already have a working mail transport. It records new email attempts made through WordPress wp_mail() in an administrator-only mailbox.

The default mailbox keeps at most 100 messages with a 30-day age limit. Message bodies are shown as escaped source: opening a logged email does not render its HTML or load its remote images. Headers and attachments are not copied. No account, external service or SMTP reconfiguration is required. The mailbox shows the site URL and environment type to help maintainers distinguish local, staging and production installations.

This is a focused inspection workflow, with no resend, forwarding or delivery-tracking controls. Reported sending outcomes are explicitly distinguished from confirmed recipient delivery.

* View recipients, subjects, message source, timestamps and reported sending outcomes.
* Search by subject or recipient.
* Delete individual messages, selected messages or all stored messages.
* Set a maximum number of stored messages (100 by default). Older entries are removed first.
* Automatically remove entries older than 30 days through WP-Cron, when logging mail and when opening the mailbox.
* Keep independent records on local, staging and production installations.

The plugin leaves your existing sending configuration in place. A successful sending outcome is not proof of delivery, inbox placement or reading. A third-party plugin can short-circuit wp_mail(); its return value is recorded without independent delivery confirmation. Queued mail plugins may create an additional entry when a queued message is processed.

Direct SMTP, external API calls that bypass wp_mail() and other programs on the server are outside the scope of this plugin. Historical mail is not imported. Attachments and mail headers are not stored. HTML messages are displayed as escaped source without executing scripts or loading remote images.

Developed by [Yaniv Sasson](https://yanivsasson.com/).
[Plugin page](https://coweb.co.il/plugins/outgoing-mail-archive/) | [CoWeb plugins](https://coweb.co.il/plugins/)

== Installation ==

1. Upload the plugin ZIP through Plugins > Add New > Upload Plugin.
2. Activate CoWeb Outgoing Mail Archive.
3. Open CoWeb Outgoing Mail Archive in the administration menu.
4. Send a new email through WordPress to see its record.

== Frequently Asked Questions ==

= Does this plugin send emails? =

It logs email attempts and does not change your existing mail transport. It is not an SMTP provider or a mail catcher such as Mailpit.

= Who can read the messages? =

Users with the manage_options capability on the current site. Content may include personal data and password reset links. Restrict administrator access accordingly.

= Where is data stored? =

In a custom table in the WordPress database. CoWeb Outgoing Mail Archive makes no external requests, loads no remote assets and sends no telemetry. Your existing mail transport may independently contact its provider.

= How long are records kept? =

At most 30 days, subject also to the configured count limit, which defaults to 100. Lowering the limit immediately deletes excess entries. Scheduled deletion depends on WP-Cron execution. Cleanup also runs when logging mail and opening the mailbox. Backups may retain deleted data according to your backup policy.

= What happens on deactivation or uninstall? =

Deactivation preserves data. Uninstallation permanently removes the plugin tables and settings.

= Are local and cloud sites synchronized? =

No. Every installation has a separate mailbox.

== Privacy ==

Stored fields: recipients, subject, message body, UTC timestamp, reported sending status and error text. Records may contain personal or sensitive information. No attachments or headers are copied. CoWeb Outgoing Mail Archive does not transmit data to the author or any external service. Access requires manage_options. Retention and manual deletion controls are available in the mailbox. Consider this logging when writing your site's privacy policy.

== Changelog ==

= 1.3.3 =
* Shortened the admin menu label and mailbox page title to Outgoing Mail Archive.

= 1.3.2 =
* Renamed to CoWeb Outgoing Mail Archive with matching text domain.
* Use WordPress.org language packs instead of bundled translations and manual loading.
* Clarified the bounded local troubleshooting workflow.

= 1.3.1 =
* Prepared public release with translatable UI, local admin assets and submission documentation.

= 1.2.0 =
* Added a configurable count limit, defaulting to 100 messages.

= 1.1.0 =
* Added individual and bulk deletion.

= 1.0.0 =
* Initial private release.

== Upgrade Notice ==

= 1.3.3 =
Shorter admin menu label and page title. No data changes.

= 1.3.2 =
Renamed for directory review. Existing mailbox records and settings use the same storage identifiers. Translations are distributed through WordPress.org language packs.

= 1.3.1 =
Public release preparation. Existing mailbox records and count settings are preserved.
