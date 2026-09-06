<?php
namespace ProdigiDirect;

/** Wires everything together and owns the settings. */
final class Plugin {
	public const OPTION = 'prodigi_direct_settings';
	public const GROUP  = 'prodigi-direct';

	private static ?Plugin $instance = null;
	private ?Catalogue $catalogue    = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	public static function activate(): void {
		$s = get_option( self::OPTION, [] );
		if ( empty( $s['callback_token'] ) ) {
			$s['callback_token'] = bin2hex( random_bytes( 24 ) );
		}
		$s += self::defaults();
		update_option( self::OPTION, $s, false );
		Assets::ensure_dir();
	}

	public static function defaults(): array {
		return [
			'mode'                => 'sandbox',
			'api_key_live'        => '',
			'api_key_sandbox'     => '',
			'shipping_method'     => 'Budget',
			'approval'            => 'manual',   // manual = the store owner approves each order · auto = send on payment
			'notify_email'        => get_option( 'admin_email' ),
			'complete_on_ship'    => 'yes',
			'callback_token'      => '',
			'setup_card_ok'       => 'no',
			'setup_channel_off'   => 'no',
			'catalogue_checked'   => '',
		];
	}

	public function settings(): array {
		return (array) get_option( self::OPTION, [] ) + self::defaults();
	}

	public function setting( string $key ) {
		return $this->settings()[ $key ] ?? null;
	}

	public function update_settings( array $changes ): void {
		update_option( self::OPTION, $changes + $this->settings(), false );
	}

	public function mode(): string {
		return 'live' === $this->setting( 'mode' ) ? 'live' : 'sandbox';
	}

	public function is_sandbox(): bool {
		return 'live' !== $this->mode();
	}

	public function api_key(): string {
		return (string) $this->setting( $this->is_sandbox() ? 'api_key_sandbox' : 'api_key_live' );
	}

	public function api(): Api_Client {
		return new Api_Client( $this->api_key(), $this->is_sandbox() );
	}

	public function catalogue(): Catalogue {
		return $this->catalogue ??= Catalogue::load();
	}

	public function boot(): void {
		load_plugin_textdomain( 'prodigi-direct', false, dirname( plugin_basename( PRODIGI_DIRECT_FILE ) ) . '/languages' );

		( new Assets() )->hooks();
		( new Order_Sender() )->hooks();
		( new Order_Status() )->hooks();
		( new Catalogue_Check() )->hooks();

		if ( is_admin() ) {
			( new Admin\Settings() )->hooks();
			( new Admin\Product_Panel() )->hooks();
			( new Admin\Orders_UI() )->hooks();
			( new Admin\Notices() )->hooks();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'prodigi-direct', CLI\Commands::class );
		}
	}
}
