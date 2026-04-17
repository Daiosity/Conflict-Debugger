<?php
/**
 * Shared trace event constants and per-request identifiers.
 *
 * @package PluginConflictDebugger
 */

declare(strict_types=1);

namespace PluginConflictDebugger\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TraceEvent {
	/**
	 * Attribution statuses.
	 */
	public const ATTRIBUTION_UNKNOWN = 'attribution_unknown';
	public const ATTRIBUTION_PARTIAL = 'attribution_partial';
	public const ATTRIBUTION_DIRECT  = 'attribution_direct';

	/**
	 * Contamination statuses.
	 */
	public const CONTAMINATION_NONE     = 'contamination_none';
	public const CONTAMINATION_POSSIBLE = 'contamination_possible';
	public const CONTAMINATION_HIGH     = 'contamination_high';

	/**
	 * Mutation statuses.
	 */
	public const MUTATION_NONE      = 'mutation_none';
	public const MUTATION_SUSPECTED = 'mutation_suspected';
	public const MUTATION_OBSERVED  = 'mutation_observed';
	public const MUTATION_CONFIRMED = 'mutation_confirmed';

	/**
	 * Evidence sources.
	 */
	public const SOURCE_RUNTIME = 'runtime';
	public const SOURCE_CLIENT  = 'client';
	public const SOURCE_TRACE   = 'trace';

	/**
	 * Current request identifier cache.
	 *
	 * @var string|null
	 */
	private static ?string $request_id = null;

	/**
	 * Per-request event sequence.
	 *
	 * @var int
	 */
	private static int $sequence = 0;

	/**
	 * Returns a stable request id for the current PHP request.
	 *
	 * @return string
	 */
	public static function current_request_id(): string {
		if ( null === self::$request_id ) {
			self::$request_id = wp_generate_uuid4();
		}

		return self::$request_id;
	}

	/**
	 * Returns a unique event id.
	 *
	 * @return string
	 */
	public static function new_event_id(): string {
		return wp_generate_uuid4();
	}

	/**
	 * Returns the next sequence number for the current request.
	 *
	 * @return int
	 */
	public static function next_sequence(): int {
		self::$sequence++;

		return self::$sequence;
	}

	/**
	 * Sanitizes a nested state payload.
	 *
	 * @param mixed $state State payload.
	 * @return array<string, mixed>
	 */
	public static function sanitize_state( mixed $state ): array {
		if ( ! is_array( $state ) ) {
			return array();
		}

		$normalized = array();

		foreach ( $state as $key => $value ) {
			$sanitized_key = sanitize_key( (string) $key );

			if ( is_array( $value ) ) {
				$normalized[ $sanitized_key ] = array_values(
					array_map(
						static fn( $item ) => is_scalar( $item ) ? sanitize_text_field( (string) $item ) : sanitize_text_field( wp_json_encode( $item ) ),
						$value
					)
				);
				continue;
			}

			if ( is_bool( $value ) ) {
				$normalized[ $sanitized_key ] = $value;
				continue;
			}

			if ( is_numeric( $value ) ) {
				$normalized[ $sanitized_key ] = (string) $value;
				continue;
			}

			$normalized[ $sanitized_key ] = sanitize_text_field( (string) $value );
		}

		return $normalized;
	}
}
