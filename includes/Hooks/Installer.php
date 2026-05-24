<?php

namespace MediaWiki\Extension\ContributionAnalytics\Hooks;

use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;

class Installer implements LoadExtensionSchemaUpdatesHook {

	/** @inheritDoc */
	public function onLoadExtensionSchemaUpdates( $updater ) {
		$dbType = $updater->getDB()->getType();
		$updater->addExtensionTable(
			'ca_daily_actor_edits',
			__DIR__ . "/../../sql/$dbType/tables-generated.sql"
		);
	}
}
