<?php
namespace ProdigiDirect;

use WC_Product;
use WC_Product_Attribute;
use WC_Product_Variable;
use WC_Product_Variation;

/**
 * Creates or adopts the variations of a product from the store owner's choices
 * (materials, sizes, frame colours). One custom attribute, "Print Options",
 * whose values are the labels the shop already uses ('16x20" Paper').
 */
final class Product_Builder {
	public const ATTR        = 'Size / Material'; // created when a product has no print attribute yet
	public const ATTR_KEY    = 'size-material';
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
	 * The custom attribute that carries the print labels on this product: whichever existing attribute
	 * has values the catalogue understands ("Print Options", "Size / Material", …), else the default.
	 */
	public function attr_key( WC_Product $product ): string {
		foreach ( $product->get_attributes() as $key => $attr ) {
			if ( ! $attr instanceof WC_Product_Attribute || $attr->is_taxonomy() ) {
				continue;
			}
			foreach ( $attr->get_options() as $opt ) {
				if ( $this->catalogue->parse_label( (string) $opt ) ) {
					return (string) $key;
				}
			}
		}
		return self::ATTR_KEY;
	}

	/** The print label of a variation, whatever the attribute is called. */
	public function label_of( WC_Product $variation ): string {
		$attrs = $variation->get_attributes();
		foreach ( $attrs as $val ) {
			if ( $this->catalogue->parse_label( (string) $val ) ) {
				return (string) $val;
			}
		}
		return (string) ( reset( $attrs ) ?: '' );
	}

	/**
	 * @param string[] $families
	 * @param string[] $sizes
	 * @param array<string, string[]> $choices  family => colours
	 * @return array{created:int, adopted:int, hidden:int, unpriced:int, low_dpi:int, skipped:string[]}
	 */
	public function build( int $product_id, array $families, array $sizes, array $choices, bool $adopt_only = false ): array {
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof WC_Product_Variable ) {
			throw new \RuntimeException( 'Only variable products can carry prints.' );
		}
		$master   = Assets::info( $product_id );
		$attr_key = $this->attr_key( $product );
		$wanted   = []; // label => [family, size, choice]
		$skipped = []; // plain-language reasons a tick produced nothing
		foreach ( $families as $family ) {
			if ( ! $this->catalogue->family( $family ) ) {
				continue;
			}
			$fam_choices = $this->catalogue->choice_attribute( $family ) ? ( $choices[ $family ] ?? [] ) : [ null ];
			if ( ! $fam_choices ) {
				$skipped[] = sprintf( '%s: tick at least one frame colour', $this->catalogue->family( $family )['short'] );
			}
			foreach ( $sizes as $size ) {
				if ( ! $this->catalogue->size( $family, $size ) || ! $this->catalogue->orderable( $family, $size ) ) {
					$skipped[] = sprintf( '%s" is not offered in %s', $size, $this->catalogue->family( $family )['short'] );
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
				$existing[ $this->label_of( $v ) ] = $v;
			}
		}
		if ( $adopt_only ) {
			// Attach Prodigi data to what is already there; create nothing, hide nothing.
			$wanted  = array_intersect_key( $wanted, $existing );
			$skipped = [];
		}

		// The parent attribute must list every option the variations reference (keep any foreign labels).
		$all_labels = array_values( array_unique( array_merge( array_keys( $existing ), array_keys( $wanted ) ) ) );
		usort( $all_labels, [ $this, 'sort_labels' ] );
		$this->set_parent_attribute( $product, $attr_key, $all_labels );

		$stats = [ 'created' => 0, 'adopted' => 0, 'hidden' => 0, 'unpriced' => 0, 'low_dpi' => 0, 'skipped' => $skipped ];
		foreach ( $wanted as $label => [ $family, $size, $choice ] ) {
			$v = $existing[ $label ] ?? null;
			if ( ! $v ) {
				$v = new WC_Product_Variation();
				$v->set_parent_id( $product_id );
				$v->set_attributes( [ $attr_key => $label ] );
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
			if ( isset( $wanted[ $label ] ) || $adopt_only ) {
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

	/**
	 * What the file supports: for every family/size, dpi + border, and whether it is a good default.
	 * @return array{sizes: array<string, array{dpi: float, band: string, border: float, fit: string, suggest: bool}>, suggested: string[], families: string[]}|null
	 */
	public function suggest( int $product_id ): ?array {
		$master = Assets::info( $product_id );
		if ( ! $master ) {
			return null;
		}
		$sizes     = [];
		$suggested = [];
		foreach ( $this->catalogue->all_size_keys() as $size ) {
			// Print-area pixels are the same shape for every family at a size; use paper's (or the first family that has it).
			$px = [ 0, 0 ];
			foreach ( array_keys( $this->catalogue->families() ) as $fam ) {
				$px = $this->catalogue->print_area_px( $fam, $size );
				if ( $px[0] ) {
					break;
				}
			}
			$dpi    = Dpi::effective( $master['w'], $master['h'], $px[0], $px[1] );
			$border = Dpi::border( $master['w'], $master['h'], $px[0], $px[1] );
			$ok     = Dpi::suggest( $master['w'], $master['h'], $px[0], $px[1] );
			$sizes[ $size ] = [ 'dpi' => $dpi, 'band' => Dpi::band( $dpi ), 'border' => $border, 'fit' => Dpi::fit_text( $border ), 'suggest' => $ok ];
			if ( $ok ) {
				$suggested[] = $size;
			}
		}
		return [ 'sizes' => $sizes, 'suggested' => $suggested, 'families' => [ 'paper', 'canvas-rolled', 'canvas-gallery-wrap' ] ];
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
				$p = $this->catalogue->parse_label( $this->label_of( $v ) );
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
			$label  = $this->label_of( $v );
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

	private function set_parent_attribute( WC_Product $product, string $attr_key, array $labels ): void {
		$attrs = $product->get_attributes();
		$attr  = $attrs[ $attr_key ] ?? new WC_Product_Attribute();
		$attr->set_id( 0 );
		if ( ! $attr->get_name() ) {
			$attr->set_name( self::ATTR );
		}
		$attr->set_options( $labels );
		$attr->set_position( 0 );
		$attr->set_visible( true );
		$attr->set_variation( true );
		$attrs[ $attr_key ] = $attr;
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
