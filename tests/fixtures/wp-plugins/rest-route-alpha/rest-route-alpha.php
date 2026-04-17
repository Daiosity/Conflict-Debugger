<?php
/**
 * Plugin Name: PCD Fixture REST Route Alpha
 * Description: Registers a fixture REST route for route-collision testing.
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
				'callback'            => static fn() => rest_ensure_response( array( 'owner' => 'alpha' ) ),
				'permission_callback' => '__return_true',
			)
		);
	}
);

