<?php
namespace ProdigiDirect;

use WC_Order;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Keeps WooCommerce orders in step with Prodigi: a callback endpoint (token in the URL, payload
 * never trusted — we re-fetch the order) plus a poll every few hours for anything still in flight.
 */
final class Order_Status {
	public const JOB_SYNC = 'prodigi_direct_sync';
	public const JOB_POLL = 'prodigi_direct_poll';

	public function hooks(): void {
		add_action( 'rest_api_init', [ $this, 'routes' ] );
		add_action( self::JOB_SYNC, [ $this, 'sync' ], 10, 1 );
		add_action( self::JOB_POLL, [ $this, 'poll' ] );
		add_action( 'action_scheduler_init', [ $this, 'schedule_poll' ] );
	}

	public function schedule_poll(): void {
		if ( function_exists( 'as_has_scheduled_action' ) && ! as_has_scheduled_action( self::JOB_POLL, [], Plugin::GROUP ) ) {
			as_schedule_recurring_action( time() + HOUR_IN_SECONDS, 6 * HOUR_IN_SECONDS, self::JOB_POLL, [], Plugin::GROUP, true );
		}
	}

	public static function callback_url(): string {
		return rest_url( 'prodigi-direct/v1/callback/' . Plugin::instance()->setting( 'callback_token' ) );
	}

	public function routes(): void {
		register_rest_route(
			'prodigi-direct/v1',
			'/callback/(?P<token>[a-f0-9]{32,})',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'callback' ],
				'permission_callback' => '__return_true',
			]
		);
	}

	public function callback( WP_REST_Request $r ) {
		if ( ! hash_equals( (string) Plugin::instance()->setting( 'callback_token' ), (string) $r['token'] ) ) {
			return new WP_REST_Response( [ 'ok' => false ], 403 );
		}
		$body = $r->get_json_params();
		$pid  = (string) ( $body['subject'] ?? ( $body['data']['order']['id'] ?? '' ) );
		if ( $pid ) {
			$order_id = $this->find_order( $pid );
			if ( $order_id ) {
				as_enqueue_async_action( self::JOB_SYNC, [ 'order_id' => $order_id ], Plugin::GROUP, true );
			}
		}
		return new WP_REST_Response( [ 'ok' => true ], 200 );
	}

	private function find_order( string $prodigi_id ): int {
		$ids = wc_get_orders( [ 'limit' => 1, 'return' => 'ids', 'meta_key' => Order_Sender::META_ORDER_ID, 'meta_value' => $prodigi_id ] );
		return $ids ? (int) $ids[0] : 0;
	}

	/** Re-fetch one order from Prodigi and apply what changed. */
	public function sync( int $order_id ): void {
		$order = wc_get_order( $order_id );
		$pid   = $order ? (string) $order->get_meta( Order_Sender::META_ORDER_ID ) : '';
		if ( ! $pid ) {
			return;
		}
		$po = Plugin::instance()->api()->get_order( $pid );
		if ( is_wp_error( $po ) ) {
			Activity_Log::add( sprintf( "Couldn't check order #%s at Prodigi: %s", $order->get_order_number(), $po->get_error_message() ), 'warning', [ 'order_id' => $order_id ] );
			return;
		}
		$this->apply( $order, $po );
	}

	public function apply( WC_Order $order, array $po ): void {
		$old   = (string) $order->get_meta( Order_Sender::META_STATE );
		$state = Status::state( $po );
		$order->update_meta_data( Order_Sender::META_LAST_SYNC, time() );
		$order->update_meta_data( Order_Sender::META_RAW, self::trim( $po ) );
		if ( 'problem' === $state ) {
			$order->update_meta_data( Order_Sender::META_ERROR, Status::problem_text( $po ) );
		} else {
			$order->delete_meta_data( Order_Sender::META_ERROR );
		}
		if ( $state !== $old ) {
			$order->update_meta_data( Order_Sender::META_STATE, $state );
			$order->add_order_note( sprintf( __( 'Prodigi: %s.', 'prodigi-direct' ), Status::label( $state ) ) . ( 'problem' === $state ? ' ' . Status::problem_text( $po ) : '' ) );
			Activity_Log::add( sprintf( 'Order #%s is now "%s" at Prodigi.', $order->get_order_number(), Status::label( $state ) ), 'problem' === $state ? 'error' : 'info', [ 'order_id' => $order->get_id() ] );
			if ( 'problem' === $state ) {
				Notifier::problem( $order, Status::problem_text( $po ) );
			}
		}
		$this->announce_shipments( $order, $po );
		if ( 'shipped' === $state && 'yes' === Plugin::instance()->setting( 'complete_on_ship' ) && ! $order->has_status( [ 'completed', 'cancelled', 'refunded' ] ) ) {
			$order->update_status( 'completed', __( 'All prints shipped by Prodigi.', 'prodigi-direct' ) );
		}
		$order->save();
	}

	/** One customer-visible note per shipment, with carrier and tracking. */
	private function announce_shipments( WC_Order $order, array $po ): void {
		$seen = (array) $order->get_meta( Order_Sender::META_SHIPMENTS );
		foreach ( Status::shipments( $po ) as $s ) {
			if ( 'shipped' !== strtolower( $s['status'] ) || in_array( $s['id'], $seen, true ) ) {
				continue;
			}
			$carrier = Status::carrier_label( $s['carrier'] );
			$text    = $s['tracking_number']
				? sprintf( __( 'Your print is on its way with %1$s. Tracking number %2$s%3$s', 'prodigi-direct' ), $carrier, $s['tracking_number'], $s['tracking_url'] ? ': ' . $s['tracking_url'] : '.' )
				: sprintf( __( 'Your print is on its way with %s.', 'prodigi-direct' ), $carrier );
			$order->add_order_note( $text, true ); // customer note → emailed by WooCommerce
			$seen[] = $s['id'];
		}
		$order->update_meta_data( Order_Sender::META_SHIPMENTS, $seen );
	}

	/** Every order still in flight, re-checked. Belt and braces for missed callbacks. */
	public function poll(): void {
		$ids = wc_get_orders( [
			'limit'      => 50,
			'return'     => 'ids',
			'meta_query' => [ [ 'key' => Order_Sender::META_STATE, 'value' => [ 'sent', 'printing', 'problem' ], 'compare' => 'IN' ] ],
		] );
		foreach ( $ids as $id ) {
			$this->sync( (int) $id );
		}
	}

	/** Keep only what the order screen shows; the full object stays at Prodigi. */
	public static function trim( array $po ): array {
		return [
			'id'        => $po['id'] ?? '',
			'status'    => $po['status'] ?? [],
			'shipments' => $po['shipments'] ?? [],
			'charges'   => $po['charges'] ?? [],
			'updated'   => $po['lastUpdated'] ?? '',
		];
	}
}
