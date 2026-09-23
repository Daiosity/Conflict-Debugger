<?php
/**
 * Integration checks against an activated WordPress test installation.
 * Run: wp eval-file /path/to/tests/wordpress-regression.php
 */

use PluginConflictDebugger\Core\DiagnosticPrivacy;
use PluginConflictDebugger\Core\RuntimeTelemetryRepository;
use PluginConflictDebugger\Core\DiagnosticSessionRepository;
use PluginConflictDebugger\Core\ValidationModeRepository;
use PluginConflictDebugger\Core\RegistrySnapshot;
use PluginConflictDebugger\Core\RuntimeTelemetry;
use PluginConflictDebugger\Core\RestRouteInspector;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

$pcd_assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
};
$pcd_repository = new RuntimeTelemetryRepository();
$pcd_missing = new stdClass();
$pcd_saved = array();
foreach ( array( 'pcd_runtime_events', 'pcd_request_contexts' ) as $pcd_key ) {
	$pcd_saved[ $pcd_key ] = get_option( $pcd_key, $pcd_missing );
}
$pcd_user_id = get_current_user_id();
try {
	$pcd_repository->delete();
	for ( $pcd_index = 0; $pcd_index < 65; ++$pcd_index ) {
		$pcd_repository->record_event( array(
			'message' => 'Example ' . $pcd_index . ' token=hidden person@example.test',
			'request_uri' => '/wp-admin/?nonce=hidden',
			'source' => 'https://example.test/wp-content/plugins/example/script.js?token=hidden',
		) );
	}
	$pcd_events = $pcd_repository->get_events( 100 );
	$pcd_assert( count( $pcd_events ) === 50, 'Runtime event retention limit failed.' );
	$pcd_stored = wp_json_encode( get_option( 'pcd_runtime_events' ) );
	$pcd_assert( ! str_contains( $pcd_stored, 'hidden' ) && ! str_contains( $pcd_stored, 'person@example.test' ), 'Sensitive data reached persistence.' );
	$pcd_assert( '/wp-admin/' === $pcd_events[0]['request_uri'], 'Request path not preserved.' );
	$pcd_assert( str_contains( $pcd_events[0]['source'], '/plugins/example/script.js' ), 'Asset attribution path not preserved.' );
	$pcd_assert( false === has_action( 'wp_ajax_nopriv_pcd_report_runtime_event' ), 'Anonymous browser reporter remains registered.' );
	$pcd_assert( false !== has_action( 'wp_ajax_pcd_report_runtime_event' ), 'Authenticated reporter is missing.' );

	wp_set_current_user( 0 );
	$pcd_runtime = new RuntimeTelemetry( $pcd_repository, new RegistrySnapshot(), new DiagnosticSessionRepository(), new ValidationModeRepository() );
	wp_dequeue_script( 'pcd-runtime-telemetry' );
	$pcd_runtime->enqueue_login_script();
	$pcd_assert( ! wp_script_is( 'pcd-runtime-telemetry', 'enqueued' ), 'Telemetry enqueued for an anonymous visitor.' );
	$pcd_assert( '/path' === DiagnosticPrivacy::path( 'https://user:pass@example.test/path?password=hidden#fragment' ), 'URL credential redaction failed.' );

	// Use a private in-memory server: no endpoints are registered on the site's REST server.
	$pcd_server = new WP_REST_Server();
	$pcd_server->register_route( 'pcd-test/v1', '/pcd-test/v1/item', array(
		array( 'methods' => 'GET', 'callback' => static fn() => 'alpha', 'permission_callback' => '__return_true' ),
		array( 'methods' => 'POST', 'callback' => static fn() => 'beta', 'permission_callback' => '__return_true' ),
	) );
	$pcd_routes = $pcd_server->get_routes();
	$pcd_handlers = $pcd_routes['/pcd-test/v1/item'];
	$pcd_handlers[0]['owner_slug'] = 'alpha';
	$pcd_handlers[1]['owner_slug'] = 'beta';
	$pcd_inspector = new RestRouteInspector();
	$pcd_assert( array() === $pcd_inspector->overlaps( $pcd_handlers ), 'WordPress GET and POST registrations should coexist.' );
	$pcd_assert( 'alpha' === $pcd_server->dispatch( new WP_REST_Request( 'HEAD', '/pcd-test/v1/item' ) )->get_data(), 'WordPress HEAD fallback changed.' );
	$pcd_assert( 'beta' === $pcd_server->dispatch( new WP_REST_Request( 'POST', '/pcd-test/v1/item' ) )->get_data(), 'Distinct REST handler was not dispatched.' );
	$pcd_server->register_route( 'pcd-test/v1', '/pcd-test/v1/item', array(
		array( 'methods' => 'HEAD', 'callback' => static fn() => 'gamma', 'permission_callback' => '__return_true' ),
	) );
	$pcd_routes = $pcd_server->get_routes();
	$pcd_handlers = $pcd_routes['/pcd-test/v1/item'];
	foreach ( array( 'alpha', 'beta', 'gamma' ) as $pcd_index => $pcd_owner ) {
		$pcd_handlers[ $pcd_index ]['owner_slug'] = $pcd_owner;
	}
	$pcd_overlaps = $pcd_inspector->overlaps( $pcd_handlers );
	$pcd_assert( 1 === count( $pcd_overlaps ) && array( 'HEAD' ) === $pcd_overlaps[0]['methods'], 'HEAD overlap not identified.' );
	$pcd_assert( 'alpha' === $pcd_server->dispatch( new WP_REST_Request( 'HEAD', '/pcd-test/v1/item' ) )->get_data(), 'Earlier GET handler should win over later HEAD handler.' );
	WP_CLI::success( 'WordPress integration passed: privacy, retention, permissions and REST method dispatch/HEAD fallback.' );
} finally {
	wp_set_current_user( $pcd_user_id );
	foreach ( $pcd_saved as $pcd_key => $pcd_value ) {
		if ( $pcd_missing === $pcd_value ) { delete_option( $pcd_key ); }
		else { update_option( $pcd_key, $pcd_value, false ); }
	}
}
