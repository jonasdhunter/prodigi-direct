<?php
namespace ProdigiDirect;

/** Live-checked Prodigi costs per SKU (print + shipping), falling back to the catalogue's verified values. */
final class Costs {
	public const OPTION = 'prodigi_direct_costs';

	/** @return array{print: float, ship: float, checked: int}|null */
	public static function for_sku( string $sku ): ?array {
		$c = self::all()[ strtoupper( $sku ) ] ?? null;
		return $c ? [ 'print' => (float) $c['print'], 'ship' => (float) $c['ship'], 'checked' => (int) $c['checked'] ] : null;
	}

	/** Cached live cost, else the catalogue's last-verified cost. */
	public static function for_family_size( Catalogue $catalogue, string $family, string $size ): ?array {
		$sku = $catalogue->sku( $family, $size );
		if ( $sku && ( $c = self::for_sku( $sku ) ) ) {
			return $c;
		}
		$c = $catalogue->cost( $family, $size );
		return $c ? $c + [ 'checked' => 0 ] : null;
	}

	public static function all(): array {
		return (array) get_option( self::OPTION, [] );
	}

	public static function set( string $sku, float $print, float $ship ): void {
		$all                       = self::all();
		$all[ strtoupper( $sku ) ] = [ 'print' => round( $print, 2 ), 'ship' => round( $ship, 2 ), 'checked' => time() ];
		update_option( self::OPTION, $all, false );
	}
}
