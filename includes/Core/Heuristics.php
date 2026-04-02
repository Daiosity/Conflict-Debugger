<?php
/**
 * Weighted heuristic rules with strict severity caps.
 *
 * @package PluginConflictDebugger
 */

declare(strict_types=1);

namespace PluginConflictDebugger\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Heuristics {
	/**
	 * Weighted rules by signal key.
	 *
	 * These scores are intentionally conservative. Weak overlap can raise
	 * awareness, but it must not inflate into high-severity findings without
	 * concrete interference or observed breakage.
	 *
	 * @var array<string, int>
	 */
	private array $weights = array(
		'surface_hook_overlap'       => 5,
		'surface_category_match'     => 10,
		'recent_change'              => 5,
		'known_risk_pattern'         => 5,
		'extreme_priority'           => 10,
		'surface_context_match'      => 15,
		'callback_chain_churn'       => 15,
		'repeated_observer_pattern'  => 10,
		'global_anomaly_pattern'     => 10,
		'duplicate_assets'           => 20,
		'optimization_stack_overlap' => 20,
		'cron_overlap'               => 15,
		'background_overlap'         => 15,
		'output_filter_overlap'      => 20,
		'admin_screen_overlap'       => 20,
		'editor_overlap'             => 20,
		'auth_overlap'               => 20,
		'seo_overlap'                => 20,
		'email_overlap'              => 20,
		'security_overlap'           => 20,
		'exact_hook_collision'       => 25,
		'rest_route_overlap'         => 25,
		'ajax_action_overlap'        => 25,
		'routing_overlap'            => 25,
		'content_model_overlap'      => 25,
		'direct_callback_mutation'   => 40,
		'asset_state_mutation'       => 40,
		'error_log_match'            => 60,
	);

	/**
	 * Evidence tiers by signal key.
	 *
	 * @var array<string, string>
	 */
	private array $tiers = array(
		'surface_hook_overlap'       => 'weak',
		'surface_category_match'     => 'weak',
		'recent_change'              => 'weak',
		'known_risk_pattern'         => 'weak',
		'extreme_priority'           => 'weak',
		'surface_context_match'      => 'contextual',
		'callback_chain_churn'       => 'contextual',
		'repeated_observer_pattern'  => 'contextual',
		'global_anomaly_pattern'     => 'contextual',
		'duplicate_assets'           => 'contextual',
		'optimization_stack_overlap' => 'contextual',
		'cron_overlap'               => 'contextual',
		'background_overlap'         => 'contextual',
		'output_filter_overlap'      => 'contextual',
		'admin_screen_overlap'       => 'contextual',
		'editor_overlap'             => 'contextual',
		'auth_overlap'               => 'contextual',
		'seo_overlap'                => 'contextual',
		'email_overlap'              => 'contextual',
		'security_overlap'           => 'contextual',
		'exact_hook_collision'       => 'concrete',
		'rest_route_overlap'         => 'concrete',
		'ajax_action_overlap'        => 'concrete',
		'routing_overlap'            => 'concrete',
		'content_model_overlap'      => 'concrete',
		'direct_callback_mutation'   => 'concrete',
		'asset_state_mutation'       => 'concrete',
		'error_log_match'            => 'observed',
	);

	/**
	 * Returns the tier for a given signal key.
	 *
	 * @param string $signal_key Signal key.
	 * @return string
	 */
	public function tier_for( string $signal_key ): string {
		return $this->tiers[ $signal_key ] ?? 'weak';
	}

	/**
	 * Scores structured evidence items with per-tier caps.
	 *
	 * @param array<int, array<string, mixed>> $evidence_items Evidence items.
	 * @return int
	 */
	public function score_evidence_items( array $evidence_items ): int {
		$tier_scores = array(
			'weak'       => 0,
			'contextual' => 0,
			'concrete'   => 0,
			'observed'   => 0,
		);
		$seen        = array();

		foreach ( $evidence_items as $evidence_item ) {
			$signal_key      = (string) ( $evidence_item['signal_key'] ?? '' );
			$shared_resource = (string) ( $evidence_item['shared_resource'] ?? '' );
			$tier            = (string) ( $evidence_item['tier'] ?? $this->tier_for( $signal_key ) );
			$fingerprint     = $signal_key . '|' . $shared_resource . '|' . (string) ( $evidence_item['message'] ?? '' );

			if ( isset( $seen[ $fingerprint ] ) ) {
				continue;
			}

			$seen[ $fingerprint ] = true;
			$tier_scores[ $tier ] += $this->weights[ $signal_key ] ?? 0;
		}

		$score  = min( 20, $tier_scores['weak'] );
		$score += min( 35, $tier_scores['contextual'] );
		$score += min( 65, $tier_scores['concrete'] );
		$score += min( 100, $tier_scores['observed'] );

		return (int) min( 100, $score );
	}

	/**
	 * Returns a finding type from the strongest evidence tier present.
	 *
	 * @param array<int, array<string, mixed>> $evidence_items Evidence items.
	 * @return string
	 */
	public function finding_type_for( array $evidence_items ): string {
		foreach ( $evidence_items as $evidence_item ) {
			$finding_type_hint = (string) ( $evidence_item['finding_type_hint'] ?? '' );
			if ( '' !== $finding_type_hint ) {
				return $finding_type_hint;
			}
		}

		if ( $this->has_tier( $evidence_items, 'observed' ) ) {
			return 'confirmed';
		}

		if ( $this->has_tier( $evidence_items, 'concrete' ) ) {
			return 'interference';
		}

		if ( $this->has_tier( $evidence_items, 'contextual' ) ) {
			return 'risk';
		}

		return 'overlap';
	}

