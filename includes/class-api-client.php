<?php
namespace ProdigiDirect;

use WP_Error;

/** Thin Prodigi v4 client over wp_remote_*. Every method returns a decoded array or WP_Error. */
final class Api_Client {
	public const LIVE    = 'https://api.prodigi.com/v4.0/';
	public const SANDBOX = 'https://api.sandbox.prodigi.com/v4.0/';

	public function __construct( private string $api_key, private bool $sandbox ) {}

	public function base_url(): string {
		return $this->sandbox ? self::SANDBOX : self::LIVE;
	}

	public function has_key(): bool {
		return '' !== trim( $this->api_key );
	}

	/** @return array|WP_Error */
	private function request( string $method, string $path, ?array $body = null, int $timeout = 30 ) {
		if ( ! $this->has_key() ) {
			return new WP_Error( 'prodigi_no_key', __( 'No Prodigi API key is set for this mode. Add it under WooCommerce → Prints → Settings.', 'prodigi-direct' ) );
		}
		$args = [
			'method'  => $method,
			'timeout' => $timeout,
			'headers' => [
				'X-API-Key'    => $this->api_key,
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
				'User-Agent'   => 'prodigi-direct/' . PRODIGI_DIRECT_VERSION . ' (WordPress; +https://github.com/jonasdhunter/prodigi-direct)',
			],
		];
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		$res = wp_remote_request( $this->base_url() . ltrim( $path, '/' ), $args );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $data ) ) {
			$data = [];
		}
		$data['_http'] = $code;
		if ( 401 === $code || 403 === $code ) {
			return new WP_Error( 'prodigi_auth', __( "Prodigi didn't accept the API key.", 'prodigi-direct' ), $data );
		}
		if ( 404 === $code ) {
			return new WP_Error( 'prodigi_not_found', __( 'Prodigi has no such product or order.', 'prodigi-direct' ), $data );
		}
		if ( $code >= 500 ) {
			return new WP_Error( 'prodigi_unavailable', __( "Prodigi's API is unavailable right now.", 'prodigi-direct' ), $data );
		}
		if ( $code >= 400 ) {
			$detail = $data['statusText'] ?? ( $data['outcome'] ?? '' );
			$fails  = $data['failures'] ?? ( $data['data'] ?? [] );
			return new WP_Error( 'prodigi_rejected', sprintf( __( 'Prodigi rejected the request: %s', 'prodigi-direct' ), $detail ?: $code ), [ 'failures' => $fails ] + $data );
		}
		return $data;
	}

	/** @return array|WP_Error product object */
	public function get_product( string $sku ) {
		$r = $this->request( 'GET', 'products/' . rawurlencode( $sku ) );
		return is_wp_error( $r ) ? $r : ( $r['product'] ?? new WP_Error( 'prodigi_shape', 'No product in response' ) );
	}

	/**
	 * One quote for a list of items. Returns [ 'items' => float, 'shipping' => float, 'unit' => [sku+attrs => float] ] or WP_Error.
	 * Prodigi answers every US quote with outcome CreatedWithIssues (a sales-tax note); that is success.
	 */
	public function quote( array $items, string $country, string $shipping_method = 'Budget', string $currency = 'USD' ) {
		$r = $this->request(
			'POST',
			'quotes',
			[
				'shippingMethod'         => strtolower( $shipping_method ),
				'destinationCountryCode' => strtoupper( $country ),
				'currencyCode'           => $currency,
				'items'                  => array_map(
					static fn( $i ) => [
						'sku'        => $i['sku'],
						'copies'     => (int) ( $i['copies'] ?? 1 ),
						'attributes' => (object) ( $i['attributes'] ?? [] ),
						'assets'     => [ [ 'printArea' => 'default' ] ],
					],
					array_values( $items )
				),
			]
		);
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$q = $r['quotes'][0] ?? null;
		if ( ! $q ) {
			$issue = $r['issues'][0]['description'] ?? ( $r['outcome'] ?? '' );
			return new WP_Error( 'prodigi_no_quote', sprintf( __( "Prodigi can't quote this: %s", 'prodigi-direct' ), $issue ), $r );
		}
		$out = [
			'items'    => (float) ( $q['costSummary']['items']['amount'] ?? 0 ),
			'shipping' => (float) ( $q['costSummary']['shipping']['amount'] ?? 0 ),
			'currency' => (string) ( $q['costSummary']['items']['currency'] ?? $currency ),
			'unit'     => [],
		];
		foreach ( $q['items'] ?? [] as $qi ) {
			$out['unit'][ strtoupper( $qi['sku'] ) ] = (float) ( $qi['unitCost']['amount'] ?? 0 );
		}
		return $out;
	}

	/** @return array|WP_Error full response {outcome, order} */
	public function create_order( array $payload ) {
		return $this->request( 'POST', 'orders', $payload, 60 );
	}

	/** @return array|WP_Error order object */
	public function get_order( string $id ) {
		$r = $this->request( 'GET', 'orders/' . rawurlencode( $id ) );
		return is_wp_error( $r ) ? $r : ( $r['order'] ?? new WP_Error( 'prodigi_shape', 'No order in response' ) );
	}

	/** @return array|WP_Error e.g. { cancel: { isAvailable: 'Yes' }, ... } */
	public function get_actions( string $id ) {
		return $this->request( 'GET', 'orders/' . rawurlencode( $id ) . '/actions' );
	}

	/** @return array|WP_Error */
	public function cancel_order( string $id ) {
		return $this->request( 'POST', 'orders/' . rawurlencode( $id ) . '/actions/cancel', [] );
	}

	/** True when the key works (a cheap product read). @return true|WP_Error */
	public function ping() {
		$r = $this->get_product( 'GLOBAL-FAP-8x10' );
		return is_wp_error( $r ) ? $r : true;
	}
}
