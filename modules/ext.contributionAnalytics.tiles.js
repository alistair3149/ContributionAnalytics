( function () {
	const tooltipId = 'mw-contributionanalytics-tile-tooltip';
	const selector = '.mw-contributionanalytics-tile[data-mw-ca-tooltip]';
	const margin = 8;
	let tooltip;
	let activeTile;

	function getTooltip() {
		if ( tooltip ) {
			return tooltip;
		}

		tooltip = document.createElement( 'div' );
		tooltip.id = tooltipId;
		tooltip.className = 'mw-contributionanalytics-tile-tooltip';
		tooltip.setAttribute( 'role', 'tooltip' );
		tooltip.hidden = true;
		document.body.appendChild( tooltip );

		return tooltip;
	}

	function closestTile( target ) {
		if ( !( target instanceof Element ) ) {
			return null;
		}

		return target.closest( selector );
	}

	function positionTooltip( tile ) {
		const tip = getTooltip();
		const tileRect = tile.getBoundingClientRect();
		const tileCenter = tileRect.left + tileRect.width / 2;
		let left = tileCenter;
		let top = tileRect.top - margin;
		let placement = 'above';

		tip.style.left = '-9999px';
		tip.style.top = '-9999px';
		tip.style.removeProperty( '--ca-tooltip-arrow-left' );
		tip.hidden = false;
		tip.classList.add( 'mw-contributionanalytics-tile-tooltip-visible' );

		const tipRect = tip.getBoundingClientRect();
		left = Math.max(
			margin,
			Math.min( left - tipRect.width / 2, window.innerWidth - tipRect.width - margin )
		);

		if ( top - tipRect.height < margin ) {
			top = tileRect.bottom + margin;
			placement = 'below';
		} else {
			top -= tipRect.height;
		}

		tip.classList.toggle( 'mw-contributionanalytics-tile-tooltip-above', placement === 'above' );
		tip.classList.toggle( 'mw-contributionanalytics-tile-tooltip-below', placement === 'below' );
		tip.style.setProperty(
			'--ca-tooltip-arrow-left',
			Math.max( margin, Math.min( tileCenter - left, tipRect.width - margin ) ) + 'px'
		);
		tip.style.left = left + 'px';
		tip.style.top = Math.max( margin, top ) + 'px';
	}

	function showTooltip( tile ) {
		const text = tile.getAttribute( 'data-mw-ca-tooltip' );
		if ( !text ) {
			return;
		}

		activeTile = tile;
		const tip = getTooltip();
		tip.textContent = text;
		tile.setAttribute( 'aria-describedby', tooltipId );
		positionTooltip( tile );
	}

	function hideTooltip( tile ) {
		if ( tile && activeTile && tile !== activeTile ) {
			return;
		}

		if ( activeTile ) {
			activeTile.removeAttribute( 'aria-describedby' );
		}

		if ( tooltip ) {
			tooltip.classList.remove( 'mw-contributionanalytics-tile-tooltip-visible' );
			tooltip.classList.remove(
				'mw-contributionanalytics-tile-tooltip-above',
				'mw-contributionanalytics-tile-tooltip-below'
			);
			tooltip.hidden = true;
		}
		activeTile = null;
	}

	document.addEventListener( 'mouseover', ( event ) => {
		const tile = closestTile( event.target );
		if ( tile && !tile.contains( event.relatedTarget ) ) {
			showTooltip( tile );
		}
	} );

	document.addEventListener( 'mouseout', ( event ) => {
		const tile = closestTile( event.target );
		if ( tile && !tile.contains( event.relatedTarget ) ) {
			hideTooltip( tile );
		}
	} );

	document.addEventListener( 'mousemove', ( event ) => {
		const tile = closestTile( event.target );
		if ( tile && tile === activeTile ) {
			positionTooltip( tile );
		}
	} );

	document.addEventListener( 'keydown', ( event ) => {
		if ( event.key === 'Escape' ) {
			hideTooltip();
		}
	} );

	window.addEventListener( 'scroll', () => {
		if ( activeTile ) {
			positionTooltip( activeTile );
		}
	}, true );

	window.addEventListener( 'resize', () => {
		if ( activeTile ) {
			positionTooltip( activeTile );
		}
	} );
}() );
