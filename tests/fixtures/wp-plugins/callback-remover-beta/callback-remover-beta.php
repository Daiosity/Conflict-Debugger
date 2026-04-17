<?php
/**
 * Plugin Name: PCD Fixture Callback Remover Beta
 * Description: Removes a callback owned by another fixture plugin to simulate callback suppression.
 * Version: 0.1.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'plugins_loaded',
	static function (): void {
		remove_action( 'template_redirect', 'pcd_fixture_callback_owner_alpha_redirect_marker', 20 );
	},
	20
);

