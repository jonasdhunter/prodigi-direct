<?php
namespace ProdigiDirect\Admin;

use ProdigiDirect\Assets;
use ProdigiDirect\Dpi;
use ProdigiDirect\Plugin;
use ProdigiDirect\Price_Book;
use ProdigiDirect\Product_Builder;

/** The "Prints" tab on a variable product: the print file, what to offer, and the sizes table. */
final class Product_Panel {
	public function hooks(): void {
		add_filter( 'woocommerce_product_data_tabs', [ $this, 'tab' ] );
		add_action( 'woocommerce_product_data_panels', [ $this, 'panel' ] );
		add_action( 'admin_post_prodigi_direct_upload_master', [ $this, 'upload_master' ] );
		add_action( 'wp_ajax_prodigi_direct_build', [ $this, 'ajax_build' ] );
		add_action( 'wp_ajax_prodigi_direct_set_price', [ $this, 'ajax_set_price' ] );
		add_action( 'woocommerce_variation_options_pricing', [ $this, 'variation_details' ], 10, 3 );
		add_filter( 'post_edit_form_tag', [ $this, 'multipart' ] );
	}

	public function multipart( $out = '' ) {
		echo ' enctype="multipart/form-data"';
		return $out;
	}

	public function tab( array $tabs ): array {
		$tabs['prodigi_direct'] = [
			'label'    => __( 'Prints', 'prodigi-direct' ),
			'target'   => 'prodigi_direct_panel',
			'class'    => [ 'show_if_variable' ],
			'priority' => 25,
		];
		return $tabs;
	}

