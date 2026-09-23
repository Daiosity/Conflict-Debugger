<?php
/**
 * Validates evidence claims before scoring, classification, and display.
 *
 * @package PluginConflictDebugger
 */

declare(strict_types=1);

namespace PluginConflictDebugger\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EvidenceAssessment {
	/**
	 * Downgrades unsupported proof claims without discarding the observation.
	 *
	 * @param array<string, mixed> $item Evidence item.
	 * @return array<string, mixed>
	 */
	public static function normalize( array $item ): array {
		$signal = (string) ( $item['signal_key'] ?? '' );
		$tier   = ( new Heuristics() )->normalize_tier( (string) ( $item['tier'] ?? '' ) );
		$clean  = empty( $item['contaminated'] ) && in_array( (string) ( $item['contamination_status'] ?? '' ), array( '', TraceEvent::CONTAMINATION_NONE ), true );
		$scoped = '' !== trim( (string) ( $item['shared_resource'] ?? '' ) )
			&& '' !== trim( (string) ( $item['execution_surface'] ?? '' ) )
			&& ! in_array( strtolower( trim( (string) ( $item['request_context'] ?? '' ) ) ), array( '', 'runtime', 'generic site behavior' ), true );
		$server = in_array( (string) ( $item['evidence_source'] ?? '' ), array( TraceEvent::SOURCE_TRACE, TraceEvent::SOURCE_RUNTIME ), true );
		$direct = $clean && $scoped && $server && self::has_direct_actor( $item );
		$proof  = $direct && in_array( $signal, array( 'asset_state_mutation', 'direct_callback_mutation' ), true );
		$broken = $direct && 'pair_specific_runtime_breakage' === $signal
			&& ! empty( $item['same_trace'] ) && '' !== trim( (string) ( $item['failure_mode'] ?? '' ) );

		$item['proof_accepted'] = $proof || $broken;
		if ( ! $clean ) {
			$item['tier'] = 'noise';
			$item['assessment_reason'] = __( 'Third-party or uncertain attribution: retained as context, not pair-specific proof.', 'daiosity-conflict-debugger' );
		} elseif ( $broken ) {
			$item['tier'] = 'runtime_breakage';
			$item['assessment_reason'] = __( 'A captured actor mutation and an explicit failure are linked to the same request and resource.', 'daiosity-conflict-debugger' );
		} elseif ( $proof ) {
			$item['tier'] = 'strong_proof';
			$item['assessment_reason'] = __( 'The mutating callback, actor, resource owner, and request are identified. User-visible breakage is not established by the mutation alone.', 'daiosity-conflict-debugger' );
		} elseif ( in_array( $tier, array( 'strong_proof', 'runtime_breakage' ), true ) ) {
			$item['tier'] = 'pair_specific_runtime_breakage' === $signal ? 'noise' : 'supporting';
			$item['assessment_reason'] = __( 'The observation lacks a captured pair-specific mutation path. Shared registrations and snapshot differences are supporting evidence, not proof of incompatibility.', 'daiosity-conflict-debugger' );
		} else {
			$item['tier'] = $tier;
		}

		return $item;
	}

	/**
	 * Requires a captured mutator rather than an actor inferred from snapshots.
	 *
	 * @param array<string, mixed> $item Evidence item.
	 * @return bool
	 */
	public static function has_direct_actor( array $item ): bool {
		$actor  = (string) ( $item['actor_slug'] ?? '' );
		$target = (string) ( $item['target_owner_slug'] ?? '' );
		return ! empty( $item['pair_specific'] ) && '' !== $actor && '' !== $target && $actor !== $target
			&& TraceEvent::ATTRIBUTION_DIRECT === ( $item['attribution_status'] ?? '' )
			&& '' !== trim( (string) ( $item['actor_callback'] ?? '' ) )
			&& '' !== trim( (string) ( $item['request_id'] ?? '' ) )
			&& in_array( (string) ( $item['mutation_status'] ?? '' ), array( TraceEvent::MUTATION_OBSERVED, TraceEvent::MUTATION_CONFIRMED ), true )
			&& in_array( (string) ( $item['mutation_kind'] ?? '' ), array( 'asset_dequeued', 'asset_deregistered', 'asset_src_changed', 'asset_dependency_changed', 'asset_version_changed', 'asset_group_changed', 'asset_media_changed', 'callback_removed', 'callback_replaced' ), true );
	}
}
