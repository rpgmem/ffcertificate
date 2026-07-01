<?php
/**
 * Namespaced WP-function shims for order-independent unit tests.
 *
 * Production code calls WordPress helpers unqualified inside plugin namespaces
 * (e.g. FreeFormCertificate\Core\RequestInput calls `wp_unslash()`), so PHP
 * resolves them to `FreeFormCertificate\Core\wp_unslash` first and only falls
 * back to the global when that namespaced symbol does not exist.
 *
 * Brain Monkey stubs functions via Patchwork. When a test does
 * `Functions\when('FreeFormCertificate\Core\wp_unslash')` and that namespaced
 * function does NOT already exist, Patchwork *creates* it — and PHP cannot
 * undefine a function, so it lingers past the test, routing to Brain Monkey
 * with no expectation. The next test that calls it (without re-stubbing) then
 * dies with "not defined nor mocked". This made the suite depend on file order
 * and blocked CI coverage sharding (each shard is a different subset of files,
 * so different tests became the victim).
 *
 * Pre-defining these shims breaks the coupling: the namespaced symbol ALWAYS
 * exists, so Brain Monkey/Patchwork *redefines and restores* it per test
 * instead of leaving a lingering create. Each shim performs the helper's
 * faithful behaviour inline (not a delegation to the global, which could itself
 * be a lingering Brain Monkey stub) — for the clean scalar inputs unit tests
 * use, this equals the usual returnArg/real-WP result; a test needing bespoke
 * behaviour simply stubs the namespaced function, which transparently
 * redefines the shim for that test.
 *
 * This file lives under tests/ (outside the pcov ./includes scope), so it never
 * affects coverage numbers. Only the RequestInput helper trio + sanitize_key
 * are shimmed — the Core helpers plugin code calls unqualified on hot paths
 * reached across the whole codebase. Add more only when a real un-stubbed call
 * path exists; over-shimming would mask genuinely missing mocks.
 */

namespace FreeFormCertificate\Core {

	if ( ! \function_exists( __NAMESPACE__ . '\\wp_unslash' ) ) {
		function wp_unslash( $value ) {
			if ( \is_array( $value ) ) {
				return \array_map( __NAMESPACE__ . '\\wp_unslash', $value );
			}
			return \is_string( $value ) ? \stripslashes( $value ) : $value;
		}
	}

	if ( ! \function_exists( __NAMESPACE__ . '\\sanitize_text_field' ) ) {
		function sanitize_text_field( $value ) {
			if ( ! \is_string( $value ) ) {
				return '';
			}
			return \trim( \preg_replace( '/[\r\n\t ]+/', ' ', \strip_tags( $value ) ) );
		}
	}

	if ( ! \function_exists( __NAMESPACE__ . '\\absint' ) ) {
		function absint( $value ) {
			return \abs( (int) $value );
		}
	}

	if ( ! \function_exists( __NAMESPACE__ . '\\sanitize_key' ) ) {
		function sanitize_key( $value ) {
			return \is_string( $value ) ? \strtolower( \preg_replace( '/[^a-z0-9_\-]/', '', $value ) ) : '';
		}
	}
}
