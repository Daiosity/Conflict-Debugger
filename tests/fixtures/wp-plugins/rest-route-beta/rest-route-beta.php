<?php
/**
 * Plugin Name: PCD Fixture REST Route Beta
 * Description: Registers the same fixture REST route to simulate route collisions.
 * Version: 0.1.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route(
			'pcd-fixture/v1',
			'/shared',
			array(
				'methods'             => 'GET',
				'callback'            => static fn() => rest_ensure_response( array( 'owner' => 'beta' ) ),
				'permission_callback' => '__return_true',
			)
		);
	}
);

