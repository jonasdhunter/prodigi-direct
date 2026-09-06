<?php
namespace ProdigiDirect;

/**
 * The plugin's knowledge of Prodigi's range: families (what the artist calls a material),
 * sizes, SKUs, the attributes an order needs, print-area pixels and last-verified costs.
 * Pure PHP — no WordPress calls — so it is unit-tested and usable from the CLI.
 */
final class Catalogue {
	/** @var array<string, array> keyed by family key, in display order */
	private array $families = [];
	private string $version = '';
	/** @var array<string,string> */
	private array $links = [];

	public static function load( ?string $file = null ): self {
		$file = $file ?: PRODIGI_DIRECT_DIR . 'catalogue/prodigi.json';
		$data = json_decode( (string) file_get_contents( $file ), true );
		if ( ! is_array( $data ) || empty( $data['families'] ) ) {
			throw new \RuntimeException( 'Prodigi catalogue file is missing or invalid: ' . $file );
		}
		$self          = new self();
		$self->version = (string) ( $data['version'] ?? '' );
		$self->links   = (array) ( $data['links'] ?? [] );
		foreach ( $data['families'] as $fam ) {
			$fam['sizes_by_key'] = [];
			foreach ( $fam['sizes'] as $size ) {
				$fam['sizes_by_key'][ $size['key'] ] = $size;
			}
			$self->families[ $fam['key'] ] = $fam;
		}
		return $self;
	}

	public function version(): string { return $this->version; }

	/** Prodigi reference links: product_range, portfolio_pdf, api_reference, dashboard, sandbox_dashboard. @return array<string,string> */
	public function links(): array { return $this->links; }

	/** Prodigi's product page for a family. */
	public function url( string $family ): string { return (string) ( $this->families[ $family ]['url'] ?? $this->links['product_range'] ?? '' ); }

	/** @return array<string, array> */
	public function families(): array { return $this->families; }

	public function family( string $key ): ?array { return $this->families[ $key ] ?? null; }

	public function size( string $family, string $size ): ?array {
		return $this->families[ $family ]['sizes_by_key'][ $size ] ?? null;
	}

	/** @return string[] */
	public function size_keys( string $family ): array {
		return array_keys( $this->families[ $family ]['sizes_by_key'] ?? [] );
	}

	/** Every size key across all families, sorted by area. @return string[] */
	public function all_size_keys(): array {
		$seen = [];
		foreach ( $this->families as $fam ) {
			foreach ( $fam['sizes'] as $s ) {
				$seen[ $s['key'] ] = $s['width_in'] * $s['height_in'] * 1000 + $s['width_in'];
			}
		}
		asort( $seen );
		return array_keys( $seen );
	}

	public function sku( string $family, string $size ): ?string {
		return $this->size( $family, $size )['sku'] ?? null;
	}

	/** @return string[] */
	public function all_skus(): array {
		$out = [];
		foreach ( $this->families as $fam ) {
			foreach ( $fam['sizes'] as $s ) {
				$out[] = $s['sku'];
			}
		}
		return $out;
	}

	public function orderable( string $family, string $size ): bool {
		$s = $this->size( $family, $size );
		return $s ? (bool) $s['orderable'] : false;
	}

	/** Last-verified cost basis (US, Budget). @return array{print: float, ship: float}|null */
	public function cost( string $family, string $size ): ?array {
		$c = $this->size( $family, $size )['cost'] ?? null;
		return $c ? [ 'print' => (float) $c['print'], 'ship' => (float) $c['ship'] ] : null;
	}

	/** @return array{0:int,1:int} width, height in pixels at 300 dpi */
	public function print_area_px( string $family, string $size ): array {
		$px = $this->size( $family, $size )['print_area_px'] ?? [ 0, 0 ];
		return [ (int) $px[0], (int) $px[1] ];
	}

	public function sizing( string $family ): string {
		return $this->families[ $family ]['sizing'] ?? 'fitPrintArea';
	}

	/** Choice values (e.g. frame colours) → label. @return array<string,string> */
	public function choices( string $family ): array {
		return $this->families[ $family ]['choices'] ?? [];
	}

	public function choice_attribute( string $family ): ?string {
		return $this->families[ $family ]['choice_attribute'] ?? null;
	}

	/**
	 * The attributes Prodigi needs on the order line: the family's fixed ones (wrap=White)
	 * plus the artist's choice (color=black) when the family has one.
	 * @return array<string,string>
	 */
	public function attributes( string $family, ?string $choice ): array {
		$fam   = $this->families[ $family ] ?? [];
		$attrs = (array) ( $fam['fixed_attributes'] ?? [] );
		$key   = $fam['choice_attribute'] ?? null;
		if ( $key && null !== $choice && '' !== $choice ) {
			$attrs[ $key ] = $choice;
		}
		return $attrs;
	}

	/** Human label in the shop's existing convention: 16x20" Paper · 16x20" Classic Frame, Black */
	public function label( string $family, string $size, ?string $choice = null ): string {
		$fam   = $this->families[ $family ];
		$label = $size . '" ' . $fam['short'];
		if ( ! empty( $fam['choice_attribute'] ) && $choice ) {
			$label .= ', ' . ( $fam['choices'][ $choice ] ?? ucfirst( $choice ) );
		}
		return $label;
	}

	/** Reverse of label(): adopt an existing variation by its "Print Options" text. */
	public function parse_label( string $label ): ?array {
		$label = trim( $label );
		if ( ! preg_match( '/^(\d+x\d+)"\s+(.+)$/u', $label, $m ) ) {
			return null;
		}
		$size = $m[1];
		$rest = $m[2];
		// Longest short-label first so "Classic Frame, matted" wins over "Classic Frame".
		$fams = $this->families;
		uasort( $fams, static fn( $a, $b ) => strlen( $b['short'] ) <=> strlen( $a['short'] ) );
		foreach ( $fams as $key => $fam ) {
			if ( $rest === $fam['short'] ) {
				return isset( $fam['sizes_by_key'][ $size ] ) ? [ 'family' => $key, 'size' => $size, 'choice' => null ] : null;
			}
			$prefix = $fam['short'] . ', ';
			if ( ! empty( $fam['choice_attribute'] ) && 0 === strpos( $rest, $prefix ) ) {
				$choice_label = substr( $rest, strlen( $prefix ) );
				$choice       = array_search( $choice_label, $fam['choices'], true );
				if ( false !== $choice && isset( $fam['sizes_by_key'][ $size ] ) ) {
					return [ 'family' => $key, 'size' => $size, 'choice' => (string) $choice ];
				}
			}
		}
		return null;
	}
}
