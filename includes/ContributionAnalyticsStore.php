<?php

namespace MediaWiki\Extension\ContributionAnalytics;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use MediaWiki\Config\Config;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\MainConfigNames;
use stdClass;
use Wikimedia\LightweightObjectStore\ExpirationAwareness;
use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\ILoadBalancer;
use Wikimedia\Rdbms\RawSQLExpression;
use Wikimedia\Rdbms\Subquery;

class ContributionAnalyticsStore {

	public function __construct(
		private readonly ILoadBalancer $loadBalancer,
		private readonly WANObjectCache $wanCache,
		private readonly Config $config
	) {
	}

	/**
	 * This function is unused for now. It allows for scheduling updates in the absence of built-in MediaWiki
	 * timers by leveraging the WAN cache.
	 *
	 * @return void
	 */
	public function scheduleRefreshIfNeeded(): void {
		$loadBalancer = $this->loadBalancer;
		$this->wanCache->getWithSetCallback(
			$this->wanCache->makeKey(
				'contributionanalytics',
				'database-cache',
				$this->config->get( MainConfigNames::DBname )
			),
			// Even if the wiki is edited a lot, this ensures that _usually_ the table is not rebuilt until
			// 12 hours later. Hacky, but works around the lack of timers in MediaWiki.
			ExpirationAwareness::TTL_HOUR * 12,
			static function () use ( $loadBalancer ) {
				DeferredUpdates::addCallableUpdate(
					static function () use ( $loadBalancer ) {
						ContributionAnalyticsCacheUpdater::updateCache(
							$loadBalancer->getConnection( DB_PRIMARY ),
						);
					},
					DeferredUpdates::POSTSEND
				);
				// Just a placeholder value in the cache.
				return true;
			},
			[ 'lockTSE' => 10 ]
		);
	}

	public function getSeries( ContributionAnalyticsRequest $request ): array {
		[ $startDate, $endDate ] = ContributionAnalyticsUtils::normalizeDateRange(
			$request->start,
			$request->end
		);
		$metric = $request->metric;
		$bucket = $request->bucket;
		$threshold = $request->threshold;

		if ( $metric === ContributionAnalyticsConstants::METRIC_THRESHOLD ) {
			return $this->getThresholdSeries( $bucket, $startDate, $endDate, $threshold );
		}

		return $this->getGroupedSeries( $metric, $bucket, $startDate, $endDate );
	}

	public function getDailyEditCounts( string $start, string $end, ?int $actorId = null ): array {
		[ $startDate, $endDate ] = ContributionAnalyticsUtils::normalizeDateRange( $start, $end );

		$result = self::buildEmptyOutput( $startDate, $endDate, ContributionAnalyticsConstants::BUCKET_DAY );
		foreach ( $this->fetchDailyEditCounts( $startDate, $endDate, $actorId ) as $row ) {
			$label = self::formatDateForOutput( (string)$row->cad_date );
			if ( isset( $result[$label] ) ) {
				$result[$label]['value'] = (int)$row->edit_count;
			}
		}

		return array_values( $result );
	}

	private function getGroupedSeries(
		string $metric,
		string $bucket,
		string $startDate,
		string $endDate
	): array {
		$emptyPeriods = self::buildEmptyOutput( $startDate, $endDate, $bucket );
		$seriesPeriods = array_fill_keys( ContributionAnalyticsConstants::GROUPED_SERIES, $emptyPeriods );

		foreach ( $this->fetchGroupedRows( $metric, $bucket, $startDate, $endDate ) as $row ) {
			$period = self::formatPeriod( (string)$row->period, $bucket );
			$seriesKey = (string)$row->series_key;
			if ( !isset( $seriesPeriods[$seriesKey][$period] ) ) {
				continue;
			}

			$value = (int)$row->value;
			$seriesPeriods[$seriesKey][$period]['value'] = $value;
			if ( $seriesKey !== ContributionAnalyticsConstants::SERIES_TOTAL ) {
				$seriesPeriods[ContributionAnalyticsConstants::SERIES_TOTAL][$period]['value'] += $value;
			}
		}

		return [
			'labels' => self::labelsFromPeriods( $emptyPeriods ),
			'datasets' => self::buildDatasets( $seriesPeriods, ContributionAnalyticsConstants::GROUPED_SERIES ),
			'meta' => [
				'metric' => $metric,
				'bucket' => $bucket,
			],
		];
	}

	private function getThresholdSeries(
		string $bucket,
		string $startDate,
		string $endDate,
		int $threshold
	): array {
		$periods = self::buildEmptyOutput( $startDate, $endDate, $bucket );
		foreach ( $this->fetchThresholdRows( $bucket, $startDate, $endDate, $threshold ) as $row ) {
			$period = self::formatPeriod( (string)$row->period, $bucket );
			if ( isset( $periods[$period] ) ) {
				$periods[$period]['value'] = (int)$row->value;
			}
		}

		return [
			'labels' => self::labelsFromPeriods( $periods ),
			'datasets' => self::buildDatasets(
				[ ContributionAnalyticsConstants::SERIES_TOTAL => $periods ],
				[ ContributionAnalyticsConstants::SERIES_TOTAL ]
			),
			'meta' => [
				'metric' => ContributionAnalyticsConstants::METRIC_THRESHOLD,
				'bucket' => $bucket,
				'threshold' => $threshold,
			],
		];
	}

