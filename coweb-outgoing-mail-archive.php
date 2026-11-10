<?php
/**
 * Plugin Name: CoWeb Outgoing Mail Archive
 * Plugin URI: https://coweb.co.il/plugins/outgoing-mail-archive/
 * Author: Yaniv Sasson
 * Author URI: https://yanivsasson.com/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Description: Log WordPress emails in a private admin mailbox with search, deletion, automatic retention limits and optional blocking of WooCommerce test-order emails.
 * Version: 1.4.0
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Text Domain: coweb-outgoing-mail-archive
 *
 * Copyright (C) 2026 Yaniv Sasson. Licensed under GPL-2.0-or-later.
 *
 * @package CoWebOutgoingMailArchive
 */
defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Private custom log table; queries must reflect current sending/deletion results.

final class CoWeb_Outgoing_Mail_Archive {
	const VERSION        = '1.4.0';
	const PAGE           = 'coweb-outgoing-mail-archive';
	const SETTINGS_PAGE  = 'coweb-outgoing-mail-archive-settings';
	const SETTINGS_GROUP = 'site_mailbox';
	const TEST_ORDER_META = '_coweb_test_order';

	private static $pending = array();
	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'site_mailbox';
	}
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( 'CREATE TABLE ' . self::table() . ' (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, created_at datetime NOT NULL, recipients longtext NOT NULL, subject text NOT NULL, message longtext NOT NULL, status varchar(20) NOT NULL, error_message text NOT NULL, PRIMARY KEY  (id), KEY created_at (created_at)) ' . $wpdb->get_charset_collate() . ';' );
		update_option( 'site_mailbox_schema', '1' );
		if ( ! wp_next_scheduled( 'site_mailbox_cleanup' ) ) {
			wp_schedule_event( time(), 'daily', 'site_mailbox_cleanup' );
		}
	}

	/*
	 * Settings.
	 */
	public static function limit() {
		return min( 100000, max( 1, (int) get_option( 'site_mailbox_limit', 100 ) ) );
	}
	public static function retention_days() {
		return min( 365, max( 1, (int) get_option( 'site_mailbox_retention_days', 30 ) ) );
	}
	public static function blocks_test_orders() {
		return 'yes' === get_option( 'site_mailbox_block_test_orders', 'no' );
	}
	public static function cleanup() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s', self::table(), gmdate( 'Y-m-d H:i:s', time() - self::retention_days() * DAY_IN_SECONDS ) ) );
		$cutoff = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i ORDER BY id DESC LIMIT 1 OFFSET %d', self::table(), self::limit() - 1 ) );
		if ( $cutoff ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id < %d', self::table(), $cutoff ) );
		}
	}

	/*
	 * Logging.
	 */
	private static function record( $to, $subject, $message, $status, $error ) {
		global $wpdb;
		$ok = $wpdb->insert(
			self::table(),
			array(
				'created_at'    => current_time( 'mysql', true ),
				'recipients'    => is_array( $to ) ? implode( ', ', $to ) : (string) $to,
				'subject'       => (string) $subject,
				'message'       => (string) $message,
				'status'        => $status,
				'error_message' => $error,
			)
		);
		if ( $ok ) {
			self::cleanup();
		}
		return $ok ? (int) $wpdb->insert_id : 0;
	}
	public static function capture( $result, $atts ) {
		$id = self::record(
			$atts['to'] ?? '',
			$atts['subject'] ?? '',
			$atts['message'] ?? '',
			$result === null ? 'pending' : ( $result ? 'accepted' : 'failed' ),
			$result === null ? '' : __( 'Another plugin handled this attempt; delivery is not confirmed.', 'coweb-outgoing-mail-archive' )
		);
		if ( $result === null ) {
			self::$pending[] = $id;
		}
		return $result;
	}
	public static function finish( $status, $error = '' ) {
		global $wpdb;
		$id = array_pop( self::$pending );
		if ( $id ) {
			$wpdb->update(
				self::table(),
				array(
					'status'        => $status,
					'error_message' => $error,
				),
				array( 'id' => $id )
			);
		}
	}
	public static function label( $s ) {
		return array(
			'pending'  => esc_html__( 'Sending outcome unknown', 'coweb-outgoing-mail-archive' ),
			'accepted' => esc_html__( 'Accepted for sending', 'coweb-outgoing-mail-archive' ),
			'failed'   => esc_html__( 'Sending failed', 'coweb-outgoing-mail-archive' ),
			'blocked'  => esc_html__( 'Blocked, not sent', 'coweb-outgoing-mail-archive' ),
		)[ $s ] ?? $s;
	}

	/*
	 * WooCommerce test orders.
	 *
	 * A test order is an order marked by the CoWeb Test Payment gateway. When blocking is on, the
	 * order's WooCommerce emails are built as usual, recorded with the "blocked" status and never
	 * handed to wp_mail(), so no mail transport can deliver them.
	 */
	public static function is_test_order( $order ) {
		$is_test = $order instanceof WC_Order && 'yes' === $order->get_meta( self::TEST_ORDER_META );
		/**
		 * Filters whether a WooCommerce order is a test order whose emails may be blocked.
		 *
		 * @param bool  $is_test Whether the order is a test order.
		 * @param mixed $order   The email object, usually a WC_Order.
		 */
		return (bool) apply_filters( 'coweb_outgoing_mail_archive_is_test_order', $is_test, $order );
	}
	public static function register_wc_email_filters( $mailer ) {
		// The partial refund email switches to this id only while it is being sent.
		$ids = array( 'customer_partially_refunded_order' );
		foreach ( $mailer->get_emails() as $email ) {
			$ids[] = $email->id;
		}
		foreach ( array_unique( $ids ) as $id ) {
			add_filter( 'woocommerce_email_enabled_' . $id, array( __CLASS__, 'wc_email_enabled' ), PHP_INT_MAX, 3 );
		}
	}
	/**
	 * Another plugin (such as the test gateway) may switch test-order emails off before they are built.
	 * While blocking is on, restore the email's own setting so it reaches the archive; wc_mail_callback()
	 * then records it instead of sending it.
	 */
	public static function wc_email_enabled( $enabled, $order = null, $email = null ) {
		if ( $enabled || ! self::blocks_test_orders() || ! $email instanceof WC_Email || ! self::is_test_order( $order ) ) {
			return $enabled;
		}
		return 'yes' === $email->enabled;
	}
	public static function wc_mail_callback( $callback, $email ) {
		if ( ! self::blocks_test_orders() || ! $email instanceof WC_Email || ! self::is_test_order( $email->object ) ) {
			return $callback;
		}
		return static function ( $to, $subject = '', $message = '' ) {
			self::record( $to, $subject, $message, 'blocked', __( 'WooCommerce test order: the email was recorded and not sent, because "Block emails for test orders" is on.', 'coweb-outgoing-mail-archive' ) );
			return true;
		};
	}

	/*
	 * Mailbox page.
	 */
	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		global $wpdb;
		self::cleanup();
		$settings_url = admin_url( 'admin.php?page=' . self::SETTINGS_PAGE );
		/* translators: %d: number of days records are kept. */
		echo '<div class="wrap" dir="' . esc_attr( is_rtl() ? 'rtl' : 'ltr' ) . '"><h1>' . esc_html__( 'CoWeb Outgoing Mail Archive', 'coweb-outgoing-mail-archive' ) . '</h1><p>' . esc_html( sprintf( __( 'New WordPress email attempts on this site. Records are kept for up to %d days and are visible to administrators only. Sending status does not confirm delivery.', 'coweb-outgoing-mail-archive' ), self::retention_days() ) ) . '</p><p>' . esc_html( home_url() ) . ' · ' . esc_html( wp_get_environment_type() ) . ' · <a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings', 'coweb-outgoing-mail-archive' ) . '</a></p>';
		if ( self::blocks_test_orders() ) {
			echo '<p><strong>' . esc_html__( 'Emails for WooCommerce test orders are blocked and only recorded here.', 'coweb-outgoing-mail-archive' ) . '</strong></p>';
		}
		$id = absint( $_GET['mail_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only administrator search/detail request.
		if ( $id ) {
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', self::table(), $id ) );
			echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ) . '">' . esc_html__( 'Back to all messages', 'coweb-outgoing-mail-archive' ) . '</a></p>';
			if ( ! $row ) {
				echo '<p>' . esc_html__( 'Message not found.', 'coweb-outgoing-mail-archive' ) . '</p></div>';
				return;
			}
			echo '<h2>' . esc_html( $row->subject ) . '</h2><p>' . esc_html__( 'To: ', 'coweb-outgoing-mail-archive' ) . '' . esc_html( $row->recipients ) . '</p><p>' . esc_html( self::label( $row->status ) ) . '</p><p>' . esc_html( $row->error_message ) . '</p><h3>' . esc_html__( 'Message source', 'coweb-outgoing-mail-archive' ) . '</h3><pre style="white-space:pre-wrap;overflow-wrap:anywhere;background:#fff;padding:20px;direction:ltr;text-align:start">' . esc_html( $row->message ) . '</pre>';
			self::delete_form_start();
			echo '<input type="hidden" name="mail_ids[]" value="' . esc_attr( $row->id ) . '"><button class="button" name="delete_scope" value="selected" data-confirm="' . esc_html__( 'Delete this message?', 'coweb-outgoing-mail-archive' ) . '">' . esc_html__( 'Delete message', 'coweb-outgoing-mail-archive' ) . '</button></form></div>';
			return;
		}
		$search = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only administrator search/detail request.
		$page   = max( 1, absint( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only administrator search/detail request.
		$like   = '%' . $wpdb->esc_like( $search ) . '%';
		$total  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE subject LIKE %s OR recipients LIKE %s', self::table(), $like, $like ) );
		$rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE subject LIKE %s OR recipients LIKE %s ORDER BY id DESC LIMIT %d OFFSET %d', self::table(), $like, $like, 30, ( $page - 1 ) * 30 ) );
		echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '"><input type="search" name="s" aria-label="' . esc_html__( 'Search by recipient or subject', 'coweb-outgoing-mail-archive' ) . '" placeholder="' . esc_html__( 'Search by recipient or subject', 'coweb-outgoing-mail-archive' ) . '" value="' . esc_attr( $search ) . '"> <button class="button">' . esc_html__( 'Search', 'coweb-outgoing-mail-archive' ) . '</button></form><p>' . esc_html( number_format_i18n( $total ) ) . ' ' . esc_html__( 'messages', 'coweb-outgoing-mail-archive' ) . '</p>';
		// Start the selection form before the table, without nesting the search form.

		self::delete_form_start();
		echo '<table class="widefat striped"><thead><tr><td><input type="checkbox" aria-label="' . esc_html__( 'Select all messages on this page', 'coweb-outgoing-mail-archive' ) . '" data-mailbox-select-all="1"></td><th>' . esc_html__( 'Date', 'coweb-outgoing-mail-archive' ) . '</th><th>' . esc_html__( 'Recipient', 'coweb-outgoing-mail-archive' ) . '</th><th>' . esc_html__( 'Subject', 'coweb-outgoing-mail-archive' ) . '</th><th>' . esc_html__( 'Status', 'coweb-outgoing-mail-archive' ) . '</th><th>' . esc_html__( 'Actions', 'coweb-outgoing-mail-archive' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$url = add_query_arg(
				array(
					'page'    => self::PAGE,
					'mail_id' => $row->id,
				),
				admin_url( 'admin.php' )
			);
			echo '<tr><td><input type="checkbox" name="mail_ids[]" value="' . esc_attr( $row->id ) . '" aria-label="' . esc_attr( esc_html__( 'Select message: ', 'coweb-outgoing-mail-archive' ) . $row->subject ) . '"></td><td>' . esc_html( get_date_from_gmt( $row->created_at, 'd/m/Y H:i' ) ) . '</td><td>' . esc_html( $row->recipients ) . '</td><td><a href="' . esc_url( $url ) . '">' . esc_html( $row->subject ?: '(' . esc_html__( 'No subject)', 'coweb-outgoing-mail-archive' ) . '' ) . '</a></td><td>' . esc_html( self::label( $row->status ) ) . '</td><td><button class="button-link-delete" name="single_id" value="' . esc_attr( $row->id ) . '" data-confirm="' . esc_html__( 'Delete this message?', 'coweb-outgoing-mail-archive' ) . '">' . esc_html__( 'Delete', 'coweb-outgoing-mail-archive' ) . '</button></td></tr>';
		}
		if ( ! $rows ) {
			echo '<tr><td colspan="6">' . esc_html__( 'No messages yet.', 'coweb-outgoing-mail-archive' ) . '</td></tr>';
		}
		echo '</tbody></table><p><button class="button" name="delete_scope" value="selected" data-confirm="' . esc_html__( 'Delete selected messages?', 'coweb-outgoing-mail-archive' ) . '">' . esc_html__( 'Delete selected', 'coweb-outgoing-mail-archive' ) . '</button></p></form><p>' . wp_kses_post(
			paginate_links(
				array(
					'base'    => add_query_arg(
						array(
							'page'  => self::PAGE,
							's'     => $search,
							'paged' => '%#%',
						),
						admin_url( 'admin.php' )
					),
					'format'  => '',
					'current' => $page,
					'total'   => max( 1, (int) ceil( $total / 30 ) ),
				)
			)
		) . '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-confirm="' . esc_html__( 'Delete all stored messages?', 'coweb-outgoing-mail-archive' ) . '"><input type="hidden" name="action" value="site_mailbox_delete">';
		wp_nonce_field( 'site_mailbox_delete' );
		echo '<input type="hidden" name="delete_scope" value="all"><button class="button">' . esc_html__( 'Delete all messages', 'coweb-outgoing-mail-archive' ) . '</button></form></div>';
	}
	private static function delete_form_start() {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="site_mailbox_delete">';
		wp_nonce_field( 'site_mailbox_delete' );
	}
	public static function delete_records( $ids ) {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		if ( ! $ids ) {
			return 0;
		}
		$deleted = 0;
		foreach ( $ids as $id ) {
			$result = $wpdb->delete( self::table(), array( 'id' => $id ), array( '%d' ) );
			if ( false !== $result ) {
				$deleted += $result;
			}
		}
		return $deleted;
	}
	public static function delete() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'coweb-outgoing-mail-archive' ) );
		}
		check_admin_referer( 'site_mailbox_delete' );
		if ( isset( $_POST['single_id'] ) ) {
			self::delete_records( array( absint( wp_unslash( $_POST['single_id'] ) ) ) );
		} elseif ( sanitize_key( wp_unslash( $_POST['delete_scope'] ?? '' ) ) === 'all' ) {
			global $wpdb;
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', self::table() ) );
		} elseif ( sanitize_key( wp_unslash( $_POST['delete_scope'] ?? '' ) ) === 'selected' ) {
				self::delete_records( array_map( 'absint', (array) wp_unslash( $_POST['mail_ids'] ?? array() ) ) );
		}
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE ) );
			exit;
	}

	/*
	 * Settings page.
	 */
	public static function register_settings() {
		register_setting(
			self::SETTINGS_GROUP,
			'site_mailbox_limit',
			array(
				'type'              => 'integer',
				'default'           => 100,
				'sanitize_callback' => array( __CLASS__, 'sanitize_limit' ),
			)
		);
		register_setting(
			self::SETTINGS_GROUP,
			'site_mailbox_retention_days',
			array(
				'type'              => 'integer',
				'default'           => 30,
				'sanitize_callback' => array( __CLASS__, 'sanitize_retention_days' ),
			)
		);
		register_setting(
			self::SETTINGS_GROUP,
			'site_mailbox_block_test_orders',
			array(
				'type'              => 'string',
				'default'           => 'no',
				'sanitize_callback' => array( __CLASS__, 'sanitize_yes_no' ),
			)
		);
		add_settings_section( 'site_mailbox_storage', esc_html__( 'Storage', 'coweb-outgoing-mail-archive' ), '__return_false', self::SETTINGS_PAGE );
		add_settings_field( 'site_mailbox_limit', esc_html__( 'Maximum stored messages', 'coweb-outgoing-mail-archive' ), array( __CLASS__, 'field_limit' ), self::SETTINGS_PAGE, 'site_mailbox_storage', array( 'label_for' => 'site_mailbox_limit' ) );
		add_settings_field( 'site_mailbox_retention_days', esc_html__( 'Keep messages for', 'coweb-outgoing-mail-archive' ), array( __CLASS__, 'field_retention_days' ), self::SETTINGS_PAGE, 'site_mailbox_storage', array( 'label_for' => 'site_mailbox_retention_days' ) );
		add_settings_section( 'site_mailbox_test_orders', esc_html__( 'WooCommerce test orders', 'coweb-outgoing-mail-archive' ), '__return_false', self::SETTINGS_PAGE );
		add_settings_field( 'site_mailbox_block_test_orders', esc_html__( 'Block emails for test orders', 'coweb-outgoing-mail-archive' ), array( __CLASS__, 'field_block_test_orders' ), self::SETTINGS_PAGE, 'site_mailbox_test_orders' );
	}
	private static function sanitize_int( $value, $option, $min, $max, $fallback, $message ) {
		$int = filter_var(
			$value,
			FILTER_VALIDATE_INT,
			array(
				'options' => array(
					'min_range' => $min,
					'max_range' => $max,
				),
			)
		);
		if ( false === $int ) {
			// The settings API is only loaded in the admin; update_option() may also run elsewhere.
			if ( function_exists( 'add_settings_error' ) ) {
				add_settings_error( $option, $option . '_invalid', $message );
			}
			return $fallback;
		}
		return $int;
	}
	public static function sanitize_limit( $value ) {
		return self::sanitize_int( $value, 'site_mailbox_limit', 1, 100000, self::limit(), esc_html__( 'Enter a whole number between 1 and 100000 for the maximum stored messages.', 'coweb-outgoing-mail-archive' ) );
	}
	public static function sanitize_retention_days( $value ) {
		return self::sanitize_int( $value, 'site_mailbox_retention_days', 1, 365, self::retention_days(), esc_html__( 'Enter a whole number of days between 1 and 365.', 'coweb-outgoing-mail-archive' ) );
	}
	public static function sanitize_yes_no( $value ) {
		return 'yes' === $value ? 'yes' : 'no';
	}
	public static function field_limit() {
		echo '<input id="site_mailbox_limit" class="small-text" type="number" min="1" max="100000" name="site_mailbox_limit" value="' . esc_attr( self::limit() ) . '" required><p class="description">' . esc_html__( 'Oldest records are automatically removed when the limit is exceeded. Lowering the limit immediately deletes excess records.', 'coweb-outgoing-mail-archive' ) . '</p>';
	}
	public static function field_retention_days() {
		echo '<input id="site_mailbox_retention_days" class="small-text" type="number" min="1" max="365" name="site_mailbox_retention_days" value="' . esc_attr( self::retention_days() ) . '" required> ' . esc_html__( 'days', 'coweb-outgoing-mail-archive' ) . '<p class="description">' . esc_html__( 'Older records are removed daily, when mail is logged and when the mailbox is opened. Shortening the period immediately deletes older records.', 'coweb-outgoing-mail-archive' ) . '</p>';
	}
	public static function field_block_test_orders() {
		$active = class_exists( 'WooCommerce' );
		echo '<label for="site_mailbox_block_test_orders"><input id="site_mailbox_block_test_orders" type="checkbox" name="site_mailbox_block_test_orders" value="yes"' . checked( self::blocks_test_orders(), true, false ) . '> ' . esc_html__( 'Do not send WooCommerce emails for test orders. Record them in the mailbox as "Blocked, not sent".', 'coweb-outgoing-mail-archive' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Test orders are orders paid with the CoWeb Test Payment gateway. Neither the customer nor the store receives their emails. This is especially important on staging and production sites, where the test gateway may be open. This setting overrides the gateway\'s own email option.', 'coweb-outgoing-mail-archive' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'When this option is off, test orders follow the test gateway\'s email setting.', 'coweb-outgoing-mail-archive' ) . '</p>';
		if ( ! $active ) {
			echo '<p class="description"><strong>' . esc_html__( 'WooCommerce is not active, so this option currently has no effect.', 'coweb-outgoing-mail-archive' ) . '</strong></p>';
		}
	}
	public static function settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="wrap" dir="' . esc_attr( is_rtl() ? 'rtl' : 'ltr' ) . '"><h1>' . esc_html__( 'Mail archive settings', 'coweb-outgoing-mail-archive' ) . '</h1>';
		settings_errors();
		echo '<form method="post" action="options.php">';
		settings_fields( self::SETTINGS_GROUP );
		do_settings_sections( self::SETTINGS_PAGE );
		submit_button();
		echo '</form></div>';
	}
	public static function admin_menu() {
		add_menu_page( esc_html__( 'CoWeb Outgoing Mail Archive', 'coweb-outgoing-mail-archive' ), esc_html__( 'CoWeb Outgoing Mail Archive', 'coweb-outgoing-mail-archive' ), 'manage_options', self::PAGE, array( __CLASS__, 'page' ), 'dashicons-email-alt', 81 );
		add_submenu_page( self::PAGE, esc_html__( 'CoWeb Outgoing Mail Archive', 'coweb-outgoing-mail-archive' ), esc_html__( 'Messages', 'coweb-outgoing-mail-archive' ), 'manage_options', self::PAGE, array( __CLASS__, 'page' ) );
		add_submenu_page( self::PAGE, esc_html__( 'Mail archive settings', 'coweb-outgoing-mail-archive' ), esc_html__( 'Settings', 'coweb-outgoing-mail-archive' ), 'manage_options', self::SETTINGS_PAGE, array( __CLASS__, 'settings_page' ) );
	}
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SETTINGS_PAGE ) ) . '">' . esc_html__( 'Settings', 'coweb-outgoing-mail-archive' ) . '</a>' );
		return $links;
	}
}
register_activation_hook( __FILE__, array( 'CoWeb_Outgoing_Mail_Archive', 'install' ) );
register_deactivation_hook(
	__FILE__,
	function () {
		wp_clear_scheduled_hook( 'site_mailbox_cleanup' );
	}
);
add_filter( 'pre_wp_mail', array( 'CoWeb_Outgoing_Mail_Archive', 'capture' ), PHP_INT_MAX, 2 );
add_action(
	'wp_mail_succeeded',
	function ( $data ) {
		CoWeb_Outgoing_Mail_Archive::finish( 'accepted' );
	}
);
add_action(
	'wp_mail_failed',
	function ( $error ) {
		CoWeb_Outgoing_Mail_Archive::finish( 'failed', $error->get_error_message() );
	}
);
add_action( 'woocommerce_email', array( 'CoWeb_Outgoing_Mail_Archive', 'register_wc_email_filters' ) );
add_filter( 'woocommerce_mail_callback', array( 'CoWeb_Outgoing_Mail_Archive', 'wc_mail_callback' ), PHP_INT_MAX, 2 );
add_action( 'site_mailbox_cleanup', array( 'CoWeb_Outgoing_Mail_Archive', 'cleanup' ) );
add_action( 'admin_menu', array( 'CoWeb_Outgoing_Mail_Archive', 'admin_menu' ) );
add_action( 'admin_init', array( 'CoWeb_Outgoing_Mail_Archive', 'register_settings' ) );
add_action( 'admin_post_site_mailbox_delete', array( 'CoWeb_Outgoing_Mail_Archive', 'delete' ) );
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( 'CoWeb_Outgoing_Mail_Archive', 'action_links' ) );
foreach ( array( 'site_mailbox_limit', 'site_mailbox_retention_days' ) as $coweb_outgoing_mail_archive_option ) {
	// Apply a lower limit or a shorter retention period right away.
	add_action( 'add_option_' . $coweb_outgoing_mail_archive_option, array( 'CoWeb_Outgoing_Mail_Archive', 'cleanup' ), 10, 0 );
	add_action( 'update_option_' . $coweb_outgoing_mail_archive_option, array( 'CoWeb_Outgoing_Mail_Archive', 'cleanup' ), 10, 0 );
}
unset( $coweb_outgoing_mail_archive_option );


add_action(
	'plugins_loaded',
	function () {
		if ( get_option( 'site_mailbox_schema' ) !== '1' ) {
			CoWeb_Outgoing_Mail_Archive::install();
		}
	}
);
add_action(
	'admin_enqueue_scripts',
	function ( $hook ) {
		if ( $hook === 'toplevel_page_coweb-outgoing-mail-archive' ) {
			wp_enqueue_script( 'coweb-outgoing-mail-archive-admin', plugins_url( 'assets/admin.js', __FILE__ ), array(), CoWeb_Outgoing_Mail_Archive::VERSION, true );
		}
	}
);
