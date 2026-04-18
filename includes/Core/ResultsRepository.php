<?php
/**
 * Scan result storage abstraction.
 *
 * @package PluginConflictDebugger
 */

declare(strict_types=1);

namespace PluginConflictDebugger\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ResultsRepository {
	/**
	 * Option key for latest results.
	 */
	private const OPTION_KEY = 'pcd_latest_scan_result';

	/**
	 * Option key for scan history.
	 */
	private const HISTORY_OPTION_KEY = 'pcd_scan_history';

	/**
	 * Maximum number of historical scans to retain.
	 */
	private const MAX_HISTORY = 12;

	/**
	 * Stores results.
	 *
	 * @param array<string, mixed> $results Scan results.
	 * @return void
	 */
	public function save( array $results ): void {
		update_option( self::OPTION_KEY, $results, false );
		$this->append_history( $results );
	}

	/**
	 * Fetches the latest stored result.
	 *
	 * @return array<string, mixed>
	 */
	public function get_latest(): array {
		$results = get_option( self::OPTION_KEY, array() );
		return is_array( $results ) ? $results : array();
	}

	/**
	 * Deletes stored results.
	 *
	 * @return void
	 */
	public function delete(): void {
		delete_option( self::OPTION_KEY );
		delete_option( self::HISTORY_OPTION_KEY );
	}

	/**
	 * Returns the option key for cleanup and extensions.
	 *
	 * @return string
	 */
	public static function option_key(): string {
		return self::OPTION_KEY;
	}

	/**
	 * Returns the scan history option key.
	 *
	 * @return string
	 */
	public static function history_option_key(): string {
		return self::HISTORY_OPTION_KEY;
	}

	/**
	 * Returns recent scan history.
	 *
	 * @param int $limit Maximum number of items.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_history( int $limit = self::MAX_HISTORY ): array {
		$history = get_option( self::HISTORY_OPTION_KEY, array() );
		$history = is_array( $history ) ? $history : array();

		return array_slice( $history, 0, max( 1, $limit ) );
	}

	/**
	 * Stores a compact history record for a scan.
	 *
	 * @param array<string, mixed> $results Full scan results.
	 * @return void
	 */
	private function append_history( array $results ): void {
		$history = $this->get_history( self::MAX_HISTORY );
		$trace_analyzer = new TraceAnalyzer();

		$entry = array(
			'scan_timestamp' => sanitize_text_field( (string) ( $results['scan_timestamp'] ?? current_time( 'mysql' ) ) ),
			'site_status'    => sanitize_key( (string) ( $results['site_status'] ?? 'healthy' ) ),
			'summary'        => array(
				'active_plugins'   => (int) ( $results['summary']['active_plugins'] ?? 0 ),
				'error_signals'    => (int) ( $results['summary']['error_signals'] ?? 0 ),
				'trace_warnings'   => (int) ( $results['summary']['trace_warnings'] ?? 0 ),
				'likely_conflicts' => (int) ( $results['summary']['likely_conflicts'] ?? 0 ),
				'recent_changes'   => (int) ( $results['summary']['recent_changes'] ?? 0 ),
			),
			'severity_counts' => is_array( $results['severity_counts'] ?? null ) ? $results['severity_counts'] : array(),
			'log_access'      => is_array( $results['log_access'] ?? null ) ? $results['log_access'] : array(),
			'findings_snapshot' => $this->build_findings_snapshot( is_array( $results['findings'] ?? null ) ? $results['findings'] : array() ),
			'plugins_snapshot'  => $this->build_plugins_snapshot( is_array( $results['plugins'] ?? null ) ? $results['plugins'] : array() ),
			'trace_snapshot'    => $trace_analyzer->build_history_snapshot( is_array( $results['trace_snapshot'] ?? null ) ? $results['trace_snapshot'] : array() ),
		);

		array_unshift( $history, $entry );
		update_option( self::HISTORY_OPTION_KEY, array_slice( $history, 0, self::MAX_HISTORY ), false );
	}

	/**
	 * Builds a compact history-safe finding snapshot.
	 *
	 * @param array<int, array<string, mixed>> $findings Findings.
	 * @return array<int, array<string, mixed>>
	 */
	private function build_findings_snapshot( array $findings ): array {
		$snapshot = array();

		foreach ( array_slice( $findings, 0, 25 ) as $finding ) {
			$snapshot[] = array(
				'signature'             => $this->finding_signature( $finding ),
				'title'                 => sanitize_text_field( (string) ( $finding['title'] ?? '' ) ),
				'severity'              => sanitize_key( (string) ( $finding['severity'] ?? 'info' ) ),
				'confidence'            => (int) ( $finding['confidence'] ?? 0 ),
				'primary_plugin_name'   => sanitize_text_field( (string) ( $finding['primary_plugin_name'] ?? '' ) ),
				'secondary_plugin_name' => sanitize_text_field( (string) ( $finding['secondary_plugin_name'] ?? '' ) ),
				'request_context'       => sanitize_text_field( (string) ( $finding['request_context'] ?? '' ) ),
				'finding_type'          => sanitize_key( (string) ( $finding['finding_type'] ?? '' ) ),
			);
		}

		return $snapshot;
	}

	/**
	 * Builds a compact plugin snapshot for history.
	 *
	 * @param array<int, array<string, mixed>> $plugins Plugins.
	 * @return array<int, array<string, mixed>>
	 */
	private function build_plugins_snapshot( array $plugins ): array {
		$snapshot = array();

		foreach ( $plugins as $plugin ) {
			$snapshot[] = array(
				'slug'       => sanitize_key( (string) ( $plugin['slug'] ?? '' ) ),
				'name'       => sanitize_text_field( (string) ( $plugin['name'] ?? '' ) ),
				'version'    => sanitize_text_field( (string) ( $plugin['version'] ?? '' ) ),
				'categories' => is_array( $plugin['categories'] ?? null ) ? array_values( array_map( 'sanitize_key', $plugin['categories'] ) ) : array(),
			);
		}

		return $snapshot;
	}

	/**
	 * Builds a stable finding signature for scan comparisons.
	 *
	 * @param array<string, mixed> $finding Finding.
	 * @return string
	 */
	private function finding_signature( array $finding ): string {
		return md5(
			wp_json_encode(
				array(
					'primary_plugin'   => sanitize_key( (string) ( $finding['primary_plugin'] ?? '' ) ),
					'secondary_plugin' => sanitize_key( (string) ( $finding['secondary_plugin'] ?? '' ) ),
					'surface_key'      => sanitize_key( (string) ( $finding['surface_key'] ?? $finding['issue_category'] ?? '' ) ),
					'finding_type'     => sanitize_key( (string) ( $finding['finding_type'] ?? '' ) ),
					'request_context'  => sanitize_text_field( (string) ( $finding['request_context'] ?? '' ) ),
					'shared_resource'  => sanitize_text_field( (string) ( $finding['shared_resource'] ?? '' ) ),
					'execution_surface'=> sanitize_text_field( (string) ( $finding['execution_surface'] ?? '' ) ),
				)
			)
		);
	}
}
