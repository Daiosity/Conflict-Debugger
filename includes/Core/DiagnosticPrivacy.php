<?php
/**
 * Bounded redaction for locally stored diagnostics.
 *
 * @package PluginConflictDebugger
 */

declare(strict_types=1);

namespace PluginConflictDebugger\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DiagnosticPrivacy {
	/**
	 * Keeps a request path without credentials, fragments or query values.
	 *
	 * @param string $uri Request or asset URL.
	 * @return string
	 */
	public static function path( string $uri ): string {
		$path = wp_parse_url( $uri, PHP_URL_PATH );
		return is_string( $path ) ? substr( sanitize_text_field( $path ), 0, 1024 ) : '';
	}

	/**
	 * Redacts URL queries, credential assignments and email addresses in messages.
	 *
	 * @param string $text Diagnostic text.
	 * @return string
	 */
	public static function text( string $text ): string {
		$text = substr( $text, 0, 4096 );
		$text = preg_replace_callback( '~https?://[^\s<>"\']+~i', static fn( array $match ): string => self::path( $match[0] ), $text ) ?? '';
		$text = preg_replace( '~\?[^\s<>"\']*~', '?[redacted]', $text ) ?? '';
		$text = preg_replace( '~\b(password|passwd|token|secret|nonce|api_key|authorization|cookie)\s*[:=]\s*[^\s,;]+~i', '$1=[redacted]', $text ) ?? '';
		$text = preg_replace( '~[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}~i', '[email]', $text ) ?? '';
		return sanitize_textarea_field( $text );
	}

	/**
	 * Bounds nested payloads and redacts before persistence or display.
	 *
	 * @param array $payload Diagnostic record.
	 * @param int   $depth Current nesting depth.
	 * @return array
	 */
	public static function scrub( array $payload, int $depth = 0 ): array {
		if ( $depth >= 6 ) {
			return array();
		}
		$result = array();
		foreach ( array_slice( $payload, 0, 100, true ) as $key => $value ) {
			if ( preg_match( '/password|passwd|secret|token|nonce|authorization|cookie|api_key/i', (string) $key ) ) {
				$result[ $key ] = '[redacted]';
			} elseif ( is_array( $value ) ) {
				$result[ $key ] = self::scrub( $value, $depth + 1 );
			} elseif ( is_string( $value ) ) {
				$is_url = in_array( $key, array( 'request_uri', 'source', 'src', 'url' ), true ) && ( str_contains( $value, '?' ) || str_contains( $value, '://' ) );
				$result[ $key ] = $is_url ? self::path( $value ) : self::text( $value );
			} elseif ( is_scalar( $value ) || null === $value ) {
				$result[ $key ] = $value;
			}
		}
		return $result;
	}
}
