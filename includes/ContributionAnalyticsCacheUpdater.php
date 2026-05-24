<?php

namespace MediaWiki\Extension\ContributionAnalytics;

use MediaWiki\User\ActorNormalization;
use MediaWiki\User\UserIdentity;
use Wikimedia\Rdbms\IDatabase;

class ContributionAnalyticsCacheUpdater {

	public static function updateCache( IDatabase $dbw ): void {
		// Lazy update. Delete the previous day's data and only update in this range.
		// This will drift from the canonical result due to page deletions and imports inserting revisions, which
		// we regard as an acceptable tradeoff.
		$dbw->doAtomicSection( __METHOD__, function () use ( $dbw ) {
			$maxDate = self::getMaxCachedDate( $dbw );
			$startTimestamp = false;

			if ( $maxDate !== null ) {
				self::deleteExisting( $dbw, $maxDate );
				$startTimestamp = $maxDate . '000000';
			}

			self::insertAggregates( $dbw, $startTimestamp );
		} );
	}

	public static function rebuild( IDatabase $dbw ): void {
		// Full rebuild. Delete everything and then recompute everything.
		self::deleteExisting( $dbw, false );
		self::insertAggregates( $dbw, false );
	}

	public static function recordContribution(
		IDatabase $dbw,
		ActorNormalization $actorNormalization,
		UserIdentity $user,
		string $timestamp
	): void {
		$dbw->upsert(
			'ca_daily_actor_edits',
			[
				'cad_date' => substr( $timestamp, 0, 8 ),
				'cad_actor' => $actorNormalization->acquireActorId( $user, $dbw ),
				'cad_actor_user' => $user->isRegistered() ? $user->getId() : null,
				'cad_edit_count' => 1,
			],
			[ [ 'cad_date', 'cad_actor' ] ],
			[ 'cad_edit_count = cad_edit_count + 1' ],
			__METHOD__
		);
	}

	public static function deleteExisting( IDatabase $dbw, string|false $startDate ): void {
		$query = $dbw->newDeleteQueryBuilder()
					 ->deleteFrom( 'ca_daily_actor_edits' );
		if ( $startDate !== false ) {
			$query->where( $dbw->expr( 'cad_date', '>=', $startDate ) );
		} else {
			$query->where( IDatabase::ALL_ROWS );
		}
		$query
			->caller( __METHOD__ )
			->execute();
	}

	private static function getMaxCachedDate( IDatabase $db ): ?string {
		$row = $db->newSelectQueryBuilder()
			->select( [ 'max_date' => 'MAX(cad_date)' ] )
			->from( 'ca_daily_actor_edits' )
			->caller( __METHOD__ )
			->fetchRow();

		return $row && $row->max_date !== null ? (string)$row->max_date : null;
	}

	private static function insertAggregates( IDatabase $dbw, false|string $startTimestamp ): void {
		$dateExpr = $dbw->buildSubstring( 'rev_timestamp', 1, 8 );
		$conds = [];

		if ( $startTimestamp !== false ) {
			$conds[] = $dbw->expr( 'rev_timestamp', '>=', $dbw->timestamp( $startTimestamp ) );
		}

		$varMap = [
			'cad_date' => $dateExpr,
			'cad_actor' => 'rev_actor',
			'cad_actor_user' => 'actor_user',
			'cad_edit_count' => 'COUNT(*)',
		];
		$selectOptions = [
			'GROUP BY' => [ $dateExpr, 'rev_actor', 'actor_user' ],
		];
		$selectJoinConds = [
			'actor' => [ 'JOIN', 'actor_id = rev_actor' ],
		];

		$dbw->insertSelect(
			'ca_daily_actor_edits',
			[ 'revision', 'actor' ],
			$varMap,
			$conds,
			__METHOD__,
			[ 'NO_AUTO_COLUMNS', 'IGNORE' ],
			$selectOptions,
			$selectJoinConds
		);
	}
}
