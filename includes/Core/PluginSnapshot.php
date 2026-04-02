<?php
/**
 * Plugin metadata normalization.
 *
 * @package PluginConflictDebugger
 */

declare(strict_types=1);

namespace PluginConflictDebugger\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PluginSnapshot {
	/**
	 * Creates a normalized plugin array.
	 *
	 * @param string $plugin_file Plugin file path relative to plugins directory.
	 * @param array  $plugin_data Plugin header data.
	 * @param array  $categories Broad categories derived from heuristics.
	 * @param bool   $recently_changed Whether plugin appears recently activated or updated.
	 * @return array<string, mixed>
	 */
	public static function from_plugin_data( string $plugin_file, array $plugin_data, array $categories, bool $recently_changed ): array {
		$slug_parts = explode( '/', $plugin_file );
		$slug       = sanitize_key( $slug_parts[0] ?? $plugin_file );

		return array(
			'file'             => $plugin_file,
			'slug'             => $slug,
			'name'             => (string) ( $plugin_data['Name'] ?? $slug ),
			'version'          => (string) ( $plugin_data['Version'] ?? '' ),
			'author'           => wp_strip_all_tags( (string) ( $plugin_data['AuthorName'] ?? $plugin_data['Author'] ?? '' ) ),
			'active'           => true,
			'categories'       => array_values( array_unique( $categories ) ),
			'recently_changed' => $recently_changed,
		);
	}
}
