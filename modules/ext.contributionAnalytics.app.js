( function () {
	const Vue = require( 'vue' );
	const { CdxField, CdxSelect, CdxTextInput, CdxMessage } = require( './codex.js' );
	const api = new mw.Api();

	function msgOption( value, message ) {
		return {
			value: value,
			label: mw.msg( message )
		};
	}

	function getJsonConfig( element ) {
		const value = element.getAttribute( 'data-mw-ca-config' );
		if ( !value ) {
			return {};
		}

		try {
			return JSON.parse( value ) || {};
		} catch ( e ) {
			return {};
		}
	}

	function getApiErrorMessage( details ) {
		if ( !details || ( !details.error && !details.errors ) ) {
			return '';
		}

		return api.getErrorMessage( details ).text();
	}

	function makeChart( canvas, chartData, metric, visibleSegments, threshold ) {
		const labelsByMetric = {
			edits: 'contributionanalytics-edits',
			editors: 'contributionanalytics-editors',
			threshold: 'contributionanalytics-threshold-users'
		};
		const labelsByDataset = {
			anon: 'contributionanalytics-anonymous-users',
			registered: 'contributionanalytics-registered-users',
			total: 'contributionanalytics-total'
		};
		const datasets = ( chartData.datasets || [] ).map( ( dataset ) => ( {
			label: metric === 'threshold' ?
				mw.msg( labelsByMetric.threshold, threshold ) :
				mw.msg( labelsByDataset[ dataset.key ] || labelsByMetric[ metric ] || labelsByMetric.edits ),
			data: dataset.values || [],
			borderWidth: 2,
			hidden: metric !== 'threshold' &&
				!!visibleSegments &&
				visibleSegments.indexOf( dataset.key ) === -1,
			tension: 0.25
		} ) );

		return new Chart( canvas, {
			type: metric === 'threshold' ? 'bar' : 'line',
			data: {
				labels: chartData.labels || [],
				datasets: datasets
			},
			options: {
				responsive: true,
				maintainAspectRatio: false,
				animation: false,
				plugins: {
					legend: {
						display: true
					}
				},
				scales: {
					y: {
						beginAtZero: true,
						ticks: {
							precision: 0
						}
					}
				}
			}
		} );
	}

	function getElementConfig( element ) {
		const jsonConfig = getJsonConfig( element );
		const threshold = Number( jsonConfig.threshold );

		return {
			hideControls: jsonConfig.hideControls === true,
			metric: jsonConfig.metric || 'edits',
			bucket: jsonConfig.bucket || 'month',
			visibleSegments: Array.isArray( jsonConfig.visibleSegments ) ?
				jsonConfig.visibleSegments :
				null,
			start: jsonConfig.start,
			end: jsonConfig.end,
			threshold: Number.isFinite( threshold ) && threshold > 0 ? threshold : 10,
			embeddedData: jsonConfig.data && Array.isArray( jsonConfig.data.labels ) ?
				jsonConfig.data :
				null
		};
	}

	function createContributionAnalyticsApp( element ) {
		const config = getElementConfig( element );

		return Vue.createMwApp( {
			components: {
				CdxField: CdxField,
				CdxSelect: CdxSelect,
				CdxTextInput: CdxTextInput,
				CdxMessage: CdxMessage
			},
			data: function () {
				const now = new Date();
				const fallbackEnd = now.toISOString().slice( 0, 10 );
				const oneYearAgo = new Date( now );
				oneYearAgo.setFullYear( now.getFullYear() - 1 );
				const fallbackStart = oneYearAgo.toISOString().slice( 0, 10 );

				return {
					loading: false,
					error: '',
					chartData: {
						labels: [],
						datasets: []
					},
					chart: null,
					thresholdTimer: null,
					abortController: null,
					showControls: !config.hideControls,
					start: config.start || fallbackStart,
					end: config.end || fallbackEnd,
					metric: config.metric,
					bucket: config.bucket,
					visibleSegments: config.visibleSegments,
					threshold: config.threshold,
					embeddedData: config.embeddedData,
					metrics: [
						msgOption( 'edits', 'contributionanalytics-edits' ),
						msgOption( 'editors', 'contributionanalytics-editors' ),
						msgOption( 'threshold', 'contributionanalytics-threshold-users' )
					],
					buckets: [
						msgOption( 'day', 'contributionanalytics-day' ),
						msgOption( 'month', 'contributionanalytics-month' )
					]
				};
			},
			computed: {
				hasData: function () {
					return this.chartData.labels.length > 0 && this.chartData.datasets.length > 0;
				}
			},
			watch: {
				metric: function () {
					this.load();
				},
				bucket: function () {
					this.load();
				},
				start: function () {
					this.load();
				},
				end: function () {
					this.load();
				},
				threshold: function () {
					if ( this.metric === 'threshold' ) {
						clearTimeout( this.thresholdTimer );
						this.thresholdTimer = setTimeout( () => this.load(), 300 );
					}
				}
			},
			mounted: function () {
				if ( this.embeddedData ) {
					this.chartData = {
						labels: this.embeddedData.labels || [],
						datasets: this.embeddedData.datasets || []
					};
					this.$nextTick( this.renderChart );
				} else {
					this.load();
				}
			},
			beforeUnmount: function () {
				clearTimeout( this.thresholdTimer );
				this.destroyChart();
			},
			methods: {
				load: function () {
					if ( this.abortController ) {
						this.abortController.abort();
					}
					const controller = new AbortController();
					this.abortController = controller;
					this.loading = true;
					this.error = '';
					api.get( {
						action: 'contributionanalytics',
						format: 'json',
						metric: this.metric,
						bucket: this.bucket,
						start: this.start,
						end: this.end,
						threshold: Number( this.threshold ),
						maxage: 600,
						smaxage: 600,
					} ).then( ( response ) => {
						if ( controller.signal.aborted ) {
							return;
						}
						const payload = response.contributionanalytics || {};
						this.chartData = {
							labels: payload.labels || [],
							datasets: payload.datasets || []
						};
						this.$nextTick( this.renderChart );
					} ).catch( ( _, details ) => {
						if ( controller.signal.aborted ) {
							return;
						}
						this.error = getApiErrorMessage( details ) ||
							mw.msg( 'contributionanalytics-error' );
						this.chartData = {
							labels: [],
							datasets: []
						};
						this.destroyChart();
					} ).always( () => {
						if ( !controller.signal.aborted ) {
							this.loading = false;
						}
					} );
				},
				destroyChart: function () {
					if ( this.chart ) {
						this.chart.destroy();
						this.chart = null;
					}
				},
				renderChart: function () {
					this.destroyChart();
					if ( !this.hasData ) {
						return;
					}
					const canvas = this.$refs.chart;
					this.chart = makeChart(
						canvas,
						this.chartData,
						this.metric,
						this.visibleSegments,
						this.threshold
					);
				}
			},
			template: `
				<div class="mw-contributionanalytics">
					<div v-if="showControls" class="mw-contributionanalytics-controls">
						<cdx-field>
							<template #label>{{ $i18n( 'contributionanalytics-metric' ).text() }}</template>
							<cdx-select v-model:selected="metric" v-bind:menu-items="metrics"></cdx-select>
						</cdx-field>
						<cdx-field>
							<template #label>{{ $i18n( 'contributionanalytics-bucket' ).text() }}</template>
							<cdx-select v-model:selected="bucket" v-bind:menu-items="buckets"></cdx-select>
						</cdx-field>
						<cdx-field v-if="metric === 'threshold'">
							<template #label>{{ $i18n( 'contributionanalytics-threshold' ).text() }}</template>
							<cdx-text-input v-model="threshold" input-type="number" v-bind:min="1"></cdx-text-input>
						</cdx-field>
						<cdx-field>
							<template #label>{{ $i18n( 'contributionanalytics-date-start' ).text() }}</template>
							<cdx-text-input v-model="start" input-type="date"></cdx-text-input>
						</cdx-field>
						<cdx-field>
							<template #label>{{ $i18n( 'contributionanalytics-date-end' ).text() }}</template>
							<cdx-text-input v-model="end" input-type="date"></cdx-text-input>
						</cdx-field>
					</div>
					<cdx-message v-if="error" type="error" inline>{{ error }}</cdx-message>
					<div class="mw-contributionanalytics-chart-wrap">
						<div v-if="loading" class="mw-contributionanalytics-status">
							{{ $i18n( 'contributionanalytics-loading' ).text() }}
						</div>
						<div v-else-if="!hasData" class="mw-contributionanalytics-status">
							{{ $i18n( 'contributionanalytics-no-data' ).text() }}
						</div>
						<canvas v-show="hasData" ref="chart"></canvas>
					</div>
				</div>
			`
		} );
	}

	function mountPlot( element ) {
		if ( element.getAttribute( 'data-mw-ca-mounted' ) === '1' ) {
			return;
		}

		element.setAttribute( 'data-mw-ca-mounted', '1' );
		createContributionAnalyticsApp( element ).mount( element );
	}

	mw.hook( 'wikipage.content' ).add( ( $content ) => {
		$content[0].querySelectorAll( '.mw-contributionanalytics-app' ).forEach( mountPlot );
	} );
}() );
