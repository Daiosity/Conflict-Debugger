<?php
/**
 * Plugin Name: PCD Fixture Callback Owner Alpha
 * Description: Adds a callback on template_redirect for callback-mutation fixtures.
 * Version: 0.1.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function pcd_fixture_callback_owner_alpha_redirect_marker(): void {
	if ( is_admin() ) {
		return;
	}
}

add_action( 'template_redirect', 'pcd_fixture_callback_owner_alpha_redirect_marker', 20 );

