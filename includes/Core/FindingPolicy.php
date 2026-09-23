<?php
/**
 * Central trust policy for pairwise conflict findings.
 *
 * @package PluginConflictDebugger
 */

declare(strict_types=1);

namespace PluginConflictDebugger\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FindingPolicy {
	/**
	 * Heuristic support service.
	 *
	 * @var Heuristics
	 */
	private Heuristics $heuristics;

	/**
	 * Exact resource signals that can establish pair-specific interference.
	 *
	 * @var string[]
	 */
	private const EXACT_RESOURCE_SIGNALS = array(
		'rest_route_overlap',
		'ajax_action_overlap',
		'routing_overlap',
		'content_model_overlap',
	);

	/**
	 * Common admin lifecycle hooks that should not stack into high confidence.
	 *
	 * @var string[]
	 */
	private const COMMON_ADMIN_HOOKS = array(
		'admin_menu',
		'admin_init',
		'current_screen',
		'admin_enqueue_scripts',
		'load-post.php',
		'load-edit.php',
		'load-post-new.php',
	);

	/**
	 * Constructor.
	 *
	 * @param Heuristics $heuristics Heuristic support service.
	 */
	public function __construct( Heuristics $heuristics ) {
		$this->heuristics = $heuristics;
	}

	/**
	 * Applies hard trust gates to a proposed finding classification.
	 *
	 * @param string                           $category Proposed finding category.
	 * @param string                           $severity Proposed severity.
	 * @param int                              $confidence Proposed confidence.
	 * @param array<int, array<string, mixed>> $evidence_items Evidence items.
	 * @param bool                             $observer_involved Whether an observer plugin is involved.
	 * @return array<string, mixed>
	 */
	public function evaluate( string $category, string $severity, int $confidence, array $evidence_items, bool $observer_involved ): array {
		$facts = $this->summarize_evidence( $evidence_items );

		if ( ! $facts['pair_specific_causality'] && ! $facts['actionable_proof'] ) {
			if ( $facts['unattributed_mutation'] || $facts['has_generic_runtime'] ) {
				$category   = 'potential_interference';
				$severity   = 'medium';
				$confidence = min( $confidence, 60 );
			} else {
				$category   = $facts['has_supporting'] ? 'shared_surface' : 'overlap';
				$severity   = $facts['has_supporting'] ? 'medium' : 'low';
				$confidence = min( $confidence, $facts['has_supporting'] ? 50 : 30 );
			}
		}

		if ( 'confirmed_conflict' === $category && ! $facts['can_confirm'] ) {
			$category   = $facts['actionable_proof'] ? 'probable_conflict' : 'potential_interference';
			$severity   = $facts['actionable_proof'] ? 'high' : 'medium';
			$confidence = min( $confidence, $facts['actionable_proof'] ? 85 : 65 );
		}

		if ( ! $facts['actionable_proof'] && ! $facts['pair_specific_runtime'] ) {
			$severity = $this->cap_severity( $severity, 'medium' );

			if ( in_array( $category, array( 'probable_conflict', 'confirmed_conflict' ), true ) ) {
				$category = $facts['has_generic_runtime'] || $facts['unattributed_mutation'] ? 'potential_interference' : ( $facts['has_supporting'] ? 'shared_surface' : 'overlap' );
			}
		}

		if ( $facts['admin_noise'] && ! $facts['admin_resource_proof'] ) {
			$category   = $facts['has_generic_runtime'] || $facts['unattributed_mutation'] ? 'potential_interference' : 'shared_surface';
			$severity   = $this->cap_severity( $severity, 'medium' );
			$confidence = min( $confidence, 50 );
		}

		if ( $facts['contaminated'] && ! $facts['pair_specific_runtime'] ) {
			$category   = $facts['has_supporting'] || $facts['has_generic_runtime'] ? 'potential_interference' : 'shared_surface';
			$severity   = $this->cap_severity( $severity, 'medium' );
			$confidence = min( max( 0, $confidence - 18 ), 50 );
		}

		if ( $observer_involved && ! $facts['can_confirm'] ) {
			$severity   = $this->cap_severity( $severity, 'medium' );
			$confidence = min( $confidence, $facts['actionable_proof'] ? 65 : 45 );

			if ( in_array( $category, array( 'probable_conflict', 'confirmed_conflict' ), true ) ) {
				$category = $facts['actionable_proof'] ? 'potential_interference' : 'shared_surface';
			}
		}

		$confidence_ceiling = $this->confidence_ceiling( $category, $facts, $observer_involved );
		$confidence         = min( $confidence, $confidence_ceiling );
		$severity           = $this->normalize_severity( $category, $severity, $confidence, $facts );

		return array(
			'category'           => $category,
			'severity'           => $severity,
			'confidence'         => $confidence,
			'confidence_ceiling' => $confidence_ceiling,
			'trust_factors'      => array(
				'actionable_proof'          => $facts['actionable_proof'],
				'direct_attribution'        => $facts['direct_attribution'],
				'partial_attribution'       => $facts['partial_attribution'],
				'attribution_status'        => $facts['direct_attribution'] ? TraceEvent::ATTRIBUTION_DIRECT : ( $facts['partial_attribution'] ? TraceEvent::ATTRIBUTION_PARTIAL : TraceEvent::ATTRIBUTION_UNKNOWN ),
				'unattributed_mutation'     => $facts['unattributed_mutation'],
				'pair_specific_runtime'     => $facts['pair_specific_runtime'],
				'pair_specific_causality'   => $facts['pair_specific_causality'],
				'confirmation_met'          => $facts['can_confirm'],
				'admin_overlap_normalized'  => $facts['admin_noise'] && ! $facts['admin_resource_proof'],
				'third_party_contamination' => $facts['contaminated'],
				'strong_proof_count'        => $facts['strong_proof_count'],
			),
		);
	}

