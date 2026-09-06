<?php
namespace ProdigiDirect;

use WC_Order;

/** Emails to the notify address for things that need a human. */
final class Notifier {
	public static function problem( WC_Order $order, string $message ): void {
		$to = (string) Plugin::instance()->setting( 'notify_email' );
		if ( ! $to ) {
			return;
		}
		$subject = sprintf( '[%s] Print order #%s needs you', wp_specialchars_decode( get_bloginfo( 'name' ) ), $order->get_order_number() );
		$body    = $message . "\n\n" . $order->get_edit_order_url();
		wp_mail( $to, $subject, $body );
	}

	public static function catalogue( string $subject, array $lines ): void {
		$to = (string) Plugin::instance()->setting( 'notify_email' );
		if ( ! $to || ! $lines ) {
			return;
		}
		wp_mail( $to, sprintf( '[%s] %s', wp_specialchars_decode( get_bloginfo( 'name' ) ), $subject ), implode( "\n", $lines ) . "\n\n" . admin_url( 'admin.php?page=prodigi-direct' ) );
	}
}
