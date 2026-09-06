<?php
namespace ProdigiDirect\Admin;

use ProdigiDirect\Plugin;

final class Notices {
	public function hooks(): void {
		add_action( 'admin_notices', [ $this, 'sandbox_banner' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'assets' ] );
	}

	public function sandbox_banner(): void {
		$screen = get_current_screen();
		if ( ! $screen || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$woo = str_contains( $screen->id, 'woocommerce' ) || str_contains( $screen->id, 'shop_order' ) || 'product' === $screen->post_type || 'wc-orders' === ( $screen->id ) || str_contains( $screen->id, 'prodigi' );
		if ( ! $woo ) {
			return;
		}
		$p = Plugin::instance();
		if ( $p->is_sandbox() ) {
			wp_admin_notice(
				sprintf( '<strong>%s</strong> %s', esc_html__( 'Print orders are in test mode', 'prodigi-direct' ), esc_html__( '— nothing will be printed or charged, and real orders are not sent automatically.', 'prodigi-direct' ) ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=prodigi-direct#settings' ) ) . '">' . esc_html__( 'Switch to live', 'prodigi-direct' ) . '</a>',
				[ 'type' => 'warning', 'dismissible' => false, 'id' => 'prodigi-direct-sandbox' ]
			);
		} elseif ( ! $p->api_key() ) {
			wp_admin_notice( esc_html__( 'Prodigi Direct has no live API key yet.', 'prodigi-direct' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=prodigi-direct#settings' ) ) . '">' . esc_html__( 'Add it', 'prodigi-direct' ) . '</a>', [ 'type' => 'error', 'dismissible' => false ] );
		}
	}

	public function assets( string $hook ): void {
		$screen = get_current_screen();
		$on     = $screen && ( 'product' === $screen->post_type || str_contains( $screen->id, 'prodigi' ) || str_contains( $screen->id, 'wc-orders' ) || 'shop_order' === $screen->post_type );
		if ( ! $on ) {
			return;
		}
		if ( str_contains( $screen->id, 'prodigi' ) ) {
			wp_enqueue_media();
		}
		wp_enqueue_style( 'prodigi-direct-admin', PRODIGI_DIRECT_URL . 'assets/admin.css', [], PRODIGI_DIRECT_VERSION );
		wp_enqueue_script( 'prodigi-direct-admin', PRODIGI_DIRECT_URL . 'assets/admin.js', [ 'jquery' ], PRODIGI_DIRECT_VERSION, true );
		wp_localize_script( 'prodigi-direct-admin', 'ProdigiDirect', [ 'nonce' => wp_create_nonce( 'prodigi_direct_ajax' ), 'ajax' => admin_url( 'admin-ajax.php' ) ] );
	}
}
