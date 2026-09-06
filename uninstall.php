<?php
// Removes only the plugin's own options. Product, variation and order meta stay: they are the shop's history.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
foreach ( [ 'prodigi_direct_settings', 'prodigi_direct_price_book', 'prodigi_direct_costs', 'prodigi_direct_activity' ] as $o ) {
	delete_option( $o );
}
