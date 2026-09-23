<?php
/**
 * WP-CLI regression checks for conservative finding policy gates.
 *
 * Run with:
 * wp eval-file tests/policy-regression.php
 *
 * @package PluginConflictDebugger
 */

declare(strict_types=1);

use PluginConflictDebugger\Core\FindingPolicy;
use PluginConflictDebugger\Core\FindingSignature;
use PluginConflictDebugger\Core\Heuristics;
use PluginConflictDebugger\Core\ScanComparator;
use PluginConflictDebugger\Core\TraceEvent;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . DIRECTORY_SEPARATOR );

	if ( ! function_exists( 'sanitize_key' ) ) {
		function sanitize_key( string $key ): string {
			return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', $key ) ?? '' );
		}
	}

	if ( ! function_exists( 'sanitize_text_field' ) ) {
		function sanitize_text_field( string $value ): string {
			return trim( preg_replace( '/[\r\n\t]+/', ' ', strip_tags( $value ) ) ?? '' );
		}
	}

	if ( ! function_exists( 'wp_json_encode' ) ) {
		function wp_json_encode( mixed $value, int $flags = 0 ): string|false {
			return json_encode( $value, $flags );
		}
	}

	require_once dirname( __DIR__ ) . '/includes/Core/TraceEvent.php';
	require_once dirname( __DIR__ ) . '/includes/Core/Heuristics.php';
	require_once dirname( __DIR__ ) . '/includes/Core/FindingPolicy.php';
	require_once dirname( __DIR__ ) . '/includes/Core/FindingSignature.php';
	require_once dirname( __DIR__ ) . '/includes/Core/ScanComparator.php';
}

$policy   = new FindingPolicy( new Heuristics() );
$failures = array();
$results  = array();

$evaluate = static function ( string $name, string $category, string $severity, int $confidence, array $evidence, array $expect ) use ( $policy, &$failures, &$results ): void {
	$result           = $policy->evaluate( $category, $severity, $confidence, $evidence, false );
	$results[ $name ] = $result;

	foreach ( $expect as $key => $expected ) {
		$actual = $result[ $key ] ?? null;
		if ( is_callable( $expected ) ? ! $expected( $actual ) : $actual !== $expected ) {
			$failures[] = sprintf( '%s: expected %s for %s, received %s', $name, is_scalar( $expected ) ? (string) $expected : 'predicate match', $key, wp_json_encode( $actual ) );
		}
	}
};

$evaluate(
	'common_admin_overlap',
	'probable_conflict',
	'high',
	84,
	array(
		array(
			'signal_key'        => 'surface_hook_overlap',
			'tier'              => 'supporting',
			'request_context'   => 'admin',
			'execution_surface' => 'admin_menu',
		),
		array(
			'signal_key'        => 'surface_hook_overlap',
			'tier'              => 'supporting',
			'request_context'   => 'admin',
			'execution_surface' => 'admin_init',
		),
	),
	array(
		'category'   => 'shared_surface',
		'severity'   => 'medium',
		'confidence' => static fn( mixed $value ): bool => is_int( $value ) && $value <= 50,
	)
);

$evaluate(
	'unattributed_asset_mutation',
	'probable_conflict',
	'high',
	78,
	array(
		array(
			'signal_key'        => 'asset_state_mutation',
			'tier'              => 'supporting',
			'request_context'   => 'admin',
			'execution_surface' => 'admin_enqueue_scripts',
			'shared_resource'   => 'handle:example-style',
			'attribution_status'=> TraceEvent::ATTRIBUTION_UNKNOWN,
			'mutation_status'   => TraceEvent::MUTATION_OBSERVED,
		),
	),
	array(
		'category'   => 'potential_interference',
		'severity'   => 'medium',
		'confidence' => static fn( mixed $value ): bool => is_int( $value ) && $value <= 60,
	)
);

$evaluate(
	'attributed_asset_mutation',
	'probable_conflict',
	'high',
	80,
	array(
		array(
			'signal_key'        => 'asset_state_mutation',
			'tier'              => 'strong_proof',
			'request_context'   => 'admin',
			'execution_surface' => 'admin_enqueue_scripts',
			'shared_resource'   => 'handle:example-style',
			'pair_specific'     => true,
			'attribution_status'=> TraceEvent::ATTRIBUTION_DIRECT,
			'mutation_status'   => TraceEvent::MUTATION_CONFIRMED,
		),
	),
	array(
		'category'   => 'probable_conflict',
		'severity'   => 'high',
		'confidence' => 80,
	)
);