	/** @return stdClass[] */
	private function fetchGroupedRows( string $metric, string $bucket, string $startDate, string $endDate ): array {
		$dbr = $this->loadBalancer->getConnection( DB_REPLICA );
		$periodExpr = self::getPeriodExpression( $dbr, $bucket );
		$seriesExpr = 'CASE WHEN cad_actor_user IS NULL THEN ' .
			$dbr->addQuotes( ContributionAnalyticsConstants::SERIES_ANONYMOUS ) . ' ELSE ' .
			$dbr->addQuotes( ContributionAnalyticsConstants::SERIES_REGISTERED ) . ' END';
		$valueExpr = $metric === ContributionAnalyticsConstants::METRIC_EDITS
			?
			'SUM(cad_edit_count)' :
			'COUNT(DISTINCT cad_actor)';

		$res = $dbr->newSelectQueryBuilder()
			->select( [
				'period' => $periodExpr,
				'series_key' => $seriesExpr,
				'value' => $valueExpr,
			] )
			->from( 'ca_daily_actor_edits' )
			->where( [
				$dbr->expr( 'cad_date', '>=', $startDate ),
				$dbr->expr( 'cad_date', '<=', $endDate ),
			] )
			->groupBy( [ $periodExpr, $seriesExpr ] )
			->orderBy( [ $periodExpr, $seriesExpr ] )
			->caller( __METHOD__ )
			->fetchResultSet();

		return iterator_to_array( $res );
	}

	/** @return stdClass[] */
	private function fetchThresholdRows( string $bucket, string $startDate, string $endDate, int $threshold ): array {
		$dbr = $this->loadBalancer->getConnection( DB_REPLICA );
		$periodExpr = self::getPeriodExpression( $dbr, $bucket );

		$actorPeriodSql = $dbr->newSelectQueryBuilder()
			->select( [
				'period' => $periodExpr,
				'cad_actor',
			] )
			->from( 'ca_daily_actor_edits' )
			->where( [
				$dbr->expr( 'cad_date', '>=', $startDate ),
				$dbr->expr( 'cad_date', '<=', $endDate ),
			] )
			->groupBy( [ $periodExpr, 'cad_actor' ] )
			->having( new RawSQLExpression( 'SUM(cad_edit_count) >= ' . $threshold ) )
			->caller( __METHOD__ )
			->getSQL();

		$res = $dbr->newSelectQueryBuilder()
			->select( [
				'period',
				'value' => 'COUNT(*)',
			] )
			->from( new Subquery( $actorPeriodSql ), 'actor_period' )
			->groupBy( 'period' )
			->orderBy( 'period' )
			->caller( __METHOD__ )
			->fetchResultSet();

		return iterator_to_array( $res );
	}

	/** @return stdClass[] */
	private function fetchDailyEditCounts( string $startDate, string $endDate, ?int $actorId ): array {
		$dbr = $this->loadBalancer->getConnection( DB_REPLICA );
		$conds = [
			$dbr->expr( 'cad_date', '>=', $startDate ),
			$dbr->expr( 'cad_date', '<=', $endDate ),
		];
		if ( $actorId !== null ) {
			$conds['cad_actor'] = $actorId;
		}

		$res = $dbr->newSelectQueryBuilder()
			->select( [
				'cad_date',
				'edit_count' => 'SUM(cad_edit_count)',
			] )
			->from( 'ca_daily_actor_edits' )
			->where( $conds )
			->groupBy( 'cad_date' )
			->orderBy( 'cad_date' )
			->caller( __METHOD__ )
			->fetchResultSet();

		return iterator_to_array( $res );
	}

	private static function formatDateForOutput( string $date ): string {
		return substr( $date, 0, 4 ) . '-' . substr( $date, 4, 2 ) . '-' . substr( $date, 6, 2 );
	}

	private static function formatPeriod( string $value, string $bucket ): string {
		if ( $bucket === ContributionAnalyticsConstants::BUCKET_MONTH ) {
			return substr( $value, 0, 4 ) . '-' . substr( $value, 4, 2 );
		}
		return self::formatDateForOutput( $value );
	}

	private static function getPeriodExpression( IDatabase $db, string $bucket ): string {
		if ( $bucket === ContributionAnalyticsConstants::BUCKET_MONTH ) {
			return $db->buildSubstring( 'cad_date', 1, 6 );
		}
		return 'cad_date';
	}

	private static function buildEmptyOutput( string $startDate, string $endDate, string $bucket ): array {
		$utc = new DateTimeZone( 'UTC' );
		$start = DateTimeImmutable::createFromFormat( '!Ymd', $startDate, $utc );
		$end = DateTimeImmutable::createFromFormat( '!Ymd', $endDate, $utc );
		if ( !$start || !$end ) {
			return [];
		}

		if ( $bucket === ContributionAnalyticsConstants::BUCKET_MONTH ) {
			$start = $start->modify( 'first day of this month' );
			$end = $end->modify( 'first day of this month' );
			$interval = new DateInterval( 'P1M' );
		} else {
			$interval = new DateInterval( 'P1D' );
		}

		$periods = [];
		for ( $date = $start; $date <= $end; $date = $date->add( $interval ) ) {
			$label = self::formatPeriod( $date->format( 'Ymd' ), $bucket );
			$periods[$label] = [
				'label' => $label,
				'value' => 0,
			];
		}

		return $periods;
	}

	private static function labelsFromPeriods( array $periods ): array {
		return array_column( array_values( $periods ), 'label' );
	}

	private static function valuesFromPeriods( array $periods ): array {
		return array_column( array_values( $periods ), 'value' );
	}

	private static function buildDatasets( array $seriesPeriods, array $seriesKeys ): array {
		$datasets = [];
		foreach ( $seriesKeys as $seriesKey ) {
			$datasets[] = [
				'key' => $seriesKey,
				'values' => self::valuesFromPeriods( $seriesPeriods[$seriesKey] ),
			];
		}

		return $datasets;
	}
}
