<?php
/**
 * Stores lightweight runtime telemetry and request contexts.
 *
 * @package PluginConflictDebugger
 */

declare(strict_types=1);

namespace PluginConflictDebugger\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RuntimeTelemetryRepository {
	/**
	 * Recent event option key.
	 */
	private const EVENTS_OPTION = 'pcd_runtime_events';

	/**
	 * Recent request context option key.
	 */
	private const CONTEXTS_OPTION = 'pcd_request_contexts';

	/**
	 * Maximum stored events.
	 */
	private const MAX_EVENTS = 50;

	/**
	 * Maximum stored request contexts.
	 */
	private const MAX_CONTEXTS = 40;

	/**
	 * Stores an observed runtime event.
	 *
	 * @param array<string, mixed> $event Runtime event payload.
	 * @return void
	 */
	public function record_event( array $event ): void {
		$events      = $this->get_events( self::MAX_EVENTS );
		$event       = $this->sanitize_event( $event );
		$fingerprint = $this->build_fingerprint( $event );

		foreach ( $events as $existing ) {
			if ( $fingerprint !== $this->build_fingerprint( $existing ) ) {
				continue;
			}

			$event_time    = strtotime( (string) ( $event['timestamp'] ?? '' ) );
			$existing_time = strtotime( (string) ( $existing['timestamp'] ?? '' ) );

			if ( false !== $event_time && false !== $existing_time && abs( $event_time - $existing_time ) < 60 ) {
				return;
			}
		}

		array_unshift( $events, $event );
		update_option( self::EVENTS_OPTION, array_slice( $events, 0, self::MAX_EVENTS ), false );
	}

	/**
	 * Stores a recent request context snapshot.
	 *
	 * @param array<string, mixed> $context Request context.
	 * @return void
	 */
	public function record_request_context( array $context ): void {
		$contexts    = $this->get_request_contexts( self::MAX_CONTEXTS );
		$context     = $this->sanitize_context( $context );
		$fingerprint = $this->build_fingerprint( $context );

		foreach ( $contexts as $existing ) {
			if ( $fingerprint !== $this->build_fingerprint( $existing ) ) {
				continue;
			}

			$context_time  = strtotime( (string) ( $context['timestamp'] ?? '' ) );
			$existing_time = strtotime( (string) ( $existing['timestamp'] ?? '' ) );

			if ( false !== $context_time && false !== $existing_time && abs( $context_time - $existing_time ) < 30 ) {
				return;
			}
		}

		array_unshift( $contexts, $context );
		update_option( self::CONTEXTS_OPTION, array_slice( $contexts, 0, self::MAX_CONTEXTS ), false );
	}

	/**
	 * Returns recent runtime events.
	 *
	 * @param int $limit Event limit.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_events( int $limit = 20 ): array {
		$events = get_option( self::EVENTS_OPTION, array() );
		$events = is_array( $events ) ? $events : array();

		return array_slice( $events, 0, max( 1, $limit ) );
	}

	/**
	 * Returns recent request contexts.
	 *
	 * @param int $limit Context limit.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_request_contexts( int $limit = 10 ): array {
		$contexts = get_option( self::CONTEXTS_OPTION, array() );
		$contexts = is_array( $contexts ) ? $contexts : array();

		return array_slice( $contexts, 0, max( 1, $limit ) );
	}

	/**
	 * Removes stored telemetry options.
	 *
	 * @return void
	 */
	public function delete(): void {
		delete_option( self::EVENTS_OPTION );
		delete_option( self::CONTEXTS_OPTION );
	}

