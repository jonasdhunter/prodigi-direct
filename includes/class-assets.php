<?php
namespace ProdigiDirect;

use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

/**
 * The private store for print masters. One JPEG per product at
 * wp-content/uploads/prodigi-private/{product_id}/print.jpg, never web-readable directly;
 * Prodigi fetches it through a signed REST link bound to the order.
 */
final class Assets {
	public const META_W     = '_prodigi_master_w';
	public const META_H     = '_prodigi_master_h';
	public const META_NAME  = '_prodigi_master_name';
	public const META_TIME  = '_prodigi_master_time';
	public const MAX_PIXELS = 200_000_000; // Prodigi hangs above ~200 MP
	public const LINK_DAYS  = 30;

	public function hooks(): void {
		add_action( 'rest_api_init', [ $this, 'routes' ] );
		add_action( 'wp_ajax_prodigi_direct_preview', [ $this, 'preview' ] );
	}

	/** Default: wp-content/uploads/prodigi-private. Set PRODIGI_DIRECT_PRIVATE_DIR in wp-config.php to move it outside the web root. */
	public static function dir(): string {
		if ( defined( 'PRODIGI_DIRECT_PRIVATE_DIR' ) && PRODIGI_DIRECT_PRIVATE_DIR ) {
			return untrailingslashit( PRODIGI_DIRECT_PRIVATE_DIR );
		}
		$up = wp_upload_dir( null, false );
		return (string) apply_filters( 'prodigi_direct_private_dir', trailingslashit( $up['basedir'] ) . 'prodigi-private' );
	}