	/**
	 * Returns a severity label with strict hard caps.
	 *
	 * @param array<int, array<string, mixed>> $evidence_items Evidence items.
	 * @param int                              $confidence Confidence score.
	 * @return string
	 */
	public function severity_for( array $evidence_items, int $confidence ): string {
		$has_observed   = $this->has_tier( $evidence_items, 'observed' );
		$has_concrete   = $this->has_tier( $evidence_items, 'concrete' );
		$has_contextual = $this->has_tier( $evidence_items, 'contextual' );
		$has_weak       = $this->has_tier( $evidence_items, 'weak' );

		if ( ! $has_observed && ! $has_concrete && ! $has_contextual && ! $has_weak ) {
			return 'info';
		}

		if ( $has_observed ) {
			return $confidence >= 70 ? 'critical' : 'high';
		}

		if ( $has_concrete ) {
			return $confidence >= 55 ? 'high' : 'medium';
		}

		if ( $has_contextual ) {
			return 'medium';
		}

		return 'low';
	}

	/**
	 * Returns a UI status bucket used by the dashboard chrome.
	 *
	 * @param string $severity Severity label.
	 * @return string
	 */
	public function ui_status_for( string $severity ): string {
		if ( 'critical' === $severity ) {
			return 'critical';
		}

		if ( in_array( $severity, array( 'medium', 'high' ), true ) ) {
			return 'warning';
		}

		return 'healthy';
	}

	/**
	 * Returns a severity rank for sorting.
	 *
	 * @param string $severity Severity label.
	 * @return int
	 */
	public function severity_rank( string $severity ): int {
		$ranks = array(
			'info'     => 0,
			'low'      => 1,
			'medium'   => 2,
			'high'     => 3,
			'critical' => 4,
		);

		return $ranks[ $severity ] ?? 0;
	}

	/**
	 * Returns a recommendation for a conflict surface.
	 *
	 * @param string $surface_key Conflict surface key.
	 * @return string
	 */
	public function suggestion_for( string $surface_key ): string {
		$suggestions = array(
			'frontend_rendering'     => __( 'Reproduce the affected frontend view in staging and compare output filters, shortcodes, and template overrides one plugin at a time.', 'plugin-conflict-debugger' ),
			'asset_loading'          => __( 'Inspect the affected page in staging, review duplicated handles or libraries, and disable one asset or optimization layer at a time.', 'plugin-conflict-debugger' ),
			'admin_screen'           => __( 'Open the affected admin screen in staging and compare menu/page slugs, save handlers, and admin assets one plugin at a time.', 'plugin-conflict-debugger' ),
			'editor'                 => __( 'Reproduce the issue in the editor context first, then test metaboxes, editor assets, and serialization behavior with one plugin disabled.', 'plugin-conflict-debugger' ),
			'authentication_account' => __( 'Retest login, registration, or profile flows in staging and compare redirect, auth, and account hooks one plugin at a time.', 'plugin-conflict-debugger' ),
			'rest_api_ajax'          => __( 'Call the affected REST route or AJAX action in staging and verify which plugin owns the endpoint, auth logic, or nonce flow.', 'plugin-conflict-debugger' ),
			'forms_submission'       => __( 'Submit the affected form in staging and compare validation, anti-spam, and processing handlers with one plugin disabled.', 'plugin-conflict-debugger' ),
			'caching_optimization'   => __( 'Retest the affected page with one caching or optimization layer disabled and review minification, defer, delay, and lazy-load settings.', 'plugin-conflict-debugger' ),
			'seo_metadata'           => __( 'Inspect page source and sitemap output in staging to confirm which plugin should own canonicals, schema, robots, and metadata.', 'plugin-conflict-debugger' ),
			'rewrite_routing'        => __( 'Flush permalinks in staging and retest the affected route, endpoint, or query var with one routing-related plugin disabled.', 'plugin-conflict-debugger' ),
			'content_model'          => __( 'Review the duplicate registration key in staging and decide which plugin should own the post type, taxonomy, or content registration.', 'plugin-conflict-debugger' ),
			'email_notifications'    => __( 'Trigger the affected notification path in staging and verify whether more than one plugin is altering or sending the same mail flow.', 'plugin-conflict-debugger' ),
			'security_access'        => __( 'Retest the blocked route or login flow in staging and compare redirect, capability, and access-control behavior one plugin at a time.', 'plugin-conflict-debugger' ),
			'background_processing'  => __( 'Review the affected cron or background task in staging and check whether multiple plugins are queueing or mutating the same workflow.', 'plugin-conflict-debugger' ),
			'commerce_checkout'      => __( 'Reproduce the issue on the affected cart, checkout, or product flow in staging and test one commerce customization layer at a time.', 'plugin-conflict-debugger' ),
		);

		return $suggestions[ $surface_key ] ?? __( 'Reproduce the affected request in staging first, then test one owner of the shared resource at a time.', 'plugin-conflict-debugger' );
	}

	/**
	 * Checks whether evidence items contain a tier.
	 *
	 * @param array<int, array<string, mixed>> $evidence_items Evidence items.
	 * @param string                           $tier Tier label.
	 * @return bool
	 */
	private function has_tier( array $evidence_items, string $tier ): bool {
		foreach ( $evidence_items as $evidence_item ) {
			if ( $tier === (string) ( $evidence_item['tier'] ?? '' ) ) {
				return true;
			}
		}

		return false;
	}
}
