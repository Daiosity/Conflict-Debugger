<?php
/**
 * Run with php tests/privacy-regression.php (no database required).
 */
declare(strict_types=1);

define( 'ABSPATH', __DIR__ );
function sanitize_text_field( $value ) { return trim( strip_tags( $value ) ); }
function sanitize_textarea_field( $value ) { return sanitize_text_field( $value ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function current_user_can( $capability ) { return $GLOBALS['pcd_test_admin'] ?? false; }
function __( $text, $domain ) { return $text; }
function wp_unslash( $value ) { return $value; }
function check_ajax_referer( $action, $key ) { $GLOBALS['pcd_nonce_checked'] = true; }
function wp_send_json_error( $data, $status ) { throw new RuntimeException( $data['message'], $status ); }

require __DIR__ . '/../includes/Core/DiagnosticPrivacy.php';
require __DIR__ . '/../includes/Core/RuntimeTelemetry.php';

use PluginConflictDebugger\Core\DiagnosticPrivacy;
use PluginConflictDebugger\Core\RuntimeTelemetry;

$check = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
};
$payload = array(
	'request_uri' => 'https://user:password@example.test/wp-admin/?token=hidden#secret',
	'message' => 'Failed /api?nonce=private for person@example.test token=hidden',
	'new_state' => array( 'src' => 'https://example.test/plugin.js?api_key=hidden', 'secret' => 'hidden' ),
);
$safe = DiagnosticPrivacy::scrub( $payload );
$encoded = json_encode( $safe );
$check( ! str_contains( $encoded, 'hidden' ) && ! str_contains( $encoded, 'private' ), 'URL credentials leaked' );
$check( ! str_contains( $encoded, 'person@example.test' ), 'Email leaked' );
$check( '/wp-admin/' === $safe['request_uri'], 'Request path lost' );
$check( '/plugin.js' === $safe['new_state']['src'], 'Asset attribution path lost' );
$check( strlen( DiagnosticPrivacy::text( str_repeat( 'x', 10000 ) ) ) <= 4096, 'Message limit failed' );
$check( count( DiagnosticPrivacy::scrub( array_fill( 0, 1000, 'x' ) ) ) === 100, 'Collection limit failed' );

$runtime = ( new ReflectionClass( RuntimeTelemetry::class ) )->newInstanceWithoutConstructor();
$assert_status = static function ( int $expected ) use ( $runtime, $check ): void {
	try {
		$runtime->handle_runtime_event();
		throw new RuntimeException( 'Expected rejection' );
	} catch ( RuntimeException $error ) {
		$check( $expected === $error->getCode(), 'Unexpected authorization/validation status: ' . $error->getMessage() );
	}
};
$assert_status( 403 );
$check( empty( $GLOBALS['pcd_nonce_checked'] ), 'Capability gate did not run first' );
$GLOBALS['pcd_test_admin'] = true;
$_POST = array( 'message' => array( 'malformed' ) );
$assert_status( 400 );
$check( ! empty( $GLOBALS['pcd_nonce_checked'] ), 'Nonce verification missing' );
$_POST = array( 'type' => 'direct_callback_mutation' );
$assert_status( 400 );
$_POST = array( 'message' => str_repeat( 'x', 4097 ) );
$assert_status( 400 );
echo "Privacy, payload bounds, browser authorization and type validation passed.\n";
