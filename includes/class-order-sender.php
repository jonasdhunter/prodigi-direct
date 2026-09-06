<?php
namespace ProdigiDirect;

use WC_Order;
use WP_Error;

/**
 * From a paid WooCommerce order to a Prodigi order: quote, wait for approval (or not), send, retry.
 * All state lives in order meta and order notes so it is visible where she already looks.
 */
final class Order_Sender {
	public const META_STATE       = '_prodigi_state';       // waiting · sent · printing · shipped · cancelled · problem · manual
	public const META_ORDER_ID    = '_prodigi_order_id';
	public const META_IDEMPOTENCY = '_prodigi_idempotency';
	public const META_QUOTE       = '_prodigi_quote';       // ['items'=>, 'shipping'=>, 'currency'=>, 'time'=>]
	public const META_ERROR       = '_prodigi_error';
	public const META_MODE        = '_prodigi_mode';
	public const META_ATTEMPTS    = '_prodigi_attempts';
	public const META_LAST_SYNC   = '_prodigi_last_sync';
	public const META_SHIPMENTS   = '_prodigi_shipments';   // shipment ids already announced to the customer
	public const META_RAW         = '_prodigi_last_status'; // last Prodigi order object (trimmed)

	public const JOB_PREPARE = 'prodigi_direct_prepare';
	public const JOB_SUBMIT  = 'prodigi_direct_submit';
	public const MAX_TRIES   = 5;

	public function hooks(): void {
		add_action( 'woocommerce_order_status_processing', [ $this, 'on_paid' ], 10, 1 );
		add_action( 'woocommerce_payment_complete', [ $this, 'on_paid' ], 10, 1 );
		add_action( 'woocommerce_order_status_cancelled', [ $this, 'on_cancelled' ], 10, 1 );
		add_action( 'woocommerce_order_status_refunded', [ $this, 'on_cancelled' ], 10, 1 );
		add_action( self::JOB_PREPARE, [ $this, 'prepare' ], 10, 1 );
		add_action( self::JOB_SUBMIT, [ $this, 'submit' ], 10, 1 );
	}

