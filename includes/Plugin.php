<?php
/**
 * Main plugin composition root.
 *
 * @package PluginConflictDebugger
 */

declare(strict_types=1);

namespace PluginConflictDebugger;

use PluginConflictDebugger\Admin\Assets;
use PluginConflictDebugger\Admin\DashboardPage;
use PluginConflictDebugger\Admin\Notices;
use PluginConflictDebugger\Core\AssetLifecycleTracer;
use PluginConflictDebugger\Core\ConflictDetector;
use PluginConflictDebugger\Core\DiagnosticSessionRepository;
use PluginConflictDebugger\Core\Environment;
use PluginConflictDebugger\Core\ErrorCollector;
use PluginConflictDebugger\Core\FindingPolicy;
use PluginConflictDebugger\Core\Heuristics;
use PluginConflictDebugger\Core\LogLocator;
use PluginConflictDebugger\Core\RegistrySnapshot;
use PluginConflictDebugger\Core\ResultsRepository;
use PluginConflictDebugger\Core\ScanComparator;
use PluginConflictDebugger\Core\TraceAnalyzer;
use PluginConflictDebugger\Core\RuntimeTelemetry;
use PluginConflictDebugger\Core\RuntimeTelemetryRepository;
use PluginConflictDebugger\Core\RuntimeMutationTracker;
use PluginConflictDebugger\Core\ScanStateRepository;
use PluginConflictDebugger\Core\Scanner;
use PluginConflictDebugger\Core\ValidationModeRepository;
use PluginConflictDebugger\Support\Capabilities;
use PluginConflictDebugger\Support\Logger;
use PluginConflictDebugger\Support\PluginChangeTracker;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {
	/**
	 * Boots the plugin.
	 *
	 * @return void
	 */
	public function boot(): void {
		$capabilities = new Capabilities();
		$repository   = new ResultsRepository();
		$telemetry    = new RuntimeTelemetryRepository();
		$scan_state   = new ScanStateRepository();
		$sessions     = new DiagnosticSessionRepository();
		$validation   = new ValidationModeRepository();
		$registry     = new RegistrySnapshot();
		$logger       = new Logger();
		$tracker      = new PluginChangeTracker();
		$environment  = new Environment();
		$traces       = new TraceAnalyzer();
		$heuristics   = new Heuristics();
		$policy       = new FindingPolicy( $heuristics );
		$log_locator  = new LogLocator();
		$comparator   = new ScanComparator();
		$collector    = new ErrorCollector( $logger, $telemetry, $sessions, $validation, $log_locator );
		$detector     = new ConflictDetector( $heuristics, $registry, $policy );
		$scanner      = new Scanner( $environment, $collector, $detector, $repository, $tracker, $traces, $validation );
		$runtime      = new RuntimeTelemetry( $telemetry, $registry, $sessions, $validation );
		$asset_tracer = new AssetLifecycleTracer( $telemetry, $registry, $sessions, $validation );
		$mutations    = new RuntimeMutationTracker( $telemetry, $sessions, $validation );

		$assets       = new Assets();
		$dashboard    = new DashboardPage( $scanner, $repository, $scan_state, $sessions, $validation, $capabilities, $traces, $comparator );
		$notices      = new Notices( $repository, $capabilities );

		$assets->register();
		$dashboard->register();
		$notices->register();
		$tracker->register();
		$registry->register();
		$runtime->register();
		$asset_tracer->register();
		$mutations->register();
	}

	/**
	 * Cancels all queued scan tokens when diagnostics are disabled.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		wp_unschedule_hook( 'pcd_run_scan_async' );
		delete_option( 'pcd_scan_state' );
		delete_option( 'pcd_active_diagnostic_session' );
		delete_option( 'pcd_active_validation_mode' );
	}
}
