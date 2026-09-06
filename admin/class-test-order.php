<?php
namespace ProdigiDirect\Admin;

use ProdigiDirect\Activity_Log;
use ProdigiDirect\Api_Client;
use ProdigiDirect\Assets;
use ProdigiDirect\Order_Payload;
use ProdigiDirect\Order_Status;
use ProdigiDirect\Plugin;
use ProdigiDirect\Product_Builder;

/** A sandbox order for one product, outside WooCommerce's order flow, to prove the pipe end to end. */
final class Test_Order {
	public function send( int $product_id ): string {
		$plugin = Plugin::instance();
		$key    = (string) $plugin->setting( 'api_key_sandbox' );
		if ( ! $key ) {
			return __( 'Add the sandbox API key first.', 'prodigi-direct' );
		}
		$product = wc_get_product( $product_id );
		if ( ! $product || ! Assets::has_master( $product_id ) ) {
			return __( 'That product has no print file yet.', 'prodigi-direct' );
		}
		$line = null;
		foreach ( $product->get_children() as $vid ) {
			$v = wc_get_product( $vid );
			if ( $v && $v->get_meta( Product_Builder::META_SKU ) ) {
				$line = [
					'item_id'    => $vid,
					'sku'        => (string) $v->get_meta( Product_Builder::META_SKU ),
					'sizing'     => (string) $v->get_meta( Product_Builder::META_SIZING ),
					'attributes' => (array) json_decode( (string) $v->get_meta( Product_Builder::META_ATTRS ), true ),
					'qty'        => 1,
					'asset_url'  => Assets::order_url( $product_id, 0 ),
					'line_total' => (float) $v->get_regular_price(),
				];
				if ( str_contains( $line['sku'], 'FAP' ) ) {
					break; // prefer the cheapest paper size for the test
				}
			}
		}
		if ( ! $line ) {
			return __( 'That product has no print sizes set up yet.', 'prodigi-direct' );
		}
		// Order id 0 links are only valid for previews; mint a real one bound to a fake order id.
		$line['asset_url'] = Assets::order_url( $product_id, PHP_INT_MAX );
		$order = [
			'number'   => 'TEST-' . time(),
			'email'    => (string) $plugin->setting( 'notify_email' ),
			'phone'    => '5550100',
			'currency' => get_woocommerce_currency(),
			'shipping' => [ 'first_name' => 'Test', 'last_name' => 'Order', 'address_1' => '1 Test Street', 'address_2' => '', 'city' => 'Austin', 'state' => 'TX', 'postcode' => '78701', 'country' => 'US' ],
		];
		$payload = Order_Payload::build( $order, [ $line ], (string) $plugin->setting( 'shipping_method' ), Order_Status::callback_url(), wp_generate_uuid4() );
		$api     = new Api_Client( $key, true );
		$r       = $api->create_order( $payload );
		if ( is_wp_error( $r ) ) {
			Activity_Log::add( 'Test order failed: ' . $r->get_error_message(), 'error' );
			return sprintf( __( 'Test order failed: %s', 'prodigi-direct' ), $r->get_error_message() );
		}
		$id = (string) ( $r['order']['id'] ?? '?' );
		Activity_Log::add( sprintf( 'Test order sent to Prodigi sandbox (%s, outcome %s).', $id, $r['outcome'] ?? '?' ) );
		return sprintf( __( 'Test order created in Prodigi’s sandbox: %1$s (outcome: %2$s). Check it at sandbox-beta-dashboard.pwinty.com.', 'prodigi-direct' ), $id, $r['outcome'] ?? '?' );
	}
}
