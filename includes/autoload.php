<?php
/**
 * Minimal PSR-4-ish autoloader: ProdigiDirect\Order_Payload → includes/class-order-payload.php,
 * ProdigiDirect\Admin\Settings → admin/class-settings.php, ProdigiDirect\CLI\Commands → cli/class-commands.php.
 */
spl_autoload_register(
	static function ( string $class ): void {
		if ( 0 !== strpos( $class, 'ProdigiDirect\\' ) ) {
			return;
		}
		$parts = explode( '\\', substr( $class, strlen( 'ProdigiDirect\\' ) ) );
		$name  = array_pop( $parts );
		$dir   = $parts ? strtolower( $parts[0] ) : 'includes';
		$file  = PRODIGI_DIRECT_DIR . $dir . '/class-' . str_replace( '_', '-', strtolower( $name ) ) . '.php';
		if ( is_file( $file ) ) {
			require $file;
		}
	}
);
