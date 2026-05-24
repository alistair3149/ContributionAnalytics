<?php

use MediaWiki\Extension\ContributionAnalytics\ContributionAnalyticsStore;
use MediaWiki\MediaWikiServices;

/** @phpcs-require-sorted-array */
return [
	'ContributionAnalytics.Store' => static function ( MediaWikiServices $services ): ContributionAnalyticsStore {
		return new ContributionAnalyticsStore(
			$services->getDBLoadBalancer(),
			$services->getMainWANObjectCache(),
			$services->getMainConfig()
		);
	},
];
