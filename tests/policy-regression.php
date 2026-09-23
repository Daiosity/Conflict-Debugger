<?php
/**
 * Standalone regression checks for conservative finding policy gates.
 *
 * Run with:
 * php tests/policy-regression.php
 *
 * @package PluginConflictDebugger
 */

declare(strict_types=1);

use PluginConflictDebugger\Core\FindingPolicy;
use PluginConflictDebugger\Core\FindingSignature;
use PluginConflictDebugger\Core\Heuristics;
use PluginConflictDebugger\Core\ScanComparator;
use PluginConflictDebugger\Core\TraceEvent;
use PluginConflictDebugger\Core\EvidenceAssessment;
use PluginConflictDebugger\Core\EvidenceScope;
use PluginConflictDebugger\Core\RestRouteInspector;
use PluginConflictDebugger\Core\ConflictDetector;
use PluginConflictDebugger\Core\RegistrySnapshot;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . DIRECTORY_SEPARATOR );

	function __( string $text, string $domain = '' ): string {
		return $text;
	}
	function sanitize_textarea_field( string $value ): string {
		return sanitize_text_field( $value );
	}

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
	require_once dirname( __DIR__ ) . '/includes/Core/EvidenceAssessment.php';
	require_once dirname( __DIR__ ) . '/includes/Core/EvidenceScope.php';
	require_once dirname( __DIR__ ) . '/includes/Core/RestRouteInspector.php';
	require_once dirname( __DIR__ ) . '/includes/Core/ConflictDetector.php';
	require_once dirname( __DIR__ ) . '/includes/Core/RegistrySnapshot.php';
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
			'mutation_kind'     => 'asset_dequeued',
			'actor_slug'        => 'alpha',
			'target_owner_slug' => 'beta',
			'actor_callback'    => 'Alpha::dequeue',
			'request_id'        => 'request-1',
			'evidence_source'   => TraceEvent::SOURCE_TRACE,
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
			'mutation_kind'     => 'callback_replaced',
			'mutation_status'   => TraceEvent::MUTATION_OBSERVED,
			'attribution_status' => TraceEvent::ATTRIBUTION_DIRECT,
			'actor_slug'        => 'alpha',
			'target_owner_slug' => 'beta',
			'actor_callback'    => 'Alpha::replace',
			'request_id'        => 'request-1',
			'evidence_source'   => TraceEvent::SOURCE_TRACE,
		),
	),
	array(
		'category'   => 'confirmed_conflict',
		'severity'   => 'critical',
		'confidence' => 96,
	)
);

$direct = array(
	'signal_key' => 'asset_state_mutation', 'tier' => 'strong_proof',
	'request_context' => 'admin', 'execution_surface' => 'admin_enqueue_scripts',
	'shared_resource' => 'style:example', 'pair_specific' => true,
	'actor_slug' => 'alpha', 'target_owner_slug' => 'beta', 'actor_callback' => 'Alpha::dequeue',
	'attribution_status' => TraceEvent::ATTRIBUTION_DIRECT, 'mutation_status' => TraceEvent::MUTATION_OBSERVED,
	'mutation_kind' => 'asset_dequeued', 'request_id' => 'trace-1', 'evidence_source' => TraceEvent::SOURCE_TRACE,
);

foreach ( array(
	'missing_mutator' => array( 'actor_callback' => '' ),
	'missing_request' => array( 'request_id' => '' ),
	'client_claim' => array( 'evidence_source' => TraceEvent::SOURCE_CLIENT ),
	'unknown_context' => array( 'request_context' => 'runtime' ),
	'self_mutation' => array( 'target_owner_slug' => 'alpha' ),
	'normal_registration' => array( 'mutation_kind' => 'asset_registered' ),
	'contaminated_mutation' => array( 'contamination_status' => TraceEvent::CONTAMINATION_HIGH ),
) as $name => $override ) {
	$evaluate( $name, 'probable_conflict', 'high', 98, array( array_merge( $direct, $override ) ), array(
		'category' => static fn( $value ): bool => in_array( $value, array( 'shared_surface', 'potential_interference' ), true ),
		'severity' => static fn( $value ): bool => in_array( $value, array( 'low', 'medium' ), true ),
		'confidence' => static fn( $value ): bool => $value <= 65,
		'trust_factors' => static fn( $value ): bool => 0 === $value['strong_proof_count'] && ! $value['actionable_proof'],
	) );
}

