<?php

namespace MediaWiki\Extension\ContributionAnalytics;

class ContributionAnalyticsConstants {

	public const string SERIES_TOTAL = 'total';
	public const string SERIES_REGISTERED = 'registered';
	public const string SERIES_ANONYMOUS = 'anon';
	public const array GROUPED_SERIES = [
		self::SERIES_ANONYMOUS,
		self::SERIES_REGISTERED,
		self::SERIES_TOTAL,
	];

	public const string METRIC_EDITS = 'edits';
	public const string METRIC_EDITORS = 'editors';
	public const string METRIC_THRESHOLD = 'threshold';
	public const array METRICS = [
		self::METRIC_EDITS,
		self::METRIC_EDITORS,
		self::METRIC_THRESHOLD
	];

	public const string BUCKET_DAY = 'day';
	public const string BUCKET_MONTH = 'month';
	public const array BUCKETS = [
		self::BUCKET_DAY,
		self::BUCKET_MONTH
	];

	public const int DEFAULT_EDIT_THRESHOLD = 10;
}
