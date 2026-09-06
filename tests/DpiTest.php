<?php
use PHPUnit\Framework\TestCase;
use ProdigiDirect\Dpi;

final class DpiTest extends TestCase {
	public function test_fit_sizing_uses_the_limiting_side(): void {
		// 4800x6000 area (16x20 @300). Image 2400x3000 → scaled up 2x → 150 dpi.
		$this->assertEqualsWithDelta( 150.0, Dpi::effective( 2400, 3000, 4800, 6000 ), 0.01 );
		// Image already larger than the area → capped at 300.
		$this->assertEqualsWithDelta( 300.0, Dpi::effective( 9000, 12000, 4800, 6000 ), 0.01 );
		// Landscape image on a portrait area is auto-rotated by Prodigi: compare best orientation.
		$this->assertEqualsWithDelta( 150.0, Dpi::effective( 3000, 2400, 4800, 6000 ), 0.01 );
	}
	public function test_aspect_mismatch_limits_by_the_tighter_side(): void {
		// Square-ish image into 4:5: width limits. 4800 wide needs image 4800 → 2400 wide image = 150 dpi.
		$this->assertEqualsWithDelta( 150.0, Dpi::effective( 2400, 2400, 4800, 6000 ), 0.01 );
	}
	public function test_quality_bands(): void {
		$this->assertSame( 'clean', Dpi::band( 300 ) );
		$this->assertSame( 'ok', Dpi::band( 150 ) );
		$this->assertSame( 'ok', Dpi::band( 299.9 ) );
		$this->assertSame( 'low', Dpi::band( 149.9 ) );
		$this->assertSame( 'low', Dpi::band( 0 ) );
	}
	public function test_zero_dimensions_are_low(): void {
		$this->assertSame( 0.0, Dpi::effective( 0, 0, 4800, 6000 ) );
		$this->assertSame( 0.0, Dpi::effective( 100, 100, 0, 0 ) );
	}
}
