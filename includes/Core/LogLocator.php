<?php
/**
 * Resolves readable WordPress and PHP log files.
 *
 * @package PluginConflictDebugger
 */

declare(strict_types=1);

namespace PluginConflictDebugger\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class LogLocator {
	/**
	 * Returns candidate logs and the best readable source.
	 *
	 * @return array<string, mixed>
	 */
	public function inspect(): array {
		$candidates = $this->build_candidates();
		$selected   = array();

		foreach ( $candidates as $candidate ) {
			if ( ! empty( $candidate['readable'] ) ) {
				$selected = $candidate;
				break;
			}
		}

		if ( empty( $selected ) && ! empty( $candidates ) ) {
			$selected = $candidates[0];
		}

		return array(
			'selected'   => $selected,
			'candidates' => $candidates,
		);
	}

	/**
	 * Builds normalized log candidates in priority order.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function build_candidates(): array {
		$paths = array();

		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			$paths[] = array(
				'source' => 'wordpress_debug_log',
				'label'  => __( 'WordPress debug log', 'daiosity-conflict-debugger' ),
				'path'   => is_string( WP_DEBUG_LOG ) ? WP_DEBUG_LOG : trailingslashit( WP_CONTENT_DIR ) . 'debug.log',
			);
		}

		$php_error_log = trim( (string) ini_get( 'error_log' ) );
		if ( '' !== $php_error_log && ! in_array( strtolower( $php_error_log ), array( 'syslog', 'stderr' ), true ) ) {
			$paths[] = array(
				'source' => 'php_error_log',
				'label'  => __( 'PHP error log', 'daiosity-conflict-debugger' ),
				'path'   => $php_error_log,
			);
		}

		/**
		 * Filters additional local log paths available to diagnostics.
		 *
		 * Each item may be a path string or an array containing source, label,
		 * and path keys. Remote URLs are ignored.
		 *
		 * @param array<int, array<string, string>|string> $paths Candidate paths.
		 */
		$paths = apply_filters( 'PluginConflictDebugger/log_paths', $paths );
		$paths = is_array( $paths ) ? $paths : array();
		$seen  = array();
		$items = array();

		foreach ( $paths as $index => $candidate ) {
			if ( is_string( $candidate ) ) {
				$candidate = array(
					'source' => 'custom_log',
					'label'  => __( 'Custom diagnostic log', 'daiosity-conflict-debugger' ),
					'path'   => $candidate,
				);
			}

			if ( ! is_array( $candidate ) ) {
				continue;
			}

			$path = $this->normalize_local_path( (string) ( $candidate['path'] ?? '' ) );
			if ( '' === $path || isset( $seen[ strtolower( $path ) ] ) ) {
				continue;
			}

			$seen[ strtolower( $path ) ] = true;
			$exists                       = is_file( $path );
			$items[]                      = array(
				'source'   => sanitize_key( (string) ( $candidate['source'] ?? 'custom_log' ) ),
				'label'    => sanitize_text_field( (string) ( $candidate['label'] ?? __( 'Diagnostic log', 'daiosity-conflict-debugger' ) ) ),
				'path'     => $path,
				'exists'   => $exists,
				'readable' => $exists && is_readable( $path ),
				'writable' => $exists ? wp_is_writable( $path ) : wp_is_writable( dirname( $path ) ),
				'priority' => (int) $index,
			);
		}

		return $items;
	}

	/**
	 * Normalizes a local filesystem path and rejects URLs or stream wrappers.
	 *
	 * @param string $path Candidate path.
	 * @return string
	 */
	private function normalize_local_path( string $path ): string {
		$path = trim( $path );
		if ( '' === $path || preg_match( '#^[a-z][a-z0-9+.-]*://#i', $path ) ) {
			return '';
		}

		if ( ! wp_is_stream( $path ) && ! str_starts_with( $path, '/' ) && ! preg_match( '/^[A-Za-z]:[\\\\\/]/', $path ) ) {
			$path = trailingslashit( ABSPATH ) . ltrim( $path, '/\\' );
		}

		return wp_normalize_path( $path );
	}
}
