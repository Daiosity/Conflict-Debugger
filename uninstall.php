<?php
/**
 * Cleanup on uninstall.
 *
 * @package PluginConflictDebugger
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/Core/DiagnosticData.php';

( static function (): void {
if ( is_multisite() ) {
	$pcd_offset = 0;
	do {
		$pcd_site_ids = get_sites( array( 'fields' => 'ids', 'number' => 100, 'offset' => $pcd_offset ) );
		foreach ( $pcd_site_ids as $pcd_site_id ) {
			switch_to_blog( (int) $pcd_site_id );
			\PluginConflictDebugger\Core\DiagnosticData::clear();
			restore_current_blog();
		}
		$pcd_offset += 100;
	} while ( count( $pcd_site_ids ) === 100 );
} else {
	\PluginConflictDebugger\Core\DiagnosticData::clear();
}
} )();
