<?php

namespace MediaWiki\Extension\ContributionAnalytics\Maintenance;

use MediaWiki\Extension\ContributionAnalytics\ContributionAnalyticsCacheUpdater;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\MediaWikiServices;

// @codeCoverageIgnoreStart
require_once __DIR__ . '/../../../maintenance/Maintenance.php';
// @codeCoverageIgnoreEnd

class RebuildContributionAnalytics extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Rebuild ContributionAnalytics actor-day cache from live revisions.' );
	}

	public function execute(): void {
		$this->output( "Rebuilding contribution analytics cache...\n" );
		ContributionAnalyticsCacheUpdater::rebuild( $this->getPrimaryDB() );
		$this->output( "Done.\n" );
	}
}

// @codeCoverageIgnoreStart
$maintClass = RebuildContributionAnalytics::class;
require_once RUN_MAINTENANCE_IF_MAIN;
// @codeCoverageIgnoreEnd
