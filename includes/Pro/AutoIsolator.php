<?php
/**
 * Auto-isolator placeholder.
 *
 * @package PluginConflictDebugger
 */

declare(strict_types=1);

namespace PluginConflictDebugger\Pro;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AutoIsolator {
	/**
	 * Returns placeholder algorithm notes.
	 *
	 * @return array<string, mixed>
	 */
	public function describe(): array {
		return array(
			'available' => false,
			'title'     => __( 'Auto-Isolate Conflict', 'plugin-conflict-debugger' ),
			'message'   => __( 'Planned for premium. The intended approach is a binary-search plugin toggling workflow that should only run in a safe environment.', 'plugin-conflict-debugger' ),
			'steps'     => array(
				__( 'Snapshot the current active plugin set.', 'plugin-conflict-debugger' ),
				__( 'Split candidates into smaller groups for isolated testing.', 'plugin-conflict-debugger' ),
				__( 'Repeat until the smallest reproducible plugin set remains.', 'plugin-conflict-debugger' ),
			),
		);
	}
}
