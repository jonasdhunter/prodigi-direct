<?php
namespace ProdigiDirect;

/** HMAC links for the private print masters: bound to product + order, expiring. */
final class Signer {
	public function __construct( private string $secret ) {}

	public function sign( int $product_id, int $order_id, int $exp ): string {
		return hash_hmac( 'sha256', "{$product_id}|{$order_id}|{$exp}", $this->secret );
	}

	public function verify( int $product_id, int $order_id, int $exp, string $sig ): bool {
		if ( $exp < time() ) {
			return false;
		}
		return hash_equals( $this->sign( $product_id, $order_id, $exp ), $sig );
	}
}
