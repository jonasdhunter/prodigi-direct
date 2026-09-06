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

	/**
	 * Fraction of the print area left white (along the slack axis) when the image is fitted, best orientation.
	 * 0 = the image fills the sheet exactly; 0.2 = a fifth of the height (or width) is border.
	 */
	public static function border( int $img_w, int $img_h, int $area_w, int $area_h ): float {
		if ( $img_w <= 0 || $img_h <= 0 || $area_w <= 0 || $area_h <= 0 ) {
			return 1.0;
		}
		$as_is   = self::border_one( $img_w, $img_h, $area_w, $area_h );
		$rotated = self::border_one( $img_h, $img_w, $area_w, $area_h );
		return round( min( $as_is, $rotated ), 4 );
	}

	private static function border_one( int $img_w, int $img_h, int $area_w, int $area_h ): float {
		$s = min( $area_w / $img_w, $area_h / $img_h );
		$w = $img_w * $s;
		$h = $img_h * $s;
		return max( 1 - $w / $area_w, 1 - $h / $area_h );
	}

	/** A size worth offering for this file: sharp enough (≥150 dpi) and close to the file's shape (≤ 8% border). */
	public static function suggest( int $img_w, int $img_h, int $area_w, int $area_h, float $max_border = 0.08 ): bool {
		return self::band( self::effective( $img_w, $img_h, $area_w, $area_h ) ) !== 'low'
			&& self::border( $img_w, $img_h, $area_w, $area_h ) <= $max_border;
	}

	/**
	 * What a size does to this file, in words: for fit sizing, the white band it leaves; for fill,
	 * the slice it crops. $img and $area are [w, h]. Returns e.g. "crops 1% top and bottom".
	 */
	public static function consequence( int $img_w, int $img_h, int $area_w, int $area_h, string $sizing ): string {
		if ( $img_w <= 0 || $img_h <= 0 || $area_w <= 0 || $area_h <= 0 ) {
			return '';
		}
		// Prodigi rotates to the best orientation; compare aspect ratios in that orientation.
		$img  = $img_w / $img_h;
		$area = $area_w / $area_h;
		if ( ( $img > 1 ) !== ( $area > 1 ) ) {
			$img = 1 / $img;
		}
		$diff = abs( $img - $area ) / max( $img, $area );
		if ( $diff < 0.005 ) {
			return 'fills the sheet exactly';
		}
		$pct  = round( $diff * 100, 1 );
		$axis = $img > $area ? 'top and bottom' : 'left and right'; // image is wider than the area → slack top/bottom
		if ( 'fillPrintArea' === $sizing ) {
			return 'fills — crops ' . $pct . '% ' . ( $img > $area ? 'left and right' : 'top and bottom' );
		}
		return 'white margin ' . $pct . '% ' . $axis;
	}

	public static function fit_text( float $border ): string {
		if ( $border <= 0.02 ) {
			return 'fits';
		}
		if ( $border <= 0.08 ) {
			return 'small border';
		}
		return 'wide border';
	}

	public static function band_text( string $band ): string {
		return [
			'clean' => 'Clean',
			'ok'    => 'Fine from across the room',
			'low'   => 'Not sharp enough — hidden',
		][ $band ] ?? '';
	}
}