	/**
	 * Returns whether evidence establishes pair-specific causality.
	 *
	 * @param array<int, array<string, mixed>> $evidence_items Evidence items.
	 * @return bool
	 */
	public function has_pair_specific_causality( array $evidence_items ): bool {
		return (bool) $this->summarize_evidence( $evidence_items )['pair_specific_causality'];
	}

	/**
	 * Summarizes evidence once for all policy decisions.
	 *
	 * @param array<int, array<string, mixed>> $evidence_items Evidence items.
	 * @return array<string, mixed>
	 */
	private function summarize_evidence( array $evidence_items ): array {
		$breakdown             = $this->heuristics->evidence_breakdown( $evidence_items );
		$has_supporting        = false;
		$has_generic_runtime   = false;
		$pair_runtime          = false;
		$exact_resource_proof  = false;
		$direct_attribution    = false;
		$partial_attribution   = false;
		$unattributed_mutation = false;
		$contaminated          = false;
		$admin_count           = 0;
		$common_admin_count    = 0;
		$admin_resource_proof  = false;

		foreach ( $evidence_items as $evidence_item ) {
			$signal_key        = sanitize_key( (string) ( $evidence_item['signal_key'] ?? '' ) );
			$tier              = $this->heuristics->normalize_tier( (string) ( $evidence_item['tier'] ?? '' ) );
			$resource          = sanitize_text_field( (string) ( $evidence_item['shared_resource'] ?? '' ) );
			$context           = strtolower( sanitize_text_field( (string) ( $evidence_item['request_context'] ?? '' ) ) );
			$execution_surface = strtolower( sanitize_text_field( (string) ( $evidence_item['execution_surface'] ?? '' ) ) );
			$attribution       = sanitize_key( (string) ( $evidence_item['attribution_status'] ?? '' ) );
			$mutation_status   = sanitize_key( (string) ( $evidence_item['mutation_status'] ?? '' ) );
			$item_pair_runtime = false;
			$item_exact_proof  = false;
			$item_attribution  = false;

			$has_supporting      = $has_supporting || 'supporting' === $tier;
			$has_generic_runtime = $has_generic_runtime || 'generic_runtime_noise' === $signal_key;
			$contaminated        = $contaminated || ! empty( $evidence_item['contaminated'] ) || 'third_party_contamination' === $signal_key;

			if ( 'pair_specific_runtime_breakage' === $signal_key && ! empty( $evidence_item['same_trace'] ) && '' !== $resource && '' !== (string) ( $evidence_item['failure_mode'] ?? '' ) ) {
				$pair_runtime      = true;
				$item_pair_runtime = true;
			}

			if ( in_array( $signal_key, self::EXACT_RESOURCE_SIGNALS, true ) && '' !== $resource ) {
				$exact_resource_proof = true;
				$item_exact_proof     = true;
			}

			if ( 'direct_callback_mutation' === $signal_key && ! empty( $evidence_item['pair_specific'] ) && '' !== $resource && TraceEvent::ATTRIBUTION_DIRECT === $attribution ) {
				$direct_attribution = true;
				$item_attribution   = true;
			} elseif ( 'direct_callback_mutation' === $signal_key && '' !== $resource && TraceEvent::ATTRIBUTION_PARTIAL === $attribution ) {
				$partial_attribution   = true;
				$unattributed_mutation = true;
			}

			if ( 'asset_state_mutation' === $signal_key && ! empty( $evidence_item['pair_specific'] ) && '' !== $resource && TraceEvent::ATTRIBUTION_DIRECT === $attribution && in_array( $mutation_status, array( TraceEvent::MUTATION_OBSERVED, TraceEvent::MUTATION_CONFIRMED ), true ) ) {
				$direct_attribution = true;
				$item_attribution   = true;
			} elseif ( 'asset_state_mutation' === $signal_key && '' !== $resource && TraceEvent::ATTRIBUTION_PARTIAL === $attribution ) {
				$partial_attribution   = true;
				$unattributed_mutation = true;
			} elseif ( 'asset_state_mutation' === $signal_key && '' !== $resource ) {
				$unattributed_mutation = true;
			}

			if ( str_contains( $context, 'admin' ) ) {
				$admin_count++;
				if ( in_array( $execution_surface, self::COMMON_ADMIN_HOOKS, true ) ) {
					$common_admin_count++;
				}

				if ( '' !== $resource && ! in_array( strtolower( $resource ), self::COMMON_ADMIN_HOOKS, true ) && ( $item_exact_proof || $item_attribution || $item_pair_runtime ) ) {
					$admin_resource_proof = true;
				}
			}
		}

		$actionable_proof = $exact_resource_proof || $direct_attribution || $pair_runtime;
		$admin_noise      = $admin_count >= 2 && $common_admin_count >= max( 2, $admin_count - 1 );

		return array(
			'has_supporting'          => $has_supporting,
			'has_generic_runtime'     => $has_generic_runtime,
			'pair_specific_runtime'   => $pair_runtime,
			'exact_resource_proof'    => $exact_resource_proof,
			'direct_attribution'      => $direct_attribution,
			'partial_attribution'     => $partial_attribution,
			'unattributed_mutation'   => $unattributed_mutation,
			'actionable_proof'        => $actionable_proof,
			'pair_specific_causality' => $actionable_proof,
			'can_confirm'             => $pair_runtime,
			'contaminated'            => $contaminated,
			'admin_noise'             => $admin_noise,
			'admin_resource_proof'    => $admin_resource_proof,
			'strong_proof_count'      => (int) ( $breakdown['strong_proof'] ?? 0 ),
		);
	}

