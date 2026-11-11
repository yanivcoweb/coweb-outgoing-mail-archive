<?php
/**
 * Plugin Name: CoWeb Outgoing Mail Archive
 * Plugin URI: https://coweb.co.il/plugins/outgoing-mail-archive/
 * Author: Yaniv Sasson
 * Author URI: https://yanivsasson.com/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Description: Log WordPress emails in a private admin mailbox with search, deletion and automatic retention limits.
 * Version: 1.3.4
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
	public static function limit() {
		return max( 1, (int) get_option( 'site_mailbox_limit', 100 ) );
	}
	public static function cleanup() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s', self::table(), gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ) );
		$cutoff = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i ORDER BY id DESC LIMIT 1 OFFSET %d', self::table(), self::limit() - 1 ) );
		if ( $cutoff ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id < %d', self::table(), $cutoff ) );
		}
	}
	public static function capture( $result, $atts ) {
		global $wpdb;
		$to = $atts['to'] ?? '';
		$ok = $wpdb->insert(
			self::table(),
			array(
				'created_at'    => current_time( 'mysql', true ),
				'recipients'    => is_array( $to ) ? implode( ', ', $to ) : (string) $to,
				'subject'       => (string) ( $atts['subject'] ?? '' ),
				'message'       => (string) ( $atts['message'] ?? '' ),
				'status'        => $result === null ? 'pending' : ( $result ? 'accepted' : 'failed' ),
				'error_message' => $result === null ? '' : esc_html__( 'Another plugin handled this attempt; delivery is not confirmed.', 'coweb-outgoing-mail-archive' ),
			)
		);
		if ( $result === null ) {
			self::$pending[] = $ok ? $wpdb->insert_id : 0;
		}
		if ( $ok ) {
			self::cleanup();
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
		)[ $s ] ?? $s;
	}
	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		global $wpdb;
		self::cleanup();
		echo '<div class="wrap" dir="' . esc_attr( is_rtl() ? 'rtl' : 'ltr' ) . '"><h1>' . esc_html__( 'Outgoing Mail Archive', 'coweb-outgoing-mail-archive' ) . '</h1><p>' . esc_html__( 'New WordPress email attempts on this site. Records are kept for up to 30 days and are visible to administrators only. Sending status does not confirm delivery.', 'coweb-outgoing-mail-archive' ) . '</p><p>' . esc_html( home_url() ) . ' · ' . esc_html( wp_get_environment_type() ) . '</p>';
		$id = absint( $_GET['mail_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only administrator search/detail request.
		if ( ! $id ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="site_mailbox_settings">';
			wp_nonce_field( 'site_mailbox_settings' );
			echo '<label for="mailbox-limit">' . esc_html__( 'Maximum stored messages', 'coweb-outgoing-mail-archive' ) . '</label> <input id="mailbox-limit" type="number" min="1" max="100000" name="mail_limit" value="' . esc_attr( self::limit() ) . '" required> <button class="button">' . esc_html__( 'Save limit', 'coweb-outgoing-mail-archive' ) . '</button><p>' . esc_html__( 'Oldest records are automatically removed when the limit is exceeded. Lowering the limit immediately deletes excess records. The 30-day retention period also applies.', 'coweb-outgoing-mail-archive' ) . '</p></form>';
		}
		if ( $id ) {
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id=%d', self::table(), $id ) );
			echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=coweb-outgoing-mail-archive' ) ) . '">' . esc_html__( 'Back to all messages', 'coweb-outgoing-mail-archive' ) . '</a></p>';
			if ( ! $row ) {
				echo '<p>' . esc_html__( 'Message not found.', 'coweb-outgoing-mail-archive' ) . '</p></div>';
				return;
			}
			echo '<h2>' . esc_html( $row->subject ) . '</h2><p>' . esc_html__( 'To: ', 'coweb-outgoing-mail-archive' ) . '' . esc_html( $row->recipients ) . '</p><p>' . esc_html( self::label( $row->status ) ) . '</p><p>' . esc_html( $row->error_message ) . '</p>';
			self::message_view( $row->message );
			self::delete_form_start();
			echo '<input type="hidden" name="mail_ids[]" value="' . esc_attr( $row->id ) . '"><button class="button" name="delete_scope" value="selected" data-confirm="' . esc_html__( 'Delete this message?', 'coweb-outgoing-mail-archive' ) . '">' . esc_html__( 'Delete message', 'coweb-outgoing-mail-archive' ) . '</button></form></div>';
			return;
		}
		$search = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only administrator search/detail request.
		$page   = max( 1, absint( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only administrator search/detail request.
		$like   = '%' . $wpdb->esc_like( $search ) . '%';
		$total  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE subject LIKE %s OR recipients LIKE %s', self::table(), $like, $like ) );
		$rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE subject LIKE %s OR recipients LIKE %s ORDER BY id DESC LIMIT %d OFFSET %d', self::table(), $like, $like, 30, ( $page - 1 ) * 30 ) );
		echo '<form method="get"><input type="hidden" name="page" value="coweb-outgoing-mail-archive"><input type="search" name="s" aria-label="' . esc_html__( 'Search by recipient or subject', 'coweb-outgoing-mail-archive' ) . '" placeholder="' . esc_html__( 'Search by recipient or subject', 'coweb-outgoing-mail-archive' ) . '" value="' . esc_attr( $search ) . '"> <button class="button">' . esc_html__( 'Search', 'coweb-outgoing-mail-archive' ) . '</button></form><p>' . esc_html( number_format_i18n( $total ) ) . ' ' . esc_html__( 'messages', 'coweb-outgoing-mail-archive' ) . '</p>';
		// Start the selection form before the table, without nesting the search form.

		self::delete_form_start();
		echo '<table class="widefat striped"><thead><tr><td><input type="checkbox" aria-label="' . esc_html__( 'Select all messages on this page', 'coweb-outgoing-mail-archive' ) . '" data-mailbox-select-all="1"></td><th>' . esc_html__( 'Date', 'coweb-outgoing-mail-archive' ) . '</th><th>' . esc_html__( 'Recipient', 'coweb-outgoing-mail-archive' ) . '</th><th>' . esc_html__( 'Subject', 'coweb-outgoing-mail-archive' ) . '</th><th>' . esc_html__( 'Status', 'coweb-outgoing-mail-archive' ) . '</th><th>' . esc_html__( 'Actions', 'coweb-outgoing-mail-archive' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$url = add_query_arg(
				array(
					'page'    => 'coweb-outgoing-mail-archive',
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
							'page'  => 'coweb-outgoing-mail-archive',
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
	/**
	 * Whether a logged body is HTML. Headers are not stored, so this looks for common tags;
	 * plain text such as "<https://example.com>" does not match.
	 */
	public static function is_html( $message ) {
		return (bool) preg_match( '/<\s*(?:!doctype\s+html|html|head|body|table|div|p|br|a|span|h[1-6]|img|ul|ol|li|strong|b|em|font|center|style)\b/i', $message );
	}
	private static function origin( $url ) {
		$parts = wp_parse_url( $url );
		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}
		return $parts['scheme'] . '://' . $parts['host'] . ( empty( $parts['port'] ) ? '' : ':' . $parts['port'] );
	}
	/**
	 * The HTML document shown in the preview frame. A Content Security Policy blocks
	 * everything except inline styles and images from this site or data: URIs, so opening
	 * a message makes no request to another server. Links open in a new tab.
	 */
	public static function preview_document( $html ) {
		$origins = array_unique( array_filter( array( self::origin( home_url() ), self::origin( site_url() ), self::origin( content_url() ) ) ) );
		$head    = '<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; style-src \'unsafe-inline\'; img-src data: ' . implode( ' ', $origins ) . '; font-src data:"><meta name="referrer" content="no-referrer"><base target="_blank">';
		if ( preg_match( '/<head\b[^>]*>/i', $html, $match, PREG_OFFSET_CAPTURE ) ) {
			$at = $match[0][1] + strlen( $match[0][0] );
			return substr( $html, 0, $at ) . $head . substr( $html, $at );
		}
		if ( preg_match( '/<html\b[^>]*>/i', $html, $match, PREG_OFFSET_CAPTURE ) ) {
			$at = $match[0][1] + strlen( $match[0][0] );
			return substr( $html, 0, $at ) . '<head>' . $head . '</head>' . substr( $html, $at );
		}
		return '<!DOCTYPE html><html><head>' . $head . '</head><body>' . $html . '</body></html>';
	}
	private static function message_view( $message ) {
		$pre = 'white-space:pre-wrap;overflow-wrap:anywhere;background:#fff;padding:20px;margin:0;';
		echo '<h3>' . esc_html__( 'Message', 'coweb-outgoing-mail-archive' ) . '</h3>';
		if ( ! self::is_html( $message ) ) {
			echo '<pre dir="auto" style="' . esc_attr( $pre ) . 'text-align:start">' . esc_html( $message ) . '</pre>';
			return;
		}
		// The srcdoc value must be encoded in full: esc_attr() skips existing entities, so an
		// escaped "&lt;" in the email would turn back into markup inside the frame.
		$srcdoc = htmlspecialchars( self::preview_document( $message ), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8', true );
		// No allow-scripts and no allow-forms: nothing in the message runs or submits.
		// allow-same-origin (safe without allow-scripts) lets the admin script size the frame to its content.
		echo '<iframe title="' . esc_attr__( 'Message preview', 'coweb-outgoing-mail-archive' ) . '" sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox" referrerpolicy="no-referrer" data-mailbox-preview="1" style="display:block;width:100%;min-height:400px;border:1px solid #c3c4c7;background:#fff" srcdoc="' . $srcdoc . '"></iframe>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $srcdoc is fully encoded with htmlspecialchars() above.
		echo '<details style="margin-top:16px"><summary>' . esc_html__( 'Message source', 'coweb-outgoing-mail-archive' ) . '</summary><pre style="' . esc_attr( $pre ) . 'margin-top:8px;direction:ltr;text-align:left">' . esc_html( $message ) . '</pre></details>';
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
			wp_safe_redirect( admin_url( 'admin.php?page=coweb-outgoing-mail-archive' ) );
			exit;
	}
	public static function settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'coweb-outgoing-mail-archive' ) );
		}
		check_admin_referer( 'site_mailbox_settings' );
		$limit = filter_var(
			wp_unslash( $_POST['mail_limit'] ?? '' ),
			FILTER_VALIDATE_INT,
			array(
				'options' => array(
					'min_range' => 1,
					'max_range' => 100000,
				),
			)
		);
		if ( $limit === false ) {
			wp_die( esc_html__( 'Enter a whole number between 1 and 100000.', 'coweb-outgoing-mail-archive' ) );
		}
		update_option( 'site_mailbox_limit', $limit );
		self::cleanup();
		wp_safe_redirect( admin_url( 'admin.php?page=coweb-outgoing-mail-archive' ) );
		exit;
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
add_action( 'site_mailbox_cleanup', array( 'CoWeb_Outgoing_Mail_Archive', 'cleanup' ) );
add_action(
	'admin_menu',
	function () {
		add_menu_page( esc_html__( 'Outgoing Mail Archive', 'coweb-outgoing-mail-archive' ), esc_html__( 'Outgoing Mail Archive', 'coweb-outgoing-mail-archive' ), 'manage_options', 'coweb-outgoing-mail-archive', array( 'CoWeb_Outgoing_Mail_Archive', 'page' ), 'dashicons-email-alt', 81 );
	}
);
add_action( 'admin_post_site_mailbox_delete', array( 'CoWeb_Outgoing_Mail_Archive', 'delete' ) );
add_action( 'admin_post_site_mailbox_settings', array( 'CoWeb_Outgoing_Mail_Archive', 'settings' ) );


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
			wp_enqueue_script( 'coweb-outgoing-mail-archive-admin', plugins_url( 'assets/admin.js', __FILE__ ), array(), '1.3.4', true );
		}
	}
);
