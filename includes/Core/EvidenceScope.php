<?php
/**
 * Selects one coherent resource/request case instead of pooling unrelated clues.
 *
 * @package PluginConflictDebugger
 */

declare(strict_types=1);

namespace PluginConflictDebugger\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EvidenceScope {
	/**
	 * Returns the strongest coherent case with its primary observation first.
	 *
	 * @param array<int, array<string, mixed>> $items Normalized evidence.
	 * @param Heuristics                      $heuristics Scoring service.
	 * @return array<int, array<string, mixed>>
	 */
	public function select( array $items, Heuristics $heuristics ): array {
		$best = array();
		$best_rank = -1;
		$best_score = -1;
		$seen = array();
		$ranks = array( 'noise' => 0, 'supporting' => 1, 'strong_proof' => 2, 'runtime_breakage' => 3 );
		foreach ( $items as $anchor ) {
			$key = wp_json_encode( array( $anchor['request_context'] ?? '', $anchor['shared_resource'] ?? '', $anchor['request_id'] ?? '' ) );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$candidate = array();
			foreach ( $items as $item ) {
				$context = (string) ( $item['request_context'] ?? '' );
				$generic = in_array( $context, array( '', 'runtime', 'generic site behavior' ), true );
				if ( $generic && 'noise' === ( $item['tier'] ?? '' ) ) {
					$candidate[] = $item;
					continue;
				}
				if ( $context !== (string) ( $anchor['request_context'] ?? '' ) ) {
					continue;
				}
				$resource = (string) ( $item['shared_resource'] ?? '' );
				$request = (string) ( $item['request_id'] ?? '' );
				if ( '' !== $resource && $resource !== (string) ( $anchor['shared_resource'] ?? '' ) ) {
					continue;
				}
				if ( '' !== $request && $request !== (string) ( $anchor['request_id'] ?? '' ) ) {
					continue;
				}
				$candidate[] = $item;
			}
			$rank = 0;
			foreach ( $candidate as $item ) {
				$rank = max( $rank, $ranks[ $item['tier'] ?? 'noise' ] ?? 0 );
			}
			$score = $heuristics->score_evidence_items( $candidate );
			if ( $rank > $best_rank || ( $rank === $best_rank && $score > $best_score ) ) {
				$best = $candidate;
				$best_rank = $rank;
				$best_score = $score;
			}
		}
		usort(
			$best,
			static function ( array $left, array $right ) use ( $ranks ): int {
				return ( $ranks[ $right['tier'] ?? 'noise' ] ?? 0 ) <=> ( $ranks[ $left['tier'] ?? 'noise' ] ?? 0 )
					?: ( ! empty( $right['shared_resource'] ) <=> ! empty( $left['shared_resource'] ) );
			}
		);
		return $best;
	}
}
