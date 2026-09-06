<?php
namespace ProdigiDirect;

use WC_Product;
use WC_Product_Variable;

/**
 * The shop side: a material → size → frame picker over WooCommerce's own variation form,
 * the artist's recommendation, and the story + details section under the product.
 */
final class Storefront {
	public const META_PICK      = '_prodigi_recommended';      // variation id
	public const META_PICK_NOTE = '_prodigi_recommended_note'; // the artist's own sentence

	public function hooks(): void {
		if ( 'yes' !== Plugin::instance()->setting( 'storefront' ) ) {
			return;
		}
		add_action( 'wp_enqueue_scripts', [ $this, 'assets' ] );
		add_action( 'woocommerce_before_variations_form', [ $this, 'picker' ] );
		add_action( 'woocommerce_after_single_product_summary', [ $this, 'story' ], 12 );
		add_filter( 'woocommerce_product_tabs', [ $this, 'tabs' ], 98 );
		add_filter( 'woocommerce_variation_option_name', [ $this, 'option_name' ], 10, 1 );
	}

	private function managed( ?WC_Product $product ): bool {
		if ( ! $product instanceof WC_Product_Variable ) {
			return false;
		}
		foreach ( $product->get_children() as $vid ) {
			$v = wc_get_product( $vid );
			if ( $v && $v->get_meta( Product_Builder::META_SKU ) ) {
				return true;
			}
		}
		return false;
	}

	public function assets(): void {
		if ( ! is_product() ) {
			return;
		}
		global $product;
		$p = $product instanceof WC_Product ? $product : wc_get_product( get_the_ID() );
		if ( ! $this->managed( $p ) ) {
			return;
		}
		wp_enqueue_style( 'prodigi-direct-shop', PRODIGI_DIRECT_URL . 'assets/storefront.css', [], PRODIGI_DIRECT_VERSION );
		wp_enqueue_script( 'prodigi-direct-shop', PRODIGI_DIRECT_URL . 'assets/storefront.js', [ 'jquery', 'wc-add-to-cart-variation' ], PRODIGI_DIRECT_VERSION, true );
	}

	/** The options a customer can buy, grouped for the picker. */
	public function options( WC_Product_Variable $product ): array {
		$cat     = Plugin::instance()->catalogue();
		$builder = new Product_Builder( $cat );
		$attr    = $builder->attr_key( $product );
		$out     = [];
		foreach ( $product->get_available_variations( 'objects' ) as $v ) {
			if ( ! $v->is_purchasable() || ! $v->is_in_stock() || 'publish' !== $v->get_status() ) {
				continue;
			}
			$label = $builder->label_of( $v );
			$p     = $cat->parse_label( $label );
			if ( ! $p ) {
				continue;
			}
			$fam      = $cat->family( $p['family'] );
			$own      = (int) ( ( (array) Plugin::instance()->setting( 'family_images' ) )[ $p['family'] ] ?? 0 );
			$fam_img  = $own ? (string) wp_get_attachment_image_url( $own, 'large' ) : (string) ( $fam['image'] ?? '' );
			$choice_l = $p['choice'] ? ( $cat->choices( $p['family'] )[ $p['choice'] ] ?? $p['choice'] ) : '';
			$out[]    = [
				'id'       => $v->get_id(),
				'label'    => $label,
				'family'   => $p['family'],
				'material' => $fam['short'],
				'group'    => $fam['group'],
				'medium'   => (string) ( $fam['medium'] ?? 'paper' ),
				'style'    => (string) ( $fam['style'] ?? $fam['short'] ) . ( $choice_l ? ' — ' . $choice_l : '' ),
				'style_k'  => $p['family'] . '|' . (string) $p['choice'],
				'desc'     => $cat->description( $p['family'] ),
				'image'    => (string) ( $p['choice'] ? ( $fam['choice_images'][ $p['choice'] ] ?? $fam_img ) : $fam_img ),
				'size'     => $p['size'],
				'choice'   => (string) $p['choice'],
				'choice_l' => $choice_l,
				'price'    => wc_get_price_to_display( $v ),
				'price_h'  => wp_strip_all_tags( wc_price( wc_get_price_to_display( $v ) ) ),
			];
		}
		return [ 'attr' => $attr, 'options' => $out ];
	}

