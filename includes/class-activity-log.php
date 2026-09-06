<?php
namespace ProdigiDirect;

/** The last 100 things the plugin did, in sentences. Stored in one option, newest first. */
final class Activity_Log {
	public const OPTION = 'prodigi_direct_activity';
	public const MAX    = 100;

	public static function add( string $text, string $level = 'info', array $context = [] ): void {
		$log = (array) get_option( self::OPTION, [] );
		array_unshift(
			$log,
			[
				'time'    => time(),
				'level'   => $level, // info · warning · error
				'text'    => $text,
				'context' => $context,
			]
		);
		update_option( self::OPTION, array_slice( $log, 0, self::MAX ), false );
	}

	/** @return array<int, array{time:int, level:string, text:string, context:array}> */
	public static function recent( int $n = 20 ): array {
		return array_slice( (array) get_option( self::OPTION, [] ), 0, $n );
	}
}