	/**
	 * Applies category and evidence-aware confidence ceilings.
	 *
	 * @param string               $category Finding category.
	 * @param array<string, mixed> $facts Evidence summary.
	 * @param bool                 $observer_involved Whether an observer plugin is involved.
	 * @return int
	 */
	private function confidence_ceiling( string $category, array $facts, bool $observer_involved ): int {
		$ceilings = array(
			'overlap'                => 35,
			'shared_surface'         => 50,
			'potential_interference' => 65,
			'probable_conflict'      => $facts['actionable_proof'] ? 85 : 65,
			'confirmed_conflict'     => $facts['can_confirm'] ? 100 : 85,
			'observer_artifact'      => 55,
			'global_anomaly'         => 60,
		);
		$ceiling  = $ceilings[ $category ] ?? 65;

		if ( ! $facts['actionable_proof'] ) {
			$ceiling = min( $ceiling, 65 );
		}

		if ( $facts['admin_noise'] && ! $facts['admin_resource_proof'] ) {
			$ceiling = min( $ceiling, 50 );
		}

		if ( $facts['contaminated'] && ! $facts['pair_specific_runtime'] ) {
			$ceiling = min( $ceiling, 50 );
		}

		if ( $observer_involved && ! $facts['can_confirm'] ) {
			$ceiling = min( $ceiling, 65 );
		}

		return $ceiling;
	}

	/**
	 * Normalizes severity after confidence and causality gates are applied.
	 *
	 * @param string               $category Finding category.
	 * @param string               $severity Proposed severity.
	 * @param int                  $confidence Final confidence.
	 * @param array<string, mixed> $facts Evidence summary.
	 * @return string
	 */
	private function normalize_severity( string $category, string $severity, int $confidence, array $facts ): string {
		if ( 'confirmed_conflict' === $category && $facts['can_confirm'] ) {
			return $confidence >= 91 ? 'critical' : 'high';
		}

		if ( 'probable_conflict' === $category && $facts['actionable_proof'] ) {
			return $confidence >= 70 ? 'high' : 'medium';
		}

		if ( in_array( $category, array( 'shared_surface', 'potential_interference' ), true ) ) {
			return $confidence >= 40 ? 'medium' : 'low';
		}

		if ( 'overlap' === $category ) {
			return 'low';
		}

		return $this->cap_severity( $severity, $facts['actionable_proof'] ? 'high' : 'medium' );
	}

	/**
	 * Caps a severity label without changing lower severities.
	 *
	 * @param string $severity Current severity.
	 * @param string $maximum Maximum severity.
	 * @return string
	 */
	private function cap_severity( string $severity, string $maximum ): string {
		return $this->heuristics->severity_rank( $severity ) > $this->heuristics->severity_rank( $maximum ) ? $maximum : $severity;
	}
}