	public function picker(): void {
		global $product;
		if ( ! $this->managed( $product ) ) {
			return;
		}
		$data    = $this->options( $product );
		$cat     = Plugin::instance()->catalogue();
		$pick_id = (int) $product->get_meta( self::META_PICK );
		$pick    = null;
		foreach ( $data['options'] as $o ) {
			if ( $o['id'] === $pick_id ) {
				$pick = $o;
			}
		}
		$artist = (string) ( Plugin::instance()->setting( 'artist_name' ) ?: get_bloginfo( 'name' ) );
		$note   = (string) $product->get_meta( self::META_PICK_NOTE );
		$media = [];
		foreach ( $cat->media() as $key => $m ) {
			$own = (int) ( ( (array) Plugin::instance()->setting( 'family_images' ) )[ 'medium-' . $key ] ?? 0 );
			$media[ $key ] = [ 'label' => $m['label'], 'image' => $own ? (string) wp_get_attachment_image_url( $own, 'medium' ) : (string) $m['image'] ];
		}
		?>
		<div class="pd-picker" data-attr="<?php echo esc_attr( $data['attr'] ); ?>" data-options="<?php echo esc_attr( wp_json_encode( $data['options'] ) ); ?>" data-media="<?php echo esc_attr( wp_json_encode( $media ) ); ?>" data-pick="<?php echo esc_attr( $pick_id ); ?>">
			<?php if ( $pick ) : ?>
			<div class="pd-pick">
				<div class="pd-pick-head"><?php echo esc_html( sprintf( __( "%s's recommendation", 'prodigi-direct' ), $artist ) ); ?></div>
				<div class="pd-pick-body">
					<div class="pd-pick-what"><strong><?php echo esc_html( $pick['size'] . '" ' . $pick['material'] . ( $pick['choice_l'] ? ', ' . $pick['choice_l'] : '' ) ); ?></strong> <span class="pd-pick-price"><?php echo esc_html( $pick['price_h'] ); ?></span></div>
					<?php if ( $note ) : ?><p class="pd-pick-note"><?php echo esc_html( $note ); ?></p><?php endif; ?>
					<button type="button" class="pd-pick-choose" data-id="<?php echo esc_attr( $pick['id'] ); ?>"><?php esc_html_e( 'Choose this', 'prodigi-direct' ); ?></button>
				</div>
			</div>
			<?php endif; ?>
			<div class="pd-preview" aria-live="polite"><img src="" alt="" /><div class="pd-preview-cap"></div></div>
			<?php foreach ( [ 'medium' => __( 'Medium', 'prodigi-direct' ), 'size' => __( 'Size', 'prodigi-direct' ), 'style' => __( 'Style', 'prodigi-direct' ) ] as $i => $label ) : static $n = 0; ++$n; ?>
			<div class="pd-acc" data-step="<?php echo esc_attr( $i ); ?>">
				<button type="button" class="pd-acc-head" aria-expanded="false"><span class="pd-acc-num"><?php echo esc_html( $n . ' ' . $label ); ?></span><span class="pd-acc-val"></span><span class="pd-acc-chev" aria-hidden="true"></span></button>
				<div class="pd-acc-body" hidden><div class="pd-tiles"></div></div>
			</div>
			<?php endforeach; ?>
			<div class="pd-chosen" hidden></div>
		</div>
		<?php
	}

	/** Under the summary: the story (the product description, her words) and the details panel. */
	public function story(): void {
		global $product;
		if ( ! $this->managed( $product ) ) {
			return;
		}
		$story = 'yes' === Plugin::instance()->setting( 'story' ) ? $product->get_description() : '';
		$data  = $this->options( $product );
		$cat   = Plugin::instance()->catalogue();
		$fams  = [];
		$sizes = [];
		foreach ( $data['options'] as $o ) {
			$fams[ $o['family'] ] = $o['material'];
			$sizes[ $o['size'] ]  = true;
		}
		$size_keys = array_keys( $sizes );
		usort( $size_keys, static fn( $a, $b ) => array_product( array_map( 'intval', explode( 'x', $a ) ) ) <=> array_product( array_map( 'intval', explode( 'x', $b ) ) ) );
		$artist = (string) ( Plugin::instance()->setting( 'artist_name' ) ?: get_bloginfo( 'name' ) );
		?>
		<section class="pd-story-section">
			<?php if ( trim( wp_strip_all_tags( $story ) ) ) : ?>
			<div class="pd-story">
				<h2 class="pd-h"><?php esc_html_e( 'The story', 'prodigi-direct' ); ?></h2>
				<div class="pd-story-text"><?php echo wp_kses_post( wpautop( $story ) ); ?></div>
				<div class="pd-story-sig">— <?php echo esc_html( $artist ); ?></div>
			</div>
			<?php endif; ?>
			<aside class="pd-details">
				<h2 class="pd-h"><?php esc_html_e( 'Details', 'prodigi-direct' ); ?></h2>
				<dl>
					<?php foreach ( $fams as $key => $label ) : ?>
						<dt><?php echo esc_html( $label ); ?></dt><dd><?php echo esc_html( $cat->description( $key ) ); ?></dd>
					<?php endforeach; ?>
					<dt><?php esc_html_e( 'Sizes', 'prodigi-direct' ); ?></dt><dd><?php echo esc_html( implode( ' · ', array_map( static fn( $s ) => str_replace( 'x', ' × ', $s ) . '"', $size_keys ) ) ); ?></dd>
					<dt><?php esc_html_e( 'Printing', 'prodigi-direct' ); ?></dt><dd><?php esc_html_e( 'Each print is made to order by a fine-art lab after you buy, from the artist’s master file, and ships in protective packaging.', 'prodigi-direct' ); ?></dd>
					<?php echo apply_filters( 'prodigi_direct_details_extra', '', $product ); // phpcs:ignore ?>
				</dl>
			</aside>
		</section>
		<?php
	}

	/** The story section replaces WooCommerce's Description and Additional information tabs. */
	public function tabs( array $tabs ): array {
		global $product;
		if ( $this->managed( $product ) ) {
			unset( $tabs['description'], $tabs['additional_information'] );
		}
		return $tabs;
	}

	public function option_name( $name ) {
		return $name;
	}
}
