<?php
/**
 * Plugin Name: PCD Fixture Asset Owner Alpha
 * Description: Registers and enqueues a shared admin stylesheet for asset lifecycle tests.
 * Version: 0.1.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'admin_enqueue_scripts',
	static function (): void {
		wp_register_style(
			'pcd-fixture-shared-admin-style',
			plugins_url( 'shared.css', __FILE__ ),
			array(),
			'1.0.0'
		);
		wp_enqueue_style( 'pcd-fixture-shared-admin-style' );
	},
	10
);

