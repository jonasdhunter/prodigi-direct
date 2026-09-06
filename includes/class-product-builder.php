<?php
namespace ProdigiDirect;

use WC_Product;
use WC_Product_Attribute;
use WC_Product_Variable;
use WC_Product_Variation;

/**
 * Creates or adopts the variations of a painting from the artist's choices
 * (materials, sizes, frame colours). One custom attribute, "Print Options",
 * whose values are the labels the shop already uses ('16x20" Paper').
 */
final class Product_Builder {
	public const ATTR        = 'Print Options';
	public const ATTR_KEY    = 'print-options';
	public const META_FAM    = '_prodigi_family';
	public const META_SIZE   = '_prodigi_size';
	public const META_CHOICE = '_prodigi_choice';
	public const META_SKU    = '_prodigi_sku';
	public const META_SIZING = '_prodigi_sizing';
	public const META_ATTRS  = '_prodigi_attributes';
	public const META_DPI    = '_prodigi_dpi';
	public const P_FAMILIES  = '_prodigi_families';
	public const P_SIZES     = '_prodigi_sizes';
	public const P_CHOICES   = '_prodigi_choices';
	public const P_MANAGED   = '_prodigi_managed';

	public function __construct( private Catalogue $catalogue ) {}

	/**
	 * @param string[] $families
	 * @param string[] $sizes
	 * @param array<string, string[]> $choices  family => colours
	 * @return array{created:int, adopted:int, hidden:int, unpriced:int, low_dpi:int}
	 */
	public function build( int $product_id, array $families, array $sizes, array $choices ): array {
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product_Variable ) {
			throw new \RuntimeException( 'Only variable products can carry prints.' );
		}
		$master = Assets::info( $product_id );
		$wanted = []; // label => [family, size, choice]
		foreach ( $families as $family ) {
			if ( ! $this->catalogue->family( $family ) ) {
				continue;
			}
			$fam_choices = $this->catalogue->choice_attribute( $family ) ? ( $choices[ $family ] ?? [] ) : [ null ];
			foreach ( $sizes as $size ) {
				if ( ! $this->catalogue->size( $family, $size ) || ! $this->catalogue->orderable( $family, $size ) ) {
					continue;
				}
				foreach ( $fam_choices as $choice ) {
					$wanted[ $this->catalogue->label( $family, $size, $choice ) ] = [ $family, $size, $choice ];
				}
			}
		}

		// Existing variations, by label.
		$existing = [];
		foreach ( $product->get_children() as $vid ) {
			$v = wc_get_product( $vid );
			if ( $v ) {
				$existing[ (string) $v->get_attribute( self::ATTR_KEY ) ] = $v;
			}
		}

		// The parent attribute must list every option the variations reference (keep any foreign labels).
		$all_labels = array_values( array_unique( array_merge( array_keys( $existing ), array_keys( $wanted ) ) ) );
		usort( $all_labels, [ $this, 'sort_labels' ] );
		$this->set_parent_attribute( $product, $all_labels );

		$stats = [ 'created' => 0, 'adopted' => 0, 'hidden' => 0, 'unpriced' => 0, 'low_dpi' => 0 ];
		foreach ( $wanted as $label => [ $family, $size, $choice ] ) {
			$v = $existing[ $label ] ?? null;
			if ( ! $v ) {
				$v = new WC_Product_Variation();
				$v->set_parent_id( $product_id );
				$v->set_attributes( [ self::ATTR_KEY => $label ] );
				++$stats['created'];
			} else {
				++$stats['adopted'];
			}
			$sku   = $this->catalogue->sku( $family, $size );
			$attrs = $this->catalogue->attributes( $family, $choice );
			$v->update_meta_data( self::META_FAM, $family );
			$v->update_meta_data( self::META_SIZE, $size );
			$v->update_meta_data( self::META_CHOICE, (string) $choice );
			$v->update_meta_data( self::META_SKU, $sku );
			$v->update_meta_data( self::META_SIZING, $this->catalogue->sizing( $family ) );
			$v->update_meta_data( self::META_ATTRS, wp_json_encode( $attrs ) );

			if ( '' === (string) $v->get_regular_price() ) {
				$price = Price_Book::get( $family, $size );
				if ( null !== $price ) {
					$v->set_regular_price( (string) $price );
				} else {
					++$stats['unpriced'];
				}
			}

			if ( $master ) {
				[ $aw, $ah ] = $this->catalogue->print_area_px( $family, $size );
				$dpi         = Dpi::effective( $master['w'], $master['h'], $aw, $ah );
				$v->update_meta_data( self::META_DPI, $dpi );
				$low = 'low' === Dpi::band( $dpi );
				$v->set_status( $low ? 'private' : 'publish' );
				if ( $low ) {
					++$stats['low_dpi'];
				}
			} elseif ( ! $v->get_id() ) {
				$v->set_status( 'publish' );
			}
			if ( '' === (string) $v->get_regular_price() && ! $v->get_id() ) {
				$v->set_status( 'private' ); // never sell an unpriced print
			}
			$v->save();
		}

		// Managed variations no longer wanted are hidden, never deleted.
		foreach ( $existing as $label => $v ) {
			if ( isset( $wanted[ $label ] ) ) {
				continue;
			}
			$is_ours = (bool) $v->get_meta( self::META_SKU ) || null !== $this->catalogue->parse_label( $label );
			if ( $is_ours && 'private' !== $v->get_status() ) {
				$v->set_status( 'private' );
				$v->save();
				++$stats['hidden'];
			}
		}

