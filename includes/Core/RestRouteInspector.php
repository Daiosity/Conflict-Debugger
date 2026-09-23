<?php
/**
 * Read-only REST dispatch overlap analysis.
 *
 * @package PluginConflictDebugger
 */

declare(strict_types=1);

namespace PluginConflictDebugger\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RestRouteInspector {
	/**
	 * Finds overlapping methods without invoking endpoints or permission callbacks.
	 *
	 * @param array<int, array<string, mixed>> $handlers Registered handlers in dispatch order.
	 * @return array<int, array<string, mixed>>
	 */
	public function overlaps( array $handlers ): array {
		$results = array();
		foreach ( $handlers as $index => $first ) {
			foreach ( array_slice( $handlers, $index + 1 ) as $second ) {
				if ( empty( $first['owner_slug'] ) || empty( $second['owner_slug'] ) || $first['owner_slug'] === $second['owner_slug'] ) {
					continue;
				}
				$methods = array_values( array_intersect( $this->methods( $first['methods'] ?? array() ), $this->methods( $second['methods'] ?? array() ) ) );
				if ( empty( $methods ) ) {
					continue;
				}
				$results[] = array(
					'first_owner' => $first['owner_slug'],
					'next_owner'  => $second['owner_slug'],
					'methods'     => $methods,
				);
			}
		}
		return $results;
	}

	/**
	 * Normalizes method maps and models WordPress's per-handler HEAD fallback.
	 *
	 * @param mixed $methods REST method declaration.
	 * @return string[]
	 */
	private function methods( mixed $methods ): array {
		if ( is_string( $methods ) ) {
			$methods = explode( ',', $methods );
		}
		$result = array();
		foreach ( (array) $methods as $key => $value ) {
			if ( ! is_int( $key ) && ! $value ) {
				continue;
			}
			$method = strtoupper( trim( (string) ( is_int( $key ) ? $value : $key ) ) );
			if ( preg_match( '/^[A-Z]+$/', $method ) ) {
				$result[] = $method;
			}
		}
		if ( in_array( 'GET', $result, true ) ) {
			$result[] = 'HEAD';
		}
		$result = array_values( array_unique( $result ) );
		sort( $result );
		return $result;
	}
}
