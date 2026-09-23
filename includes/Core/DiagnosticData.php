<?php
/**
 * Shared diagnostic cleanup for the dashboard and uninstall.
 *
 * @package PluginConflictDebugger
 */

declare(strict_types=1);

namespace PluginConflictDebugger\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DiagnosticData {
	/**
	 * Deletes this site's diagnostic data without touching server log files.
	 *
	 * @return void
	 */
	public static function clear(): void {
		wp_unschedule_hook( 'pcd_run_scan_async' );
		foreach ( array(
			'pcd_latest_scan_result', 'pcd_scan_history', 'pcd_runtime_log',
			'pcd_recent_plugin_changes', 'pcd_scan_state', 'pcd_admin_menu_snapshot',
			'pcd_runtime_events', 'pcd_request_contexts', 'pcd_shortcode_snapshot',
			'pcd_block_snapshot', 'pcd_ajax_action_snapshot', 'pcd_asset_handle_snapshot',
			'pcd_active_diagnostic_session', 'pcd_last_diagnostic_session',
			'pcd_active_validation_mode', 'pcd_last_validation_mode',
		) as $option ) {
			delete_option( $option );
		}
	}
}
