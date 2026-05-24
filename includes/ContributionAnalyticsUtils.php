<?php

namespace MediaWiki\Extension\ContributionAnalytics;

use DateTimeImmutable;
use DateTimeZone;

class ContributionAnalyticsUtils {

	public static function getDefaultDateRange(): array {
		$end = new DateTimeImmutable( 'today', new DateTimeZone( 'UTC' ) );
		return [ $end->modify( '-1 year' )->format( 'Y-m-d' ), $end->format( 'Y-m-d' ) ];
	}

	public static function parseDate( string $date ): ?DateTimeImmutable {
		$utc = new DateTimeZone( 'UTC' );
		foreach ( [ '!Y-m-d', '!Ymd' ] as $format ) {
			$dt = DateTimeImmutable::createFromFormat( $format, $date, $utc );
			if ( $dt === false ) {
				continue;
			}
			if ( DateTimeImmutable::getLastErrors() === false ) {
				return $dt;
			}
		}

		return null;
	}

	public static function normalizeDate( string $date ): ?string {
		$dt = self::parseDate( $date );
		return $dt?->format( 'Y-m-d' );
	}

	public static function dateForStorage( string $date ): string {
		$normalized = self::normalizeDate( $date );

		return $normalized === null ? gmdate( 'Ymd' ) : str_replace( '-', '', $normalized );
	}

	public static function normalizeDateRange( string $start, string $end ): array {
		$startDate = self::dateForStorage( $start );
		$endDate = self::dateForStorage( $end );

		return $startDate <= $endDate
			? [
				$startDate,
				$endDate
			]
			: [
				$endDate,
				$startDate
			];
	}

	/**
	 * Validate a user-supplied start/end date range.
	 *
	 * On success returns [ 'start' => 'YYYY-MM-DD', 'end' => 'YYYY-MM-DD' ].
	 * On failure returns [ 'error' => [ <message-key>, ...$params ] ], where
	 * the value is a message specifier suitable for ApiBase::dieWithError() or
	 * Parser::msg().
	 */
	public static function validateDateRange( string $start, string $end, string $bucket ): array {
		$startDt = self::parseDate( $start );
		if ( $startDt === null ) {
			return [ 'error' => [ 'contributionanalytics-invalid-date', $start ] ];
		}

		$endDt = self::parseDate( $end );
		if ( $endDt === null ) {
			return [ 'error' => [ 'contributionanalytics-invalid-date', $end ] ];
		}

		// Normalize to ascending order.
		if ( $startDt > $endDt ) {
			[ $startDt, $endDt ] = [ $endDt, $startDt ];
		}

		$maxYears = $bucket === ContributionAnalyticsConstants::BUCKET_DAY ? 2 : 30;
		if ( $endDt > $startDt->modify( "+{$maxYears} years" ) ) {
			return [ 'error' => [ 'contributionanalytics-range-too-large', $maxYears, $bucket ] ];
		}

		return [
			'start' => $startDt->format( 'Y-m-d' ),
			'end' => $endDt->format( 'Y-m-d' ),
		];
	}
}
