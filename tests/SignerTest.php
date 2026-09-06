<?php
use PHPUnit\Framework\TestCase;
use ProdigiDirect\Signer;

final class SignerTest extends TestCase {
	public function test_sign_and_verify(): void {
		$s   = new Signer( 'secret' );
		$exp = time() + 100;
		$sig = $s->sign( 12, 1042, $exp );
		$this->assertTrue( $s->verify( 12, 1042, $exp, $sig ) );
		$this->assertFalse( $s->verify( 13, 1042, $exp, $sig ) );
		$this->assertFalse( $s->verify( 12, 1042, $exp - 1, $sig ) );
		$this->assertFalse( ( new Signer( 'other' ) )->verify( 12, 1042, $exp, $sig ) );
	}
	public function test_expired(): void {
		$s   = new Signer( 'secret' );
		$exp = time() - 1;
		$this->assertFalse( $s->verify( 12, 1042, $exp, $s->sign( 12, 1042, $exp ) ) );
	}
}
