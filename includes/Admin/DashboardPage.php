<?php
/**
 * Dashboard page controller and renderer.
 *
 * @package PluginConflictDebugger
 */

declare(strict_types=1);

namespace PluginConflictDebugger\Admin;

use PluginConflictDebugger\Core\DiagnosticSessionRepository;
use PluginConflictDebugger\Core\ResultsRepository;
use PluginConflictDebugger\Core\ScanStateRepository;
use PluginConflictDebugger\Core\Scanner;
use PluginConflictDebugger\Support\Capabilities;
use PluginConflictDebugger\Support\Helpers;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DashboardPage {
	/**
	 * Async worker hook.
	 */
	private const ASYNC_SCAN_HOOK = 'pcd_run_scan_async';

	/**
	 * Scanner service.
	 *
	 * @var Scanner
	 */
	private Scanner $scanner;

	/**
	 * Results repository.
	 *
	 * @var ResultsRepository
	 */
	private ResultsRepository $repository;

	/**
	 * Scan state repository.
	 *
	 * @var ScanStateRepository
	 */
	private ScanStateRepository $scan_state;

	/**
	 * Diagnostic session repository.
	 *
	 * @var DiagnosticSessionRepository
	 */
	private DiagnosticSessionRepository $sessions;

	/**
	 * Capability service.
	 *
	 * @var Capabilities
	 */
	private Capabilities $capabilities;

	/**
	 * Constructor.
	 *
	 * @param Scanner             $scanner Scanner service.
	 * @param ResultsRepository   $repository Results repository.
	 * @param ScanStateRepository $scan_state Scan state repository.
	 * @param DiagnosticSessionRepository $sessions Diagnostic session repository.
	 * @param Capabilities        $capabilities Capability service.
	 */
	public function __construct( Scanner $scanner, ResultsRepository $repository, ScanStateRepository $scan_state, DiagnosticSessionRepository $sessions, Capabilities $capabilities ) {
		$this->scanner      = $scanner;
		$this->repository   = $repository;
		$this->scan_state   = $scan_state;
		$this->sessions     = $sessions;
		$this->capabilities = $capabilities;
	}

	/**
	 * Hooks admin menu and scan handlers.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_pcd_run_scan', array( $this, 'handle_scan' ) );
		add_action( 'wp_ajax_pcd_start_scan', array( $this, 'ajax_start_scan' ) );
		add_action( 'wp_ajax_pcd_get_scan_status', array( $this, 'ajax_get_scan_status' ) );
		add_action( 'wp_ajax_pcd_start_diagnostic_session', array( $this, 'ajax_start_diagnostic_session' ) );
		add_action( 'wp_ajax_pcd_end_diagnostic_session', array( $this, 'ajax_end_diagnostic_session' ) );
		add_action( self::ASYNC_SCAN_HOOK, array( $this, 'run_background_scan' ), 10, 1 );
	}

	/**
	 * Adds the tools submenu page.
	 *
	 * @return void
	 */
	public function add_menu(): void {
		add_submenu_page(
			'tools.php',
			__( 'Plugin Conflict Debugger', 'plugin-conflict-debugger' ),
			__( 'Plugin Conflict Debugger', 'plugin-conflict-debugger' ),
			$this->capabilities->required_capability(),
			'plugin-conflict-debugger',
			array( $this, 'render' )
		);
	}

	/**
	 * Handles manual scan requests for non-JS fallback.
	 *
	 * @return void
	 */
	public function handle_scan(): void {
		if ( ! $this->capabilities->can_manage() ) {
			wp_die( esc_html__( 'You are not allowed to run this scan.', 'plugin-conflict-debugger' ) );
		}

		check_admin_referer( 'pcd_run_scan_action', 'pcd_run_scan_nonce' );

		$this->start_background_scan();

		set_transient(
			'pcd_scan_notice',
			array(
				'type'    => 'info',
				'message' => __( 'Scan started in the background. You can stay on this page and watch progress.', 'plugin-conflict-debugger' ),
			),
			60
		);

		wp_safe_redirect( admin_url( 'tools.php?page=plugin-conflict-debugger' ) );
		exit;
	}

	/**
	 * AJAX endpoint for starting a background scan.
	 *
	 * @return void
	 */
	public function ajax_start_scan(): void {
		if ( ! $this->capabilities->can_manage() ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to run this scan.', 'plugin-conflict-debugger' ) ), 403 );
		}

		check_ajax_referer( 'pcd_scan_ajax', 'nonce' );

		$state = $this->start_background_scan();

		wp_send_json_success( $state );
	}

	/**
	 * AJAX endpoint for polling scan status.
	 *
	 * @return void
	 */
	public function ajax_get_scan_status(): void {
		if ( ! $this->capabilities->can_manage() ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to view scan status.', 'plugin-conflict-debugger' ) ), 403 );
		}

		check_ajax_referer( 'pcd_scan_ajax', 'nonce' );
		wp_send_json_success( $this->scan_state->get() );
	}

	/**
	 * AJAX endpoint for starting a focused diagnostic session.
	 *
	 * @return void
	 */
	public function ajax_start_diagnostic_session(): void {
		if ( ! $this->capabilities->can_manage() ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to manage diagnostic sessions.', 'plugin-conflict-debugger' ) ), 403 );
		}

		check_ajax_referer( 'pcd_scan_ajax', 'nonce' );

		$target_context = sanitize_key( (string) ( $_POST['target_context'] ?? 'all' ) );
		$session        = $this->sessions->start( $target_context );

		wp_send_json_success( $session );
	}

	/**
	 * AJAX endpoint for ending a focused diagnostic session.
	 *
	 * @return void
	 */
	public function ajax_end_diagnostic_session(): void {
		if ( ! $this->capabilities->can_manage() ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to manage diagnostic sessions.', 'plugin-conflict-debugger' ) ), 403 );
		}

		check_ajax_referer( 'pcd_scan_ajax', 'nonce' );

		$session = $this->sessions->end( 'completed' );
		wp_send_json_success( $session );
	}

	/**
	 * Runs the scan in background worker context.
	 *
	 * @param string $token Scan token.
	 * @return void
	 */
	public function run_background_scan( string $token ): void {
		$state = $this->scan_state->get();
		if ( ( $state['status'] ?? 'idle' ) === 'running' && (string) ( $state['token'] ?? '' ) !== $token ) {
			return;
		}

		$this->scan_state->mark_running( $token, __( 'Scan started.', 'plugin-conflict-debugger' ), 10 );

		try {
			$results = $this->scanner->run_scan_with_progress(
				function ( string $message, int $progress ) use ( $token ): void {
					$this->scan_state->mark_running( $token, $message, $progress );
				}
			);

			$finding_count = count( $results['findings'] ?? array() );
			$this->scan_state->mark_complete( $token, $finding_count );

			set_transient(
				'pcd_scan_notice',
				array(
					'type'    => $finding_count > 0 ? 'warning' : 'success',
					'message' => $finding_count > 0
						? sprintf(
							/* translators: %d finding count. */
							__( 'Scan complete. %d interaction finding(s) need review. These signals are conservative diagnostics, not guaranteed proof.', 'plugin-conflict-debugger' ),
							$finding_count
						)
						: __( 'Scan complete. No significant plugin conflict signals were detected in this pass.', 'plugin-conflict-debugger' ),
				),
				120
			);
		} catch ( Throwable $exception ) {
			$this->scan_state->mark_failed( $token, $exception->getMessage() );
			set_transient(
				'pcd_scan_notice',
				array(
					'type'    => 'error',
					'message' => __( 'Scan failed. Please check server logs and try again.', 'plugin-conflict-debugger' ),
				),
				120
			);
		}
	}

	/**
	 * Starts background scan if one is not already running.
	 *
	 * @return array<string, mixed>
	 */
	private function start_background_scan(): array {
		$state = $this->scan_state->get();
		if ( in_array( $state['status'] ?? 'idle', array( 'queued', 'running' ), true ) ) {
			return $state;
		}

		$token = wp_generate_uuid4();
		$state = $this->scan_state->mark_queued( $token );

		if ( ! wp_next_scheduled( self::ASYNC_SCAN_HOOK, array( $token ) ) ) {
			wp_schedule_single_event( time() + 1, self::ASYNC_SCAN_HOOK, array( $token ) );
		}

		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}

		return $state;
	}

	/**
	 * Renders the dashboard.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! $this->capabilities->can_manage() ) {
			wp_die( esc_html__( 'You are not allowed to view this page.', 'plugin-conflict-debugger' ) );
		}

		$results           = $this->repository->get_latest();
		$history           = $this->repository->get_history( 8 );
		$scan_state        = $this->scan_state->get();
		$has_results       = ! empty( $results );
		$summary           = $has_results ? ( $results['summary'] ?? array() ) : array();
		$findings          = $has_results ? ( $results['findings'] ?? array() ) : array();
		$scan_timestamp    = $has_results ? (string) ( $results['scan_timestamp'] ?? '' ) : '';
		$logs_unavailable  = $has_results ? ! empty( $results['analysis_notes']['logs_unavailable'] ) : false;
		$log_access        = $has_results && ! empty( $results['log_access'] ) && is_array( $results['log_access'] ) ? $results['log_access'] : array();
		$analysis_notes    = $has_results && ! empty( $results['analysis_notes']['notes'] ) && is_array( $results['analysis_notes']['notes'] )
			? array_values( array_filter( $results['analysis_notes']['notes'], fn( $note ): bool => ! $logs_unavailable || 'Direct log access is unavailable. Analysis is based on runtime and plugin interaction signals.' !== (string) $note ) )
			: array();
		$diagnostic_session = $has_results && is_array( $results['diagnostic_session'] ?? null ) ? $results['diagnostic_session'] : array();
		$active_session     = ! empty( $diagnostic_session['active'] ) && is_array( $diagnostic_session['active'] ) ? $diagnostic_session['active'] : $this->sessions->get_active();
		$last_session       = ! empty( $diagnostic_session['last'] ) && is_array( $diagnostic_session['last'] ) ? $diagnostic_session['last'] : $this->sessions->get_last();
		$session_contexts   = $this->sessions->get_supported_contexts();
		$plugin_drilldown  = $has_results ? $this->build_plugin_drilldown( $results ) : array();
		$scan_comparison   = $has_results ? $this->build_scan_comparison( $results, $history ) : array();
		$runtime_event_view = $has_results ? $this->build_runtime_event_view( $results, $findings, $active_session, $last_session ) : array(
			'summary'         => array(),
			'events'          => array(),
			'finding_event_map' => array(),
			'focus_label'     => '',
		);
		$finding_event_map = is_array( $runtime_event_view['finding_event_map'] ?? null ) ? $runtime_event_view['finding_event_map'] : array();
		$is_scan_active    = in_array( (string) ( $scan_state['status'] ?? 'idle' ), array( 'queued', 'running' ), true );
		$scan_status_text  = (string) ( $scan_state['message'] ?? __( 'No scan is running.', 'plugin-conflict-debugger' ) );
		$scan_progress     = (int) ( $scan_state['progress'] ?? 0 );
		?>
		<div class="wrap pcd-wrap">
			<div class="pcd-header">
				<div>
					<h1><?php esc_html_e( 'Plugin Conflict Debugger', 'plugin-conflict-debugger' ); ?></h1>
					<p class="description">
						<?php esc_html_e( 'Find likely plugin conflicts before you waste hours disabling plugins manually.', 'plugin-conflict-debugger' ); ?>
					</p>
				</div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="pcd_run_scan" />
					<?php wp_nonce_field( 'pcd_run_scan_action', 'pcd_run_scan_nonce' ); ?>
					<button
						type="button"
						class="button button-primary button-large"
						data-pcd-scan-button="true"
						data-pcd-default-label="<?php echo esc_attr__( 'Run Scan', 'plugin-conflict-debugger' ); ?>"
						<?php disabled( $is_scan_active ); ?>
					>
						<?php esc_html_e( 'Run Scan', 'plugin-conflict-debugger' ); ?>
					</button>
					<noscript>
						<button type="submit" class="button button-primary button-large"><?php esc_html_e( 'Run Scan (No JS)', 'plugin-conflict-debugger' ); ?></button>
					</noscript>
				</form>
			</div>

			<div class="pcd-meta">
				<strong><?php esc_html_e( 'Last scanned at:', 'plugin-conflict-debugger' ); ?></strong>
				<?php echo $scan_timestamp ? esc_html( Helpers::format_datetime( $scan_timestamp ) ) : esc_html__( 'Not yet scanned', 'plugin-conflict-debugger' ); ?>
			</div>

			<div
				class="pcd-scan-status"
				data-pcd-scan-status="true"
				data-pcd-scan-state="<?php echo esc_attr( (string) ( $scan_state['status'] ?? 'idle' ) ); ?>"
				<?php echo $is_scan_active || 'failed' === ( $scan_state['status'] ?? '' ) ? '' : 'hidden'; ?>
			>
				<div data-pcd-scan-message="true"><?php echo esc_html( $scan_status_text ); ?></div>
				<div class="pcd-scan-progress">
					<div class="pcd-scan-progress-bar" data-pcd-scan-progress="true" style="width: <?php echo esc_attr( (string) $scan_progress ); ?>%;"></div>
				</div>
				<span class="pcd-scan-progress-label" data-pcd-scan-progress-label="true"><?php echo esc_html( (string) $scan_progress ); ?>%</span>
			</div>

			<?php if ( $logs_unavailable ) : ?>
				<div class="notice notice-info inline">
					<p><?php esc_html_e( 'Direct log access is unavailable. Analysis is based on runtime and plugin interaction signals.', 'plugin-conflict-debugger' ); ?></p>
				</div>
			<?php endif; ?>

			<section class="pcd-summary-cards">
				<?php $this->render_summary_card( __( 'Active Plugins', 'plugin-conflict-debugger' ), (string) ( $summary['active_plugins'] ?? '0' ) ); ?>
				<?php $this->render_summary_card( __( 'Error Signals', 'plugin-conflict-debugger' ), (string) ( $summary['error_signals'] ?? '0' ) ); ?>
				<?php $this->render_summary_card( __( 'Likely Conflicts', 'plugin-conflict-debugger' ), (string) ( $summary['likely_conflicts'] ?? '0' ) ); ?>
				<?php $this->render_summary_card( __( 'Recent Plugin Changes', 'plugin-conflict-debugger' ), (string) ( $summary['recent_changes'] ?? '0' ) ); ?>
				<div class="pcd-summary-card pcd-summary-card-status">
					<span class="pcd-summary-label"><?php esc_html_e( 'Site Status', 'plugin-conflict-debugger' ); ?></span>
					<p class="pcd-site-status">
						<span class="pcd-status-badge pcd-status-<?php echo esc_attr( (string) ( $results['site_status'] ?? 'healthy' ) ); ?>">
							<?php echo esc_html( ucfirst( (string) ( $results['site_status'] ?? __( 'Healthy', 'plugin-conflict-debugger' ) ) ) ); ?>
						</span>
					</p>
					<p class="pcd-summary-note"><?php esc_html_e( 'Summarizes current scan severity, not a guaranteed root cause.', 'plugin-conflict-debugger' ); ?></p>
				</div>
			</section>

			<nav class="nav-tab-wrapper pcd-tab-nav" aria-label="<?php esc_attr_e( 'Plugin Conflict Debugger sections', 'plugin-conflict-debugger' ); ?>">
				<button type="button" class="nav-tab nav-tab-active" data-pcd-tab-trigger="findings" aria-selected="true"><?php esc_html_e( 'Findings', 'plugin-conflict-debugger' ); ?></button>
				<button type="button" class="nav-tab" data-pcd-tab-trigger="plugins" aria-selected="false"><?php esc_html_e( 'Plugins', 'plugin-conflict-debugger' ); ?></button>
				<button type="button" class="nav-tab" data-pcd-tab-trigger="diagnostics" aria-selected="false"><?php esc_html_e( 'Diagnostics', 'plugin-conflict-debugger' ); ?></button>
				<button type="button" class="nav-tab" data-pcd-tab-trigger="pro" aria-selected="false"><?php esc_html_e( 'Pro Preview', 'plugin-conflict-debugger' ); ?></button>
			</nav>

			<div class="pcd-tab-panels">
				<section class="pcd-tab-panel is-active" data-pcd-tab-panel="findings">
					<section class="pcd-panel">
						<div class="pcd-panel-header">
							<h2><?php esc_html_e( 'Findings', 'plugin-conflict-debugger' ); ?></h2>
							<p><?php esc_html_e( 'Each finding is conservative and evidence-tiered, not a guaranteed root cause.', 'plugin-conflict-debugger' ); ?></p>
						</div>

						<?php if ( ! $has_results ) : ?>
							<div class="pcd-empty-state">
								<h3><?php esc_html_e( 'Scan not yet run', 'plugin-conflict-debugger' ); ?></h3>
								<p><?php esc_html_e( 'Run your first scan to review recent error signals, suspicious plugin combinations, and possible conflict patterns.', 'plugin-conflict-debugger' ); ?></p>
							</div>
						<?php elseif ( empty( $findings ) ) : ?>
							<div class="pcd-empty-state">
								<h3><?php esc_html_e( 'No issues detected', 'plugin-conflict-debugger' ); ?></h3>
								<p><?php esc_html_e( 'This scan did not find strong conflict signals. If the site still breaks intermittently, test again after reproducing the issue or enabling debug logging in staging.', 'plugin-conflict-debugger' ); ?></p>
							</div>
						<?php else : ?>
							<div class="pcd-findings-table-wrap">
							<table class="widefat striped pcd-findings-table">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Severity', 'plugin-conflict-debugger' ); ?></th>
										<th><?php esc_html_e( 'Plugin A', 'plugin-conflict-debugger' ); ?></th>
										<th><?php esc_html_e( 'Plugin B', 'plugin-conflict-debugger' ); ?></th>
										<th><?php esc_html_e( 'Surface', 'plugin-conflict-debugger' ); ?></th>
										<th><?php esc_html_e( 'Confidence', 'plugin-conflict-debugger' ); ?></th>
										<th><?php esc_html_e( 'Explanation', 'plugin-conflict-debugger' ); ?></th>
										<th><?php esc_html_e( 'Action', 'plugin-conflict-debugger' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $findings as $finding ) : ?>
										<?php $finding_signature = $this->finding_signature( $finding ); ?>
										<?php $linked_runtime_events = is_array( $finding_event_map[ $finding_signature ] ?? null ) ? $finding_event_map[ $finding_signature ] : array(); ?>
										<tr>
											<td>
												<span class="pcd-status-badge pcd-status-<?php echo esc_attr( $finding['severity'] ?? 'info' ); ?>">
													<?php echo esc_html( ucfirst( (string) ( $finding['severity'] ?? 'info' ) ) ); ?>
												</span>
											</td>
											<td><?php echo esc_html( (string) ( $finding['primary_plugin_name'] ?? __( 'Unknown', 'plugin-conflict-debugger' ) ) ); ?></td>
											<td><?php echo esc_html( (string) ( $finding['secondary_plugin_name'] ?? '-' ) ); ?></td>
											<td>
												<?php echo esc_html( (string) ( $finding['surface_label'] ?? $finding['issue_category'] ?? '-' ) ); ?>
												<?php if ( ! empty( $finding['affected_area'] ) ) : ?>
													<div class="pcd-surface-area"><?php echo esc_html( (string) $finding['affected_area'] ); ?></div>
												<?php endif; ?>
												<?php if ( ! empty( $finding['request_context'] ) ) : ?>
													<div class="pcd-surface-area"><?php echo esc_html( sprintf( __( 'Context: %s', 'plugin-conflict-debugger' ), (string) $finding['request_context'] ) ); ?></div>
												<?php endif; ?>
												<?php if ( ! empty( $finding['execution_surface'] ) ) : ?>
													<div class="pcd-surface-area"><?php echo esc_html( sprintf( __( 'Execution surface: %s', 'plugin-conflict-debugger' ), (string) $finding['execution_surface'] ) ); ?></div>
												<?php endif; ?>
												<?php if ( ! empty( $finding['shared_resource'] ) ) : ?>
													<div class="pcd-surface-area"><?php echo esc_html( sprintf( __( 'Resource: %s', 'plugin-conflict-debugger' ), (string) $finding['shared_resource'] ) ); ?></div>
												<?php endif; ?>
											</td>
											<td><?php echo esc_html( (string) ( $finding['confidence'] ?? 0 ) ); ?>%</td>
											<td>
												<?php if ( ! empty( $finding['title'] ) ) : ?>
													<div class="pcd-finding-title"><?php echo esc_html( (string) $finding['title'] ); ?></div>
												<?php endif; ?>
												<?php if ( ! empty( $finding['finding_type'] ) ) : ?>
													<div class="pcd-finding-type"><?php echo esc_html( sprintf( __( 'Type: %s', 'plugin-conflict-debugger' ), str_replace( '_', ' ', (string) $finding['finding_type'] ) ) ); ?></div>
												<?php endif; ?>
												<div class="pcd-explanation"><?php echo esc_html( (string) ( $finding['explanation'] ?? '' ) ); ?></div>
												<?php if ( ! empty( $finding['why_this_is_not_or_is_actionable'] ) ) : ?>
													<div class="pcd-actionability-note"><?php echo esc_html( (string) $finding['why_this_is_not_or_is_actionable'] ); ?></div>
												<?php endif; ?>
												<?php if ( ! empty( $finding['evidence'] ) && is_array( $finding['evidence'] ) ) : ?>
													<details class="pcd-evidence-details">
														<summary>
															<?php
															echo esc_html(
																sprintf(
																	/* translators: %d evidence count. */
																	__( 'View evidence (%d)', 'plugin-conflict-debugger' ),
																	count( $finding['evidence'] )
																)
															);
															?>
														</summary>
														<ul class="pcd-evidence-list">
															<?php foreach ( $finding['evidence'] as $evidence_item ) : ?>
																<li><?php echo esc_html( (string) $evidence_item ); ?></li>
															<?php endforeach; ?>
														</ul>
													</details>
												<?php endif; ?>
												<?php if ( ! empty( $linked_runtime_events ) ) : ?>
													<div class="pcd-runtime-event-links">
														<?php
														$first_link = $linked_runtime_events[0];
														$link_count = count( $linked_runtime_events );
														?>
														<a
															href="<?php echo esc_url( '#' . (string) ( $first_link['id'] ?? '' ) ); ?>"
															class="pcd-runtime-event-link"
															data-pcd-open-tab="diagnostics"
															data-pcd-scroll-target="<?php echo esc_attr( '#' . (string) ( $first_link['id'] ?? '' ) ); ?>"
														>
															<?php
															echo esc_html(
																sprintf(
																	/* translators: %d event count. */
																	_n( 'View matching runtime event (%d)', 'View matching runtime events (%d)', $link_count, 'plugin-conflict-debugger' ),
																	$link_count
																)
															);
															?>
														</a>
													</div>
												<?php endif; ?>
											</td>
											<td><?php echo esc_html( (string) ( $finding['recommended_next_step'] ?? '' ) ); ?></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
							</div>
						<?php endif; ?>
					</section>
				</section>

				<section class="pcd-tab-panel" data-pcd-tab-panel="plugins" hidden>
					<section class="pcd-panel">
						<div class="pcd-panel-header">
							<h2><?php esc_html_e( 'Plugin Drilldown', 'plugin-conflict-debugger' ); ?></h2>
							<p><?php esc_html_e( 'Inspect one plugin at a time to see its categories, active findings, runtime contexts, and likely interaction hotspots.', 'plugin-conflict-debugger' ); ?></p>
						</div>
						<?php if ( empty( $plugin_drilldown ) ) : ?>
							<div class="pcd-empty-state">
								<h3><?php esc_html_e( 'No plugin drilldown data yet', 'plugin-conflict-debugger' ); ?></h3>
								<p><?php esc_html_e( 'Run a scan to build per-plugin diagnostics and relationship context.', 'plugin-conflict-debugger' ); ?></p>
							</div>
						<?php else : ?>
							<div class="pcd-drilldown-toolbar">
								<label class="screen-reader-text" for="pcd-plugin-drilldown-select"><?php esc_html_e( 'Select a plugin', 'plugin-conflict-debugger' ); ?></label>
								<select id="pcd-plugin-drilldown-select" class="pcd-plugin-select" data-pcd-plugin-select="true">
									<?php foreach ( $plugin_drilldown as $plugin_slug => $plugin_data ) : ?>
										<option value="<?php echo esc_attr( (string) $plugin_slug ); ?>"><?php echo esc_html( (string) ( $plugin_data['name'] ?? $plugin_slug ) ); ?></option>
									<?php endforeach; ?>
								</select>
							</div>

							<?php foreach ( $plugin_drilldown as $plugin_slug => $plugin_data ) : ?>
								<section class="pcd-plugin-drilldown-card" data-pcd-plugin-card="<?php echo esc_attr( (string) $plugin_slug ); ?>" hidden>
									<div class="pcd-plugin-drilldown-header">
										<div>
											<h3><?php echo esc_html( (string) ( $plugin_data['name'] ?? $plugin_slug ) ); ?></h3>
											<p class="description"><?php echo esc_html( sprintf( __( 'Version %1$s. %2$d current finding(s) involve this plugin.', 'plugin-conflict-debugger' ), (string) ( $plugin_data['version'] ?? '-' ), (int) ( $plugin_data['finding_count'] ?? 0 ) ) ); ?></p>
										</div>
										<div class="pcd-plugin-badges">
											<?php foreach ( (array) ( $plugin_data['categories'] ?? array() ) as $category ) : ?>
												<span class="pcd-status-badge pcd-status-info"><?php echo esc_html( ucfirst( str_replace( '-', ' ', (string) $category ) ) ); ?></span>
											<?php endforeach; ?>
										</div>
									</div>

									<div class="pcd-drilldown-grid">
										<div class="pcd-drilldown-stat">
											<span class="pcd-summary-label"><?php esc_html_e( 'Findings', 'plugin-conflict-debugger' ); ?></span>
											<strong class="pcd-summary-value"><?php echo esc_html( (string) ( $plugin_data['finding_count'] ?? 0 ) ); ?></strong>
										</div>
										<div class="pcd-drilldown-stat">
											<span class="pcd-summary-label"><?php esc_html_e( 'Top Severity', 'plugin-conflict-debugger' ); ?></span>
											<strong class="pcd-summary-value pcd-summary-value-small"><?php echo esc_html( ucfirst( (string) ( $plugin_data['top_severity'] ?? 'info' ) ) ); ?></strong>
										</div>
										<div class="pcd-drilldown-stat">
											<span class="pcd-summary-label"><?php esc_html_e( 'Request Contexts', 'plugin-conflict-debugger' ); ?></span>
											<strong class="pcd-summary-value pcd-summary-value-small"><?php echo esc_html( (string) count( (array) ( $plugin_data['contexts'] ?? array() ) ) ); ?></strong>
										</div>
										<div class="pcd-drilldown-stat">
											<span class="pcd-summary-label"><?php esc_html_e( 'Related Plugins', 'plugin-conflict-debugger' ); ?></span>
											<strong class="pcd-summary-value pcd-summary-value-small"><?php echo esc_html( (string) count( (array) ( $plugin_data['related_plugins'] ?? array() ) ) ); ?></strong>
										</div>
									</div>

									<div class="pcd-drilldown-meta">
										<div>
											<h4><?php esc_html_e( 'Observed Contexts', 'plugin-conflict-debugger' ); ?></h4>
											<p><?php echo esc_html( ! empty( $plugin_data['contexts'] ) ? implode( ', ', (array) $plugin_data['contexts'] ) : __( 'No contexts captured yet.', 'plugin-conflict-debugger' ) ); ?></p>
										</div>
										<div>
											<h4><?php esc_html_e( 'Related Plugins', 'plugin-conflict-debugger' ); ?></h4>
											<p><?php echo esc_html( ! empty( $plugin_data['related_plugins'] ) ? implode( ', ', (array) $plugin_data['related_plugins'] ) : __( 'No related plugins flagged in the latest scan.', 'plugin-conflict-debugger' ) ); ?></p>
										</div>
									</div>

									<?php if ( ! empty( $plugin_data['findings'] ) ) : ?>
										<div class="pcd-plugin-findings-list">
											<?php foreach ( (array) $plugin_data['findings'] as $plugin_finding ) : ?>
												<article class="pcd-plugin-finding-item">
													<div class="pcd-plugin-finding-topline">
														<span class="pcd-status-badge pcd-status-<?php echo esc_attr( (string) ( $plugin_finding['severity'] ?? 'info' ) ); ?>"><?php echo esc_html( ucfirst( (string) ( $plugin_finding['severity'] ?? 'info' ) ) ); ?></span>
														<strong><?php echo esc_html( (string) ( $plugin_finding['title'] ?? '' ) ); ?></strong>
													</div>
													<p><?php echo esc_html( (string) ( $plugin_finding['summary'] ?? '' ) ); ?></p>
													<p class="pcd-actionability-note"><?php echo esc_html( (string) ( $plugin_finding['meta'] ?? '' ) ); ?></p>
												</article>
											<?php endforeach; ?>
										</div>
									<?php else : ?>
										<p><?php esc_html_e( 'This plugin is active but does not appear in any current findings.', 'plugin-conflict-debugger' ); ?></p>
									<?php endif; ?>
								</section>
							<?php endforeach; ?>
						<?php endif; ?>
					</section>
				</section>

				<section class="pcd-tab-panel" data-pcd-tab-panel="diagnostics" hidden>
					<div class="pcd-diagnostics-grid">
						<section class="pcd-panel">
							<h2><?php esc_html_e( 'Diagnostic Session', 'plugin-conflict-debugger' ); ?></h2>
							<p><?php esc_html_e( 'Start a focused session, reproduce the problem in that site area, then run a scan to review telemetry captured for that trace.', 'plugin-conflict-debugger' ); ?></p>
							<div class="pcd-session-controls" data-pcd-session-controls="true">
								<label class="screen-reader-text" for="pcd-session-context"><?php esc_html_e( 'Choose a site area for this diagnostic session', 'plugin-conflict-debugger' ); ?></label>
								<select id="pcd-session-context" class="pcd-plugin-select" data-pcd-session-context="true" <?php disabled( ! empty( $active_session ) ); ?>>
									<?php foreach ( $session_contexts as $context_key => $context_label ) : ?>
										<option value="<?php echo esc_attr( (string) $context_key ); ?>" <?php selected( (string) $context_key, (string) ( $active_session['target_context'] ?? 'all' ) ); ?>><?php echo esc_html( (string) $context_label ); ?></option>
									<?php endforeach; ?>
								</select>
								<button type="button" class="button button-primary" data-pcd-session-start="true" <?php disabled( ! empty( $active_session ) ); ?>><?php esc_html_e( 'Start Session', 'plugin-conflict-debugger' ); ?></button>
								<button type="button" class="button" data-pcd-session-end="true" <?php disabled( empty( $active_session ) ); ?>><?php esc_html_e( 'End Session', 'plugin-conflict-debugger' ); ?></button>
							</div>
							<div class="pcd-session-status" data-pcd-session-status="true">
								<?php if ( ! empty( $active_session ) ) : ?>
									<p>
										<span class="pcd-status-badge pcd-status-warning"><?php esc_html_e( 'Active', 'plugin-conflict-debugger' ); ?></span>
										<?php
										echo esc_html(
											sprintf(
												/* translators: %s session label. */
												__( 'Focused on: %s', 'plugin-conflict-debugger' ),
												(string) ( $active_session['label'] ?? __( 'Any site area', 'plugin-conflict-debugger' ) )
											)
										);
										?>
									</p>
									<ul class="pcd-meta-list">
										<li><?php echo esc_html( sprintf( __( 'Started: %s', 'plugin-conflict-debugger' ), Helpers::format_datetime( (string) ( $active_session['started_at'] ?? '' ) ) ) ); ?></li>
										<?php if ( ! empty( $active_session['last_activity_at'] ) ) : ?>
											<li><?php echo esc_html( sprintf( __( 'Last captured activity: %s', 'plugin-conflict-debugger' ), Helpers::format_datetime( (string) $active_session['last_activity_at'] ) ) ); ?></li>
										<?php endif; ?>
										<?php if ( ! empty( $active_session['expires_at'] ) ) : ?>
											<li><?php echo esc_html( sprintf( __( 'Expires: %s', 'plugin-conflict-debugger' ), Helpers::format_datetime( (string) $active_session['expires_at'] ) ) ); ?></li>
										<?php endif; ?>
									</ul>
								<?php elseif ( ! empty( $last_session ) ) : ?>
									<p>
										<span class="pcd-status-badge pcd-status-info"><?php esc_html_e( 'Last Session', 'plugin-conflict-debugger' ); ?></span>
										<?php
										echo esc_html(
											sprintf(
												/* translators: 1: label, 2: status. */
												__( '%1$s (%2$s)', 'plugin-conflict-debugger' ),
												(string) ( $last_session['label'] ?? __( 'Any site area', 'plugin-conflict-debugger' ) ),
												ucfirst( str_replace( '_', ' ', (string) ( $last_session['status'] ?? 'completed' ) ) )
											)
										);
										?>
									</p>
									<ul class="pcd-meta-list">
										<li><?php echo esc_html( sprintf( __( 'Started: %s', 'plugin-conflict-debugger' ), Helpers::format_datetime( (string) ( $last_session['started_at'] ?? '' ) ) ) ); ?></li>
										<?php if ( ! empty( $last_session['ended_at'] ) ) : ?>
											<li><?php echo esc_html( sprintf( __( 'Ended: %s', 'plugin-conflict-debugger' ), Helpers::format_datetime( (string) $last_session['ended_at'] ) ) ); ?></li>
										<?php endif; ?>
									</ul>
								<?php else : ?>
									<p><?php esc_html_e( 'No focused diagnostic session is active right now.', 'plugin-conflict-debugger' ); ?></p>
								<?php endif; ?>
							</div>
						</section>

						<section class="pcd-panel">
							<h2><?php esc_html_e( 'Log Access Check', 'plugin-conflict-debugger' ); ?></h2>
							<?php if ( ! empty( $log_access ) ) : ?>
								<p>
									<span class="pcd-status-badge pcd-status-<?php echo esc_attr( ! empty( $log_access['readable'] ) ? 'healthy' : 'warning' ); ?>">
										<?php echo esc_html( ucfirst( str_replace( '_', ' ', (string) ( $log_access['status'] ?? __( 'Unknown', 'plugin-conflict-debugger' ) ) ) ) ); ?>
									</span>
								</p>
								<p><?php echo esc_html( (string) ( $log_access['status_message'] ?? '' ) ); ?></p>
								<ul class="pcd-meta-list">
									<li><?php echo esc_html( sprintf( __( 'WP_DEBUG: %s', 'plugin-conflict-debugger' ), ! empty( $log_access['wp_debug'] ) ? __( 'Enabled', 'plugin-conflict-debugger' ) : __( 'Disabled', 'plugin-conflict-debugger' ) ) ); ?></li>
									<li><?php echo esc_html( sprintf( __( 'WP_DEBUG_LOG: %s', 'plugin-conflict-debugger' ), ! empty( $log_access['wp_debug_log'] ) ? __( 'Enabled', 'plugin-conflict-debugger' ) : __( 'Disabled', 'plugin-conflict-debugger' ) ) ); ?></li>
									<li><?php echo esc_html( sprintf( __( 'File exists: %s', 'plugin-conflict-debugger' ), ! empty( $log_access['exists'] ) ? __( 'Yes', 'plugin-conflict-debugger' ) : __( 'No', 'plugin-conflict-debugger' ) ) ); ?></li>
									<li><?php echo esc_html( sprintf( __( 'Readable by PHP: %s', 'plugin-conflict-debugger' ), ! empty( $log_access['readable'] ) ? __( 'Yes', 'plugin-conflict-debugger' ) : __( 'No', 'plugin-conflict-debugger' ) ) ); ?></li>
									<li><?php echo esc_html( sprintf( __( 'Writable by server: %s', 'plugin-conflict-debugger' ), ! empty( $log_access['writable'] ) ? __( 'Yes', 'plugin-conflict-debugger' ) : __( 'No', 'plugin-conflict-debugger' ) ) ); ?></li>
								</ul>
								<?php if ( ! empty( $log_access['path'] ) ) : ?>
									<code class="pcd-request-context-uri"><?php echo esc_html( (string) $log_access['path'] ); ?></code>
								<?php endif; ?>
								<?php if ( ! empty( $log_access['recommendations'] ) && is_array( $log_access['recommendations'] ) ) : ?>
									<ul class="pcd-meta-list">
										<?php foreach ( $log_access['recommendations'] as $recommendation ) : ?>
											<li><?php echo esc_html( (string) $recommendation ); ?></li>
										<?php endforeach; ?>
									</ul>
								<?php endif; ?>
							<?php else : ?>
								<p><?php esc_html_e( 'No log access data is available yet. Run a scan to populate this check.', 'plugin-conflict-debugger' ); ?></p>
							<?php endif; ?>
						</section>

						<?php if ( $has_results && ! empty( $results['environment'] ) && is_array( $results['environment'] ) ) : ?>
							<section class="pcd-panel">
								<h2><?php esc_html_e( 'Environment Snapshot', 'plugin-conflict-debugger' ); ?></h2>
								<ul class="pcd-meta-list">
									<li><?php echo esc_html( sprintf( __( 'WordPress: %s', 'plugin-conflict-debugger' ), (string) ( $results['environment']['wordpress_version'] ?? '-' ) ) ); ?></li>
									<li><?php echo esc_html( sprintf( __( 'PHP: %s', 'plugin-conflict-debugger' ), (string) ( $results['environment']['php_version'] ?? '-' ) ) ); ?></li>
									<li><?php echo esc_html( sprintf( __( 'Theme: %s', 'plugin-conflict-debugger' ), (string) ( $results['environment']['active_theme'] ?? '-' ) ) ); ?></li>
									<li><?php echo esc_html( sprintf( __( 'Multisite: %s', 'plugin-conflict-debugger' ), ! empty( $results['environment']['is_multisite'] ) ? __( 'Yes', 'plugin-conflict-debugger' ) : __( 'No', 'plugin-conflict-debugger' ) ) ); ?></li>
									<li><?php echo esc_html( sprintf( __( 'Debug mode: %s', 'plugin-conflict-debugger' ), ! empty( $results['environment']['wp_debug'] ) ? __( 'Enabled', 'plugin-conflict-debugger' ) : __( 'Disabled', 'plugin-conflict-debugger' ) ) ); ?></li>
								</ul>
							</section>
						<?php endif; ?>

						<section class="pcd-panel">
							<h2><?php esc_html_e( 'Analysis Notes', 'plugin-conflict-debugger' ); ?></h2>
							<?php if ( $logs_unavailable ) : ?>
								<p><?php esc_html_e( 'Direct log access is unavailable. Analysis is based on runtime and plugin interaction signals.', 'plugin-conflict-debugger' ); ?></p>
							<?php endif; ?>
							<?php if ( ! empty( $analysis_notes ) ) : ?>
								<ul class="pcd-meta-list">
									<?php foreach ( $analysis_notes as $analysis_note ) : ?>
										<li><?php echo esc_html( (string) $analysis_note ); ?></li>
									<?php endforeach; ?>
								</ul>
							<?php else : ?>
								<p><?php esc_html_e( 'No extra analysis notes were captured in this scan.', 'plugin-conflict-debugger' ); ?></p>
							<?php endif; ?>
						</section>

						<?php if ( $has_results && ! empty( $results['request_contexts'] ) && is_array( $results['request_contexts'] ) ) : ?>
							<section class="pcd-panel pcd-panel-span-2">
								<h2><?php esc_html_e( 'Recent Request Contexts', 'plugin-conflict-debugger' ); ?></h2>
								<div class="pcd-request-contexts">
									<?php foreach ( array_slice( $results['request_contexts'], 0, 10 ) as $request_context ) : ?>
										<article class="pcd-request-context-card">
											<div class="pcd-request-context-topline">
												<span class="pcd-status-badge pcd-status-info"><?php echo esc_html( (string) ( $request_context['request_context'] ?? __( 'runtime', 'plugin-conflict-debugger' ) ) ); ?></span>
												<?php if ( ! empty( $request_context['timestamp'] ) ) : ?>
													<span class="pcd-request-context-time"><?php echo esc_html( Helpers::format_datetime( (string) $request_context['timestamp'] ) ); ?></span>
												<?php endif; ?>
											</div>
											<code class="pcd-request-context-uri"><?php echo esc_html( (string) ( $request_context['request_uri'] ?? '/' ) ); ?></code>
										</article>
									<?php endforeach; ?>
								</div>
							</section>
						<?php endif; ?>

						<section class="pcd-panel pcd-panel-span-2">
							<h2><?php esc_html_e( 'Recent Runtime Events', 'plugin-conflict-debugger' ); ?></h2>
							<?php if ( ! empty( $runtime_event_view['events'] ) ) : ?>
								<?php if ( ! empty( $runtime_event_view['focus_label'] ) ) : ?>
									<p class="pcd-summary-note">
										<?php
										echo esc_html(
											sprintf(
												/* translators: %s session label. */
												__( 'Showing events captured for the focused diagnostic session: %s', 'plugin-conflict-debugger' ),
												(string) $runtime_event_view['focus_label']
											)
										);
										?>
									</p>
								<?php endif; ?>
								<div class="pcd-runtime-summary-grid">
									<div class="pcd-drilldown-stat">
										<span class="pcd-summary-label"><?php esc_html_e( 'Captured Events', 'plugin-conflict-debugger' ); ?></span>
										<strong class="pcd-summary-value"><?php echo esc_html( (string) ( $runtime_event_view['summary']['total'] ?? 0 ) ); ?></strong>
									</div>
									<div class="pcd-drilldown-stat">
										<span class="pcd-summary-label"><?php esc_html_e( 'JS Errors', 'plugin-conflict-debugger' ); ?></span>
										<strong class="pcd-summary-value"><?php echo esc_html( (string) ( $runtime_event_view['summary']['js_errors'] ?? 0 ) ); ?></strong>
									</div>
									<div class="pcd-drilldown-stat">
										<span class="pcd-summary-label"><?php esc_html_e( 'Failed Requests', 'plugin-conflict-debugger' ); ?></span>
										<strong class="pcd-summary-value"><?php echo esc_html( (string) ( $runtime_event_view['summary']['failed_requests'] ?? 0 ) ); ?></strong>
									</div>
									<div class="pcd-drilldown-stat">
										<span class="pcd-summary-label"><?php esc_html_e( 'Mutation Signals', 'plugin-conflict-debugger' ); ?></span>
										<strong class="pcd-summary-value"><?php echo esc_html( (string) ( $runtime_event_view['summary']['mutations'] ?? 0 ) ); ?></strong>
									</div>
								</div>
								<div class="pcd-runtime-events-list">
									<?php foreach ( (array) $runtime_event_view['events'] as $runtime_event ) : ?>
										<article id="<?php echo esc_attr( (string) ( $runtime_event['id'] ?? '' ) ); ?>" class="pcd-runtime-event-card">
											<div class="pcd-runtime-event-topline">
												<div class="pcd-runtime-event-badges">
													<span class="pcd-status-badge pcd-status-<?php echo esc_attr( (string) ( $runtime_event['level_badge'] ?? 'info' ) ); ?>">
														<?php echo esc_html( (string) ( $runtime_event['type_label'] ?? __( 'Runtime Event', 'plugin-conflict-debugger' ) ) ); ?>
													</span>
													<?php if ( ! empty( $runtime_event['request_context'] ) ) : ?>
														<span class="pcd-status-badge pcd-status-info"><?php echo esc_html( (string) $runtime_event['request_context'] ); ?></span>
													<?php endif; ?>
												</div>
												<?php if ( ! empty( $runtime_event['timestamp'] ) ) : ?>
													<span class="pcd-request-context-time"><?php echo esc_html( Helpers::format_datetime( (string) $runtime_event['timestamp'] ) ); ?></span>
												<?php endif; ?>
											</div>
											<h4><?php echo esc_html( (string) ( $runtime_event['title'] ?? __( 'Runtime event', 'plugin-conflict-debugger' ) ) ); ?></h4>
											<p><?php echo esc_html( (string) ( $runtime_event['message'] ?? '' ) ); ?></p>
											<div class="pcd-runtime-event-meta">
												<?php if ( ! empty( $runtime_event['request_uri'] ) ) : ?>
													<div>
														<span class="pcd-summary-label"><?php esc_html_e( 'Request', 'plugin-conflict-debugger' ); ?></span>
														<code class="pcd-request-context-uri"><?php echo esc_html( (string) $runtime_event['request_uri'] ); ?></code>
													</div>
												<?php endif; ?>
												<div>
													<ul class="pcd-meta-list">
														<?php if ( ! empty( $runtime_event['resource'] ) ) : ?>
															<li><?php echo esc_html( sprintf( __( 'Resource: %s', 'plugin-conflict-debugger' ), (string) $runtime_event['resource'] ) ); ?></li>
														<?php endif; ?>
														<?php if ( ! empty( $runtime_event['execution_surface'] ) ) : ?>
															<li><?php echo esc_html( sprintf( __( 'Execution surface: %s', 'plugin-conflict-debugger' ), (string) $runtime_event['execution_surface'] ) ); ?></li>
														<?php endif; ?>
														<?php if ( ! empty( $runtime_event['failure_mode'] ) ) : ?>
															<li><?php echo esc_html( sprintf( __( 'Failure mode: %s', 'plugin-conflict-debugger' ), (string) $runtime_event['failure_mode'] ) ); ?></li>
														<?php endif; ?>
														<?php if ( ! empty( $runtime_event['status_code'] ) ) : ?>
															<li><?php echo esc_html( sprintf( __( 'HTTP status: %d', 'plugin-conflict-debugger' ), (int) $runtime_event['status_code'] ) ); ?></li>
														<?php endif; ?>
														<?php if ( ! empty( $runtime_event['owner_labels'] ) ) : ?>
															<li><?php echo esc_html( sprintf( __( 'Observed owners: %s', 'plugin-conflict-debugger' ), implode( ', ', (array) $runtime_event['owner_labels'] ) ) ); ?></li>
														<?php endif; ?>
													</ul>
												</div>
											</div>
											<?php if ( ! empty( $runtime_event['resource_hints'] ) ) : ?>
												<p class="pcd-actionability-note"><?php echo esc_html( sprintf( __( 'Resource hints: %s', 'plugin-conflict-debugger' ), implode( ', ', (array) $runtime_event['resource_hints'] ) ) ); ?></p>
											<?php endif; ?>
											<?php if ( ! empty( $runtime_event['matched_findings'] ) ) : ?>
												<div class="pcd-runtime-event-links">
													<strong><?php esc_html_e( 'Linked findings:', 'plugin-conflict-debugger' ); ?></strong>
													<ul class="pcd-meta-list">
														<?php foreach ( (array) $runtime_event['matched_findings'] as $matched_finding ) : ?>
															<li><?php echo esc_html( (string) $matched_finding ); ?></li>
														<?php endforeach; ?>
													</ul>
												</div>
											<?php endif; ?>
										</article>
									<?php endforeach; ?>
								</div>
							<?php else : ?>
								<p><?php esc_html_e( 'No recent runtime events were captured yet. Start a diagnostic session, reproduce the issue, and run another scan to populate this viewer.', 'plugin-conflict-debugger' ); ?></p>
							<?php endif; ?>
						</section>

						<section class="pcd-panel pcd-panel-span-2">
							<h2><?php esc_html_e( 'Compare Last Two Scans', 'plugin-conflict-debugger' ); ?></h2>
							<?php if ( ! empty( $scan_comparison['has_previous'] ) ) : ?>
								<div class="pcd-compare-grid">
									<div class="pcd-drilldown-stat">
										<span class="pcd-summary-label"><?php esc_html_e( 'Current Conflicts', 'plugin-conflict-debugger' ); ?></span>
										<strong class="pcd-summary-value"><?php echo esc_html( (string) ( $scan_comparison['current_conflicts'] ?? 0 ) ); ?></strong>
									</div>
									<div class="pcd-drilldown-stat">
										<span class="pcd-summary-label"><?php esc_html_e( 'Previous Conflicts', 'plugin-conflict-debugger' ); ?></span>
										<strong class="pcd-summary-value"><?php echo esc_html( (string) ( $scan_comparison['previous_conflicts'] ?? 0 ) ); ?></strong>
									</div>
									<div class="pcd-drilldown-stat">
										<span class="pcd-summary-label"><?php esc_html_e( 'New Findings', 'plugin-conflict-debugger' ); ?></span>
										<strong class="pcd-summary-value"><?php echo esc_html( (string) count( (array) ( $scan_comparison['new_findings'] ?? array() ) ) ); ?></strong>
									</div>
									<div class="pcd-drilldown-stat">
										<span class="pcd-summary-label"><?php esc_html_e( 'Resolved Findings', 'plugin-conflict-debugger' ); ?></span>
										<strong class="pcd-summary-value"><?php echo esc_html( (string) count( (array) ( $scan_comparison['resolved_findings'] ?? array() ) ) ); ?></strong>
									</div>
								</div>
								<div class="pcd-drilldown-meta">
									<div>
										<h4><?php esc_html_e( 'Current Scan', 'plugin-conflict-debugger' ); ?></h4>
										<p><?php echo esc_html( Helpers::format_datetime( (string) ( $scan_comparison['current_timestamp'] ?? '' ) ) ); ?></p>
									</div>
									<div>
										<h4><?php esc_html_e( 'Previous Scan', 'plugin-conflict-debugger' ); ?></h4>
										<p><?php echo esc_html( Helpers::format_datetime( (string) ( $scan_comparison['previous_timestamp'] ?? '' ) ) ); ?></p>
									</div>
								</div>
								<div class="pcd-compare-lists">
									<div>
										<h4><?php esc_html_e( 'New Since Previous Scan', 'plugin-conflict-debugger' ); ?></h4>
										<?php if ( ! empty( $scan_comparison['new_findings'] ) ) : ?>
											<ul class="pcd-meta-list">
												<?php foreach ( (array) $scan_comparison['new_findings'] as $compare_item ) : ?>
													<li><?php echo esc_html( (string) $compare_item ); ?></li>
												<?php endforeach; ?>
											</ul>
										<?php else : ?>
											<p><?php esc_html_e( 'No new findings compared with the previous scan.', 'plugin-conflict-debugger' ); ?></p>
										<?php endif; ?>
									</div>
									<div>
										<h4><?php esc_html_e( 'Resolved Since Previous Scan', 'plugin-conflict-debugger' ); ?></h4>
										<?php if ( ! empty( $scan_comparison['resolved_findings'] ) ) : ?>
											<ul class="pcd-meta-list">
												<?php foreach ( (array) $scan_comparison['resolved_findings'] as $compare_item ) : ?>
													<li><?php echo esc_html( (string) $compare_item ); ?></li>
												<?php endforeach; ?>
											</ul>
										<?php else : ?>
											<p><?php esc_html_e( 'No findings appear to have resolved since the previous scan.', 'plugin-conflict-debugger' ); ?></p>
										<?php endif; ?>
									</div>
								</div>
							<?php else : ?>
								<p><?php esc_html_e( 'A second scan is needed before comparison data becomes available.', 'plugin-conflict-debugger' ); ?></p>
							<?php endif; ?>
						</section>

						<section class="pcd-panel pcd-panel-span-2">
							<h2><?php esc_html_e( 'Scan History', 'plugin-conflict-debugger' ); ?></h2>
							<?php if ( ! empty( $history ) ) : ?>
								<div class="pcd-history-table-wrap">
									<table class="widefat striped">
										<thead>
											<tr>
												<th><?php esc_html_e( 'Scan Time', 'plugin-conflict-debugger' ); ?></th>
												<th><?php esc_html_e( 'Site Status', 'plugin-conflict-debugger' ); ?></th>
												<th><?php esc_html_e( 'Conflicts', 'plugin-conflict-debugger' ); ?></th>
												<th><?php esc_html_e( 'Errors', 'plugin-conflict-debugger' ); ?></th>
												<th><?php esc_html_e( 'Log Access', 'plugin-conflict-debugger' ); ?></th>
											</tr>
										</thead>
										<tbody>
											<?php foreach ( $history as $history_item ) : ?>
												<tr>
													<td><?php echo esc_html( Helpers::format_datetime( (string) ( $history_item['scan_timestamp'] ?? '' ) ) ); ?></td>
													<td>
														<span class="pcd-status-badge pcd-status-<?php echo esc_attr( (string) ( $history_item['site_status'] ?? 'healthy' ) ); ?>">
															<?php echo esc_html( ucfirst( (string) ( $history_item['site_status'] ?? __( 'Unknown', 'plugin-conflict-debugger' ) ) ) ); ?>
														</span>
													</td>
													<td><?php echo esc_html( (string) ( $history_item['summary']['likely_conflicts'] ?? 0 ) ); ?></td>
													<td><?php echo esc_html( (string) ( $history_item['summary']['error_signals'] ?? 0 ) ); ?></td>
													<td><?php echo esc_html( ucfirst( str_replace( '_', ' ', (string) ( $history_item['log_access']['status'] ?? __( 'Unknown', 'plugin-conflict-debugger' ) ) ) ) ); ?></td>
												</tr>
											<?php endforeach; ?>
										</tbody>
									</table>
								</div>
							<?php else : ?>
								<p><?php esc_html_e( 'No prior scan history is stored yet.', 'plugin-conflict-debugger' ); ?></p>
							<?php endif; ?>
						</section>
					</div>
				</section>

				<section class="pcd-tab-panel" data-pcd-tab-panel="pro" hidden>
					<section class="pcd-panel">
						<h2><?php esc_html_e( 'Pro Preview', 'plugin-conflict-debugger' ); ?></h2>
						<ul class="pcd-feature-list">
							<li><?php esc_html_e( 'Safe Test Mode for controlled plugin isolation', 'plugin-conflict-debugger' ); ?></li>
							<li><?php esc_html_e( 'Auto-Isolate Conflict using binary search workflows', 'plugin-conflict-debugger' ); ?></li>
							<li><?php esc_html_e( 'Scheduled scans and team alerts', 'plugin-conflict-debugger' ); ?></li>
						</ul>
						<p><?php esc_html_e( 'The current free foundation is built so these workflows can be added later without rewriting the scan engine.', 'plugin-conflict-debugger' ); ?></p>
					</section>
				</section>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders a summary card.
	 *
	 * @param string $label Summary label.
	 * @param string $value Summary value.
	 * @return void
	 */
	private function render_summary_card( string $label, string $value ): void {
		?>
		<div class="pcd-summary-card">
			<span class="pcd-summary-label"><?php echo esc_html( $label ); ?></span>
			<strong class="pcd-summary-value"><?php echo esc_html( $value ); ?></strong>
		</div>
		<?php
	}

	/**
	 * Builds per-plugin drilldown data from the latest scan.
	 *
	 * @param array<string, mixed> $results Latest scan results.
	 * @return array<string, array<string, mixed>>
	 */
	private function build_plugin_drilldown( array $results ): array {
		$plugins   = is_array( $results['plugins'] ?? null ) ? $results['plugins'] : array();
		$findings  = is_array( $results['findings'] ?? null ) ? $results['findings'] : array();
		$drilldown = array();

		foreach ( $plugins as $plugin ) {
			$slug = sanitize_key( (string) ( $plugin['slug'] ?? '' ) );
			if ( '' === $slug ) {
				continue;
			}

			$drilldown[ $slug ] = array(
				'slug'           => $slug,
				'name'           => sanitize_text_field( (string) ( $plugin['name'] ?? $slug ) ),
				'version'        => sanitize_text_field( (string) ( $plugin['version'] ?? '' ) ),
				'categories'     => is_array( $plugin['categories'] ?? null ) ? array_values( array_map( 'sanitize_key', $plugin['categories'] ) ) : array(),
				'finding_count'  => 0,
				'top_severity'   => 'info',
				'contexts'       => array(),
				'related_plugins'=> array(),
				'findings'       => array(),
			);
		}

		foreach ( $findings as $finding ) {
			$primary_slug   = sanitize_key( (string) ( $finding['primary_plugin'] ?? '' ) );
			$secondary_slug = sanitize_key( (string) ( $finding['secondary_plugin'] ?? '' ) );
			$related_name   = sanitize_text_field( (string) ( $finding['secondary_plugin_name'] ?? '' ) );
			$request_context = sanitize_text_field( (string) ( $finding['request_context'] ?? '' ) );
			$summary        = sanitize_text_field( (string) ( $finding['explanation'] ?? '' ) );
			$meta_bits      = array_filter(
				array(
					$request_context ? sprintf( __( 'Context: %s', 'plugin-conflict-debugger' ), $request_context ) : '',
					! empty( $finding['shared_resource'] ) ? sprintf( __( 'Resource: %s', 'plugin-conflict-debugger' ), sanitize_text_field( (string) $finding['shared_resource'] ) ) : '',
					! empty( $finding['execution_surface'] ) ? sprintf( __( 'Surface: %s', 'plugin-conflict-debugger' ), sanitize_text_field( (string) $finding['execution_surface'] ) ) : '',
				)
			);

			foreach ( array( $primary_slug, $secondary_slug ) as $plugin_slug ) {
				if ( '' === $plugin_slug || ! isset( $drilldown[ $plugin_slug ] ) ) {
					continue;
				}

				$drilldown[ $plugin_slug ]['finding_count']++;
				$drilldown[ $plugin_slug ]['top_severity'] = $this->higher_severity(
					(string) $drilldown[ $plugin_slug ]['top_severity'],
					sanitize_key( (string) ( $finding['severity'] ?? 'info' ) )
				);

				if ( '' !== $request_context ) {
					$drilldown[ $plugin_slug ]['contexts'][ $request_context ] = $request_context;
				}

				$counterpart_name = $plugin_slug === $primary_slug
					? sanitize_text_field( (string) ( $finding['secondary_plugin_name'] ?? '' ) )
					: sanitize_text_field( (string) ( $finding['primary_plugin_name'] ?? '' ) );

				if ( '' !== $counterpart_name ) {
					$drilldown[ $plugin_slug ]['related_plugins'][ $counterpart_name ] = $counterpart_name;
				}

				$drilldown[ $plugin_slug ]['findings'][] = array(
					'severity' => sanitize_key( (string) ( $finding['severity'] ?? 'info' ) ),
					'title'    => sanitize_text_field( (string) ( $finding['title'] ?? '' ) ),
					'summary'  => $summary,
					'meta'     => implode( ' | ', $meta_bits ),
				);
			}
		}

		foreach ( $drilldown as $slug => $plugin_data ) {
			$drilldown[ $slug ]['contexts']        = array_values( $plugin_data['contexts'] );
			$drilldown[ $slug ]['related_plugins'] = array_values( $plugin_data['related_plugins'] );
		}

		uasort(
			$drilldown,
			function ( array $left, array $right ): int {
				$count_compare = (int) ( $right['finding_count'] ?? 0 ) <=> (int) ( $left['finding_count'] ?? 0 );
				if ( 0 !== $count_compare ) {
					return $count_compare;
				}

				return strcasecmp( (string) ( $left['name'] ?? '' ), (string) ( $right['name'] ?? '' ) );
			}
		);

		return $drilldown;
	}

	/**
	 * Builds a comparison between the current scan and the previous stored scan.
	 *
	 * @param array<string, mixed>              $results Latest results.
	 * @param array<int, array<string, mixed>> $history Scan history.
	 * @return array<string, mixed>
	 */
	private function build_scan_comparison( array $results, array $history ): array {
		$current_signatures = $this->findings_snapshot_from_results( $results );
		$previous_entry     = $history[1] ?? array();
		$previous_snapshot  = is_array( $previous_entry['findings_snapshot'] ?? null ) ? $previous_entry['findings_snapshot'] : array();

		if ( empty( $previous_entry ) ) {
			return array(
				'has_previous' => false,
			);
		}

		$current_map  = array();
		$previous_map = array();

		foreach ( $current_signatures as $item ) {
			$signature = sanitize_text_field( (string) ( $item['signature'] ?? '' ) );
			if ( '' !== $signature ) {
				$current_map[ $signature ] = $item;
			}
		}

		foreach ( $previous_snapshot as $item ) {
			$signature = sanitize_text_field( (string) ( $item['signature'] ?? '' ) );
			if ( '' !== $signature ) {
				$previous_map[ $signature ] = $item;
			}
		}

		$new_signatures      = array_diff_key( $current_map, $previous_map );
		$resolved_signatures = array_diff_key( $previous_map, $current_map );

		return array(
			'has_previous'      => true,
			'current_timestamp' => sanitize_text_field( (string) ( $results['scan_timestamp'] ?? '' ) ),
			'previous_timestamp'=> sanitize_text_field( (string) ( $previous_entry['scan_timestamp'] ?? '' ) ),
			'current_conflicts' => (int) ( $results['summary']['likely_conflicts'] ?? 0 ),
			'previous_conflicts'=> (int) ( $previous_entry['summary']['likely_conflicts'] ?? 0 ),
			'new_findings'      => array_values( array_map( array( $this, 'format_compare_finding' ), array_slice( $new_signatures, 0, 6 ) ) ),
			'resolved_findings' => array_values( array_map( array( $this, 'format_compare_finding' ), array_slice( $resolved_signatures, 0, 6 ) ) ),
		);
	}

	/**
	 * Builds a runtime event viewer model and finding links.
	 *
	 * @param array<string, mixed>              $results Latest scan results.
	 * @param array<int, array<string, mixed>> $findings Latest findings.
	 * @param array<string, mixed>              $active_session Active diagnostic session.
	 * @param array<string, mixed>              $last_session Last diagnostic session.
	 * @return array<string, mixed>
	 */
	private function build_runtime_event_view( array $results, array $findings, array $active_session = array(), array $last_session = array() ): array {
		$runtime_events      = is_array( $results['runtime_events'] ?? null ) ? $results['runtime_events'] : array();
		$focus_session       = ! empty( $active_session['id'] ) ? $active_session : $last_session;
		$focus_session_id    = sanitize_text_field( (string) ( $focus_session['id'] ?? '' ) );
		$focused_events_only = array();
		$finding_event_map  = array();
		$annotated_events   = array();
		$summary            = array(
			'total'           => 0,
			'js_errors'       => 0,
			'failed_requests' => 0,
			'mutations'       => 0,
		);

		if ( '' !== $focus_session_id ) {
			foreach ( $runtime_events as $runtime_event ) {
				if ( $focus_session_id === sanitize_text_field( (string) ( $runtime_event['session_id'] ?? '' ) ) ) {
					$focused_events_only[] = $runtime_event;
				}
			}
		}

		if ( ! empty( $focused_events_only ) ) {
			$runtime_events = $focused_events_only;
		}

		foreach ( array_slice( $runtime_events, 0, 18 ) as $index => $runtime_event ) {
			$event_id          = 'pcd-runtime-event-' . ( $index + 1 );
			$matched_findings  = array();
			$owner_labels      = array();
			$event_owner_slugs = is_array( $runtime_event['owner_slugs'] ?? null ) ? array_values( array_filter( array_map( 'sanitize_key', $runtime_event['owner_slugs'] ) ) ) : array();

			foreach ( $event_owner_slugs as $owner_slug ) {
				$owner_labels[] = $this->humanize_runtime_hint( $owner_slug );
			}

			foreach ( $findings as $finding ) {
				if ( ! $this->runtime_event_matches_finding( $runtime_event, $finding ) ) {
					continue;
				}

				$finding_signature = $this->finding_signature( $finding );
				$finding_title     = sanitize_text_field( (string) ( $finding['title'] ?? '' ) );
				$finding_label     = '' !== $finding_title
					? $finding_title
					: trim(
						implode(
							' / ',
							array_filter(
								array(
									sanitize_text_field( (string) ( $finding['primary_plugin_name'] ?? '' ) ),
									sanitize_text_field( (string) ( $finding['secondary_plugin_name'] ?? '' ) ),
								)
							)
						)
					);

				$finding_event_map[ $finding_signature ][] = array(
					'id'    => $event_id,
					'label' => $finding_label,
				);
				$matched_findings[ $finding_label ] = $finding_label;
			}

			$type = sanitize_key( (string) ( $runtime_event['type'] ?? 'runtime' ) );
			if ( in_array( $type, array( 'js_error', 'promise_rejection', 'missing_asset' ), true ) ) {
				$summary['js_errors']++;
			}

			if ( 'http_response' === $type || ! empty( $runtime_event['status_code'] ) ) {
				$summary['failed_requests']++;
			}

			if ( '' !== (string) ( $runtime_event['mutation_kind'] ?? '' ) || in_array( $type, array( 'callback_mutation', 'asset_mutation' ), true ) ) {
				$summary['mutations']++;
			}

			$annotated_events[] = array(
				'id'               => $event_id,
				'timestamp'        => sanitize_text_field( (string) ( $runtime_event['timestamp'] ?? '' ) ),
				'type'             => $type,
				'type_label'       => $this->runtime_event_type_label( $runtime_event ),
				'level_badge'      => $this->runtime_event_level_badge( $runtime_event ),
				'title'            => $this->runtime_event_title( $runtime_event ),
				'message'          => sanitize_textarea_field( (string) ( $runtime_event['message'] ?? '' ) ),
				'request_context'  => sanitize_text_field( (string) ( $runtime_event['request_context'] ?? '' ) ),
				'request_uri'      => sanitize_text_field( (string) ( $runtime_event['request_uri'] ?? '' ) ),
				'resource'         => sanitize_text_field( (string) ( $runtime_event['resource'] ?? '' ) ),
				'execution_surface'=> sanitize_text_field( (string) ( $runtime_event['execution_surface'] ?? '' ) ),
				'failure_mode'     => str_replace( '_', ' ', sanitize_key( (string) ( $runtime_event['failure_mode'] ?? '' ) ) ),
				'status_code'      => (int) ( $runtime_event['status_code'] ?? 0 ),
				'resource_hints'   => array_values(
					array_map(
						array( $this, 'humanize_runtime_hint' ),
						is_array( $runtime_event['resource_hints'] ?? null ) ? $runtime_event['resource_hints'] : array()
					)
				),
				'owner_labels'     => array_values( array_unique( array_filter( $owner_labels ) ) ),
				'matched_findings' => array_values( $matched_findings ),
			);
		}

		$summary['total'] = count( $annotated_events );

		return array(
			'summary'           => $summary,
			'events'            => $annotated_events,
			'finding_event_map' => $finding_event_map,
			'focus_label'       => ! empty( $focused_events_only ) ? sanitize_text_field( (string) ( $focus_session['label'] ?? '' ) ) : '',
		);
	}

	/**
	 * Determines whether a runtime event is relevant to a finding.
	 *
	 * @param array<string, mixed> $runtime_event Runtime event.
	 * @param array<string, mixed> $finding Finding.
	 * @return bool
	 */
	private function runtime_event_matches_finding( array $runtime_event, array $finding ): bool {
		$score            = 0;
		$request_context  = strtolower( sanitize_text_field( (string) ( $finding['request_context'] ?? '' ) ) );
		$event_context    = strtolower( sanitize_text_field( (string) ( $runtime_event['request_context'] ?? '' ) ) );
		$shared_resource  = strtolower( sanitize_text_field( (string) ( $finding['shared_resource'] ?? '' ) ) );
		$event_resource   = strtolower( sanitize_text_field( (string) ( $runtime_event['resource'] ?? '' ) ) );
		$event_surface    = strtolower( sanitize_text_field( (string) ( $runtime_event['execution_surface'] ?? '' ) ) );
		$finding_surface  = strtolower( sanitize_text_field( (string) ( $finding['execution_surface'] ?? '' ) ) );
		$owner_slugs      = is_array( $runtime_event['owner_slugs'] ?? null ) ? array_values( array_filter( array_map( 'sanitize_key', $runtime_event['owner_slugs'] ) ) ) : array();
		$resource_hints   = is_array( $runtime_event['resource_hints'] ?? null ) ? array_values( array_filter( array_map( 'sanitize_text_field', $runtime_event['resource_hints'] ) ) ) : array();
		$plugin_tokens    = array_filter(
			array(
				sanitize_key( (string) ( $finding['primary_plugin'] ?? '' ) ),
				sanitize_key( (string) ( $finding['secondary_plugin'] ?? '' ) ),
				strtolower( sanitize_text_field( (string) ( $finding['primary_plugin_name'] ?? '' ) ) ),
				strtolower( sanitize_text_field( (string) ( $finding['secondary_plugin_name'] ?? '' ) ) ),
			)
		);
		$event_text       = strtolower(
			implode(
				' ',
				array(
					(string) ( $runtime_event['message'] ?? '' ),
					(string) ( $runtime_event['source'] ?? '' ),
					(string) ( $runtime_event['resource'] ?? '' ),
					implode( ' ', $resource_hints ),
					implode( ' ', $owner_slugs ),
				)
			)
		);

		if ( '' !== $request_context && '' !== $event_context && $request_context === $event_context ) {
			$score += 2;
		}

		if ( '' !== $finding_surface && '' !== $event_surface && $finding_surface === $event_surface ) {
			$score += 3;
		}

		if ( '' !== $shared_resource ) {
			if ( '' !== $event_resource && $this->contains_comparable_fragment( $event_resource, $shared_resource ) ) {
				$score += 4;
			}

			foreach ( $resource_hints as $resource_hint ) {
				if ( $this->contains_comparable_fragment( strtolower( $resource_hint ), $shared_resource ) ) {
					$score += 4;
					break;
				}
			}
		}

		foreach ( $plugin_tokens as $plugin_token ) {
			if ( '' === $plugin_token ) {
				continue;
			}

			if ( in_array( sanitize_key( $plugin_token ), $owner_slugs, true ) ) {
				$score += 5;
				continue;
			}

			if ( $this->contains_comparable_fragment( $event_text, strtolower( $plugin_token ) ) ) {
				$score += 2;
			}
		}

		if ( ! empty( $runtime_event['status_code'] ) && in_array( (string) ( $finding['finding_type'] ?? '' ), array( 'confirmed', 'interference' ), true ) ) {
			$score += 1;
		}

		return $score >= 5;
	}

	/**
	 * Returns a UI label for a runtime event type.
	 *
	 * @param array<string, mixed> $runtime_event Runtime event.
	 * @return string
	 */
	private function runtime_event_type_label( array $runtime_event ): string {
		$type = sanitize_key( (string) ( $runtime_event['type'] ?? 'runtime' ) );

		$labels = array(
			'js_error'          => __( 'JS Error', 'plugin-conflict-debugger' ),
			'promise_rejection' => __( 'Promise Rejection', 'plugin-conflict-debugger' ),
			'missing_asset'     => __( 'Missing Asset', 'plugin-conflict-debugger' ),
			'http_response'     => __( 'Failed Request', 'plugin-conflict-debugger' ),
			'php_runtime'       => __( 'PHP Runtime', 'plugin-conflict-debugger' ),
			'callback_mutation' => __( 'Callback Mutation', 'plugin-conflict-debugger' ),
			'asset_mutation'    => __( 'Asset Mutation', 'plugin-conflict-debugger' ),
		);

		return $labels[ $type ] ?? __( 'Runtime Event', 'plugin-conflict-debugger' );
	}

	/**
	 * Returns a concise title for a runtime event.
	 *
	 * @param array<string, mixed> $runtime_event Runtime event.
	 * @return string
	 */
	private function runtime_event_title( array $runtime_event ): string {
		$resource          = sanitize_text_field( (string) ( $runtime_event['resource'] ?? '' ) );
		$execution_surface = sanitize_text_field( (string) ( $runtime_event['execution_surface'] ?? '' ) );
		$request_context   = sanitize_text_field( (string) ( $runtime_event['request_context'] ?? '' ) );
		$type_label        = $this->runtime_event_type_label( $runtime_event );

		if ( '' !== $resource ) {
			return sprintf(
				/* translators: %s runtime resource. */
				__( '%s on %s', 'plugin-conflict-debugger' ),
				$type_label,
				$resource
			);
		}

		if ( '' !== $execution_surface ) {
			return sprintf(
				/* translators: %s execution surface. */
				__( '%s around %s', 'plugin-conflict-debugger' ),
				$type_label,
				$execution_surface
			);
		}

		if ( '' !== $request_context ) {
			return sprintf(
				/* translators: %s request context. */
				__( '%s in %s', 'plugin-conflict-debugger' ),
				$type_label,
				$request_context
			);
		}

		return $type_label;
	}

	/**
	 * Maps a runtime event to a badge severity.
	 *
	 * @param array<string, mixed> $runtime_event Runtime event.
	 * @return string
	 */
	private function runtime_event_level_badge( array $runtime_event ): string {
		$type       = sanitize_key( (string) ( $runtime_event['type'] ?? 'runtime' ) );
		$status_code = (int) ( $runtime_event['status_code'] ?? 0 );

		if ( in_array( $type, array( 'php_runtime', 'js_error', 'missing_asset' ), true ) || $status_code >= 500 ) {
			return 'critical';
		}

		if ( in_array( $type, array( 'callback_mutation', 'asset_mutation', 'promise_rejection' ), true ) || $status_code >= 400 ) {
			return 'warning';
		}

		return 'info';
	}

	/**
	 * Humanizes a runtime hint for the UI.
	 *
	 * @param string $hint Raw hint.
	 * @return string
	 */
	private function humanize_runtime_hint( string $hint ): string {
		$hint = sanitize_text_field( $hint );
		if ( '' === $hint ) {
			return '';
		}

		if ( str_contains( $hint, ':' ) ) {
			list( $prefix, $value ) = array_pad( explode( ':', $hint, 2 ), 2, '' );
			$prefix = ucwords( str_replace( array( '-', '_' ), ' ', sanitize_key( $prefix ) ) );
			$value  = str_replace( array( '-', '_' ), ' ', sanitize_text_field( $value ) );

			return trim( $prefix . ': ' . $value );
		}

		return ucwords( str_replace( array( '-', '_' ), ' ', $hint ) );
	}

	/**
	 * Checks whether two strings share a meaningful comparable fragment.
	 *
	 * @param string $haystack Haystack.
	 * @param string $needle Needle.
	 * @return bool
	 */
	private function contains_comparable_fragment( string $haystack, string $needle ): bool {
		$haystack = strtolower( trim( $haystack ) );
		$needle   = strtolower( trim( $needle ) );

		if ( '' === $haystack || '' === $needle ) {
			return false;
		}

		if ( str_contains( $haystack, $needle ) || str_contains( $needle, $haystack ) ) {
			return true;
		}

		$needle_parts = preg_split( '/[\s:_\-\/\\\\]+/', $needle );
		if ( ! is_array( $needle_parts ) ) {
			return false;
		}

		foreach ( $needle_parts as $needle_part ) {
			$needle_part = trim( (string) $needle_part );
			if ( strlen( $needle_part ) < 4 ) {
				continue;
			}

			if ( str_contains( $haystack, $needle_part ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Builds a compact finding snapshot from full latest results.
	 *
	 * @param array<string, mixed> $results Latest results.
	 * @return array<int, array<string, mixed>>
	 */
	private function findings_snapshot_from_results( array $results ): array {
		$findings  = is_array( $results['findings'] ?? null ) ? $results['findings'] : array();
		$snapshot = array();

		foreach ( array_slice( $findings, 0, 25 ) as $finding ) {
			$snapshot[] = array(
				'signature'             => $this->finding_signature( $finding ),
				'title'                 => sanitize_text_field( (string) ( $finding['title'] ?? '' ) ),
				'severity'              => sanitize_key( (string) ( $finding['severity'] ?? 'info' ) ),
				'primary_plugin_name'   => sanitize_text_field( (string) ( $finding['primary_plugin_name'] ?? '' ) ),
				'secondary_plugin_name' => sanitize_text_field( (string) ( $finding['secondary_plugin_name'] ?? '' ) ),
				'request_context'       => sanitize_text_field( (string) ( $finding['request_context'] ?? '' ) ),
			);
		}

		return $snapshot;
	}

	/**
	 * Formats a comparison finding label.
	 *
	 * @param array<string, mixed> $finding Finding snapshot.
	 * @return string
	 */
	private function format_compare_finding( array $finding ): string {
		$plugins = array_filter(
			array(
				sanitize_text_field( (string) ( $finding['primary_plugin_name'] ?? '' ) ),
				sanitize_text_field( (string) ( $finding['secondary_plugin_name'] ?? '' ) ),
			)
		);

		$context = sanitize_text_field( (string) ( $finding['request_context'] ?? '' ) );
		$title   = sanitize_text_field( (string) ( $finding['title'] ?? '' ) );

		return trim(
			implode(
				' - ',
				array_filter(
					array(
						'' !== $title ? $title : implode( ' / ', $plugins ),
						! empty( $plugins ) ? implode( ' / ', $plugins ) : '',
						'' !== $context ? sprintf( __( 'Context: %s', 'plugin-conflict-debugger' ), $context ) : '',
					)
				)
			)
		);
	}

	/**
	 * Returns the higher of two severity labels.
	 *
	 * @param string $left Left severity.
	 * @param string $right Right severity.
	 * @return string
	 */
	private function higher_severity( string $left, string $right ): string {
		$ranks = array(
			'info'     => 0,
			'low'      => 1,
			'medium'   => 2,
			'high'     => 3,
			'critical' => 4,
		);

		return ( $ranks[ $right ] ?? 0 ) > ( $ranks[ $left ] ?? 0 ) ? $right : $left;
	}

	/**
	 * Builds a stable finding signature for local comparisons.
	 *
	 * @param array<string, mixed> $finding Finding.
	 * @return string
	 */
	private function finding_signature( array $finding ): string {
		return md5(
			wp_json_encode(
				array(
					'primary_plugin'    => sanitize_key( (string) ( $finding['primary_plugin'] ?? '' ) ),
					'secondary_plugin'  => sanitize_key( (string) ( $finding['secondary_plugin'] ?? '' ) ),
					'surface_key'       => sanitize_key( (string) ( $finding['surface_key'] ?? $finding['issue_category'] ?? '' ) ),
					'finding_type'      => sanitize_key( (string) ( $finding['finding_type'] ?? '' ) ),
					'request_context'   => sanitize_text_field( (string) ( $finding['request_context'] ?? '' ) ),
					'shared_resource'   => sanitize_text_field( (string) ( $finding['shared_resource'] ?? '' ) ),
					'execution_surface' => sanitize_text_field( (string) ( $finding['execution_surface'] ?? '' ) ),
				)
			)
		);
	}
}