		$product->update_meta_data( self::P_FAMILIES, array_values( $families ) );
		$product->update_meta_data( self::P_SIZES, array_values( $sizes ) );
		$product->update_meta_data( self::P_CHOICES, $choices );
		$product->update_meta_data( self::P_MANAGED, 'yes' );
		$product->save();
		WC_Product_Variable::sync( $product_id );
		wc_delete_product_transients( $product_id );

		Activity_Log::add( sprintf( 'Print sizes set up for "%s": %d new, %d kept, %d hidden.', $product->get_name(), $stats['created'], $stats['adopted'], $stats['hidden'] ) );
		return $stats;
	}

	/** Adopt what's already there: read families/sizes from existing labels so the tab reflects the shop. */
	public function detect( int $product_id ): array {
		$product  = wc_get_product( $product_id );
		$families = [];
		$sizes    = [];
		$choices  = [];
		if ( $product instanceof WC_Product_Variable ) {
			foreach ( $product->get_children() as $vid ) {
				$v = wc_get_product( $vid );
				if ( ! $v || 'publish' !== $v->get_status() ) {
					continue;
				}
				$p = $this->catalogue->parse_label( (string) $v->get_attribute( self::ATTR_KEY ) );
				if ( $p ) {
					$families[ $p['family'] ] = true;
					$sizes[ $p['size'] ]      = true;
					if ( $p['choice'] ) {
						$choices[ $p['family'] ][ $p['choice'] ] = true;
					}
				}
			}
		}
		return [
			'families' => array_keys( $families ),
			'sizes'    => array_keys( $sizes ),
			'choices'  => array_map( 'array_keys', $choices ),
		];
	}

	/** The table rows for the product's Prints tab. */
	public function rows( int $product_id ): array {
		$product = wc_get_product( $product_id );
		$rows    = [];
		if ( ! $product instanceof WC_Product_Variable ) {
			return $rows;
		}
		$master = Assets::info( $product_id );
		foreach ( $product->get_children() as $vid ) {
			$v      = wc_get_product( $vid );
			$label  = (string) $v->get_attribute( self::ATTR_KEY );
			$family = (string) $v->get_meta( self::META_FAM );
			$size   = (string) $v->get_meta( self::META_SIZE );
			if ( ! $family ) {
				$p = $this->catalogue->parse_label( $label );
				if ( ! $p ) {
					continue; // not a print
				}
				$family = $p['family'];
				$size   = $p['size'];
			}
			$cost  = Costs::for_family_size( $this->catalogue, $family, $size );
			$price = '' === (string) $v->get_regular_price() ? null : (float) $v->get_regular_price();
			$m     = $cost ? Pricing::margin( $price, $cost['print'], $cost['ship'] ) : null;
			$dpi   = null;
			if ( $master ) {
				[ $aw, $ah ] = $this->catalogue->print_area_px( $family, $size );
				$dpi         = Dpi::effective( $master['w'], $master['h'], $aw, $ah );
			}
			$rows[] = [
				'variation_id' => $vid,
				'label'        => $label,
				'family'       => $family,
				'size'         => $size,
				'material'     => $this->catalogue->family( $family )['short'] ?? $family,
				'sku'          => (string) ( $v->get_meta( self::META_SKU ) ?: $this->catalogue->sku( $family, $size ) ),
				'price'        => $price,
				'cost'         => $cost,
				'margin'       => $m,
				'dpi'          => $dpi,
				'band'         => null === $dpi ? null : Dpi::band( $dpi ),
				'status'       => $v->get_status(),
				'mapped'       => (bool) $v->get_meta( self::META_SKU ),
			];
		}
		usort( $rows, fn( $a, $b ) => $this->sort_labels( $a['label'], $b['label'] ) );
		return $rows;
	}

	private function set_parent_attribute( WC_Product $product, array $labels ): void {
		$attrs = $product->get_attributes();
		$attr  = $attrs[ self::ATTR_KEY ] ?? new WC_Product_Attribute();
		$attr->set_id( 0 );
		$attr->set_name( self::ATTR );
		$attr->set_options( $labels );
		$attr->set_position( 0 );
		$attr->set_visible( true );
		$attr->set_variation( true );
		$attrs[ self::ATTR_KEY ] = $attr;
		$product->set_attributes( $attrs );
		$product->save();
	}

	/** Sort by material group order then by area, so the dropdown reads like a ladder. */
	private function sort_labels( string $a, string $b ): int {
		$pa = $this->catalogue->parse_label( $a );
		$pb = $this->catalogue->parse_label( $b );
		if ( ! $pa || ! $pb ) {
			return strnatcmp( $a, $b );
		}
		$order = array_flip( array_keys( $this->catalogue->families() ) );
		$fa    = $order[ $pa['family'] ] ?? 99;
		$fb    = $order[ $pb['family'] ] ?? 99;
		if ( $fa !== $fb ) {
			return $fa <=> $fb;
		}
		$area = static function ( string $s ): int {
			[ $w, $h ] = array_map( 'intval', explode( 'x', $s ) );
			return $w * $h * 1000 + $w;
		};
		$c = $area( $pa['size'] ) <=> $area( $pb['size'] );
		return $c ?: strnatcmp( (string) $pa['choice'], (string) $pb['choice'] );
	}
}