foreach ( array( 'rest_route_overlap', 'ajax_action_overlap', 'routing_overlap', 'content_model_overlap' ) as $signal ) {
	$evaluate( 'unproven_' . $signal, 'probable_conflict', 'high', 99, array( array(
		'signal_key' => $signal, 'tier' => 'strong_proof', 'shared_resource' => 'example',
		'request_context' => 'admin', 'execution_surface' => 'init',
	) ), array( 'category' => 'shared_surface', 'severity' => 'medium', 'confidence' => 50 ) );
}

$assert = static function ( bool $condition, string $name ) use ( &$failures, &$results ): void {
	$results[ $name ] = $condition;
	if ( ! $condition ) { $failures[] = $name; }
};
$normalized = EvidenceAssessment::normalize( $direct );
$assert( 'strong_proof' === $normalized['tier'] && $normalized['proof_accepted'], 'complete_actor_proof_accepted' );
$assert( EvidenceAssessment::normalize( $normalized ) === $normalized, 'assessment_idempotent' );
$heuristics = new Heuristics();
$assert( $heuristics->score_evidence_items( array( $normalized ) ) === $heuristics->score_evidence_items( array(
	array_merge( $normalized, array( 'message' => 'first observation' ) ),
	array_merge( $normalized, array( 'message' => 'same observation, different wording' ) ),
) ), 'reworded_evidence_does_not_inflate_score' );

$rest = new RestRouteInspector();
$handlers = array(
	array( 'owner_slug' => 'alpha', 'methods' => array( 'GET' => true ) ),
	array( 'owner_slug' => 'beta', 'methods' => array( 'POST' => true ) ),
);
$assert( array() === $rest->overlaps( $handlers ), 'rest_disjoint_methods_coexist' );
$handlers[1]['methods'] = 'GET, POST';
$overlaps = $rest->overlaps( $handlers );
$assert( array( 'GET', 'HEAD' ) === $overlaps[0]['methods'], 'rest_matching_methods_reported' );
$handlers[1]['methods'] = array( 'HEAD' => true );
$assert( array( 'HEAD' ) === $rest->overlaps( $handlers )[0]['methods'], 'rest_head_fallback' );
$handlers[1]['methods'] = array( 'GET' => false );
$assert( array() === $rest->overlaps( $handlers ), 'rest_disabled_method_ignored' );
$handlers[1] = $handlers[0];
$assert( array() === $rest->overlaps( $handlers ), 'rest_same_owner_ignored' );

$scope = new EvidenceScope();
$items = array(
	$normalized,
	array_merge( $normalized, array( 'shared_resource' => 'style:unrelated', 'signal_key' => 'direct_callback_mutation' ) ),
	array_merge( $normalized, array( 'request_id' => 'trace-2' ) ),
	array_merge( $normalized, array( 'request_context' => 'frontend' ) ),
	array( 'tier' => 'noise', 'signal_key' => 'recent_change', 'request_context' => 'runtime' ),
);
$selected = $scope->select( $items, new Heuristics() );
$assert( 2 === count( $selected ), 'unrelated_resources_requests_contexts_excluded' );
$assert( 'strong_proof' === $selected[0]['tier'], 'primary_evidence_precedes_background_noise' );

$detector = new ConflictDetector( new Heuristics(), new RegistrySnapshot(), $policy );
$method = new ReflectionMethod( $detector, 'analyze_runtime_mutation_entries' );
$entry = array_merge( $direct, array(
	'type' => 'asset_lifecycle', 'resource_key' => 'example', 'resource' => 'example',
	'owner_slugs' => array( 'alpha', 'beta', 'bystander' ), 'message' => 'Observed change',
) );
$pairs = $method->invoke( $detector, array( $entry ) );
$assert( array( 'alpha:beta' ) === array_keys( $pairs ), 'mutation_does_not_blame_bystander' );
$merge = new ReflectionMethod( $detector, 'merge_pair_analysis' );
$surface_map = array();
$arguments = array( &$surface_map, $pairs['alpha:beta'] );
$merge->invokeArgs( $detector, $arguments );
$merged = $surface_map['asset_loading']['evidence_items'][0];
$assert( 'trace-1' === $merged['request_id'] && 'Alpha::dequeue' === $merged['actor_callback'], 'trace_metadata_survives_analysis_merge' );
$assert( EvidenceAssessment::normalize( $merged )['proof_accepted'], 'attributed_mutation_survives_detector_pipeline' );
$entry['type'] = 'php_runtime';
$assert( array() === $method->invoke( $detector, array( $entry ) ), 'php_error_not_misclassified_as_asset_mutation' );
$entry['type'] = 'asset_lifecycle';
$entry['target_owner_slug'] = 'alpha';
$assert( array() === $method->invoke( $detector, array( $entry ) ), 'self_mutation_not_assigned_to_bystander' );

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
