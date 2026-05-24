<?php

namespace MediaWiki\Extension\ContributionAnalytics;

class ContributionAnalyticsRequest {

	public function __construct(
		public readonly string $metric,
		public readonly string $bucket,
		public readonly string $start,
		public readonly string $end,
		public readonly int $threshold,
		private readonly ?array $visibleSegments,
		private readonly bool $hideControls
	) {
	}

	public static function newForPlotArgs(
		array $namedArgs,
		string $start,
		string $end
	): self {
		$v = trim( $namedArgs['threshold'] ?? '' );
		$threshold = ctype_digit( $v ) && (int)$v > 0
			? min( (int)$v, 1000000 )
			: ContributionAnalyticsConstants::DEFAULT_EDIT_THRESHOLD;

		$segments = null;
		if ( isset( $namedArgs['segments'] ) ) {
			$parsed = self::normalizePlotSegments( $namedArgs['segments'] );
			if ( $parsed !== [] ) {
				$segments = $parsed;
			}
		}

		return new self(
			self::normalizeChoice( $namedArgs['metric'] ?? '', ContributionAnalyticsConstants::METRICS, ContributionAnalyticsConstants::METRIC_EDITS ),
			self::normalizeChoice( $namedArgs['bucket'] ?? '', ContributionAnalyticsConstants::BUCKETS, ContributionAnalyticsConstants::BUCKET_MONTH ),
			$start,
			$end,
			$threshold,
			$segments,
			!isset( $namedArgs['controls'] ) || !self::argumentIsTruthy( $namedArgs['controls'] )
		);
	}

	public function toClientConfig(): array {
		$config = [
			'hideControls' => $this->hideControls,
			'metric' => $this->metric,
			'bucket' => $this->bucket,
			'threshold' => $this->threshold,
			'start' => $this->start,
			'end' => $this->end,
		];

		if ( $this->visibleSegments !== null ) {
			$config['visibleSegments'] = $this->visibleSegments;
		}

		return $config;
	}

	private static function normalizePlotSegments( string $value ): array {
		$segments = [];
		foreach ( explode( ',', $value ) as $segment ) {
			$segment = match ( strtolower( trim( $segment ) ) ) {
				'anon', 'ip' => ContributionAnalyticsConstants::SERIES_ANONYMOUS,
				'registered' => ContributionAnalyticsConstants::SERIES_REGISTERED,
				'all', 'total' => ContributionAnalyticsConstants::SERIES_TOTAL,
				default => '',
			};

			if ( $segment !== '' && !in_array( $segment, $segments, true ) ) {
				$segments[] = $segment;
			}
		}

		return $segments;
	}

	private static function argumentIsTruthy( string $value ): bool {
		return in_array( strtolower( trim( $value ) ), [ '1', 'true', 'yes' ], true );
	}

	public static function normalizeBucket( string $value ): string {
		return self::normalizeChoice( $value, ContributionAnalyticsConstants::BUCKETS, ContributionAnalyticsConstants::BUCKET_MONTH );
	}

	private static function normalizeChoice( string $value, array $allowed, string $fallback ): string {
		$value = strtolower( trim( $value ) );
		return in_array( $value, $allowed, true ) ? $value : $fallback;
	}
}