	public function panel(): void {
		global $post;
		$pid     = (int) $post->ID;
		$plugin  = Plugin::instance();
		$cat     = $plugin->catalogue();
		$builder = new Product_Builder( $cat );
		$product = wc_get_product( $pid );
		$master  = Assets::info( $pid );
		$fams    = (array) $product->get_meta( Product_Builder::P_FAMILIES );
		$sizes   = (array) $product->get_meta( Product_Builder::P_SIZES );
		$choices = (array) $product->get_meta( Product_Builder::P_CHOICES );
		if ( ! $fams && ! $sizes ) {
			$d       = $builder->detect( $pid );
			$fams    = $d['families'];
			$sizes   = $d['sizes'];
			$choices = $d['choices'];
		}
		$rows    = $builder->rows( $pid );
		$suggest = $builder->suggest( $pid );
		$fresh   = ! $fams && ! $sizes && ! $rows; // nothing chosen yet: pre-tick what the file supports
		if ( $fresh && $suggest ) {
			$fams  = $suggest['families'];
			$sizes = $suggest['suggested'];
		}
		$links = $cat->links();
		?>
		<div id="prodigi_direct_panel" class="panel woocommerce_options_panel hidden prodigi-panel" data-product="<?php echo esc_attr( $pid ); ?>">
			<h3><?php esc_html_e( 'The print file', 'prodigi-direct' ); ?></h3>
			<div class="prodigi-block">
				<?php if ( $master ) : ?>
					<p class="prodigi-master">
						<img src="<?php echo esc_url( Assets::preview_url( $pid ) ); ?>" alt="" class="prodigi-thumb" />
						<strong><?php echo esc_html( $master['name'] ); ?></strong> · <?php echo esc_html( number_format_i18n( $master['w'] ) . ' × ' . number_format_i18n( $master['h'] ) ); ?> px · <?php echo esc_html( size_format( $master['size'] ) ); ?> · <?php echo esc_html( sprintf( __( 'uploaded %s', 'prodigi-direct' ), wp_date( get_option( 'date_format' ), $master['time'] ) ) ); ?>
					</p>
				<?php else : ?>
					<p class="prodigi-warn"><?php esc_html_e( 'No print file yet — Prodigi cannot print this artwork until there is one. Upload the full-size JPEG (quality 95, under 200 megapixels).', 'prodigi-direct' ); ?></p>
				<?php endif; ?>
				<p>
					<input type="file" name="prodigi_master" accept="image/jpeg" />
					<button type="submit" class="button" name="prodigi_direct_action" value="upload_master" formaction="<?php echo esc_url( admin_url( 'admin-post.php?action=prodigi_direct_upload_master&product=' . $pid . '&_wpnonce=' . wp_create_nonce( 'prodigi_master_' . $pid ) ) ); ?>" formmethod="post" formenctype="multipart/form-data"><?php echo $master ? esc_html__( 'Replace file', 'prodigi-direct' ) : esc_html__( 'Upload file', 'prodigi-direct' ); ?></button>
					<span class="description"><?php esc_html_e( 'The file is stored privately; only Prodigi gets a link, and only for an order.', 'prodigi-direct' ); ?></span>
				</p>
			</div>

			<h3><?php esc_html_e( 'What to offer', 'prodigi-direct' ); ?> <small class="prodigi-links"><a href="<?php echo esc_url( $links['product_range'] ?? '' ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Prodigi product range ↗', 'prodigi-direct' ); ?></a> · <a href="<?php echo esc_url( $links['portfolio_pdf'] ?? '' ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'portfolio PDF ↗', 'prodigi-direct' ); ?></a></small></h3>
			<div class="prodigi-block prodigi-offer">
				<div class="prodigi-col">
					<strong><?php esc_html_e( 'Materials', 'prodigi-direct' ); ?></strong>
					<?php foreach ( $cat->families() as $key => $fam ) : ?>
						<label class="prodigi-check"><input type="checkbox" class="prodigi-family" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $fams, true ) ); ?> /> <?php echo esc_html( $fam['label'] ); ?> <a class="prodigi-ext" href="<?php echo esc_url( $cat->url( $key ) ); ?>" target="_blank" rel="noopener" title="<?php esc_attr_e( 'This product at Prodigi', 'prodigi-direct' ); ?>">↗</a></label>
						<?php if ( $cat->choice_attribute( $key ) ) : ?>
							<div class="prodigi-choices" data-family="<?php echo esc_attr( $key ); ?>" <?php echo in_array( $key, $fams, true ) ? '' : 'hidden'; ?>>
								<?php foreach ( $cat->choices( $key ) as $val => $lab ) : ?>
									<label class="prodigi-check prodigi-sub"><input type="checkbox" class="prodigi-choice" data-family="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $val ); ?>" <?php checked( in_array( $val, (array) ( $choices[ $key ] ?? [] ), true ) ); ?> /> <?php echo esc_html( $lab ); ?></label>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					<?php endforeach; ?>
				</div>
				<div class="prodigi-col">
					<strong><?php esc_html_e( 'Sizes (inches)', 'prodigi-direct' ); ?></strong>
					<?php if ( $suggest ) : ?>
						<button type="button" class="button button-small" id="prodigi-suggest" data-sizes="<?php echo esc_attr( implode( ',', $suggest['suggested'] ) ); ?>" data-families="<?php echo esc_attr( implode( ',', $suggest['families'] ) ); ?>"><?php esc_html_e( 'Suggest from the file', 'prodigi-direct' ); ?></button>
						<span class="description"><?php echo esc_html( sprintf( __( 'Ticks the sizes this %1$d × %2$d px file fills with little or no border at 150 dpi or better.', 'prodigi-direct' ), $master['w'], $master['h'] ) ); ?></span>
					<?php endif; ?>
					<?php foreach ( $cat->all_size_keys() as $size ) :
						$hint = $suggest['sizes'][ $size ] ?? null; ?>
						<label class="prodigi-check <?php echo $hint && $hint['suggest'] ? 'prodigi-suggested' : ''; ?>"><input type="checkbox" class="prodigi-size" value="<?php echo esc_attr( $size ); ?>" <?php checked( in_array( $size, $sizes, true ) ); ?> /> <?php echo esc_html( str_replace( 'x', ' × ', $size ) ); ?>"
							<?php if ( $hint ) : ?><small class="prodigi-hint prodigi-hint-<?php echo esc_attr( $hint['band'] ); ?>"><?php echo esc_html( 'low' === $hint['band'] ? __( 'not sharp enough', 'prodigi-direct' ) : $hint['fit'] . ' · ' . round( $hint['dpi'] ) . ' dpi' ); ?></small><?php endif; ?>
						</label>
					<?php endforeach; ?>
				</div>
			</div>
			<p>
				<button type="button" class="button button-primary" id="prodigi-build"><?php esc_html_e( 'Set up print sizes', 'prodigi-direct' ); ?></button>
				<span class="description"><?php esc_html_e( 'Creates the sizes below, keeps the ones that already exist, and hides any you untick. Prices come from the price book unless already set. Sizes marked "wide border" print with white space around the image; sizes that are not sharp enough are hidden from the shop.', 'prodigi-direct' ); ?></span>
				<span id="prodigi-build-result"></span>
			</p>

			<h3><?php esc_html_e( 'Sizes in the shop', 'prodigi-direct' ); ?></h3>
			<?php if ( ! $rows ) : ?>
				<p class="description"><?php esc_html_e( 'No print sizes yet. Tick materials and sizes above, then press Set up print sizes.', 'prodigi-direct' ); ?></p>
			<?php else : ?>
			<table class="widefat striped prodigi-table">
				<thead><tr>
					<th><?php esc_html_e( 'Size', 'prodigi-direct' ); ?></th>
					<th><?php esc_html_e( 'Your price', 'prodigi-direct' ); ?></th>
					<th><?php esc_html_e( 'Prodigi cost', 'prodigi-direct' ); ?></th>
					<th><?php esc_html_e( 'You keep', 'prodigi-direct' ); ?></th>
					<th><?php esc_html_e( 'File quality', 'prodigi-direct' ); ?></th>
					<th><?php esc_html_e( 'In the shop', 'prodigi-direct' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $rows as $r ) : ?>
					<tr class="<?php echo $r['margin'] && $r['margin']['loss'] ? 'prodigi-loss' : ''; ?>">
						<td><?php echo esc_html( $r['label'] ); ?><?php if ( ! $r['mapped'] ) : ?> <span class="prodigi-tag"><?php esc_html_e( 'not set up', 'prodigi-direct' ); ?></span><?php endif; ?><br /><small class="prodigi-details"><?php echo esc_html( $r['sku'] ); ?></small></td>
						<td><input type="number" step="0.01" min="0" class="prodigi-price small-text" data-variation="<?php echo esc_attr( $r['variation_id'] ); ?>" value="<?php echo esc_attr( null === $r['price'] ? '' : $r['price'] ); ?>" /></td>
						<td><?php echo $r['cost'] ? wp_kses_post( wc_price( $r['cost']['print'] + $r['cost']['ship'] ) ) . '<br /><small>' . esc_html( sprintf( __( 'print %1$s + shipping %2$s', 'prodigi-direct' ), wp_strip_all_tags( wc_price( $r['cost']['print'] ) ), wp_strip_all_tags( wc_price( $r['cost']['ship'] ) ) ) ) . '</small>' : '—'; ?></td>
						<td class="prodigi-keep"><?php echo $r['margin'] && null !== $r['margin']['keep'] ? wp_kses_post( wc_price( $r['margin']['keep'] ) . ' <small>(' . $r['margin']['pct'] . '%)</small>' ) : '<span class="prodigi-warn">' . esc_html__( 'set a price', 'prodigi-direct' ) . '</span>'; ?></td>
						<td><?php echo null === $r['band'] ? '<span class="description">' . esc_html__( 'no file', 'prodigi-direct' ) . '</span>' : '<span class="prodigi-dot prodigi-' . esc_attr( $r['band'] ) . '"></span> ' . esc_html( Dpi::band_text( $r['band'] ) ) . ' <small>(' . esc_html( (string) round( $r['dpi'] ) ) . ' dpi)</small>'; ?></td>
						<td><?php echo 'publish' === $r['status'] ? esc_html__( 'Yes', 'prodigi-direct' ) : esc_html__( 'Hidden', 'prodigi-direct' ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description"><?php esc_html_e( 'Cost = print + shipping to a US address on the shipping level in settings. Change a price here and it saves straight away.', 'prodigi-direct' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	public function upload_master(): void {
		$pid = (int) ( $_GET['product'] ?? 0 );
		check_admin_referer( 'prodigi_master_' . $pid );
		if ( ! current_user_can( 'edit_product', $pid ) ) {
			wp_die( esc_html__( 'Not allowed.', 'prodigi-direct' ) );
		}
		$back = get_edit_post_link( $pid, 'raw' ) . '#prodigi';
		if ( empty( $_FILES['prodigi_master']['tmp_name'] ) ) {
			wp_safe_redirect( add_query_arg( 'prodigi_msg', rawurlencode( __( 'Choose a JPEG first.', 'prodigi-direct' ) ), $back ) );
			exit;
		}
		$r = Assets::store( $pid, $_FILES['prodigi_master']['tmp_name'], sanitize_file_name( $_FILES['prodigi_master']['name'] ) );
		if ( is_wp_error( $r ) ) {
			wp_safe_redirect( add_query_arg( 'prodigi_msg', rawurlencode( $r->get_error_message() ), $back ) );
			exit;
		}
		// Re-run the build so file-quality bands update.
		$product = wc_get_product( $pid );
		$fams    = (array) $product->get_meta( Product_Builder::P_FAMILIES );
		if ( $fams ) {
			( new Product_Builder( Plugin::instance()->catalogue() ) )->build( $pid, $fams, (array) $product->get_meta( Product_Builder::P_SIZES ), (array) $product->get_meta( Product_Builder::P_CHOICES ) );
		}
		wp_safe_redirect( add_query_arg( 'prodigi_msg', rawurlencode( __( 'Print file saved.', 'prodigi-direct' ) ), $back ) );
		exit;
	}

	public function ajax_build(): void {
		check_ajax_referer( 'prodigi_direct_ajax', 'nonce' );
		$pid = (int) ( $_POST['product'] ?? 0 );
		if ( ! current_user_can( 'edit_product', $pid ) ) {
			wp_send_json_error( [ 'message' => __( 'Not allowed.', 'prodigi-direct' ) ] );
		}
		$fams    = array_map( 'sanitize_key', (array) ( $_POST['families'] ?? [] ) );
		$sizes   = array_map( 'sanitize_text_field', (array) ( $_POST['sizes'] ?? [] ) );
		$choices = [];
		foreach ( (array) ( $_POST['choices'] ?? [] ) as $fam => $vals ) {
			$choices[ sanitize_key( $fam ) ] = array_map( 'sanitize_text_field', (array) $vals );
		}
		try {
			$stats = ( new Product_Builder( Plugin::instance()->catalogue() ) )->build( $pid, $fams, $sizes, $choices );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
		$msg = sprintf( __( 'Done: %1$d new, %2$d kept, %3$d hidden.', 'prodigi-direct' ), $stats['created'], $stats['adopted'], $stats['hidden'] );
		if ( $stats['unpriced'] ) {
			$msg .= ' ' . sprintf( __( '%d need a price (they stay hidden until they have one).', 'prodigi-direct' ), $stats['unpriced'] );
		}
		if ( $stats['low_dpi'] ) {
			$msg .= ' ' . sprintf( __( '%d hidden because the file is not sharp enough at that size.', 'prodigi-direct' ), $stats['low_dpi'] );
		}
		if ( $stats['skipped'] ) {
			$msg .= ' ' . __( 'Skipped:', 'prodigi-direct' ) . ' ' . implode( '; ', array_unique( $stats['skipped'] ) ) . '.';
		}
		wp_send_json_success( [ 'message' => $msg ] );
	}

	public function ajax_set_price(): void {
		check_ajax_referer( 'prodigi_direct_ajax', 'nonce' );
		$vid = (int) ( $_POST['variation'] ?? 0 );
		$v   = wc_get_product( $vid );
		if ( ! $v || ! current_user_can( 'edit_product', $v->get_parent_id() ) ) {
			wp_send_json_error( [ 'message' => __( 'Not allowed.', 'prodigi-direct' ) ] );
		}
		$price = trim( (string) ( $_POST['price'] ?? '' ) );
		$v->set_regular_price( '' === $price ? '' : wc_format_decimal( $price ) );
		if ( '' !== $price && 'private' === $v->get_status() && (float) ( $v->get_meta( Product_Builder::META_DPI ) ?: 300 ) >= Dpi::OK ) {
			$v->set_status( 'publish' );
		}
		if ( '' === $price ) {
			$v->set_status( 'private' );
		}
		$v->save();
		\WC_Product_Variable::sync( $v->get_parent_id() );
		$rows = ( new Product_Builder( Plugin::instance()->catalogue() ) )->rows( $v->get_parent_id() );
		foreach ( $rows as $r ) {
			if ( $r['variation_id'] === $vid ) {
				wp_send_json_success( [
					'keep'   => $r['margin'] && null !== $r['margin']['keep'] ? wc_price( $r['margin']['keep'] ) . ' <small>(' . $r['margin']['pct'] . '%)</small>' : '<span class="prodigi-warn">' . esc_html__( 'set a price', 'prodigi-direct' ) . '</span>',
					'loss'   => (bool) ( $r['margin']['loss'] ?? false ),
					'status' => 'publish' === $r['status'] ? __( 'Yes', 'prodigi-direct' ) : __( 'Hidden', 'prodigi-direct' ),
				] );
			}
		}
		wp_send_json_success( [] );
	}

	/** Read-only details on each variation row for whoever opens the Variations tab. */
	public function variation_details( $loop, $variation_data, $variation ): void {
		$v   = wc_get_product( $variation->ID );
		$sku = $v ? (string) $v->get_meta( Product_Builder::META_SKU ) : '';
		if ( ! $sku ) {
			return;
		}
		echo '<p class="form-row form-row-full prodigi-details"><small>' . esc_html__( 'Prodigi product:', 'prodigi-direct' ) . ' ' . esc_html( $sku ) . ' · ' . esc_html( (string) $v->get_meta( Product_Builder::META_ATTRS ) ) . '</small></p>';
	}
}
