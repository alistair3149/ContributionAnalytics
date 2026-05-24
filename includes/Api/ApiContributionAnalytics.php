<?php

namespace MediaWiki\Extension\ContributionAnalytics\Api;

use MediaWiki\Api\ApiBase;
use MediaWiki\Api\ApiMain;
use MediaWiki\Extension\ContributionAnalytics\ContributionAnalyticsConstants;
use MediaWiki\Extension\ContributionAnalytics\ContributionAnalyticsRequest;
use MediaWiki\Extension\ContributionAnalytics\ContributionAnalyticsStore;
use MediaWiki\Extension\ContributionAnalytics\ContributionAnalyticsUtils;
use Wikimedia\ParamValidator\ParamValidator;
use Wikimedia\ParamValidator\TypeDef\IntegerDef;

class ApiContributionAnalytics extends ApiBase {

	public function __construct(
		ApiMain $mainModule,
		string $moduleName,
		private readonly ContributionAnalyticsStore $store
	) {
		parent::__construct( $mainModule, $moduleName );
	}

	public function execute(): void {
		if ( $this->getUser()->pingLimiter( 'contributionanalytics' ) ) {
			$this->dieWithError( 'apierror-ratelimited' );
		}

		$params = $this->extractRequestParams();

		$dates = ContributionAnalyticsUtils::validateDateRange(
			(string)$params['start'],
			(string)$params['end'],
			(string)$params['bucket']
		);
		if ( isset( $dates['error'] ) ) {
			$this->dieWithError( $dates['error'] );
		}
		$params['start'] = $dates['start'];
		$params['end'] = $dates['end'];

		$data = $this->store->getSeries( new ContributionAnalyticsRequest(
			$params['metric'],
			$params['bucket'],
			$params['start'],
			$params['end'],
			$params['threshold'],
			null,
			false
		) );

		$this->getResult()->addValue( null, $this->getModuleName(), $data );
	}

	/** @inheritDoc */
	protected function getAllowedParams(): array {
		[ $defaultStart, $defaultEnd ] = ContributionAnalyticsUtils::getDefaultDateRange();
		return [
			'metric' => [
				ParamValidator::PARAM_TYPE => ContributionAnalyticsConstants::METRICS,
				ParamValidator::PARAM_DEFAULT => ContributionAnalyticsConstants::METRIC_EDITS,
			],
			'bucket' => [
				ParamValidator::PARAM_TYPE => ContributionAnalyticsConstants::BUCKETS,
				ParamValidator::PARAM_DEFAULT => ContributionAnalyticsConstants::BUCKET_MONTH,
			],
			'start' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_DEFAULT => $defaultStart,
			],
			'end' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_DEFAULT => $defaultEnd,
			],
			'threshold' => [
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_DEFAULT => ContributionAnalyticsConstants::DEFAULT_EDIT_THRESHOLD,
				IntegerDef::PARAM_MIN => 1,
			],
		];
	}

	/** @inheritDoc */
	public function isReadMode(): bool {
		return true;
	}

	/** @inheritDoc */
	public function getExamplesMessages(): array {
		return [
			'action=contributionanalytics&metric=threshold&bucket=month&threshold=5&start=2024-01-01&end=2024-12-31'
				=> 'apihelp-contributionanalytics-example-threshold',
		];
	}
}
