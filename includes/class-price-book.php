<?php
namespace ProdigiDirect;

/** One price per size × material. Set once; every product follows. Stored as [family][size] => price. */
final class Price_Book {
	public const OPTION = 'prodigi_direct_price_book';

	/** @return array<string, array<string, float>> */
	public static function all(): array {
		return (array) get_option( self::OPTION, [] );
	}

	public static function get( string $family, string $size ): ?float {
		$v = self::all()[ $family ][ $size ] ?? null;
		return null === $v || '' === $v ? null : (float) $v;
	}

	public static function set( string $family, string $size, ?float $price ): void {
		$book = self::all();
		if ( null === $price ) {
			unset( $book[ $family ][ $size ] );
		} else {
			$book[ $family ][ $size ] = round( $price, 2 );
		}
		update_option( self::OPTION, $book, false );
	}

	/** @param array<string, array<string, float|string>> $grid */
	public static function replace( array $grid ): void {
		$clean = [];
		foreach ( $grid as $family => $sizes ) {
			foreach ( (array) $sizes as $size => $price ) {
				if ( '' !== trim( (string) $price ) && is_numeric( $price ) ) {
					$clean[ sanitize_key( $family ) ][ sanitize_text_field( $size ) ] = round( (float) $price, 2 );
				}
			}
		}
		update_option( self::OPTION, $clean, false );
	}

	/** Learn prices from the variations that already exist (their labels tell family + size). */
	public static function learn_from_shop( Catalogue $catalogue ): int {
		$learned = 0;
		$book    = self::all();
		$ids     = wc_get_products( [ 'type' => 'variation', 'limit' => -1, 'return' => 'ids', 'status' => [ 'publish', 'private' ] ] );
		foreach ( $ids as $vid ) {
			$v      = wc_get_product( $vid );
			$label  = (string) $v->get_attribute( 'print-options' );
			$parsed = $catalogue->parse_label( $label );
			$price  = $v->get_regular_price();
			if ( $parsed && '' !== $price && ! isset( $book[ $parsed['family'] ][ $parsed['size'] ] ) ) {
				$book[ $parsed['family'] ][ $parsed['size'] ] = (float) $price;
				++$learned;
			}
		}
		update_option( self::OPTION, $book, false );
		return $learned;
	}
}
