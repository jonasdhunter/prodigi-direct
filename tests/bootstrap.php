<?php
// Unit tests run without WordPress. Pure classes must not call WP functions.
define( 'PRODIGI_DIRECT_DIR', dirname( __DIR__ ) . '/' );
define( 'PRODIGI_DIRECT_TESTING', true );
require PRODIGI_DIRECT_DIR . 'includes/autoload.php';
