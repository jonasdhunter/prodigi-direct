<?php
namespace ProdigiDirect\Admin;

use Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController;
use ProdigiDirect\Order_Sender;
use ProdigiDirect\Order_Status;
use ProdigiDirect\Plugin;
use ProdigiDirect\Status;
use WC_Order;
use WP_Post;

/** The "Print this order" box and the Print column. Works with HPOS and the legacy posts table. */
final class Orders_UI {
	public function hooks(): void {
		add_action( 'add_meta_boxes', [ $this, 'meta_box' ] );
		add_filter( 'manage_woocommerce_page_wc-orders_columns', [ $this, 'column' ], 20 );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', [ $this, 'column_value' ], 10, 2 );
		add_filter( 'manage_edit-shop_order_columns', [ $this, 'column' ], 20 );
		add_action( 'manage_shop_order_posts_custom_column', [ $this, 'column_value_legacy' ], 10, 2 );
		add_action( 'admin_post_prodigi_direct_order', [ $this, 'handle' ] );
	}

	private function hpos(): bool {
		return class_exists( CustomOrdersTableController::class ) && wc_get_container()->get( CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled();
	}

	public function meta_box(): void {
		$screen = $this->hpos() ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		add_meta_box( 'prodigi_direct_box', __( 'Print this order', 'prodigi-direct' ), [ $this, 'render' ], $screen, 'side', 'high' );
	}

	public function column( array $columns ): array {
		$out = [];
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'order_status' === $key ) {
				$out['prodigi_direct'] = __( 'Print', 'prodigi-direct' );
			}
		}
		return isset( $out['prodigi_direct'] ) ? $out : $out + [ 'prodigi_direct' => __( 'Print', 'prodigi-direct' ) ];
	}

	public function column_value( $column, $order ): void {
		if ( 'prodigi_direct' === $column && $order instanceof WC_Order ) {
			$this->pill( $order );
		}
	}

	public function column_value_legacy( $column, $post_id ): void {
		if ( 'prodigi_direct' === $column ) {
			$this->pill( wc_get_order( $post_id ) );
		}
	}

	private function pill( ?WC_Order $order ): void {
		$state = $order ? (string) $order->get_meta( Order_Sender::META_STATE ) : '';
		if ( ! $state ) {
			echo '<span class="prodigi-pill prodigi-none">—</span>';
			return;
		}
		echo '<span class="prodigi-pill prodigi-' . esc_attr( $state ) . '">' . esc_html( Status::label( $state ) ) . '</span>';
	}

	public function render( $post_or_order ): void {
		$order  = $post_or_order instanceof WP_Post ? wc_get_order( $post_or_order->ID ) : $post_or_order;
		$plugin = Plugin::instance();
		$sender = new Order_Sender();
		$lines  = $sender->print_lines( $order );
		$state  = (string) $order->get_meta( Order_Sender::META_STATE );
		if ( ! $lines && ! $state ) {
			echo '<p class="description">' . esc_html__( 'Nothing on this order is a print.', 'prodigi-direct' ) . '</p>';
			return;
		}
		$quote = (array) $order->get_meta( Order_Sender::META_QUOTE );
		$pid   = (string) $order->get_meta( Order_Sender::META_ORDER_ID );
		$err   = (string) $order->get_meta( Order_Sender::META_ERROR );
		$raw   = (array) $order->get_meta( Order_Sender::META_RAW );
		$act   = fn( string $a ) => wp_nonce_url( admin_url( 'admin-post.php?action=prodigi_direct_order&do=' . $a . '&order=' . $order->get_id() ), 'prodigi_order_' . $order->get_id() );
		?>
		<div class="prodigi-box">
			<ul class="prodigi-lines">
				<?php foreach ( $lines as $l ) : ?>
					<li><?php echo esc_html( $l['qty'] > 1 ? $l['qty'] . ' × ' : '' ); ?><?php echo esc_html( $l['name'] ); ?><?php if ( ! $l['has_master'] ) : ?> <span class="prodigi-warn"><?php esc_html_e( '(no print file)', 'prodigi-direct' ); ?></span><?php endif; ?></li>
				<?php endforeach; ?>
			</ul>
			<?php if ( $quote && isset( $quote['items'] ) ) :
				$paid = array_sum( array_column( $lines, 'line_total' ) ) + (float) $order->get_shipping_total(); ?>
				<p><?php echo wp_kses_post( sprintf( __( 'Prodigi will charge about <strong>%1$s</strong> (print %2$s + shipping %3$s). The prints on this order paid %4$s. You keep about <strong>%5$s</strong>.', 'prodigi-direct' ), wc_price( $quote['items'] + $quote['shipping'] ), wc_price( $quote['items'] ), wc_price( $quote['shipping'] ), wc_price( $paid ), wc_price( $paid - $quote['items'] - $quote['shipping'] ) ) ); ?></p>
			<?php endif; ?>
			<p class="prodigi-state"><?php echo esc_html__( 'Status:', 'prodigi-direct' ) . ' <strong>' . esc_html( Status::label( $state ) ) . '</strong>'; ?>
				<?php if ( 'live' !== $order->get_meta( Order_Sender::META_MODE ) && $pid ) : ?> <span class="prodigi-tag"><?php esc_html_e( 'test mode', 'prodigi-direct' ); ?></span><?php endif; ?></p>
			<?php if ( $err ) : ?><p class="prodigi-problem"><?php echo esc_html( $err ); ?></p><?php endif; ?>
			<?php if ( $pid && ! empty( $raw['shipments'] ) ) : ?>
				<ul class="prodigi-shipments"><?php foreach ( Status::shipments( $raw ) as $s ) : ?>
					<li><?php echo esc_html( Status::carrier_label( $s['carrier'] ) ); ?> — <?php echo $s['tracking_url'] ? '<a href="' . esc_url( $s['tracking_url'] ) . '" target="_blank" rel="noopener">' . esc_html( $s['tracking_number'] ?: __( 'track', 'prodigi-direct' ) ) . '</a>' : esc_html( $s['tracking_number'] ?: $s['status'] ); ?></li>
				<?php endforeach; ?></ul>
			<?php endif; ?>

			<p class="prodigi-actions">
			<?php if ( in_array( $state, [ 'waiting', 'problem' ], true ) && ! $pid ) : ?>
				<a class="button button-primary" href="<?php echo esc_url( $act( 'approve' ) ); ?>"><?php echo 'problem' === $state ? esc_html__( 'Try again', 'prodigi-direct' ) : ( $plugin->is_sandbox() ? esc_html__( 'Send to Prodigi (test)', 'prodigi-direct' ) : esc_html__( 'Approve and send to Prodigi', 'prodigi-direct' ) ); ?></a>
				<a class="button" href="<?php echo esc_url( $act( 'manual' ) ); ?>"><?php esc_html_e( "I'll fulfil this myself", 'prodigi-direct' ); ?></a>
			<?php elseif ( 'manual' === $state ) : ?>
				<a class="button" href="<?php echo esc_url( $act( 'reset' ) ); ?>"><?php esc_html_e( 'Send to Prodigi after all', 'prodigi-direct' ); ?></a>
			<?php elseif ( $pid ) : ?>
				<a class="button" href="<?php echo esc_url( $act( 'sync' ) ); ?>"><?php esc_html_e( 'Check status now', 'prodigi-direct' ); ?></a>
				<?php if ( in_array( $state, [ 'sent', 'problem' ], true ) ) : ?>
					<a class="button" href="<?php echo esc_url( $act( 'cancel' ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Cancel this order at Prodigi?', 'prodigi-direct' ) ); ?>')"><?php esc_html_e( 'Cancel at Prodigi', 'prodigi-direct' ); ?></a>
				<?php endif; ?>
			<?php elseif ( ! $state && $lines ) : ?>
				<a class="button button-primary" href="<?php echo esc_url( $act( 'prepare' ) ); ?>"><?php esc_html_e( 'Prepare print order', 'prodigi-direct' ); ?></a>
			<?php endif; ?>
			</p>
			<?php if ( $pid ) : ?><p class="prodigi-details"><small><?php echo esc_html__( 'Prodigi order', 'prodigi-direct' ) . ' ' . esc_html( $pid ); ?></small></p><?php endif; ?>
		</div>
		<?php
	}

	public function handle(): void {
		$order_id = (int) ( $_GET['order'] ?? 0 );
		check_admin_referer( 'prodigi_order_' . $order_id );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'prodigi-direct' ) );
		}
		$order  = wc_get_order( $order_id );
		$sender = new Order_Sender();
		$do     = sanitize_key( $_GET['do'] ?? '' );
		$msg    = '';
		switch ( $do ) {
			case 'approve':
				$sender->reset_to_waiting( $order_id );
				$sender->submit( $order_id ); // run now, so she sees the result on reload
				$msg = __( 'Sent.', 'prodigi-direct' );
				break;
			case 'manual':
				$sender->mark_manual( $order_id );
				break;
			case 'reset':
				$sender->reset_to_waiting( $order_id );
				break;
			case 'prepare':
				$sender->prepare( $order_id );
				break;
			case 'sync':
				( new Order_Status() )->sync( $order_id );
				break;
			case 'cancel':
				$r   = $sender->cancel_at_prodigi( $order );
				$msg = true === $r ? __( 'Cancelled at Prodigi.', 'prodigi-direct' ) : $r;
				break;
		}
		wp_safe_redirect( add_query_arg( 'prodigi_msg', rawurlencode( $msg ), $order->get_edit_order_url() ) );
		exit;
	}
}
