<?php

namespace MediaWiki\Extension\ContributionAnalytics;

use MediaWiki\Html\Html;
use MediaWiki\Json\FormatJson;

class ContributionPlotRenderer {
	public const MODULES = [ 'ext.contributionAnalytics.app' ];

	public static function render(
		ContributionAnalyticsRequest $request,
		?array $series = null
	): string {
		$config = $request->toClientConfig();
		if ( $series !== null ) {
			$config['data'] = $series;
		}
		$attrs = [
			'class' => 'mw-contributionanalytics-app',
			'data-mw-ca-config' => FormatJson::encode( $config ),
		];

		return Html::rawElement( 'div', $attrs, '' );
	}
}
