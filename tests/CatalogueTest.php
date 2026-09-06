<?php
use PHPUnit\Framework\TestCase;
use ProdigiDirect\Catalogue;

final class CatalogueTest extends TestCase {
	private Catalogue $c;
	protected function setUp(): void { $this->c = Catalogue::load(); }

	public function test_families_are_ordered_and_grouped(): void {
		$keys = array_keys( $this->c->families() );
		$this->assertSame( 'paper', $keys[0] );
		$this->assertContains( 'framed-print-classic', $keys );
		$this->assertSame( 'framed', $this->c->family( 'framed-print-box' )['group'] );
	}

	public function test_sku_lookup(): void {
		$this->assertSame( 'GLOBAL-FAP-16x20', $this->c->sku( 'paper', '16x20' ) );
		$this->assertSame( 'GLOBAL-CFPM-16x20', $this->c->sku( 'framed-print-classic-mounted', '16x20' ) );
		$this->assertNull( $this->c->sku( 'paper', '99x99' ) );
	}

	public function test_attributes_merge_fixed_and_choice(): void {
		$this->assertSame( [], $this->c->attributes( 'paper', null ) );
		$this->assertSame( [ 'wrap' => 'MirrorWrap' ], $this->c->attributes( 'canvas-gallery-wrap', null ) );
		$this->assertSame( [ 'color' => 'black' ], $this->c->attributes( 'framed-print-classic', 'black' ) );
		$this->assertSame( [ 'wrap' => 'MirrorWrap', 'color' => 'natural' ], $this->c->attributes( 'framed-canvas-float', 'natural' ) );
	}

	public function test_required_attributes_are_satisfied(): void {
		foreach ( $this->c->families() as $key => $fam ) {
			$choice = $fam['choice_attribute'] ?? null ? array_key_first( $fam['choices'] ) : null;
			$attrs  = $this->c->attributes( $key, $choice );
			foreach ( $fam['required_attributes'] as $req ) {
				$this->assertArrayHasKey( $req, $attrs, "$key needs $req" );
			}
		}
	}

	public function test_labels_match_the_existing_shop_convention(): void {
		$this->assertSame( '8x10" Paper', $this->c->label( 'paper', '8x10' ) );
		$this->assertSame( '16x20" Rolled Canvas', $this->c->label( 'canvas-rolled', '16x20' ) );
		$this->assertSame( '16x20" Gallery Wrapped', $this->c->label( 'canvas-gallery-wrap', '16x20' ) );
		$this->assertSame( '16x20" Classic Frame, Black', $this->c->label( 'framed-print-classic', '16x20', 'black' ) );
		$this->assertSame( '16x20" Classic Frame, matted, Antique Gold', $this->c->label( 'framed-print-classic-mounted', '16x20', 'gold' ) );
	}

	public function test_parse_label_round_trips(): void {
		foreach ( [ [ 'paper', '8x10', null ], [ 'canvas-gallery-wrap', '24x30', null ], [ 'framed-print-box-mounted', '16x20', 'white' ], [ 'framed-canvas-float', '30x40', 'brown' ] ] as [ $f, $s, $c ] ) {
			$this->assertSame( [ 'family' => $f, 'size' => $s, 'choice' => $c ], $this->c->parse_label( $this->c->label( $f, $s, $c ) ) );
		}
		$this->assertNull( $this->c->parse_label( 'Original painting' ) );
	}

	public function test_cost_and_print_area(): void {
		$cost = $this->c->cost( 'paper', '8x10' );
		$this->assertSame( 9.0, $cost['print'] );
		$this->assertSame( 6.85, $cost['ship'] );
		$this->assertSame( [ 4800, 6000 ], $this->c->print_area_px( 'paper', '16x20' ) );
		$this->assertFalse( $this->c->orderable( 'framed-print-classic-mounted', '24x30' ) );
		$this->assertTrue( $this->c->orderable( 'paper', '8x10' ) );
	}

	public function test_size_keys_by_family_and_all_sizes(): void {
		$this->assertContains( '30x40', $this->c->size_keys( 'paper' ) );
		$all = $this->c->all_size_keys();
		$this->assertContains( '8x8', $all );
		$this->assertContains( '24x36', $all );
		$this->assertSame( '24x36', $this->c->size( 'framed-print-classic', '24x36' )['key'] );
	}

	public function test_every_sku_in_use_can_be_listed(): void {
		$skus = $this->c->all_skus();
		$this->assertGreaterThan( 90, count( $skus ) );
		$this->assertContains( 'GLOBAL-FRA-CAN-16x20', $skus );
	}
}
