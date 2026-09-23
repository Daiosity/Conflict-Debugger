<?php
/**
 * Stable finding identity and history snapshots.
 *
 * @package PluginConflictDebugger
 */

declare(strict_types=1);

namespace PluginConflictDebugger\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class FindingSignature {
	/**
	 * Builds an identity that remains stable when severity or category changes.
	 *
	 * @param array<string, mixed> $finding Finding data.
	 * @return string
	 */
	public static function from_finding( array $finding ): string {
		$plugins = array_filter(
			array(
				sanitize_key( (string) ( $finding['primary_plugin'] ?? '' ) ),
				sanitize_key( (string) ( $finding['secondary_plugin'] ?? '' ) ),
			)
		);
		sort( $plugins, SORT_STRING );

		return md5(
			wp_json_encode(
				array(
					'plugins'          => array_values( $plugins ),
					'surface_key'      => sanitize_key( (string) ( $finding['surface_key'] ?? $finding['issue_category'] ?? '' ) ),
					'request_context'  => sanitize_text_field( (string) ( $finding['request_context'] ?? '' ) ),
					'shared_resource'  => sanitize_text_field( (string) ( $finding['shared_resource'] ?? '' ) ),
					'execution_surface' => sanitize_text_field( (string) ( $finding['execution_surface'] ?? '' ) ),
				)
			)
		);
	}

	/**
	 * Builds a compact history-safe snapshot.
	 *
	 * @param array<string, mixed> $finding Finding data.
	 * @return array<string, mixed>
	 */
	public static function snapshot( array $finding ): array {
		return array(
			'identity'              => self::from_finding( $finding ),
			'signature'             => self::from_finding( $finding ),
			'title'                 => sanitize_text_field( (string) ( $finding['title'] ?? '' ) ),
			'severity'              => sanitize_key( (string) ( $finding['severity'] ?? 'info' ) ),
			'confidence'            => min( 100, max( 0, (int) ( $finding['confidence'] ?? 0 ) ) ),
			'category'              => sanitize_key( (string) ( $finding['category'] ?? $finding['finding_type'] ?? '' ) ),
			'finding_type'          => sanitize_key( (string) ( $finding['finding_type'] ?? $finding['category'] ?? '' ) ),
			'primary_plugin'        => sanitize_key( (string) ( $finding['primary_plugin'] ?? '' ) ),
			'primary_plugin_name'   => sanitize_text_field( (string) ( $finding['primary_plugin_name'] ?? '' ) ),
			'secondary_plugin'      => sanitize_key( (string) ( $finding['secondary_plugin'] ?? '' ) ),
			'secondary_plugin_name' => sanitize_text_field( (string) ( $finding['secondary_plugin_name'] ?? '' ) ),
			'surface_key'           => sanitize_key( (string) ( $finding['surface_key'] ?? $finding['issue_category'] ?? '' ) ),
			'surface_label'         => sanitize_text_field( (string) ( $finding['surface_label'] ?? '' ) ),
			'request_context'       => sanitize_text_field( (string) ( $finding['request_context'] ?? '' ) ),
			'shared_resource'       => sanitize_text_field( (string) ( $finding['shared_resource'] ?? '' ) ),
			'execution_surface'     => sanitize_text_field( (string) ( $finding['execution_surface'] ?? '' ) ),
		);
	}

	/**
	 * Returns an identity from a current or legacy history snapshot.
	 *
	 * @param array<string, mixed> $snapshot Finding snapshot.
	 * @return string
	 */
	public static function from_snapshot( array $snapshot ): string {
		$identity = sanitize_text_field( (string) ( $snapshot['identity'] ?? '' ) );

		if ( '' !== $identity ) {
			return $identity;
		}

		return sanitize_text_field( (string) ( $snapshot['signature'] ?? '' ) );
	}
}
