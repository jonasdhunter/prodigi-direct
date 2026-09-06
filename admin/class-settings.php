<?php
namespace ProdigiDirect\Admin;

use ProdigiDirect\Activity_Log;
use ProdigiDirect\Assets;
use ProdigiDirect\Catalogue_Check;
use ProdigiDirect\Costs;
use ProdigiDirect\Order_Sender;
use ProdigiDirect\Order_Status;
use ProdigiDirect\Plugin;
use ProdigiDirect\Price_Book;
use ProdigiDirect\Pricing;

/** WooCommerce → Prints: status, price book, test print, activity, settings. One page. */
final class Settings {
	public const PAGE = 'prodigi-direct';

	public function hooks(): void {
		add_action( 'admin_menu', [ $this, 'menu' ], 60 );
		add_action( 'admin_post_prodigi_direct_settings', [ $this, 'save' ] );
		add_action( 'admin_notices', [ $this, 'flash' ], 5 ); // before the sandbox banner
	}

	public function menu(): void {
		add_submenu_page( 'woocommerce', __( 'Prints (Prodigi)', 'prodigi-direct' ), __( 'Prints', 'prodigi-direct' ), 'manage_woocommerce', self::PAGE, [ $this, 'render' ] );
	}

	public function flash(): void {
		if ( ! empty( $_GET['prodigi_msg'] ) ) {
			wp_admin_notice( esc_html( wp_unslash( $_GET['prodigi_msg'] ) ), [ 'type' => 'info', 'dismissible' => true ] );
		}
	}

