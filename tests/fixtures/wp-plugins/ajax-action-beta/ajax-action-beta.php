<?php
/**
 * Plugin Name: PCD Fixture AJAX Action Beta
 * Description: Registers the same AJAX action to simulate action collisions.
 * Version: 0.1.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function pcd_fixture_ajax_action_beta_handler(): void {
	wp_send_json_success( array( 'owner' => 'beta' ) );
}

add_action( 'wp_ajax_pcd_fixture_shared_action', 'pcd_fixture_ajax_action_beta_handler' );
add_action( 'wp_ajax_nopriv_pcd_fixture_shared_action', 'pcd_fixture_ajax_action_beta_handler' );
