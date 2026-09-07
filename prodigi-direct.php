<?php
/**
 * Plugin Name:       Prodigi Direct for WooCommerce
 * Plugin URI:        https://github.com/jonasdhunter/prodigi-direct
 * Description:       Sell fine-art prints fulfilled by Prodigi without mapping variants by hand. Sizes, materials and frames are generated from a built-in catalogue; paid orders go straight to Prodigi's API; tracking comes back onto the order.
 * Version:           0.6.7
 * Requires at least: 6.6
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * WC requires at least: 9.0
 * WC tested up to:   11.1
 * Author:            Jonas Hunter
 * License:           MIT
 * Text Domain:       prodigi-direct
 */

defined( 'ABSPATH' ) || exit;

define( 'PRODIGI_DIRECT_VERSION', '0.6.7' );
define( 'PRODIGI_DIRECT_FILE', __FILE__ );
define( 'PRODIGI_DIRECT_DIR', plugin_dir_path( __FILE__ ) );
define( 'PRODIGI_DIRECT_URL', plugin_dir_url( __FILE__ ) );

require PRODIGI_DIRECT_DIR . 'includes/autoload.php';

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-error"><p>Prodigi Direct needs WooCommerce to be active.</p></div>';
				}
			);
			return;
		}
		\ProdigiDirect\Plugin::instance()->boot();
	}
);

register_activation_hook( __FILE__, [ \ProdigiDirect\Plugin::class, 'activate' ] );
