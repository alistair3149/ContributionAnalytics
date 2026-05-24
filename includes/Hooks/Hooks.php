<?php

namespace MediaWiki\Extension\ContributionAnalytics\Hooks;

use DateTimeImmutable;
use DateTimeZone;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\ContributionAnalytics\ContributionAnalyticsCacheUpdater;
use MediaWiki\Extension\ContributionAnalytics\ContributionAnalyticsConstants;
use MediaWiki\Extension\ContributionAnalytics\ContributionAnalyticsRequest;
use MediaWiki\Extension\ContributionAnalytics\ContributionAnalyticsStore;
use MediaWiki\Extension\ContributionAnalytics\ContributionAnalyticsUtils;
use MediaWiki\Extension\ContributionAnalytics\ContributionPlotRenderer;
use MediaWiki\Extension\ContributionAnalytics\ContributionTilesRenderer;
use MediaWiki\Parser\Parser;
use MediaWiki\User\ActorNormalization;
use MediaWiki\User\UserFactory;
use Wikimedia\LightweightObjectStore\ExpirationAwareness;
use Wikimedia\Rdbms\ILoadBalancer;

class Hooks {
	public function __construct(
		private readonly ContributionAnalyticsStore $store,
		private readonly UserFactory $userFactory,
		private readonly ActorNormalization $actorNormalization,
		private readonly ILoadBalancer $loadBalancer
	) {
	}

	/** @inheritDoc */
	public function onParserFirstCallInit( Parser $parser ): void {
		$parser->setFunctionHook( 'contribution_tiles', [ $this, 'renderContributionTiles' ] );
		$parser->setFunctionHook( 'contribution_plot', [ $this, 'renderContributionPlot' ] );
	}

	public static function onRegistration(): void {
		$GLOBALS['wgSpecialPageCacheUpdates']['ContributionAnalytics'] = [
			ContributionAnalyticsCacheUpdater::class,
			'rebuild',
		];
	}

	/** @inheritDoc */
	public function onPageSaveComplete( $wikiPage, $user, $summary, $flags, $revisionRecord, $editResult ): void {
		$timestamp = $revisionRecord->getTimestamp();
		$actorNormalization = $this->actorNormalization;
		$loadBalancer = $this->loadBalancer;

		DeferredUpdates::addCallableUpdate(
			static function () use ( $loadBalancer, $actorNormalization, $user, $timestamp ) {
				ContributionAnalyticsCacheUpdater::recordContribution(
					$loadBalancer->getConnection( DB_PRIMARY ),
					$actorNormalization,
					$user,
					$timestamp
				);
			},
			DeferredUpdates::POSTSEND
		);
	}

	public function renderContributionTiles( Parser $parser, ...$args ): array {
		if ( !$parser->incrementExpensiveFunctionCount() ) {
			return [ '' ];
		}

		[ 'named' => $named, 'positional' => $positional ] =
			self::parseFunctionArgs( $args );

		$dates = $this->resolveDateRange( $parser, $named, ContributionAnalyticsConstants::BUCKET_DAY );
		if ( !isset( $dates['start'] ) ) {
			return $dates;
		}

		$userName = $named['user'] ?? $positional[0] ?? '';
		$actorId = null;
		if ( $userName !== '' ) {
			$user = $this->userFactory->newFromName( $userName );
			if ( $user ) {
				$userName = $user->getName();
				$actorId = $this->actorNormalization->findActorIdByName(
					$userName,
					$this->loadBalancer->getConnection( DB_REPLICA )
				);
			}
			// Invalid name or unknown actor: fall back to the site-wide view.
			if ( $actorId === null || $actorId <= 0 ) {
				$userName = '';
				$actorId = null;
			}
		}

		$days = $this->store->getDailyEditCounts( $dates['start'], $dates['end'], $actorId );
		$output = $parser->getOutput();
		$output->addModuleStyles( ContributionTilesRenderer::MODULE_STYLES );
		$output->addModules( ContributionTilesRenderer::MODULES );
		if ( self::todayIsInRange( $dates['end'] ) ) {
			$output->updateCacheExpiry( ExpirationAwareness::TTL_DAY, 'contribution_tiles' );
		}
		$titleOverride = $named['title'] ?? null;
		$summaryOverride = $named['summary'] ?? null;
		$html = ( new ContributionTilesRenderer( $parser ) )->render( $days, $userName, $titleOverride, $summaryOverride );

		return [
			$html,
			'noparse' => true,
			'isHTML' => true,
		];
	}

	public function renderContributionPlot( Parser $parser, ...$args ): array {
		if ( !$parser->incrementExpensiveFunctionCount() ) {
			return [ '' ];
		}
		$named = self::parseFunctionArgs( $args )['named'];
		$bucket = ContributionAnalyticsRequest::normalizeBucket( $named['bucket'] ?? '' );
		$dates = $this->resolveDateRange( $parser, $named, $bucket );
		if ( !isset( $dates['start'] ) ) {
			return $dates;
		}
		$request = ContributionAnalyticsRequest::newForPlotArgs(
			$named,
			$dates['start'],
			$dates['end']
		);
		$series = $this->store->getSeries( $request );
		$output = $parser->getOutput();
		$output->addModules( ContributionPlotRenderer::MODULES );
		if ( self::todayIsInRange( $dates['end'] ) ) {
			$output->updateCacheExpiry( ExpirationAwareness::TTL_DAY, 'contribution_plot' );
		}
		$html = ContributionPlotRenderer::render( $request, $series );

		return [
			$html,
			'noparse' => true,
			'isHTML' => true,
		];
	}

	private static function todayIsInRange( string $end ): bool {
		$endDate = ContributionAnalyticsUtils::parseDate( $end );
		if ( $endDate === null ) {
			return false;
		}
		return $endDate >= new DateTimeImmutable( 'yesterday', new DateTimeZone( 'UTC' ) );
	}

	/**
	 * @return array{named:array<string,string>,positional:array<int,string>}
	 */
	private static function parseFunctionArgs( array $args ): array {
		$named = [];
		$positional = [];
		foreach ( $args as $arg ) {
			$arg = trim( (string)$arg );
			if ( $arg === '' ) {
				continue;
			}
			if ( str_contains( $arg, '=' ) ) {
				[ $key, $value ] = array_map( 'trim', explode( '=', $arg, 2 ) );
				$named[strtolower( $key )] = $value;
			} else {
				$positional[] = $arg;
			}
		}
		return [ 'named' => $named, 'positional' => $positional ];
	}

	private function resolveDateRange(
		Parser $parser,
		array $namedArgs,
		string $bucket
	): array {
		[ $defaultStart, $defaultEnd ] = ContributionAnalyticsUtils::getDefaultDateRange();
		$start = $namedArgs['start'] ?? $defaultStart;
		$end = $namedArgs['end'] ?? $defaultEnd;

		$result = ContributionAnalyticsUtils::validateDateRange( $start, $end, $bucket );
		if ( isset( $result['error'] ) ) {
			return [
				'<span class="error">' . $parser->msg( ...$result['error'] )->escaped() . '</span>',
				'noparse' => true,
				'isHTML' => true,
			];
		}

		return [ 'start' => $result['start'], 'end' => $result['end'] ];
	}
}
