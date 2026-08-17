<?php
/**
 * CustomSlots — parsing/query helpers for custom scheduling blocks (#941).
 *
 * A "custom" calendar (`schedule_type = 'custom'`) stores an explicit list of
 * bookable blocks instead of a weekly working-hours pattern. Each block is one
 * bookable session:
 *   { date: 'Y-m-d', start: 'H:i', end: 'H:i', capacity: int>=1, label?: string }
 *
 * These helpers are pure array/string logic (no DB), so they unit-test cleanly
 * and can be shared by the slot generator, the validator, and the frontend
 * config builder.
 *
 * @package FreeFormCertificate\SelfScheduling
 * @since 6.20.0
 */

declare(strict_types=1);

namespace FreeFormCertificate\SelfScheduling;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure helpers over the custom-blocks JSON.
 */
final class CustomSlots {

	/**
	 * Decode the stored custom_slots value (JSON string or array) into a list
	 * of normalized blocks. Malformed entries are dropped.
	 *
	 * @param string|array<mixed>|null $raw Stored custom_slots.
	 * @return array<int, array{date:string,start:string,end:string,capacity:int,label:string}>
	 */
	public static function decode( $raw ): array {
		if ( is_string( $raw ) ) {
			$raw = json_decode( $raw, true );
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$blocks = array();
		foreach ( $raw as $block ) {
			if ( ! is_array( $block ) || empty( $block['date'] ) || empty( $block['start'] ) || empty( $block['end'] ) ) {
				continue;
			}
			$blocks[] = array(
				'date'     => (string) $block['date'],
				'start'    => (string) $block['start'],
				'end'      => (string) $block['end'],
				'capacity' => max( 1, (int) ( $block['capacity'] ?? 1 ) ),
				'label'    => (string) ( $block['label'] ?? '' ),
			);
		}
		return $blocks;
	}

	/**
	 * All blocks for a given date (Y-m-d), in stored order.
	 *
	 * @param string|array<mixed>|null $raw  Stored custom_slots.
	 * @param string                   $date Date (Y-m-d).
	 * @return array<int, array{date:string,start:string,end:string,capacity:int,label:string}>
	 */
	public static function for_date( $raw, string $date ): array {
		return array_values(
			array_filter(
				self::decode( $raw ),
				static function ( $block ) use ( $date ) {
					return $block['date'] === $date;
				}
			)
		);
	}

	/**
	 * The block matching (date, start) — the unique capacity key — or null.
	 *
	 * Start times are compared at H:i granularity so a stored 'H:i' block still
	 * matches a booking whose `start_time` is 'H:i:s'.
	 *
	 * @param string|array<mixed>|null $raw   Stored custom_slots.
	 * @param string                   $date  Date (Y-m-d).
	 * @param string                   $start Start time (H:i or H:i:s).
	 * @return array{date:string,start:string,end:string,capacity:int,label:string}|null
	 */
	public static function find( $raw, string $date, string $start ): ?array {
		$needle = self::hm( $start );
		foreach ( self::decode( $raw ) as $block ) {
			if ( $block['date'] === $date && self::hm( $block['start'] ) === $needle ) {
				return $block;
			}
		}
		return null;
	}

	/**
	 * The distinct dates (Y-m-d) that carry at least one block, sorted ascending.
	 *
	 * @param string|array<mixed>|null $raw Stored custom_slots.
	 * @return array<int, string>
	 */
	public static function dates( $raw ): array {
		$dates = array();
		foreach ( self::decode( $raw ) as $block ) {
			$dates[ $block['date'] ] = true;
		}
		$dates = array_keys( $dates );
		sort( $dates );
		return $dates;
	}

	/**
	 * Normalize a time to H:i for comparison (strips any seconds).
	 *
	 * @param string $time Time (H:i or H:i:s).
	 * @return string
	 */
	public static function hm( string $time ): string {
		return substr( $time, 0, 5 );
	}
}
