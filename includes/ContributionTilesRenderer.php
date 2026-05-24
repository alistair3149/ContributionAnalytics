<?php

namespace MediaWiki\Extension\ContributionAnalytics;

use DateTimeImmutable;
use DateTimeZone;
use MediaWiki\Html\Html;
use MediaWiki\Language\RawMessage;
use MediaWiki\Parser\Parser;

class ContributionTilesRenderer {
	public const MODULES = [ 'ext.contributionAnalytics.tiles' ];
	public const MODULE_STYLES = [ 'ext.contributionAnalytics.tiles.styles' ];

	public function __construct( private readonly Parser $parser ) {
	}

	public function render(
		array $days,
		string $userName = '',
		?string $customTitle = null,
		?string $customSummary = null,
	): string {
		[ $max, $total ] = $this->getCounts( $days );
		$tiles = $this->makeLeadingSpacers( $days );

		foreach ( $days as $day ) {
			$value = (int)$day['value'];
			$level = $this->getTileLevel( $value, $max );
			$editCountMessage = $this->parser
				->msg( 'contributionanalytics-tiles-edit-count' )
				->numParams( $value )
				->text();
			$tiles .= Html::element( 'span', [
				'class' => 'mw-contributionanalytics-tile mw-contributionanalytics-tile-level-' . $level,
				'data-mw-ca-tooltip' => $day['label'] . ': ' . $editCountMessage,
				'aria-hidden' => 'true',
			], '' );
		}

		if ( $customTitle !== null ) {
			$title = $customTitle;
		} else if ( $userName === '' ) {
			$title = $this->parser->msg( 'contributionanalytics-tiles-title' )->text();
		} else {
			$title = $this->parser
				->msg( 'contributionanalytics-tiles-user-title' )
				->params( $userName )
				->text();
		}

		if ( $customSummary !== null ) {
			$summary = ( new RawMessage( $customSummary ) )->numParams( $total )->text();
		} else {
			$summary = $this->parser->msg( 'contributionanalytics-tiles-summary' )->numParams( $total )->text();
		}

		return Html::rawElement( 'figure', [
				'class' => 'mw-contributionanalytics-tiles',
				'role' => 'img',
				'aria-label' => $summary,
			], implode( '', [
				Html::element(
					'figcaption',
					[ 'class' => 'mw-contributionanalytics-tiles-heading' ],
					$title
				),
				Html::rawElement(
					'div',
					[ 'class' => 'mw-contributionanalytics-tiles-grid' ],
					$tiles
				),
				Html::rawElement( 'div', [ 'class' => 'mw-contributionanalytics-tiles-footer' ], implode( '', [
					Html::element( 'span', [], $summary ),
					$this->makeLegend(),
				] ) ),
			] ) );
	}

	private function getCounts( array $days ): array {
		$max = 0;
		$total = 0;
		foreach ( $days as $day ) {
			$value = (int)$day['value'];
			$max = max( $max, $value );
			$total += $value;
		}

		return [ $max, $total ];
	}

	private function makeLeadingSpacers( array $days ): string {
		if ( $days === [] ) {
			return '';
		}

		$firstDay = reset( $days );
		$start = DateTimeImmutable::createFromFormat(
			'!Y-m-d',
			(string)$firstDay['label'],
			new DateTimeZone( 'UTC' )
		);
		if ( !$start ) {
			return '';
		}

		return str_repeat(
			Html::element( 'span', [ 'class' => 'mw-contributionanalytics-tile-spacer' ], '' ),
			(int)$start->format( 'w' )
		);
	}

	private function getTileLevel( int $value, int $max ): int {
		if ( $value === 0 || $max === 0 ) {
			return 0;
		}
		// log(1) = 0, so we always add 1
		$result = (int)ceil( log( $value + 1 ) / log( $max + 1 ) * 4);
		// Just in case
		return max( 1, min( 4,  $result ) );
	}

	private function makeLegend(): string {
		$legend = Html::element(
			'span',
			[],
			$this->parser->msg( 'contributionanalytics-tiles-less' )->text()
		);
		for ( $level = 0; $level <= 4; $level++ ) {
			$legend .= Html::element( 'span', [
				'class' => "mw-contributionanalytics-tile mw-contributionanalytics-tile-level-$level",
				'aria-hidden' => 'true',
			], '' );
		}
		$legend .= Html::element(
			'span',
			[],
			$this->parser->msg( 'contributionanalytics-tiles-more' )->text()
		);

		return Html::rawElement( 'span', [ 'class' => 'mw-contributionanalytics-tiles-legend' ], $legend );
	}
}
