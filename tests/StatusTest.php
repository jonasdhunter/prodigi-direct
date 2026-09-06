<?php
use PHPUnit\Framework\TestCase;
use ProdigiDirect\Status;

final class StatusTest extends TestCase {
	private function order( array $over = [] ): array {
		return array_replace_recursive( [
			'id' => 'ord_1', 'status' => [ 'stage' => 'InProgress', 'issues' => [], 'details' => [ 'downloadAssets' => 'NotStarted', 'printReadyAssetsPrepared' => 'NotStarted', 'allocateProductionLocation' => 'NotStarted', 'inProduction' => 'NotStarted', 'shipping' => 'NotStarted' ] ],
			'shipments' => [],
		], $over );
	}
	public function test_stage_mapping(): void {
		$this->assertSame( 'sent', Status::state( $this->order() ) );
		$this->assertSame( 'printing', Status::state( $this->order( [ 'status' => [ 'details' => [ 'inProduction' => 'InProgress' ] ] ] ) ) );
		$this->assertSame( 'printing', Status::state( $this->order( [ 'status' => [ 'details' => [ 'inProduction' => 'Complete', 'shipping' => 'InProgress' ] ] ] ) ) );
		$this->assertSame( 'shipped', Status::state( $this->order( [ 'status' => [ 'stage' => 'Complete', 'details' => [ 'shipping' => 'Complete' ] ] ] ) ) );
		$this->assertSame( 'cancelled', Status::state( $this->order( [ 'status' => [ 'stage' => 'Cancelled' ] ] ) ) );
	}
	public function test_case_insensitive_values(): void {
		$this->assertSame( 'shipped', Status::state( $this->order( [ 'status' => [ 'stage' => 'complete' ] ] ) ) );
	}
	public function test_issues_take_precedence_and_translate(): void {
		$o = $this->order( [ 'status' => [ 'issues' => [ [ 'objectId' => 'ori_1', 'errorCode' => 'order.items.assets.FailedToDownloaded', 'description' => 'x' ] ] ] ] );
		$this->assertSame( 'problem', Status::state( $o ) );
		$this->assertStringContainsString( "couldn't fetch the print file", Status::problem_text( $o ) );
		$auth = $this->order( [ 'status' => [ 'issues' => [ [ 'objectId' => 'ord_1', 'errorCode' => 'RequiresPaymentAuthorisation', 'description' => 'x', 'authorisationDetails' => [ 'authorisationUrl' => 'https://pay' ] ] ] ] ] );
		$this->assertStringContainsString( 'card', Status::problem_text( $auth ) );
		$this->assertSame( 'https://pay', Status::action_url( $auth ) );
		$warn = $this->order( [ 'status' => [ 'issues' => [ [ 'objectId' => 'ori_1', 'errorCode' => 'order.items.assets.NotDownloaded', 'description' => 'Warning: attempt 1 of 10' ] ] ] ] );
		$this->assertSame( 'sent', Status::state( $warn ), 'a download retry warning is not a problem yet' );
	}
	public function test_shipments_extract_tracking(): void {
		$o = $this->order( [ 'shipments' => [ [ 'id' => 'shp_1', 'status' => 'Shipped', 'carrier' => [ 'name' => 'fedex', 'service' => 'Ground' ], 'tracking' => [ 'number' => '123', 'url' => 'https://t/123' ], 'items' => [ [ 'itemId' => 'ori_1' ] ] ] ] ] );
		$s = Status::shipments( $o );
		$this->assertSame( 'fedex', $s[0]['carrier'] );
		$this->assertSame( '123', $s[0]['tracking_number'] );
		$this->assertSame( 'https://t/123', $s[0]['tracking_url'] );
		$this->assertSame( 'FedEx', Status::carrier_label( 'fedex' ) );
	}
	public function test_labels_for_her(): void {
		$this->assertSame( 'Waiting for you', Status::label( 'waiting' ) );
		$this->assertSame( 'Shipped', Status::label( 'shipped' ) );
		$this->assertSame( 'Problem', Status::label( 'problem' ) );
		$this->assertSame( '—', Status::label( '' ) );
	}
}
