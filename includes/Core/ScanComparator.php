<?php
/**
 * Compares the latest scan with the previous stored scan.
 *
 * @package PluginConflictDebugger
 */

declare(strict_types=1);

namespace PluginConflictDebugger\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ScanComparator {
	/**
	 * Compares current results with the previous history entry.
	 *
	 * @param array<string, mixed>             $results Latest results.
	 * @param array<int, array<string, mixed>> $history Scan history.
	 * @return array<string, mixed>
	 */
	public function compare( array $results, array $history ): array {
		$previous_entry = is_array( $history[1] ?? null ) ? $history[1] : array();

		if ( empty( $previous_entry ) ) {
			return array( 'has_previous' => false );
		}

		$current_snapshot  = $this->snapshot_current_findings( $results );
		$previous_snapshot = is_array( $previous_entry['findings_snapshot'] ?? null ) ? $previous_entry['findings_snapshot'] : array();
		$current_map       = $this->index_snapshot( $current_snapshot );
		$previous_map      = $this->index_snapshot( $previous_snapshot );
		$new_findings      = array_values( array_diff_key( $current_map, $previous_map ) );
		$resolved_findings = array_values( array_diff_key( $previous_map, $current_map ) );
		$changed_findings  = array();

		foreach ( array_intersect_key( $current_map, $previous_map ) as $identity => $current ) {
			$previous = $previous_map[ $identity ];

			if ( ! $this->has_meaningful_change( $current, $previous ) ) {
				continue;
			}

			$changed_findings[] = array(
				'identity' => $identity,
				'current'  => $current,
				'previous' => $previous,
			);
		}

		usort(
			$changed_findings,
			static fn( array $left, array $right ): int => abs( (int) ( $right['current']['confidence'] ?? 0 ) - (int) ( $right['previous']['confidence'] ?? 0 ) ) <=> abs( (int) ( $left['current']['confidence'] ?? 0 ) - (int) ( $left['previous']['confidence'] ?? 0 ) )
		);

		return array(
			'has_previous'       => true,
			'current_timestamp'  => sanitize_text_field( (string) ( $results['scan_timestamp'] ?? '' ) ),
			'previous_timestamp' => sanitize_text_field( (string) ( $previous_entry['scan_timestamp'] ?? '' ) ),
			'current_conflicts'  => (int) ( $results['summary']['likely_conflicts'] ?? 0 ),
			'previous_conflicts' => (int) ( $previous_entry['summary']['likely_conflicts'] ?? 0 ),
			'new_findings'       => array_slice( $new_findings, 0, 8 ),
			'resolved_findings'  => array_slice( $resolved_findings, 0, 8 ),
			'changed_findings'   => array_slice( $changed_findings, 0, 8 ),
		);
	}

	/**
	 * Builds current finding snapshots.
	 *
	 * @param array<string, mixed> $results Latest results.
	 * @return array<int, array<string, mixed>>
	 */
	private function snapshot_current_findings( array $results ): array {
		$findings = is_array( $results['findings'] ?? null ) ? $results['findings'] : array();

		return array_map(
			static fn( array $finding ): array => FindingSignature::snapshot( $finding ),
			array_slice( $findings, 0, 25 )
		);
	}

	/**
	 * Indexes a snapshot by stable identity.
	 *
	 * @param array<int, array<string, mixed>> $snapshot Snapshot rows.
	 * @return array<string, array<string, mixed>>
	 */
	private function index_snapshot( array $snapshot ): array {
		$indexed = array();

		foreach ( $snapshot as $item ) {
			$identity = FindingSignature::from_snapshot( $item );
			if ( '' !== $identity ) {
				$indexed[ $identity ] = $item;
			}
		}

		return $indexed;
	}

	/**
	 * Checks for a category, severity, or meaningful confidence change.
	 *
	 * @param array<string, mixed> $current Current finding.
	 * @param array<string, mixed> $previous Previous finding.
	 * @return bool
	 */
	private function has_meaningful_change( array $current, array $previous ): bool {
		if ( (string) ( $current['severity'] ?? '' ) !== (string) ( $previous['severity'] ?? '' ) ) {
			return true;
		}

		if ( (string) ( $current['category'] ?? '' ) !== (string) ( $previous['category'] ?? '' ) ) {
			return true;
		}

		return abs( (int) ( $current['confidence'] ?? 0 ) - (int) ( $previous['confidence'] ?? 0 ) ) >= 5;
	}
}
