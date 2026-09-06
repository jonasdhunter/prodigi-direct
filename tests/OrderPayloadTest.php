<?php
use PHPUnit\Framework\TestCase;
use ProdigiDirect\Order_Payload;

final class OrderPayloadTest extends TestCase {
	private function order(): array {
		return [
			'number'   => '1042',
			'email'    => 'buyer@example.com',
			'phone'    => '555 0100',
			'currency' => 'USD',
			'shipping' => [
				'first_name' => 'Ada', 'last_name' => 'Lovelace', 'address_1' => '1 Main St', 'address_2' => 'Apt 2',
				'city' => 'Austin', 'state' => 'TX', 'postcode' => '78701', 'country' => 'US',
			],
		];
	}
	private function lines(): array {
		return [
			[ 'item_id' => 7, 'sku' => 'GLOBAL-FAP-16x20', 'sizing' => 'fitPrintArea', 'attributes' => [], 'qty' => 2, 'asset_url' => 'https://shop.example/wp-json/prodigi-direct/v1/master/12?o=1042&e=1&s=x', 'line_total' => 186.0 ],
			[ 'item_id' => 8, 'sku' => 'GLOBAL-CFP-8x10', 'sizing' => 'fitPrintArea', 'attributes' => [ 'color' => 'black' ], 'qty' => 1, 'asset_url' => 'https://shop.example/x', 'line_total' => 120.0 ],
		];
	}
	public function test_builds_the_v4_shape(): void {
		$p = Order_Payload::build( $this->order(), $this->lines(), 'Budget', 'https://shop.example/wp-json/prodigi-direct/v1/callback/abc', 'uuid-1' );
		$this->assertSame( 'Budget', $p['shippingMethod'] );
		$this->assertSame( '1042', $p['merchantReference'] );
		$this->assertSame( 'uuid-1', $p['idempotencyKey'] );
		$this->assertSame( 'https://shop.example/wp-json/prodigi-direct/v1/callback/abc', $p['callbackUrl'] );
		$this->assertSame( 'Ada Lovelace', $p['recipient']['name'] );
		$this->assertSame( 'buyer@example.com', $p['recipient']['email'] );
		$this->assertSame( '555 0100', $p['recipient']['phoneNumber'] );
		$this->assertSame( [ 'line1' => '1 Main St', 'line2' => 'Apt 2', 'postalOrZipCode' => '78701', 'countryCode' => 'US', 'townOrCity' => 'Austin', 'stateOrCounty' => 'TX' ], $p['recipient']['address'] );
		$this->assertCount( 2, $p['items'] );
		$i = $p['items'][0];
		$this->assertSame( '1042-7', $i['merchantReference'] );
		$this->assertSame( 'GLOBAL-FAP-16x20', $i['sku'] );
		$this->assertSame( 2, $i['copies'] );
		$this->assertSame( 'fitPrintArea', $i['sizing'] );
		$this->assertSame( [ [ 'printArea' => 'default', 'url' => 'https://shop.example/wp-json/prodigi-direct/v1/master/12?o=1042&e=1&s=x' ] ], $i['assets'] );
		$this->assertSame( [ 'amount' => '93.00', 'currency' => 'USD' ], $i['recipientCost'] ); // per-unit
		$this->assertSame( [ 'color' => 'black' ], $p['items'][1]['attributes'] );
		$this->assertArrayNotHasKey( 'attributes', $i ); // empty attributes are omitted
		$this->assertSame( [ 'wc_order' => '1042' ], $p['metadata'] );
	}
	public function test_missing_address_fields_are_reported(): void {
		$o = $this->order(); $o['shipping']['postcode'] = ''; $o['shipping']['city'] = '';
		$errors = Order_Payload::validate( $o, $this->lines() );
		$this->assertContains( 'postcode', $errors );
		$this->assertContains( 'city', $errors );
		$this->assertSame( [], Order_Payload::validate( $this->order(), $this->lines() ) );
		$this->assertContains( 'no_print_lines', Order_Payload::validate( $this->order(), [] ) );
	}
	public function test_line_without_asset_url_is_an_error(): void {
		$l = $this->lines(); $l[0]['asset_url'] = '';
		$this->assertContains( 'asset:7', Order_Payload::validate( $this->order(), $l ) );
	}
}
