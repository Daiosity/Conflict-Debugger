<?php
/**
 * Shared helpers.
 *
 * @package PluginConflictDebugger
 */

declare(strict_types=1);

namespace PluginConflictDebugger\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Helpers {
	/**
	 * Formats datetime in site timezone.
	 *
	 * @param string $mysql_datetime Datetime string.
	 * @return string
	 */
	public static function format_datetime( string $mysql_datetime ): string {
		$timestamp = strtotime( $mysql_datetime );

		if ( ! $timestamp ) {
			return $mysql_datetime;
		}

		return wp_date(
			get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
			$timestamp
		);
	}
}
