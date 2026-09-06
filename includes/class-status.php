<?php
namespace ProdigiDirect;

/** Reads a Prodigi order object into the five words she sees, and the problems into sentences. */
final class Status {
	public const STATES = [ 'waiting', 'sent', 'printing', 'shipped', 'cancelled', 'problem', 'manual' ];

	private static function lc( $v ): string { return strtolower( (string) $v ); }

	/** Issues that mean "act now", as opposed to Prodigi's own retry warnings. */
	private static function blocking_issues( array $order ): array {
		$out = [];
		foreach ( $order['status']['issues'] ?? [] as $issue ) {
			$code = self::lc( $issue['errorCode'] ?? '' );
			if ( str_ends_with( $code, 'assets.notdownloaded' ) ) {
				continue; // "Warning: download attempt n of 10" — Prodigi is still trying
			}
			$out[] = $issue;
		}
		return $out;
	}

	public static function state( array $order ): string {
		$stage = self::lc( $order['status']['stage'] ?? '' );
		if ( 'cancelled' === $stage ) {
			return 'cancelled';
		}
		if ( self::blocking_issues( $order ) ) {
			return 'problem';
		}
		$d = $order['status']['details'] ?? [];
		if ( 'complete' === $stage || 'complete' === self::lc( $d['shipping'] ?? '' ) ) {
			return 'shipped';
		}
		if ( in_array( self::lc( $d['inProduction'] ?? '' ), [ 'inprogress', 'complete' ], true ) || 'inprogress' === self::lc( $d['shipping'] ?? '' ) ) {
			return 'printing';
		}
		return 'sent';
	}

	public static function label( string $state ): string {
		return [
			'waiting'   => 'Waiting for you',
			'sent'      => 'Sent',
			'printing'  => 'Printing',
			'shipped'   => 'Shipped',
			'cancelled' => 'Cancelled',
			'problem'   => 'Problem',
			'manual'    => 'Handled by you',
		][ $state ] ?? '—';
	}

	/** One sentence in her words for the first blocking issue. */
	public static function problem_text( array $order ): string {
		foreach ( self::blocking_issues( $order ) as $issue ) {
			$code = self::lc( $issue['errorCode'] ?? '' );
			if ( str_contains( $code, 'requirespaymentauthorisation' ) ) {
				return "Prodigi couldn't charge the card on the account. Check the card in Prodigi, then try again.";
			}
			if ( str_contains( $code, 'failedtodownload' ) ) {
				return "Prodigi couldn't fetch the painting file. Usually a temporary link problem — try again.";
			}
			if ( str_contains( $code, 'itemunavailable' ) ) {
				return "Prodigi can't make one of these prints right now (the size or material is unavailable).";
			}
			return 'Prodigi reported a problem: ' . ( $issue['description'] ?? $issue['errorCode'] ?? 'unknown' );
		}
		return '';
	}

	public static function action_url( array $order ): string {
		foreach ( self::blocking_issues( $order ) as $issue ) {
			if ( ! empty( $issue['authorisationDetails']['authorisationUrl'] ) ) {
				return (string) $issue['authorisationDetails']['authorisationUrl'];
			}
		}
		return '';
	}

	/** @return array<int, array{id:string,status:string,carrier:string,service:string,tracking_number:string,tracking_url:string,items:string[]}> */
	public static function shipments( array $order ): array {
		$out = [];
		foreach ( $order['shipments'] ?? [] as $s ) {
			$out[] = [
				'id'              => (string) ( $s['id'] ?? '' ),
				'status'          => (string) ( $s['status'] ?? '' ),
				'carrier'         => (string) ( $s['carrier']['name'] ?? '' ),
				'service'         => (string) ( $s['carrier']['service'] ?? '' ),
				'tracking_number' => (string) ( $s['tracking']['number'] ?? '' ),
				'tracking_url'    => (string) ( $s['tracking']['url'] ?? '' ),
				'dispatched'      => (string) ( $s['dispatchDate'] ?? '' ),
				'items'           => array_map( static fn( $i ) => (string) ( $i['itemId'] ?? '' ), $s['items'] ?? [] ),
			];
		}
		return $out;
	}

	public static function carrier_label( string $carrier ): string {
		$map = [ 'fedex' => 'FedEx', 'ups' => 'UPS', 'usps' => 'USPS', 'dhl' => 'DHL', 'royalmail' => 'Royal Mail', 'canadapost' => 'Canada Post', 'mixed' => 'Several carriers' ];
		return $map[ strtolower( $carrier ) ] ?? ( $carrier ?: 'the carrier' );
	}
}