	public static function ensure_dir(): void {
		$dir = self::dir();
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! is_file( $dir . '/.htaccess' ) ) {
			file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" ); // Apache; nginx needs the snippet in README
		}
		if ( ! is_file( $dir . '/index.html' ) ) {
			file_put_contents( $dir . '/index.html', '' );
		}
	}

	public static function path( int $product_id ): string {
		return self::dir() . '/' . $product_id . '/print.jpg';
	}

	public static function has_master( int $product_id ): bool {
		return is_file( self::path( $product_id ) );
	}

	/** @return array{w:int,h:int,name:string,time:int,size:int}|null */
	public static function info( int $product_id ): ?array {
		if ( ! self::has_master( $product_id ) ) {
			return null;
		}
		$product = wc_get_product( $product_id );
		return [
			'w'    => (int) $product->get_meta( self::META_W ),
			'h'    => (int) $product->get_meta( self::META_H ),
			'name' => (string) $product->get_meta( self::META_NAME ),
			'time' => (int) $product->get_meta( self::META_TIME ),
			'size' => (int) filesize( self::path( $product_id ) ),
		];
	}

	/**
	 * Store a JPEG from a local path (an upload or an existing master). Validates type and size.
	 * @return true|WP_Error
	 */
	public static function store( int $product_id, string $source_path, string $original_name = '' ) {
		if ( ! is_file( $source_path ) ) {
			return new WP_Error( 'prodigi_missing', __( 'That file does not exist.', 'prodigi-direct' ) );
		}
		$info = @getimagesize( $source_path );
		if ( ! $info ) {
			return new WP_Error( 'prodigi_not_image', __( "That isn't an image file.", 'prodigi-direct' ) );
		}
		if ( IMAGETYPE_JPEG !== $info[2] ) {
			return new WP_Error( 'prodigi_not_jpeg', __( 'Prodigi needs a JPEG. Save it as JPEG (quality 95) and upload that.', 'prodigi-direct' ) );
		}
		[ $w, $h ] = $info;
		if ( $w * $h > self::MAX_PIXELS ) {
			return new WP_Error( 'prodigi_too_big', __( 'Prodigi needs a JPEG under 200 megapixels. Save a smaller copy and upload that.', 'prodigi-direct' ) );
		}
		self::ensure_dir();
		$dest = self::path( $product_id );
		wp_mkdir_p( dirname( $dest ) );
		if ( ! copy( $source_path, $dest ) ) {
			return new WP_Error( 'prodigi_copy', __( 'Could not save the file into the private folder.', 'prodigi-direct' ) );
		}
		@chmod( $dest, 0640 );
		self::make_thumb( $dest, dirname( $dest ) . '/thumb.jpg' );
		$product = wc_get_product( $product_id );
		$product->update_meta_data( self::META_W, $w );
		$product->update_meta_data( self::META_H, $h );
		$product->update_meta_data( self::META_NAME, $original_name ?: basename( $source_path ) );
		$product->update_meta_data( self::META_TIME, time() );
		$product->save_meta_data();
		Activity_Log::add( sprintf( 'Print file for "%s" replaced (%d × %d px).', $product->get_name(), $w, $h ) );
		return true;
	}

	/**
	 * A 320px preview for the admin screen. Tries `vips` (cheap on any file size), then WordPress's
	 * image editor for files under 30 MP. Bigger files without vips get no preview — never an OOM.
	 */
	private static function make_thumb( string $src, string $dest ): void {
		@unlink( $dest );
		$vips = function_exists( 'exec' ) ? trim( (string) @shell_exec( 'command -v vips 2>/dev/null' ) ) : '';
		if ( $vips ) {
			@exec( escapeshellcmd( $vips ) . ' thumbnail ' . escapeshellarg( $src ) . ' ' . escapeshellarg( $dest . '[Q=82]' ) . ' 320 2>/dev/null', $o, $rc );
			if ( 0 === $rc && is_file( $dest ) ) {
				return;
			}
		}
		$info = @getimagesize( $src );
		if ( ! $info || $info[0] * $info[1] > 30_000_000 ) {
			return;
		}
		wp_raise_memory_limit( 'image' );
		$editor = wp_get_image_editor( $src );
		if ( is_wp_error( $editor ) ) {
			return;
		}
		$editor->resize( 320, 320, false );
		$editor->set_quality( 82 );
		$editor->save( $dest, 'image/jpeg' );
	}

	public static function thumb_path( int $product_id ): string {
		return self::dir() . '/' . $product_id . '/thumb.jpg';
	}

	private static function signer(): Signer {
		return new Signer( wp_salt( 'auth' ) );
	}

	/** The link Prodigi receives on an order line. Valid 30 days, bound to this order. */
	public static function order_url( int $product_id, int $order_id ): string {
		$exp = time() + self::LINK_DAYS * DAY_IN_SECONDS;
		$sig = self::signer()->sign( $product_id, $order_id, $exp );
		return add_query_arg(
			[ 'o' => $order_id, 'e' => $exp, 's' => $sig ],
			rest_url( 'prodigi-direct/v1/master/' . $product_id )
		);
	}

	/** The admin preview goes through admin-ajax (cookie auth), never through the signed master route. */
	public static function preview_url( int $product_id ): string {
		return add_query_arg( [ 'action' => 'prodigi_direct_preview', 'product' => $product_id, '_wpnonce' => wp_create_nonce( 'prodigi_preview_' . $product_id ), 'v' => (int) @filemtime( self::thumb_path( $product_id ) ) ], admin_url( 'admin-ajax.php' ) );
	}

	public function preview(): void {
		$pid = (int) ( $_GET['product'] ?? 0 );
		if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'prodigi_preview_' . $pid ) || ! current_user_can( 'edit_product', $pid ) ) {
			status_header( 403 );
			exit;
		}
		$path = self::thumb_path( $pid );
		if ( ! is_file( $path ) ) {
			status_header( 404 );
			exit;
		}
		nocache_headers();
		header( 'Content-Type: image/jpeg' );
		header( 'Content-Length: ' . filesize( $path ) );
		readfile( $path );
		exit;
	}

	public function routes(): void {
		register_rest_route(
			'prodigi-direct/v1',
			'/master/(?P<id>\d+)',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'serve' ],
				'permission_callback' => '__return_true', // the signature is the permission
				'args'                => [ 'id' => [ 'validate_callback' => static fn( $v ) => is_numeric( $v ) ] ],
			]
		);
	}

	public function serve( WP_REST_Request $r ) {
		$pid = (int) $r['id'];
		$oid = (int) $r->get_param( 'o' );
		$exp = (int) $r->get_param( 'e' );
		$sig = (string) $r->get_param( 's' );
		if ( ! self::signer()->verify( $pid, $oid, $exp, $sig ) ) {
			return new WP_Error( 'prodigi_forbidden', 'Link expired or invalid', [ 'status' => 403 ] );
		}
		if ( $oid > 0 && PHP_INT_MAX !== $oid ) { // PHP_INT_MAX = the settings-page test order, which has no WooCommerce order
			$order = wc_get_order( $oid );
			if ( ! $order || in_array( $order->get_meta( Order_Sender::META_STATE ), [ 'shipped', 'cancelled' ], true ) ) {
				return new WP_Error( 'prodigi_revoked', 'Link revoked', [ 'status' => 403 ] );
			}
		} elseif ( $oid <= 0 ) {
			return new WP_Error( 'prodigi_forbidden', 'No order for this link', [ 'status' => 403 ] );
		}
		$path = self::path( $pid );
		if ( ! is_file( $path ) ) {
			return new WP_Error( 'prodigi_missing', 'No print file', [ 'status' => 404 ] );
		}
		$this->stream( $path );
		exit;
	}

	/** Streams the JPEG with Range support (Prodigi fetches in 4 MB ranged GETs). Uses X-Accel-Redirect when nginx is configured for it. */
	private function stream( string $path ): void {
		nocache_headers();
		$size = filesize( $path );
		header( 'Content-Type: image/jpeg' );
		header( 'Content-Disposition: inline; filename="print.jpg"' );
		header( 'Accept-Ranges: bytes' );
		$accel = apply_filters( 'prodigi_direct_accel_prefix', defined( 'PRODIGI_DIRECT_ACCEL' ) ? PRODIGI_DIRECT_ACCEL : '' );
		if ( $accel ) {
			header( 'X-Accel-Redirect: ' . rtrim( $accel, '/' ) . '/' . basename( dirname( $path ) ) . '/print.jpg' );
			return;
		}
		$start = 0;
		$end   = $size - 1;
		if ( ! empty( $_SERVER['HTTP_RANGE'] ) && preg_match( '/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m ) ) {
			if ( '' !== $m[1] ) {
				$start = (int) $m[1];
			}
			if ( '' !== $m[2] ) {
				$end = min( (int) $m[2], $size - 1 );
			}
			if ( $start > $end || $start >= $size ) {
				status_header( 416 );
				header( "Content-Range: bytes */$size" );
				return;
			}
			status_header( 206 );
			header( "Content-Range: bytes $start-$end/$size" );
		}
		header( 'Content-Length: ' . ( $end - $start + 1 ) );
		if ( ob_get_level() ) {
			ob_end_clean();
		}
		$fh = fopen( $path, 'rb' );
		fseek( $fh, $start );
		$left = $end - $start + 1;
		while ( $left > 0 && ! feof( $fh ) ) {
			$chunk = fread( $fh, min( 1024 * 1024, $left ) );
			echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary
			flush();
			$left -= strlen( $chunk );
		}
		fclose( $fh );
	}
}
