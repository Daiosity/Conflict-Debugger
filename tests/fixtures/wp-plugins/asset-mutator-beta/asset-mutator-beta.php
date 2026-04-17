<?php
/**
 * Plugin Name: PCD Fixture Asset Mutator Beta
 * Description: Removes a shared admin stylesheet after it has been registered and queued.
 * Version: 0.1.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'admin_enqueue_scripts',
	static function (): void {
		wp_dequeue_style( 'pcd-fixture-shared-admin-style' );
		wp_deregister_style( 'pcd-fixture-shared-admin-style' );
	},
	100
);

