<?php
/**
 * Plugin Name: PCD Fixture Admin Overlap Beta
 * Description: Companion fixture plugin for broad admin overlap without pair-specific conflict proof.
 * Version: 0.1.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'admin_init',
	static function (): void {
		// Intentional no-op fixture hook.
	}
);

add_action(
	'admin_menu',
	static function (): void {
		// Intentional no-op fixture hook.
	}
);

add_action(
	'admin_enqueue_scripts',
	static function (): void {
		// Intentional no-op fixture hook.
	}
);

