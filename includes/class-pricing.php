<?php
namespace ProdigiDirect;

final class Pricing {
	/** @return array{cost: float, keep: ?float, pct: ?float, loss: bool} */
	public static function margin( ?float $price, float $print, float $ship ): array {
		$cost = round( $print + $ship, 2 );
		if ( null === $price ) {
			return [ 'cost' => $cost, 'keep' => null, 'pct' => null, 'loss' => false ];
		}
		$keep = round( $price - $cost, 2 );
		$pct  = $price > 0 ? round( 100 * $keep / $price, 1 ) : null;
		return [ 'cost' => $cost, 'keep' => $keep, 'pct' => $pct, 'loss' => $keep < 0 ];
	}

	/** A cost move worth reporting (more than 5% either way). */
	public static function cost_changed( float $old, float $new, float $threshold = 0.05 ): bool {
		if ( $old <= 0 ) {
			return $new > 0;
		}
		return abs( $new - $old ) / $old > $threshold;
	}
}
