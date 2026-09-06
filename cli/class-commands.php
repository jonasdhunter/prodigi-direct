<?php
namespace ProdigiDirect\CLI;

use ProdigiDirect\Assets;
use ProdigiDirect\Catalogue_Check;
use ProdigiDirect\Order_Sender;
use ProdigiDirect\Order_Status;
use ProdigiDirect\Plugin;
use ProdigiDirect\Price_Book;
use ProdigiDirect\Product_Builder;
use WP_CLI;

/**
 * Prodigi Direct from the command line.
 *
 * ## EXAMPLES
 *
 *     wp prodigi-direct seed --csv=examples/seed.csv
 *     wp prodigi-direct master 123 /srv/art-master/serenity.jpg
 *     wp prodigi-direct build 123 --families=paper,canvas-rolled --sizes=8x10,16x20
 *     wp prodigi-direct check-catalogue
 *     wp prodigi-direct send 1042
 *     wp prodigi-direct sync 1042
 */
final class Commands {
	/**
	 * Import a seed CSV: product,label,price[,family,size] — sets the price book and marks matching variations.
	 *
	 * ## OPTIONS
	 * --csv=<path>
	 * : Path to the CSV.
	 */
	public function seed( array $args, array $assoc ): void {
		$path = $assoc['csv'] ?? '';
		if ( ! is_file( $path ) ) {
			WP_CLI::error( 'CSV not found: ' . $path );
		}
		$cat  = Plugin::instance()->catalogue();
		$fh   = fopen( $path, 'r' );
		$head = array_map( 'trim', fgetcsv( $fh ) );
		$n    = 0;
		while ( ( $row = fgetcsv( $fh ) ) !== false ) {
			$r = array_combine( $head, $row );
			$p = $cat->parse_label( $r['label'] ?? $r['option'] ?? '' );
			if ( ! $p ) {
				continue;
			}
			$price = (float) ( $r['price'] ?? $r['retail'] ?? 0 );
			if ( $price > 0 && null === Price_Book::get( $p['family'], $p['size'] ) ) {
				Price_Book::set( $p['family'], $p['size'], $price );
				++$n;
			}
		}
		WP_CLI::success( "Price book: $n prices set." );
	}

	/**
	 * Attach a print master (JPEG) to a product from a local path.
	 *
	 * ## OPTIONS
	 * <product_id>
	 * : The product.
	 * <path>
	 * : Local path to the JPEG.
	 */
	public function master( array $args ): void {
		[ $pid, $path ] = $args;
		$r              = Assets::store( (int) $pid, $path, basename( $path ) );
		is_wp_error( $r ) ? WP_CLI::error( $r->get_error_message() ) : WP_CLI::success( 'Stored.' );
	}

	/**
	 * Build/adopt the print variations of a product.
	 *
	 * ## OPTIONS
	 * <product_id>
	 * : The product.
	 * [--families=<list>]
	 * : Comma-separated family keys.
	 * [--sizes=<list>]
	 * : Comma-separated size keys.
	 * [--choices=<json>]
	 * : e.g. '{"framed-print-classic":["black","white"]}'
	 * [--detect]
	 * : Use what the product already has.
	 */
	public function build( array $args, array $assoc ): void {
		$pid     = (int) $args[0];
		$builder = new Product_Builder( Plugin::instance()->catalogue() );
		if ( isset( $assoc['detect'] ) ) {
			$d = $builder->detect( $pid );
		} else {
			$d = [
				'families' => array_filter( explode( ',', $assoc['families'] ?? '' ) ),
				'sizes'    => array_filter( explode( ',', $assoc['sizes'] ?? '' ) ),
				'choices'  => (array) json_decode( $assoc['choices'] ?? '{}', true ),
			];
		}
		$stats = $builder->build( $pid, $d['families'], $d['sizes'], $d['choices'] );
		WP_CLI::success( wp_json_encode( $stats ) );
	}

	/**
	 * Adopt every variable product that already has print labels (one-time install step).
	 *
	 * @subcommand adopt-all
	 */
	public function adopt_all(): void {
		$builder = new Product_Builder( Plugin::instance()->catalogue() );
		foreach ( wc_get_products( [ 'type' => 'variable', 'limit' => -1 ] ) as $p ) {
			$d = $builder->detect( $p->get_id() );
			if ( ! $d['families'] ) {
				continue;
			}
			$stats = $builder->build( $p->get_id(), $d['families'], $d['sizes'], $d['choices'] );
			WP_CLI::log( sprintf( '%s: %s', $p->get_name(), wp_json_encode( $stats ) ) );
		}
		WP_CLI::success( 'Done.' );
	}

	/**
	 * Check every SKU in use against Prodigi and refresh costs.
	 *
	 * @subcommand check-catalogue
	 */
	public function check_catalogue(): void {
		$r = ( new Catalogue_Check() )->run();
		WP_CLI::log( wp_json_encode( $r, JSON_PRETTY_PRINT ) );
		WP_CLI::success( 'Checked ' . $r['checked'] . ' SKUs.' );
	}

	/**
	 * Prepare (quote) or send an order.
	 *
	 * ## OPTIONS
	 * <order_id>
	 * : The WooCommerce order.
	 * [--now]
	 * : Send immediately instead of waiting for approval.
	 */
	public function send( array $args, array $assoc ): void {
		$id     = (int) $args[0];
		$sender = new Order_Sender();
		$sender->prepare( $id );
		if ( isset( $assoc['now'] ) ) {
			$sender->reset_to_waiting( $id );
			$sender->submit( $id );
		}
		$o = wc_get_order( $id );
		WP_CLI::success( sprintf( 'State: %s %s', $o->get_meta( Order_Sender::META_STATE ), $o->get_meta( Order_Sender::META_ERROR ) ) );
	}

	/**
	 * Re-fetch an order's status from Prodigi.
	 *
	 * ## OPTIONS
	 * <order_id>
	 * : The WooCommerce order.
	 */
	public function sync( array $args ): void {
		( new Order_Status() )->sync( (int) $args[0] );
		$o = wc_get_order( (int) $args[0] );
		WP_CLI::success( 'State: ' . $o->get_meta( Order_Sender::META_STATE ) );
	}

	/**
	 * Print the lines the plugin would send for an order (no API call).
	 *
	 * ## OPTIONS
	 * <order_id>
	 * : The WooCommerce order.
	 */
	public function lines( array $args ): void {
		$o = wc_get_order( (int) $args[0] );
		WP_CLI::log( wp_json_encode( ( new Order_Sender() )->print_lines( $o ), JSON_PRETTY_PRINT ) );
	}
}
