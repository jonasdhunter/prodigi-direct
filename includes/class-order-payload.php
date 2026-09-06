<?php
namespace ProdigiDirect;

/**
 * Turns a WooCommerce order (as plain arrays, so it is testable) into a Prodigi v4 order request.
 */
final class Order_Payload {
	/**
	 * @param array $order  number, email, phone, currency, shipping{first_name,last_name,address_1,address_2,city,state,postcode,country}
	 * @param array $lines  item_id, sku, sizing, attributes, qty, asset_url, line_total
	 */
	public static function build( array $order, array $lines, string $shipping_method, string $callback_url, string $idempotency_key ): array {
		$ship  = $order['shipping'];
		$items = [];
		foreach ( $lines as $line ) {
			$qty  = max( 1, (int) $line['qty'] );
			$item = [
				'merchantReference' => $order['number'] . '-' . $line['item_id'],
				'sku'               => $line['sku'],
				'copies'            => $qty,
				'sizing'            => $line['sizing'] ?: 'fitPrintArea',
				'assets'            => [ [ 'printArea' => 'default', 'url' => $line['asset_url'] ] ],
			];
			if ( ! empty( $line['attributes'] ) ) {
				$item['attributes'] = $line['attributes'];
			}
			if ( isset( $line['line_total'] ) && '' !== $line['line_total'] ) {
				$item['recipientCost'] = [
					'amount'   => number_format( (float) $line['line_total'] / $qty, 2, '.', '' ),
					'currency' => $order['currency'] ?: 'USD',
				];
			}
			$items[] = $item;
		}
		// Prodigi validates optional fields when present ("MustNotBeEmptyOrWhitespace"), so empty ones are omitted.
		$recipient = self::compact( [
			'name'        => trim( ( $ship['first_name'] ?? '' ) . ' ' . ( $ship['last_name'] ?? '' ) ),
			'email'       => trim( (string) ( $order['email'] ?? '' ) ),
			'phoneNumber' => trim( (string) ( $order['phone'] ?? '' ) ),
		] );
		$recipient['address'] = self::compact( [
			'line1'           => trim( (string) ( $ship['address_1'] ?? '' ) ),
			'line2'           => trim( (string) ( $ship['address_2'] ?? '' ) ),
			'postalOrZipCode' => trim( (string) ( $ship['postcode'] ?? '' ) ),
			'countryCode'     => strtoupper( trim( (string) ( $ship['country'] ?? '' ) ) ),
			'townOrCity'      => trim( (string) ( $ship['city'] ?? '' ) ),
			'stateOrCounty'   => trim( (string) ( $ship['state'] ?? '' ) ),
		] );
		return [
			'merchantReference' => (string) $order['number'],
			'shippingMethod'    => $shipping_method,
			'idempotencyKey'    => $idempotency_key,
			'callbackUrl'       => $callback_url,
			'recipient'         => $recipient,
			'items'             => $items,
			'metadata'          => [ 'wc_order' => (string) $order['number'] ],
		];
	}

	/** Drop empty strings; keep everything else. */
	private static function compact( array $a ): array {
		return array_filter( $a, static fn( $v ) => '' !== $v && null !== $v );
	}

	/** @return string[] machine keys of what is missing; empty when the order can be sent */
	public static function validate( array $order, array $lines ): array {
		$errors = [];
		$ship   = $order['shipping'] ?? [];
		if ( '' === trim( ( $ship['first_name'] ?? '' ) . ( $ship['last_name'] ?? '' ) ) ) {
			$errors[] = 'name';
		}
		foreach ( [ 'address_1', 'city', 'postcode', 'country' ] as $k ) {
			if ( '' === trim( (string) ( $ship[ $k ] ?? '' ) ) ) {
				$errors[] = $k;
			}
		}
		if ( ! $lines ) {
			$errors[] = 'no_print_lines';
		}
		foreach ( $lines as $line ) {
			if ( empty( $line['sku'] ) ) {
				$errors[] = 'sku:' . $line['item_id'];
			}
			if ( empty( $line['asset_url'] ) ) {
				$errors[] = 'asset:' . $line['item_id'];
			}
		}
		return $errors;
	}
}