	/**
	 * Sanitizes an event payload.
	 *
	 * @param array<string, mixed> $event Event payload.
	 * @return array<string, mixed>
	 */
	private function sanitize_event( array $event ): array {
		return array(
			'timestamp'       => sanitize_text_field( (string) ( $event['timestamp'] ?? current_time( 'mysql' ) ) ),
			'type'            => sanitize_key( (string) ( $event['type'] ?? 'runtime' ) ),
			'level'           => sanitize_key( (string) ( $event['level'] ?? 'info' ) ),
			'message'         => sanitize_textarea_field( (string) ( $event['message'] ?? '' ) ),
			'request_context' => sanitize_text_field( (string) ( $event['request_context'] ?? '' ) ),
			'request_uri'     => $this->sanitize_uri( (string) ( $event['request_uri'] ?? '' ) ),
			'source'          => sanitize_text_field( (string) ( $event['source'] ?? '' ) ),
			'resource'        => sanitize_text_field( (string) ( $event['resource'] ?? '' ) ),
			'execution_surface' => sanitize_text_field( (string) ( $event['execution_surface'] ?? '' ) ),
			'callback_identifier' => sanitize_text_field( (string) ( $event['callback_identifier'] ?? '' ) ),
			'failure_mode'    => sanitize_key( (string) ( $event['failure_mode'] ?? '' ) ),
			'mutation_kind'   => sanitize_key( (string) ( $event['mutation_kind'] ?? '' ) ),
			'status_code'     => (int) ( $event['status_code'] ?? 0 ),
			'session_id'      => sanitize_text_field( (string) ( $event['session_id'] ?? '' ) ),
			'resource_hints'  => $this->sanitize_resource_hints( $event['resource_hints'] ?? array() ),
			'owner_slugs'     => $this->sanitize_resource_hints( $event['owner_slugs'] ?? array() ),
		);
	}

	/**
	 * Sanitizes a request context payload.
	 *
	 * @param array<string, mixed> $context Context payload.
	 * @return array<string, mixed>
	 */
	private function sanitize_context( array $context ): array {
		return array(
			'timestamp'       => sanitize_text_field( (string) ( $context['timestamp'] ?? current_time( 'mysql' ) ) ),
			'request_context' => sanitize_text_field( (string) ( $context['request_context'] ?? '' ) ),
			'request_uri'     => $this->sanitize_uri( (string) ( $context['request_uri'] ?? '' ) ),
			'screen_id'       => sanitize_key( (string) ( $context['screen_id'] ?? '' ) ),
			'ajax_action'     => sanitize_key( (string) ( $context['ajax_action'] ?? '' ) ),
			'rest_route'      => sanitize_text_field( (string) ( $context['rest_route'] ?? '' ) ),
			'resource'        => sanitize_text_field( (string) ( $context['resource'] ?? '' ) ),
			'session_id'      => sanitize_text_field( (string) ( $context['session_id'] ?? '' ) ),
			'resource_hints'  => $this->sanitize_resource_hints( $context['resource_hints'] ?? array() ),
		);
	}

	/**
	 * Creates a simple deduplication fingerprint.
	 *
	 * @param array<string, mixed> $payload Payload.
	 * @return string
	 */
	private function build_fingerprint( array $payload ): string {
		return md5(
			wp_json_encode(
				array(
					'type'            => (string) ( $payload['type'] ?? '' ),
					'message'         => (string) ( $payload['message'] ?? '' ),
					'request_context' => (string) ( $payload['request_context'] ?? '' ),
					'request_uri'     => (string) ( $payload['request_uri'] ?? '' ),
					'resource'        => (string) ( $payload['resource'] ?? '' ),
					'execution_surface' => (string) ( $payload['execution_surface'] ?? '' ),
					'resource_hints'  => implode( ',', (array) ( $payload['resource_hints'] ?? array() ) ),
				)
			)
		);
	}

	/**
	 * Sanitizes resource hint arrays.
	 *
	 * @param mixed $resource_hints Resource hints.
	 * @return string[]
	 */
	private function sanitize_resource_hints( mixed $resource_hints ): array {
		if ( ! is_array( $resource_hints ) ) {
			return array();
		}

		return array_values(
			array_unique(
				array_filter(
					array_map(
						static fn( $hint ): string => sanitize_text_field( (string) $hint ),
						$resource_hints
					)
				)
			)
		);
	}

	/**
	 * Sanitizes a request URI to a relative path.
	 *
	 * @param string $uri URI string.
	 * @return string
	 */
	private function sanitize_uri( string $uri ): string {
		$path = wp_parse_url( $uri, PHP_URL_PATH );
		$query = wp_parse_url( $uri, PHP_URL_QUERY );

		if ( ! is_string( $path ) ) {
			$path = $uri;
		}

		$sanitized = '/' . ltrim( sanitize_text_field( $path ), '/' );
		if ( is_string( $query ) && '' !== $query ) {
			$sanitized .= '?' . sanitize_text_field( $query );
		}

		return $sanitized;
	}
}
