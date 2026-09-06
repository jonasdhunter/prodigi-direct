<?php
namespace ProdigiDirect;

/** File-quality maths for fitPrintArea: how sharp will this file be at this size? */
final class Dpi {
	public const CLEAN = 300.0;
	public const OK    = 150.0;

	/** Effective dpi when the image is fitted inside the print area (best orientation), capped at 300. */
	public static function effective( int $img_w, int $img_h, int $area_w, int $area_h ): float {
		if ( $img_w <= 0 || $img_h <= 0 || $area_w <= 0 || $area_h <= 0 ) {
			return 0.0;
		}
		// fitPrintArea scales the image by s = min(areaW/imgW, areaH/imgH). Prodigi rotates to whichever
		// orientation fills the area best (the larger s). Effective dpi = 300 / s, never above 300.
		$as_is   = min( $area_w / $img_w, $area_h / $img_h );
		$rotated = min( $area_w / $img_h, $area_h / $img_w );
		$s       = max( $as_is, $rotated );
		return round( min( 300.0, 300.0 / $s ), 2 );
	}

	/** clean (≥300) · ok (150–299) · low (<150, hidden from the shop) */
	public static function band( float $dpi ): string {
		if ( $dpi >= self::CLEAN ) {
			return 'clean';
		}
		return $dpi >= self::OK ? 'ok' : 'low';
	}

	public static function band_text( string $band ): string {
		return [
			'clean' => 'Clean',
			'ok'    => 'Fine from across the room',
			'low'   => 'Not sharp enough — hidden',
		][ $band ] ?? '';
	}
}
