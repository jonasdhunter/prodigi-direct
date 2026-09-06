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

	public function test_border_fraction_for_fit(): void {
		// Same aspect: no white border.
		$this->assertEqualsWithDelta( 0.0, Dpi::border( 2400, 3000, 4800, 6000 ), 0.001 );
		// Square into 4:5 → fills width, leaves 20% of the height white.
		$this->assertEqualsWithDelta( 0.2, Dpi::border( 3000, 3000, 4800, 6000 ), 0.001 );
		// Landscape image into portrait area is rotated first.
		$this->assertEqualsWithDelta( 0.0, Dpi::border( 3000, 2400, 4800, 6000 ), 0.001 );
	}

	public function test_suggested_sizes_need_fit_and_sharpness(): void {
		$this->assertTrue( Dpi::suggest( 4800, 6000, 4800, 6000 ) );       // exact, 300 dpi
		$this->assertTrue( Dpi::suggest( 4800, 6000, 3300, 4200 ) );       // 11x14 from a 16x20 file: 2% border, 300 dpi
		$this->assertTrue( Dpi::suggest( 4800, 6000, 9000, 12000 ) );      // 30x40 from a 16x20 file: 160 dpi, still offered
		$this->assertFalse( Dpi::suggest( 4800, 6000, 12000, 16000 ) );    // 40x53: 120 dpi, not offered
		$this->assertFalse( Dpi::suggest( 3000, 3000, 4800, 6000 ) );      // square into 4:5: 20% border
		$this->assertFalse( Dpi::suggest( 1000, 1250, 4800, 6000 ) );      // too soft
	}

	public function test_consequence_words(): void {
		// 3600x4500 (4:5) on 11x14 (3300x4200): the file is a touch wider → white top and bottom on paper, crop left/right on canvas
		$this->assertStringContainsString( 'white margin', Dpi::consequence( 3600, 4500, 3300, 4200, 'fitPrintArea' ) );
		$this->assertStringContainsString( 'top and bottom', Dpi::consequence( 3600, 4500, 3300, 4200, 'fitPrintArea' ) );
		$this->assertStringContainsString( 'crops', Dpi::consequence( 3600, 4500, 3300, 4200, 'fillPrintArea' ) );
		$this->assertSame( 'fills the sheet exactly', Dpi::consequence( 4800, 6000, 4800, 6000, 'fitPrintArea' ) );
		$this->assertStringContainsString( 'top and bottom', Dpi::consequence( 3000, 3000, 4800, 6000, 'fitPrintArea' ) ); // square on 4:5: fills the width, white above and below
	}
}
