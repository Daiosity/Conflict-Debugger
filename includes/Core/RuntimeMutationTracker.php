<?php
/**
 * Tracks callback-chain mutations on sensitive hooks.
 *
 * @package PluginConflictDebugger
 */

declare(strict_types=1);

namespace PluginConflictDebugger\Core;

use ReflectionFunction;
use ReflectionMethod;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class RuntimeMutationTracker {
	/**
	 * Telemetry repository.
	 *
	 * @var RuntimeTelemetryRepository
	 */
	private RuntimeTelemetryRepository $repository;

	/**
	 * Registry snapshot service.
	 *
	 * @var RegistrySnapshot
	 */
	private RegistrySnapshot $registry;

	/**
	 * Baseline callback snapshots.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private array $hook_baseline = array();

	/**
	 * Emitted mutation fingerprints.
	 *
	 * @var array<string, bool>
	 */
	private array $emitted = array();

	/**
	 * Sensitive hooks to monitor for callback mutations.
	 *
	 * @var string[]
	 */
	private array $sensitive_hooks = array(
		'template_redirect',
		'the_content',
		'admin_init',
		'current_screen',
		'admin_menu',
		'enqueue_block_editor_assets',
		'authenticate',
		'login_redirect',
		'rest_api_init',
		'wp_enqueue_scripts',
		'admin_enqueue_scripts',
		'script_loader_tag',
		'style_loader_tag',
	);

	/**
	 * Constructor.
	 *
	 * @param RuntimeTelemetryRepository $repository Telemetry repository.
	 * @param RegistrySnapshot           $registry Registry snapshot service.
	 */
	public function __construct( RuntimeTelemetryRepository $repository, RegistrySnapshot $registry ) {
		$this->repository = $repository;
		$this->registry   = $registry;
	}

	/**
	 * Registers runtime mutation hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'capture_hook_baseline' ), 1 );
		add_action( 'wp_loaded', array( $this, 'compare_hook_snapshots' ), 999 );
	}

	/**
	 * Captures an early hook baseline before late plugin mutations occur.
	 *
	 * @return void
	 */
	public function capture_hook_baseline(): void {
		foreach ( $this->sensitive_hooks as $hook_name ) {
			$this->hook_baseline[ $hook_name ] = $this->snapshot_hook_callbacks( $hook_name );
		}
	}

	/**
	 * Compares the current callback state against the baseline.
	 *
	 * @return void
	 */
	public function compare_hook_snapshots(): void {
		foreach ( $this->sensitive_hooks as $hook_name ) {
			$baseline = $this->hook_baseline[ $hook_name ] ?? array();
			$current  = $this->snapshot_hook_callbacks( $hook_name );

			if ( empty( $baseline ) || empty( $current ) ) {
				continue;
			}

			$current_owners = array_values(
				array_unique(
					array_filter(
						array_map(
							static fn( array $callback ): string => sanitize_key( (string) ( $callback['owner_slug'] ?? '' ) ),
							$current
						)
					)
				)
			);

			foreach ( $baseline as $fingerprint => $callback_meta ) {
				if ( isset( $current[ $fingerprint ] ) ) {
					continue;
				}

				$removed_owner = sanitize_key( (string) ( $callback_meta['owner_slug'] ?? '' ) );
				if ( '' === $removed_owner ) {
					continue;
				}

				foreach ( $current_owners as $current_owner ) {
					if ( '' === $current_owner || $current_owner === $removed_owner ) {
						continue;
					}

					$callback_label = sanitize_text_field( (string) ( $callback_meta['callback_label'] ?? '' ) );

					$this->record_event_once(
						array(
							'event_id'             => TraceEvent::new_event_id(),
							'request_id'           => TraceEvent::current_request_id(),
							'sequence'             => TraceEvent::next_sequence(),
							'type'                 => 'callback_mutation',
							'evidence_source'      => TraceEvent::SOURCE_TRACE,
							'level'                => 'warning',
							'message'              => sprintf(
								/* translators: 1: removed plugin slug, 2: callback label, 3: hook name, 4: remaining plugin slug. */
								__( 'Observed callback-chain churn: %1$s callback %2$s was present earlier on %3$s but not in the later snapshot while %4$s callbacks remained attached.', 'plugin-conflict-debugger' ),
								$removed_owner,
								'' !== $callback_label ? $callback_label : __( 'unknown callback', 'plugin-conflict-debugger' ),
								$hook_name,
								$current_owner
							),
							'request_context'      => $this->detect_request_context(),
							'request_uri'          => $this->current_request_uri(),
							'request_scope'        => $this->current_request_uri(),
							'scope_type'           => 'path',
							'resource'             => $callback_label,
							'resource_type'        => 'callback',
							'resource_key'         => $callback_label,
							'execution_surface'    => $hook_name,
							'hook'                 => $hook_name,
							'callback_identifier'  => $callback_label,
							'mutation_kind'        => 'callback_chain_churn',
							'mutation_status'      => TraceEvent::MUTATION_SUSPECTED,
							'attribution_status'   => TraceEvent::ATTRIBUTION_UNKNOWN,
							'contamination_status' => TraceEvent::CONTAMINATION_NONE,
							'actor_slug'           => '',
							'target_owner_slug'    => $removed_owner,
							'resource_hints'       => array( 'hook:' . $hook_name ),
							'owner_slugs'          => array( $removed_owner, $current_owner ),
						)
					);
				}
			}
		}
	}

	/**
	 * Snapshots a hook callback list.
	 *
	 * @param string $hook_name Hook name.
	 * @return array<string, array<string, mixed>>
	 */
	private function snapshot_hook_callbacks( string $hook_name ): array {
		global $wp_filter;

		if ( empty( $wp_filter[ $hook_name ] ) || ! $wp_filter[ $hook_name ] instanceof \WP_Hook ) {
			return array();
		}

		$snapshot = array();

		foreach ( $wp_filter[ $hook_name ]->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$callback_function = $callback['function'] ?? null;
				$owner_slug        = $this->resolve_plugin_slug_from_callback( $callback_function );
				if ( '' === $owner_slug ) {
					continue;
				}

				$fingerprint = $this->fingerprint_callback( $callback_function, (int) $priority, $hook_name );
				if ( '' === $fingerprint ) {
					continue;
				}

				$snapshot[ $fingerprint ] = array(
					'owner_slug'     => $owner_slug,
					'priority'       => (int) $priority,
					'hook'           => $hook_name,
					'callback_label' => $this->describe_callback( $callback_function ),
				);
			}
		}

		return $snapshot;
	}

	/**
	 * Records a runtime event once per request.
	 *
	 * @param array<string, mixed> $event Event payload.
	 * @return void
	 */
	private function record_event_once( array $event ): void {
		$fingerprint = md5(
			wp_json_encode(
				array(
					'type'        => (string) ( $event['type'] ?? '' ),
					'resource'    => (string) ( $event['resource'] ?? '' ),
					'owners'      => (array) ( $event['owner_slugs'] ?? array() ),
					'request_uri' => (string) ( $event['request_uri'] ?? '' ),
				)
			)
		);

		if ( isset( $this->emitted[ $fingerprint ] ) ) {
			return;
		}

		$this->emitted[ $fingerprint ] = true;
		$this->repository->record_event(
			array_merge(
				array(
					'timestamp' => current_time( 'mysql' ),
				),
				$event
			)
		);
	}

	/**
	 * Returns current request context.
	 *
	 * @return string
	 */
	private function detect_request_context(): string {
		global $pagenow;

		if ( wp_doing_cron() ) {
			return 'cron';
		}

		if ( wp_doing_ajax() ) {
			return 'ajax';
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return 'REST';
		}

		if ( 'wp-login.php' === $pagenow ) {
			return 'login';
		}

		if ( is_admin() ) {
			return 'admin';
		}

		return 'frontend';
	}

	/**
	 * Returns the current request URI.
	 *
	 * @return string
	 */
	private function current_request_uri(): string {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( (string) $_SERVER['REQUEST_URI'] ) : '/';
		return sanitize_text_field( $request_uri );
	}

	/**
	 * Resolves a plugin slug from a callback.
	 *
	 * @param mixed $callback Callback.
	 * @return string
	 */
	private function resolve_plugin_slug_from_callback( mixed $callback ): string {
		try {
			if ( is_array( $callback ) && isset( $callback[0], $callback[1] ) ) {
				$reflection = new ReflectionMethod( $callback[0], (string) $callback[1] );
				return $this->resolve_plugin_slug_from_path( (string) $reflection->getFileName() );
			}

			if ( is_string( $callback ) && function_exists( $callback ) ) {
				$reflection = new ReflectionFunction( $callback );
				return $this->resolve_plugin_slug_from_path( (string) $reflection->getFileName() );
			}

			if ( $callback instanceof \Closure ) {
				$reflection = new ReflectionFunction( $callback );
				return $this->resolve_plugin_slug_from_path( (string) $reflection->getFileName() );
			}
		} catch ( Throwable $exception ) {
			return '';
		}

		return '';
	}

	/**
	 * Resolves a plugin slug from a file path.
	 *
	 * @param string $path File path or URL.
	 * @return string
	 */
	private function resolve_plugin_slug_from_path( string $path ): string {
		if ( false !== strpos( $path, WP_PLUGIN_URL ) ) {
			$relative = wp_make_link_relative( $path );
			$parts    = explode( '/', trim( $relative, '/' ) );
			$index    = array_search( 'plugins', $parts, true );

			if ( false !== $index && isset( $parts[ $index + 1 ] ) ) {
				return sanitize_key( $parts[ $index + 1 ] );
			}
		}

		$plugin_dir = wp_normalize_path( WP_PLUGIN_DIR );
		$normalized = wp_normalize_path( $path );

		if ( 0 !== strpos( $normalized, $plugin_dir ) ) {
			return '';
		}

		$relative = ltrim( substr( $normalized, strlen( $plugin_dir ) ), '/' );
		$parts    = explode( '/', $relative );

		return isset( $parts[0] ) ? sanitize_key( $parts[0] ) : '';
	}

	/**
	 * Builds a stable callback fingerprint.
	 *
	 * @param mixed  $callback Callback.
	 * @param int    $priority Callback priority.
	 * @param string $hook_name Hook name.
	 * @return string
	 */
	private function fingerprint_callback( mixed $callback, int $priority, string $hook_name ): string {
		if ( is_array( $callback ) && isset( $callback[1] ) ) {
			$class = is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0];
			return md5( $hook_name . '|' . $priority . '|' . $class . '::' . (string) $callback[1] );
		}

		if ( is_string( $callback ) ) {
			return md5( $hook_name . '|' . $priority . '|' . $callback );
		}

		if ( $callback instanceof \Closure ) {
			return md5( $hook_name . '|' . $priority . '|closure' );
		}

		return '';
	}

	/**
	 * Builds a readable callback identifier for runtime telemetry.
	 *
	 * @param mixed $callback Callback.
	 * @return string
	 */
	private function describe_callback( mixed $callback ): string {
		if ( is_array( $callback ) && isset( $callback[1] ) ) {
			$class = is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0];
			return sanitize_text_field( $class . '::' . (string) $callback[1] );
		}

		if ( is_string( $callback ) ) {
			return sanitize_text_field( $callback );
		}

		if ( $callback instanceof \Closure ) {
			return 'Closure';
		}

		return '';
	}
}
