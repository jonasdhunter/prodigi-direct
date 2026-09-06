<?php
namespace ProdigiDirect;

/**
 * Nightly: every SKU the shop uses is checked at Prodigi (still exists? what does it cost now?).
 * A retired SKU hides its variations; a cost move over 5% is reported. Never deletes anything.
 */
final class Catalogue_Check {
	public const JOB = 'prodigi_direct_catalogue_check';

	public function hooks(): void {
		add_action( self::JOB, [ $this, 'run' ] );
		add_action( 'action_scheduler_init', [ $this, 'schedule' ] );
	}

	public function schedule(): void {
		if ( function_exists( 'as_has_scheduled_action' ) && ! as_has_scheduled_action( self::JOB, [], Plugin::GROUP ) ) {
			as_schedule_recurring_action( strtotime( 'tomorrow 03:10' ), DAY_IN_SECONDS, self::JOB, [], Plugin::GROUP, true );
		}
	}

	/** SKUs in use → variation ids. @return array<string, int[]> */
	public function skus_in_use(): array {
		$map = [];
		$ids = wc_get_products( [ 'type' => 'variation', 'limit' => -1, 'return' => 'ids', 'status' => [ 'publish', 'private' ] ] );
		foreach ( $ids as $vid ) {
			$v   = wc_get_product( $vid );
			$sku = $v ? strtoupper( (string) $v->get_meta( Product_Builder::META_SKU ) ) : '';
			if ( $sku ) {
				$map[ $sku ][] = (int) $vid;
			}
		}
		return $map;
	}

	/** @return array{checked:int, retired:string[], changed:string[], errors:string[]} */
	public function run(): array {
		$plugin  = Plugin::instance();
		$api     = $plugin->readonly_api();
		$cat     = $plugin->catalogue();
		$report  = [ 'checked' => 0, 'retired' => [], 'changed' => [], 'errors' => [] ];
		if ( ! $api->has_key() ) {
			$report['errors'][] = __( 'No API key set.', 'prodigi-direct' );
			return $report;
		}
		$attrs_by_sku = [];
		foreach ( $cat->families() as $fkey => $fam ) {
			foreach ( $fam['sizes'] as $s ) {
				$choice                            = $cat->choice_attribute( $fkey ) ? array_key_first( $cat->choices( $fkey ) ) : null;
				$attrs_by_sku[ strtoupper( $s['sku'] ) ] = [ $cat->attributes( $fkey, $choice ), $cat->label( $fkey, $s['key'] ) ];
			}
		}
		foreach ( $this->skus_in_use() as $sku => $vids ) {
			++$report['checked'];
			$label = $attrs_by_sku[ $sku ][1] ?? $sku;
			$attrs = $attrs_by_sku[ $sku ][0] ?? [];
			$prod  = $api->get_product( $sku );
			if ( is_wp_error( $prod ) && 'prodigi_not_found' === $prod->get_error_code() ) {
				$report['retired'][] = $label;
				foreach ( $vids as $vid ) {
					$v = wc_get_product( $vid );
					if ( $v && 'private' !== $v->get_status() ) {
						$v->set_status( 'private' );
						$v->save();
					}
				}
				continue;
			}
			if ( is_wp_error( $prod ) ) {
				$report['errors'][] = $label . ': ' . $prod->get_error_message();
				continue;
			}
			$q = $api->quote( [ [ 'sku' => $sku, 'copies' => 1, 'attributes' => $attrs ] ], 'US', (string) $plugin->setting( 'shipping_method' ) );
			if ( is_wp_error( $q ) ) {
				$report['errors'][] = $label . ': ' . $q->get_error_message();
				continue;
			}
			$old = Costs::for_sku( $sku ) ?: ( $cat->cost( ...$this->family_size( $cat, $sku ) ) ?: null );
			Costs::set( $sku, $q['items'], $q['shipping'] );
			if ( $old && Pricing::cost_changed( $old['print'] + $old['ship'], $q['items'] + $q['shipping'] ) ) {
				$report['changed'][] = sprintf( '%s: %s → %s', $label, wp_strip_all_tags( wc_price( $old['print'] + $old['ship'] ) ), wp_strip_all_tags( wc_price( $q['items'] + $q['shipping'] ) ) );
			}
		}
		$plugin->update_settings( [ 'catalogue_checked' => time() ] );
		$lines = [];
		foreach ( $report['retired'] as $r ) {
			$lines[] = sprintf( 'Prodigi no longer offers %s. It is hidden from the shop until you choose what to do.', $r );
		}
		foreach ( $report['changed'] as $c ) {
			$lines[] = 'Cost changed — ' . wp_strip_all_tags( $c );
		}
		foreach ( $lines as $l ) {
			Activity_Log::add( $l, 'warning' );
		}
		Activity_Log::add( sprintf( 'Catalogue check: %d print types checked, %d retired, %d cost changes.', $report['checked'], count( $report['retired'] ), count( $report['changed'] ) ) );
		if ( $lines ) {
			Notifier::catalogue( 'Prodigi catalogue changes', $lines );
		}
		return $report;
	}

	private function family_size( Catalogue $cat, string $sku ): array {
		foreach ( $cat->families() as $fkey => $fam ) {
			foreach ( $fam['sizes'] as $s ) {
				if ( strtoupper( $s['sku'] ) === $sku ) {
					return [ $fkey, $s['key'] ];
				}
			}
		}
		return [ '', '' ];
	}
}
