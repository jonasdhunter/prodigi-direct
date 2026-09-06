<?php
use PHPUnit\Framework\TestCase;
use ProdigiDirect\Pricing;

final class PricingTest extends TestCase {
	public function test_margin(): void {
		$m = Pricing::margin( 33.0, 9.0, 6.85 );
		$this->assertSame( 15.85, $m['cost'] );
		$this->assertSame( 17.15, $m['keep'] );
		$this->assertSame( 52.0, $m['pct'] );
	}
	public function test_negative_margin_flags(): void {
		$m = Pricing::margin( 10.0, 9.0, 6.85 );
		$this->assertLessThan( 0, $m['keep'] );
		$this->assertTrue( $m['loss'] );
	}
	public function test_no_price(): void {
		$m = Pricing::margin( null, 9.0, 6.85 );
		$this->assertNull( $m['keep'] );
		$this->assertNull( $m['pct'] );
		$this->assertFalse( $m['loss'] );
	}
	public function test_cost_change_is_material_above_five_percent(): void {
		$this->assertFalse( Pricing::cost_changed( 15.00, 15.50 ) );
		$this->assertTrue( Pricing::cost_changed( 15.00, 16.50 ) );
		$this->assertTrue( Pricing::cost_changed( 15.00, 12.00 ) );
	}
}