	public function render(): void {
		$plugin = Plugin::instance();
		$s      = $plugin->settings();
		$cat    = $plugin->catalogue();
		$book   = Price_Book::all();
		$api    = $plugin->api();
		$ping   = $api->has_key() ? $api->ping() : null;
		$in_use = ( new Catalogue_Check() )->skus_in_use();
		$nonce  = wp_create_nonce( 'prodigi_direct_settings' );
		$action = admin_url( 'admin-post.php' );
		$checked = (int) $s['catalogue_checked'];
		$waiting = wc_get_orders( [ 'limit' => 20, 'return' => 'ids', 'meta_key' => Order_Sender::META_STATE, 'meta_value' => 'waiting' ] );
		$problems = wc_get_orders( [ 'limit' => 20, 'return' => 'ids', 'meta_key' => Order_Sender::META_STATE, 'meta_value' => 'problem' ] );
		?>
		<div class="wrap prodigi-wrap">
			<h1><?php esc_html_e( 'Prints', 'prodigi-direct' ); ?> <small><?php echo esc_html( 'v' . PRODIGI_DIRECT_VERSION . ' · catalogue ' . $cat->version() ); ?></small></h1>

			<div class="prodigi-card" id="status">
				<h2><?php esc_html_e( 'Status', 'prodigi-direct' ); ?></h2>
				<ul class="prodigi-checks">
					<li class="<?php echo true === $ping ? 'ok' : 'bad'; ?>"><?php echo true === $ping ? esc_html( sprintf( __( 'Connected to Prodigi — %s mode.', 'prodigi-direct' ), $plugin->is_sandbox() ? __( 'TEST', 'prodigi-direct' ) : __( 'live', 'prodigi-direct' ) ) ) : esc_html( $ping ? $ping->get_error_message() : __( 'No API key for this mode yet.', 'prodigi-direct' ) ); ?></li>
					<li class="<?php echo 'yes' === $s['setup_card_ok'] ? 'ok' : 'bad'; ?>"><?php echo 'yes' === $s['setup_card_ok'] ? esc_html__( 'Card on file at Prodigi: yes.', 'prodigi-direct' ) : esc_html__( 'Card on file at Prodigi: not confirmed — live orders will stop at Prodigi until a card is added (Prodigi dashboard → Billing). Tick it below once done.', 'prodigi-direct' ); ?></li>
					<li class="<?php echo 'yes' === $s['setup_channel_off'] ? 'ok' : 'bad'; ?>"><?php echo 'yes' === $s['setup_channel_off'] ? esc_html__( 'Prodigi\'s own WooCommerce channel is off.', 'prodigi-direct' ) : esc_html__( 'Prodigi\'s own WooCommerce channel: not confirmed off. If it is still connected, orders will be fulfilled twice. Turn it off in the Prodigi dashboard (Sales channels), then tick it below.', 'prodigi-direct' ); ?></li>
					<li class="<?php echo is_dir( Assets::dir() ) ? 'ok' : 'bad'; ?>"><?php echo esc_html( sprintf( __( 'Private print-file folder: %s', 'prodigi-direct' ), Assets::dir() ) ); ?> <?php echo $this->folder_public() ? '<span class="prodigi-warn">' . esc_html__( '— WARNING: this folder is readable from the web. Add the nginx rule from the README.', 'prodigi-direct' ) . '</span>' : '<span class="ok">' . esc_html__( '(not web-readable)', 'prodigi-direct' ) . '</span>'; ?></li>
					<li class="<?php echo $checked ? 'ok' : 'warn'; ?>"><?php echo $checked ? esc_html( sprintf( __( 'Last catalogue check: %1$s — %2$d print types in use.', 'prodigi-direct' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $checked ), count( $in_use ) ) ) : esc_html__( 'The catalogue has not been checked against Prodigi yet.', 'prodigi-direct' ); ?>
						<form method="post" action="<?php echo esc_url( $action ); ?>" class="inline"><input type="hidden" name="action" value="prodigi_direct_settings" /><input type="hidden" name="do" value="check_catalogue" /><input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>" /><button class="button"><?php esc_html_e( 'Check catalogue now', 'prodigi-direct' ); ?></button></form></li>
					<li class="<?php echo $waiting ? 'warn' : 'ok'; ?>"><?php echo $waiting ? wp_kses_post( sprintf( _n( '%d order is waiting for your approval.', '%d orders are waiting for your approval.', count( $waiting ), 'prodigi-direct' ), count( $waiting ) ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=wc-orders&status=wc-processing' ) ) . '">' . esc_html__( 'Open orders', 'prodigi-direct' ) . '</a>' ) : esc_html__( 'No orders waiting for you.', 'prodigi-direct' ); ?></li>
					<?php if ( $problems ) : ?><li class="bad"><?php echo wp_kses_post( sprintf( _n( '%d order has a problem.', '%d orders have a problem.', count( $problems ), 'prodigi-direct' ), count( $problems ) ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=wc-orders' ) ) . '">' . esc_html__( 'Open orders', 'prodigi-direct' ) . '</a>' ); ?></li><?php endif; ?>
				</ul>
			</div>

			<div class="prodigi-card" id="price-book">
				<h2><?php esc_html_e( 'Price book', 'prodigi-direct' ); ?></h2>
				<p class="description"><?php esc_html_e( 'One price per size and material. New products pick these up when you set up their print sizes; prices already set on a product are not changed. Under each box: what Prodigi charges (print + US shipping) and what you keep.', 'prodigi-direct' ); ?></p>
				<form method="post" action="<?php echo esc_url( $action ); ?>">
					<input type="hidden" name="action" value="prodigi_direct_settings" /><input type="hidden" name="do" value="price_book" /><input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>" />
					<div class="prodigi-scroll"><table class="widefat prodigi-book">
						<thead><tr><th><?php esc_html_e( 'Size', 'prodigi-direct' ); ?></th><?php foreach ( $cat->families() as $key => $fam ) : ?><th><?php echo esc_html( $fam['short'] ); ?></th><?php endforeach; ?></tr></thead>
						<tbody>
						<?php foreach ( $cat->all_size_keys() as $size ) : ?>
							<tr><th><?php echo esc_html( str_replace( 'x', ' × ', $size ) ); ?>"</th>
							<?php foreach ( $cat->families() as $key => $fam ) : ?>
								<td>
								<?php if ( $cat->size( $key, $size ) && $cat->orderable( $key, $size ) ) :
									$cost  = Costs::for_family_size( $cat, $key, $size );
									$price = $book[ $key ][ $size ] ?? null;
									$m     = $cost ? Pricing::margin( null === $price ? null : (float) $price, $cost['print'], $cost['ship'] ) : null; ?>
									<input type="number" step="1" min="0" class="small-text" name="book[<?php echo esc_attr( $key ); ?>][<?php echo esc_attr( $size ); ?>]" value="<?php echo esc_attr( null === $price ? '' : $price ); ?>" />
									<small class="<?php echo $m && $m['loss'] ? 'prodigi-warn' : ''; ?>"><?php echo $cost ? esc_html( sprintf( __( 'cost %s', 'prodigi-direct' ), wp_strip_all_tags( wc_price( $m['cost'] ) ) ) ) . ( $m && null !== $m['keep'] ? '<br />' . esc_html( sprintf( __( 'keep %1$s (%2$s%%)', 'prodigi-direct' ), wp_strip_all_tags( wc_price( $m['keep'] ) ), $m['pct'] ) ) : '' ) : '—'; ?></small>
								<?php else : ?><span class="description">—</span><?php endif; ?>
								</td>
							<?php endforeach; ?></tr>
						<?php endforeach; ?>
						</tbody>
					</table></div>
					<p><button class="button button-primary"><?php esc_html_e( 'Save price book', 'prodigi-direct' ); ?></button>
					<button class="button" name="do" value="learn_prices"><?php esc_html_e( 'Fill from the shop’s current prices', 'prodigi-direct' ); ?></button></p>
				</form>
			</div>

			<div class="prodigi-card" id="test">
				<h2><?php esc_html_e( 'Test print', 'prodigi-direct' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Sends one paper print of a product to a test address through Prodigi’s sandbox — nothing is printed or charged. Use it after installing, and after any change to prints.', 'prodigi-direct' ); ?></p>
				<form method="post" action="<?php echo esc_url( $action ); ?>">
					<input type="hidden" name="action" value="prodigi_direct_settings" /><input type="hidden" name="do" value="test_order" /><input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>" />
					<select name="product"><?php foreach ( wc_get_products( [ 'type' => 'variable', 'limit' => 50, 'status' => 'publish' ] ) as $p ) : ?><option value="<?php echo esc_attr( $p->get_id() ); ?>"><?php echo esc_html( $p->get_name() ); ?></option><?php endforeach; ?></select>
					<button class="button" <?php disabled( ! $s['api_key_sandbox'] ); ?>><?php esc_html_e( 'Send a test order', 'prodigi-direct' ); ?></button>
					<?php if ( ! $s['api_key_sandbox'] ) : ?><span class="description"><?php esc_html_e( 'Needs the sandbox API key in settings.', 'prodigi-direct' ); ?></span><?php endif; ?>
				</form>
			</div>

			<div class="prodigi-card" id="material-photos">
				<h2><?php esc_html_e( 'Material photos', 'prodigi-direct' ); ?></h2>
				<p class="description"><?php esc_html_e( 'A real photograph of each material — a print on paper, a stretched canvas, a framed print. Shown on the product page when a customer hovers or taps a material. Use your own photos of your own prints; leave blank to show none.', 'prodigi-direct' ); ?></p>
				<form method="post" action="<?php echo esc_url( $action ); ?>" id="prodigi-material-photos">
					<input type="hidden" name="action" value="prodigi_direct_settings" /><input type="hidden" name="do" value="family_images" /><input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>" />
					<div class="prodigi-photos">
					<?php $imgs = (array) $s['family_images']; foreach ( $cat->families() as $key => $fam ) : $id = (int) ( $imgs[ $key ] ?? 0 ); ?>
						<div class="prodigi-photo" data-family="<?php echo esc_attr( $key ); ?>">
							<div class="prodigi-photo-img"><?php echo $id ? wp_get_attachment_image( $id, 'medium' ) : '<span class="description">' . esc_html__( 'no photo', 'prodigi-direct' ) . '</span>'; ?></div>
							<strong><?php echo esc_html( $fam['label'] ); ?></strong>
							<input type="hidden" name="family_images[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $id ); ?>" />
							<button type="button" class="button prodigi-photo-pick"><?php esc_html_e( 'Choose photo', 'prodigi-direct' ); ?></button>
							<button type="button" class="button-link prodigi-photo-clear"><?php esc_html_e( 'remove', 'prodigi-direct' ); ?></button>
						</div>
					<?php endforeach; ?>
					</div>
					<p><button class="button button-primary"><?php esc_html_e( 'Save material photos', 'prodigi-direct' ); ?></button></p>
				</form>
			</div>

			<div class="prodigi-card" id="reference">
				<h2><?php esc_html_e( 'Prodigi reference', 'prodigi-direct' ); ?></h2>
				<ul class="prodigi-reflinks">
					<?php $l = $cat->links(); ?>
					<li><a href="<?php echo esc_url( $l['product_range'] ?? '' ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Product range', 'prodigi-direct' ); ?></a> — <?php esc_html_e( 'everything Prodigi prints, with photos', 'prodigi-direct' ); ?></li>
					<li><a href="<?php echo esc_url( $l['portfolio_pdf'] ?? '' ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Portfolio PDF', 'prodigi-direct' ); ?></a> — <?php esc_html_e( 'the printed catalogue', 'prodigi-direct' ); ?></li>
					<?php foreach ( $cat->families() as $key => $fam ) : ?>
						<li><a href="<?php echo esc_url( $cat->url( $key ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $fam['label'] ); ?></a> <small><?php echo esc_html( $fam['sku_pattern'] ); ?></small></li>
					<?php endforeach; ?>
					<li><a href="<?php echo esc_url( $l['dashboard'] ?? '' ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Prodigi dashboard', 'prodigi-direct' ); ?></a> — <?php esc_html_e( 'billing, invoices, order history', 'prodigi-direct' ); ?> · <a href="<?php echo esc_url( $l['sandbox_dashboard'] ?? '' ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'sandbox dashboard', 'prodigi-direct' ); ?></a></li>
					<li><a href="<?php echo esc_url( $l['api_reference'] ?? '' ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'API reference', 'prodigi-direct' ); ?></a></li>
				</ul>
			</div>

			<div class="prodigi-card" id="activity">
				<h2><?php esc_html_e( 'Recent activity', 'prodigi-direct' ); ?></h2>
				<ul class="prodigi-activity">
					<?php foreach ( Activity_Log::recent( 25 ) as $e ) : ?>
						<li class="<?php echo esc_attr( $e['level'] ); ?>"><small><?php echo esc_html( wp_date( 'M j, H:i', $e['time'] ) ); ?></small> <?php echo esc_html( $e['text'] ); ?><?php if ( ! empty( $e['context']['order_id'] ) ) : ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-orders&action=edit&id=' . (int) $e['context']['order_id'] ) ); ?>"><?php esc_html_e( 'open', 'prodigi-direct' ); ?></a><?php endif; ?></li>
					<?php endforeach; ?>
					<?php if ( ! Activity_Log::recent( 1 ) ) : ?><li class="description"><?php esc_html_e( 'Nothing yet.', 'prodigi-direct' ); ?></li><?php endif; ?>
				</ul>
			</div>

			<details class="prodigi-card" id="settings" <?php echo $api->has_key() ? '' : 'open'; ?>>
				<summary><h2><?php esc_html_e( 'Settings', 'prodigi-direct' ); ?></h2></summary>
				<form method="post" action="<?php echo esc_url( $action ); ?>">
					<input type="hidden" name="action" value="prodigi_direct_settings" /><input type="hidden" name="do" value="settings" /><input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $nonce ); ?>" />
					<table class="form-table">
						<tr><th><?php esc_html_e( 'Mode', 'prodigi-direct' ); ?></th><td>
							<label><input type="radio" name="mode" value="sandbox" <?php checked( 'sandbox', $s['mode'] ); ?> /> <?php esc_html_e( 'Test (sandbox) — nothing printed or charged', 'prodigi-direct' ); ?></label><br />
							<label><input type="radio" name="mode" value="live" <?php checked( 'live', $s['mode'] ); ?> /> <?php esc_html_e( 'Live — real orders go to Prodigi', 'prodigi-direct' ); ?></label></td></tr>
						<tr><th><label for="api_key_live"><?php esc_html_e( 'Live API key', 'prodigi-direct' ); ?></label></th><td><input type="password" id="api_key_live" name="api_key_live" class="regular-text" value="<?php echo esc_attr( $s['api_key_live'] ); ?>" autocomplete="off" /> <span class="description"><?php esc_html_e( 'Prodigi dashboard → Settings → API.', 'prodigi-direct' ); ?></span></td></tr>
						<tr><th><label for="api_key_sandbox"><?php esc_html_e( 'Sandbox API key', 'prodigi-direct' ); ?></label></th><td><input type="password" id="api_key_sandbox" name="api_key_sandbox" class="regular-text" value="<?php echo esc_attr( $s['api_key_sandbox'] ); ?>" autocomplete="off" /> <span class="description"><?php esc_html_e( 'From sandbox-beta-dashboard.pwinty.com — a separate account and key.', 'prodigi-direct' ); ?></span></td></tr>
						<tr><th><?php esc_html_e( 'Who approves', 'prodigi-direct' ); ?></th><td>
							<label><input type="radio" name="approval" value="manual" <?php checked( 'manual', $s['approval'] ); ?> /> <?php esc_html_e( 'Me — every paid order waits for my "Approve and send"', 'prodigi-direct' ); ?></label><br />
							<label><input type="radio" name="approval" value="auto" <?php checked( 'auto', $s['approval'] ); ?> /> <?php esc_html_e( 'Automatic — send to Prodigi as soon as an order is paid (live mode only)', 'prodigi-direct' ); ?></label></td></tr>
						<tr><th><label for="shipping_method"><?php esc_html_e( 'Shipping level', 'prodigi-direct' ); ?></label></th><td><select id="shipping_method" name="shipping_method"><?php foreach ( [ 'Budget', 'Standard', 'StandardPlus', 'Express', 'Overnight' ] as $m ) : ?><option <?php selected( $m, $s['shipping_method'] ); ?>><?php echo esc_html( $m ); ?></option><?php endforeach; ?></select> <span class="description"><?php esc_html_e( 'The basis of every cost and margin shown.', 'prodigi-direct' ); ?></span></td></tr>
						<tr><th><label for="notify_email"><?php esc_html_e( 'Email problems to', 'prodigi-direct' ); ?></label></th><td><input type="email" id="notify_email" name="notify_email" class="regular-text" value="<?php echo esc_attr( $s['notify_email'] ); ?>" /></td></tr>
						<tr><th><?php esc_html_e( 'When everything ships', 'prodigi-direct' ); ?></th><td><label><input type="checkbox" name="complete_on_ship" value="yes" <?php checked( 'yes', $s['complete_on_ship'] ); ?> /> <?php esc_html_e( 'Mark the WooCommerce order Completed (sends the customer the completed-order email)', 'prodigi-direct' ); ?></label></td></tr>
						<tr><th><?php esc_html_e( 'Product page', 'prodigi-direct' ); ?></th><td>
							<label><input type="checkbox" name="storefront" value="yes" <?php checked( 'yes', $s['storefront'] ); ?> /> <?php esc_html_e( 'Show the material / size / frame picker, the artist’s recommendation, and the story + details section on print products', 'prodigi-direct' ); ?></label><br />
							<label for="artist_name"><?php esc_html_e( 'Artist’s name', 'prodigi-direct' ); ?></label> <input type="text" id="artist_name" name="artist_name" class="regular-text" value="<?php echo esc_attr( $s['artist_name'] ); ?>" placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" /> <span class="description"><?php esc_html_e( 'Used as “<name>’s recommendation” and to sign the story.', 'prodigi-direct' ); ?></span></td></tr>
						<tr><th><?php esc_html_e( 'Setup checks', 'prodigi-direct' ); ?></th><td>
							<label><input type="checkbox" name="setup_card_ok" value="yes" <?php checked( 'yes', $s['setup_card_ok'] ); ?> /> <?php esc_html_e( 'A card is registered on the Prodigi account', 'prodigi-direct' ); ?></label><br />
							<label><input type="checkbox" name="setup_channel_off" value="yes" <?php checked( 'yes', $s['setup_channel_off'] ); ?> /> <?php esc_html_e( 'Prodigi’s own WooCommerce sales channel is disconnected', 'prodigi-direct' ); ?></label></td></tr>
						<tr><th><?php esc_html_e( 'Callback address', 'prodigi-direct' ); ?></th><td><code><?php echo esc_html( Order_Status::callback_url() ); ?></code><p class="description"><?php esc_html_e( 'Sent with every order automatically; nothing to paste anywhere.', 'prodigi-direct' ); ?></p></td></tr>
					</table>
					<p><button class="button button-primary"><?php esc_html_e( 'Save settings', 'prodigi-direct' ); ?></button></p>
				</form>
			</details>
		</div>
		<?php
	}

	private function folder_public(): bool {
		$up  = wp_upload_dir( null, false );
		$url = trailingslashit( $up['baseurl'] ) . 'prodigi-private/index.html';
		$r   = wp_remote_head( $url, [ 'timeout' => 5, 'sslverify' => false ] );
		return ! is_wp_error( $r ) && 200 === (int) wp_remote_retrieve_response_code( $r );
	}

	public function save(): void {
		check_admin_referer( 'prodigi_direct_settings' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'prodigi-direct' ) );
		}
		$plugin = Plugin::instance();
		$do     = sanitize_key( $_POST['do'] ?? '' );
		$msg    = __( 'Saved.', 'prodigi-direct' );
		$anchor = '';
		switch ( $do ) {
			case 'settings':
				$plugin->update_settings( [
					'mode'              => 'live' === ( $_POST['mode'] ?? '' ) ? 'live' : 'sandbox',
					'api_key_live'      => sanitize_text_field( wp_unslash( $_POST['api_key_live'] ?? '' ) ),
					'api_key_sandbox'   => sanitize_text_field( wp_unslash( $_POST['api_key_sandbox'] ?? '' ) ),
					'approval'          => 'auto' === ( $_POST['approval'] ?? '' ) ? 'auto' : 'manual',
					'shipping_method'   => in_array( $_POST['shipping_method'] ?? '', [ 'Budget', 'Standard', 'StandardPlus', 'Express', 'Overnight' ], true ) ? $_POST['shipping_method'] : 'Budget',
					'notify_email'      => sanitize_email( wp_unslash( $_POST['notify_email'] ?? '' ) ),
					'complete_on_ship'  => isset( $_POST['complete_on_ship'] ) ? 'yes' : 'no',
					'setup_card_ok'     => isset( $_POST['setup_card_ok'] ) ? 'yes' : 'no',
					'setup_channel_off' => isset( $_POST['setup_channel_off'] ) ? 'yes' : 'no',
					'storefront'        => isset( $_POST['storefront'] ) ? 'yes' : 'no',
					'artist_name'       => sanitize_text_field( wp_unslash( $_POST['artist_name'] ?? '' ) ),
				] );
				Activity_Log::add( sprintf( 'Settings saved (%s mode).', $plugin->mode() ) );
				$anchor = '#settings';
				break;
			case 'price_book':
				Price_Book::replace( (array) ( $_POST['book'] ?? [] ) );
				Activity_Log::add( 'Price book saved.' );
				$anchor = '#price-book';
				break;
			case 'family_images':
				$clean = [];
				foreach ( (array) ( $_POST['family_images'] ?? [] ) as $fam => $id ) {
					if ( (int) $id > 0 ) {
						$clean[ sanitize_key( $fam ) ] = (int) $id;
					}
				}
				$plugin->update_settings( [ 'family_images' => $clean ] );
				$anchor = '#material-photos';
				break;
			case 'learn_prices':
				$n   = Price_Book::learn_from_shop( $plugin->catalogue() );
				$msg = sprintf( __( 'Filled %d prices from the shop.', 'prodigi-direct' ), $n );
				$anchor = '#price-book';
				break;
			case 'check_catalogue':
				$r   = ( new Catalogue_Check() )->run();
				$msg = sprintf( __( 'Checked %1$d print types: %2$d retired, %3$d cost changes, %4$d errors.', 'prodigi-direct' ), $r['checked'], count( $r['retired'] ), count( $r['changed'] ), count( $r['errors'] ) );
				if ( $r['errors'] ) {
					$msg .= ' ' . implode( ' · ', array_slice( $r['errors'], 0, 3 ) );
				}
				break;
			case 'test_order':
				$msg    = ( new Test_Order() )->send( (int) ( $_POST['product'] ?? 0 ) );
				$anchor = '#activity';
				break;
		}
		wp_safe_redirect( add_query_arg( 'prodigi_msg', rawurlencode( $msg ), admin_url( 'admin.php?page=' . self::PAGE ) ) . $anchor );
		exit;
	}
}
