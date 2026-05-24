<?php

namespace MediaWiki\Extension\ContributionAnalytics\Specials;

use MediaWiki\Extension\ContributionAnalytics\ContributionAnalyticsConstants;
use MediaWiki\Extension\ContributionAnalytics\ContributionAnalyticsRequest;
use MediaWiki\Extension\ContributionAnalytics\ContributionAnalyticsUtils;
use MediaWiki\Extension\ContributionAnalytics\ContributionPlotRenderer;
use MediaWiki\SpecialPage\SpecialPage;

class SpecialContributionAnalytics extends SpecialPage {

	public function __construct() {
		parent::__construct( 'ContributionAnalytics' );
	}

	/** @param ?string $subPage */
	public function execute( $subPage ): void {
		$this->setHeaders();
		$this->outputHeader();

		[ $start, $end ] = ContributionAnalyticsUtils::getDefaultDateRange();
		$request = new ContributionAnalyticsRequest(
			ContributionAnalyticsConstants::METRIC_EDITS,
			ContributionAnalyticsConstants::BUCKET_MONTH,
			$start,
			$end,
			ContributionAnalyticsConstants::DEFAULT_EDIT_THRESHOLD,
			null,
			false
		);
		$out = $this->getOutput();
		$out->addModules( ContributionPlotRenderer::MODULES );
		$out->addHTML( ContributionPlotRenderer::render( $request ) );
	}
}