$evaluate(
	'partially_attributed_asset_mutation',
	'probable_conflict',
	'high',
	78,
	array(
		array(
			'signal_key'        => 'asset_state_mutation',
			'tier'              => 'strong_proof',
			'request_context'   => 'admin',
			'execution_surface' => 'admin_enqueue_scripts',
			'shared_resource'   => 'handle:example-style',
			'pair_specific'     => true,
			'attribution_status'=> TraceEvent::ATTRIBUTION_PARTIAL,
			'mutation_status'   => TraceEvent::MUTATION_OBSERVED,
		),
	),
	array(
		'category'   => 'potential_interference',
		'severity'   => 'medium',
		'confidence' => static fn( mixed $value ): bool => is_int( $value ) && $value <= 60,
	)
);

$evaluate(
	'unlinked_runtime_failure',
	'confirmed_conflict',
	'critical',
	96,
	array(
		array(
			'signal_key'        => 'pair_specific_runtime_breakage',
			'tier'              => 'runtime_breakage',
			'request_context'   => 'rest',
			'execution_surface' => 'rest_api_init',
			'shared_resource'   => 'route:/example/v1/item',
			'failure_mode'      => 'http_500',
			'same_trace'        => false,
		),
	),
	array(
		'category' => 'overlap',
		'severity' => 'low',
	)
);

$evaluate(
	'confirmed_same_trace_failure',
	'confirmed_conflict',
	'critical',
	96,
	array(
		array(
			'signal_key'        => 'pair_specific_runtime_breakage',
			'tier'              => 'runtime_breakage',
			'request_context'   => 'rest',
			'execution_surface' => 'rest_api_init',
			'shared_resource'   => 'route:/example/v1/item',
			'failure_mode'      => 'http_500',
			'same_trace'        => true,
			'pair_specific'     => true,
		),
	),
	array(
		'category'   => 'confirmed_conflict',
		'severity'   => 'critical',
		'confidence' => 96,
	)
);

$previous_finding = array(
	'primary_plugin'        => 'alpha/alpha.php',
	'primary_plugin_name'   => 'Alpha',
	'secondary_plugin'      => 'beta/beta.php',
	'secondary_plugin_name' => 'Beta',
	'surface_key'           => 'asset_loading',
	'request_context'       => 'admin',
	'execution_surface'     => 'admin_enqueue_scripts',
	'shared_resource'       => 'handle:example-style',
	'category'              => 'shared_surface',
	'finding_type'          => 'shared_surface',
	'severity'              => 'low',
	'confidence'            => 30,
	'title'                 => 'Shared asset surface',
);
$current_finding  = array_merge(
	$previous_finding,
	array(
		'category'     => 'potential_interference',
		'finding_type' => 'potential_interference',
		'severity'     => 'medium',
		'confidence'   => 55,
	)
);
$comparison       = ( new ScanComparator() )->compare(
	array(
		'scan_timestamp' => '2026-07-13 12:00:00',
		'summary'        => array( 'likely_conflicts' => 1 ),
		'findings'       => array( $current_finding ),
	),
	array(
		array(),
		array(
			'scan_timestamp'   => '2026-07-13 11:00:00',
			'summary'          => array( 'likely_conflicts' => 1 ),
			'findings_snapshot'=> array( FindingSignature::snapshot( $previous_finding ) ),
		),
	)
);

if ( 1 !== count( (array) ( $comparison['changed_findings'] ?? array() ) ) || ! empty( $comparison['new_findings'] ) || ! empty( $comparison['resolved_findings'] ) ) {
	$failures[] = 'scan_comparator: expected one changed finding without new/resolved churn';
}
$results['scan_comparator'] = $comparison;

if ( ! empty( $failures ) ) {
	foreach ( $failures as $failure ) {
		fwrite( STDERR, $failure . PHP_EOL );
	}
	exit( 1 );
}

echo wp_json_encode(
	array(
		'status' => 'passed',
		'cases'  => array_keys( $results ),
	),
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . PHP_EOL;