	public function on_paid( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_meta( self::META_STATE ) ) {
			return; // already handled
		}
		if ( ! $this->print_lines( $order ) ) {
			return;
		}
		as_enqueue_async_action( self::JOB_PREPARE, [ 'order_id' => (int) $order_id ], Plugin::GROUP, true );
	}

	/** Print lines on the order: variation meta tells us the SKU; the parent product holds the master file. */
	public function print_lines( WC_Order $order ): array {
		$lines = [];
		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			$vid = (int) $item->get_variation_id();
			if ( ! $vid ) {
				continue;
			}
			$v   = wc_get_product( $vid );
			$sku = $v ? (string) $v->get_meta( Product_Builder::META_SKU ) : '';
			if ( ! $sku ) {
				continue;
			}
			$pid     = (int) $item->get_product_id();
			$lines[] = [
				'item_id'    => (int) $item_id,
				'product_id' => $pid,
				'name'       => $item->get_name(),
				'label'      => ( new Product_Builder( Plugin::instance()->catalogue() ) )->label_of( $v ),
				'sku'        => $sku,
				'sizing'     => (string) ( $v->get_meta( Product_Builder::META_SIZING ) ?: 'fitPrintArea' ),
				'attributes' => (array) json_decode( (string) $v->get_meta( Product_Builder::META_ATTRS ), true ),
				'qty'        => (int) $item->get_quantity(),
				'line_total' => (float) $item->get_total(),
				'has_master' => Assets::has_master( $pid ),
				'asset_url'  => Assets::has_master( $pid ) ? Assets::order_url( $pid, $order->get_id() ) : '',
			];
		}
		return $lines;
	}

	/** Quote the order and either wait for approval or send. Runs in the background. */
	public function prepare( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_meta( self::META_STATE ) ) {
			return;
		}
		$plugin = Plugin::instance();
		$lines  = $this->print_lines( $order );
		if ( ! $lines ) {
			return;
		}
		$quote = $this->quote( $order, $lines );
		if ( ! is_wp_error( $quote ) ) {
			$order->update_meta_data( self::META_QUOTE, $quote + [ 'time' => time() ] );
		}
		$order->update_meta_data( self::META_MODE, $plugin->mode() );
		if ( 'auto' === $plugin->setting( 'approval' ) && ! $plugin->is_sandbox() ) {
			$order->update_meta_data( self::META_STATE, 'waiting' );
			$order->save();
			$this->request_submit( $order_id );
			return;
		}
		$order->update_meta_data( self::META_STATE, 'waiting' );
		$order->add_order_note( is_wp_error( $quote )
			? sprintf( __( 'Prints on this order are waiting for your approval. (Prodigi could not quote it: %s)', 'prodigi-direct' ), $quote->get_error_message() )
			: sprintf( __( 'Prints on this order are waiting for your approval. Prodigi will charge about %s.', 'prodigi-direct' ), wp_strip_all_tags( wc_price( $quote["items"] + $quote["shipping"] ) ) ) );
		$order->save();
		Activity_Log::add( sprintf( 'Order #%s is waiting for approval.', $order->get_order_number() ), 'info', [ 'order_id' => $order_id ] );
		if ( $plugin->is_sandbox() ) {
			Activity_Log::add( sprintf( 'Test mode is on, so order #%s was not sent automatically.', $order->get_order_number() ), 'warning', [ 'order_id' => $order_id ] );
		}
	}

	/** @return array|WP_Error */
	public function quote( WC_Order $order, array $lines ) {
		$items = array_map( static fn( $l ) => [ 'sku' => $l['sku'], 'copies' => $l['qty'], 'attributes' => $l['attributes'] ], $lines );
		return Plugin::instance()->readonly_api()->quote( $items, $order->get_shipping_country() ?: $order->get_billing_country(), (string) Plugin::instance()->setting( 'shipping_method' ), $order->get_currency() );
	}

	/** Her click (or auto mode): send now, in the background. */
	public function request_submit( int $order_id ): void {
		as_enqueue_async_action( self::JOB_SUBMIT, [ 'order_id' => $order_id ], Plugin::GROUP, true );
	}

	public function submit( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$state = (string) $order->get_meta( self::META_STATE );
		if ( ! in_array( $state, [ 'waiting', 'problem', '' ], true ) ) {
			return; // already sent
		}
		$plugin = Plugin::instance();
		$lines  = $this->print_lines( $order );
		$data   = $this->order_array( $order );
		$errors = Order_Payload::validate( $data, $lines );
		if ( $errors ) {
			$this->fail( $order, $this->explain_validation( $errors, $lines ), false );
			return;
		}
		$key = (string) $order->get_meta( self::META_IDEMPOTENCY );
		if ( ! $key ) {
			$key = wp_generate_uuid4();
			$order->update_meta_data( self::META_IDEMPOTENCY, $key );
			$order->save();
		}
		$payload  = Order_Payload::build( $data, $lines, (string) $plugin->setting( 'shipping_method' ), Order_Status::callback_url(), $key );
		$response = $plugin->api()->create_order( $payload );
		if ( is_wp_error( $response ) ) {
			$retry = in_array( $response->get_error_code(), [ 'prodigi_unavailable', 'http_request_failed' ], true );
			$this->fail( $order, $this->explain_wp_error( $response ), $retry );
			return;
		}
		$outcome = strtolower( (string) ( $response['outcome'] ?? '' ) );
		$po      = $response['order'] ?? [];
		if ( ! in_array( $outcome, [ 'created', 'createdwithissues', 'alreadyexists', 'onhold' ], true ) ) {
			$this->fail( $order, sprintf( __( 'Prodigi did not accept the order (%s).', 'prodigi-direct' ), $outcome ?: 'no outcome' ), false );
			return;
		}
		$pid = (string) ( $po['id'] ?? '' );
		$order->update_meta_data( self::META_ORDER_ID, $pid );
		$order->update_meta_data( self::META_MODE, $plugin->mode() );
		$order->update_meta_data( self::META_ATTEMPTS, 0 );
		$order->delete_meta_data( self::META_ERROR );
		$state = 'onhold' === $outcome ? 'sent' : Status::state( $po );
		$order->update_meta_data( self::META_STATE, $state );
		$order->update_meta_data( self::META_LAST_SYNC, time() );
		$order->update_meta_data( self::META_RAW, Order_Status::trim( $po ) );
		$order->add_order_note( sprintf(
			/* translators: 1: sandbox/live, 2: Prodigi order id */
			__( 'Sent to Prodigi (%1$s), Prodigi order %2$s.', 'prodigi-direct' ),
			$plugin->is_sandbox() ? __( 'TEST MODE — nothing will be printed', 'prodigi-direct' ) : __( 'live', 'prodigi-direct' ),
			$pid
		) );
		if ( 'problem' === $state ) {
			$order->update_meta_data( self::META_ERROR, Status::problem_text( $po ) );
			$order->add_order_note( Status::problem_text( $po ) );
		}
		$order->save();
		Activity_Log::add( sprintf( 'Sent order #%s to Prodigi%s (%s).', $order->get_order_number(), $plugin->is_sandbox() ? ' [test mode]' : '', $pid ), 'problem' === $state ? 'warning' : 'info', [ 'order_id' => $order_id ] );
	}

	private function fail( WC_Order $order, string $message, bool $retry ): void {
		$tries = (int) $order->get_meta( self::META_ATTEMPTS ) + 1;
		$order->update_meta_data( self::META_ATTEMPTS, $tries );
		$order->update_meta_data( self::META_ERROR, $message );
		$order->update_meta_data( self::META_STATE, 'problem' );
		if ( $retry && $tries < self::MAX_TRIES ) {
			$delay = 15 * MINUTE_IN_SECONDS * $tries;
			as_schedule_single_action( time() + $delay, self::JOB_SUBMIT, [ 'order_id' => $order->get_id() ], Plugin::GROUP, true );
			$message .= ' ' . sprintf( __( 'Will try again in %d minutes (attempt %d of %d).', 'prodigi-direct' ), $delay / 60, $tries + 1, self::MAX_TRIES );
		}
		$order->add_order_note( $message );
		$order->save();
		Activity_Log::add( sprintf( "Couldn't send order #%s: %s", $order->get_order_number(), $message ), 'error', [ 'order_id' => $order->get_id() ] );
		if ( ! $retry || $tries >= self::MAX_TRIES ) {
			Notifier::problem( $order, $message );
		}
	}

	private function explain_validation( array $errors, array $lines ): string {
		$names = [];
		foreach ( $lines as $l ) {
			$names[ 'asset:' . $l['item_id'] ] = $l['name'];
		}
		$parts = [];
		foreach ( $errors as $e ) {
			if ( isset( $names[ $e ] ) ) {
				$parts[] = sprintf( __( 'There is no print file for "%s" yet — open the product and add one.', 'prodigi-direct' ), $names[ $e ] );
			} elseif ( 'no_print_lines' === $e ) {
				$parts[] = __( 'Nothing on this order is a print.', 'prodigi-direct' );
			} elseif ( 0 === strpos( $e, 'sku:' ) ) {
				$parts[] = __( "One of these sizes isn't set up for printing yet — open the product and press Set up print sizes.", 'prodigi-direct' );
			} else {
				$parts[] = sprintf( __( 'The shipping address is missing its %s.', 'prodigi-direct' ), str_replace( '_1', '', $e ) );
			}
		}
		return implode( ' ', array_unique( $parts ) );
	}

	private function explain_wp_error( WP_Error $e ): string {
		$msg = $e->get_error_message();
		$d   = (array) $e->get_error_data();
		if ( ! empty( $d['failures'] ) ) {
			$msg .= ' ' . wp_json_encode( $d['failures'] );
		}
		return $msg;
	}

	public function order_array( WC_Order $order ): array {
		$use_ship = (bool) $order->get_shipping_address_1();
		return [
			'number'   => (string) $order->get_order_number(),
			'email'    => $order->get_billing_email(),
			'phone'    => $order->get_shipping_phone() ?: $order->get_billing_phone(),
			'currency' => $order->get_currency(),
			'shipping' => [
				'first_name' => $use_ship ? $order->get_shipping_first_name() : $order->get_billing_first_name(),
				'last_name'  => $use_ship ? $order->get_shipping_last_name() : $order->get_billing_last_name(),
				'address_1'  => $use_ship ? $order->get_shipping_address_1() : $order->get_billing_address_1(),
				'address_2'  => $use_ship ? $order->get_shipping_address_2() : $order->get_billing_address_2(),
				'city'       => $use_ship ? $order->get_shipping_city() : $order->get_billing_city(),
				'state'      => $use_ship ? $order->get_shipping_state() : $order->get_billing_state(),
				'postcode'   => $use_ship ? $order->get_shipping_postcode() : $order->get_billing_postcode(),
				'country'    => $use_ship ? $order->get_shipping_country() : $order->get_billing_country(),
			],
		];
	}

	/** "I'll fulfil this myself": no Prodigi involvement. */
	public function mark_manual( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( $order && ! $order->get_meta( self::META_ORDER_ID ) ) {
			$order->update_meta_data( self::META_STATE, 'manual' );
			$order->add_order_note( __( 'Marked as fulfilled by you — not sent to Prodigi.', 'prodigi-direct' ) );
			$order->save();
		}
	}

	/** Put a manual/problem order back to waiting so it can be approved again. */
	public function reset_to_waiting( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( $order && ! $order->get_meta( self::META_ORDER_ID ) ) {
			$order->update_meta_data( self::META_STATE, 'waiting' );
			$order->update_meta_data( self::META_ATTEMPTS, 0 );
			$order->delete_meta_data( self::META_ERROR );
			$order->save();
		}
	}

	public function on_cancelled( $order_id ): void {
		$order = wc_get_order( $order_id );
		$pid   = $order ? (string) $order->get_meta( self::META_ORDER_ID ) : '';
		if ( ! $pid || in_array( $order->get_meta( self::META_STATE ), [ 'shipped', 'cancelled' ], true ) ) {
			return;
		}
		$this->cancel_at_prodigi( $order );
	}

	/** @return true|string true or the reason it could not be cancelled */
	public function cancel_at_prodigi( WC_Order $order ) {
		$pid = (string) $order->get_meta( self::META_ORDER_ID );
		$api = Plugin::instance()->api();
		$act = $api->get_actions( $pid );
		$ok  = ! is_wp_error( $act ) && 'yes' === strtolower( (string) ( $act['cancel']['isAvailable'] ?? '' ) );
		if ( ! $ok ) {
			$order->add_order_note( __( "Prodigi can't cancel this any more — it is already being made.", 'prodigi-direct' ) );
			$order->save();
			return __( "Prodigi can't cancel this any more — it is already being made.", 'prodigi-direct' );
		}
		$r = $api->cancel_order( $pid );
		if ( is_wp_error( $r ) ) {
			$order->add_order_note( sprintf( __( 'Cancel at Prodigi failed: %s', 'prodigi-direct' ), $r->get_error_message() ) );
			$order->save();
			return $r->get_error_message();
		}
		$order->update_meta_data( self::META_STATE, 'cancelled' );
		$order->add_order_note( __( 'Cancelled at Prodigi.', 'prodigi-direct' ) );
		$order->save();
		Activity_Log::add( sprintf( 'Cancelled order #%s at Prodigi.', $order->get_order_number() ), 'info', [ 'order_id' => $order->get_id() ] );
		return true;
	}
}
