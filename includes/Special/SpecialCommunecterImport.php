<?php

namespace MediaWiki\Extension\CommunecterImport\Special;

use MediaWiki\Html\Html;
use MediaWiki\Config\Config;
use MediaWiki\Extension\CommunecterImport\CommunecterClient;
use MediaWiki\Extension\CommunecterImport\CommunecterException;
use MediaWiki\Extension\CommunecterImport\EventImporter;
use MediaWiki\Extension\CommunecterImport\EventPageBuilder;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Page\WikiPageFactory;
use RepoGroup;
use MediaWiki\SpecialPage\SpecialPage;

class SpecialCommunecterImport extends SpecialPage {
	private EventImporter $importer;
	private Config $config;

	public function __construct(
		HttpRequestFactory $http,
		WikiPageFactory $pageFactory,
		RepoGroup $repoGroup,
		Config $config
	) {
		parent::__construct( 'CommunecterImport', 'communecter-import' );
		$this->config = $config;
		$client = new CommunecterClient(
			$http,
			$config->get( 'CommunecterImportBaseUrl' ),
			$config->get( 'CommunecterImportUserAgent' ),
			(int)$config->get( 'CommunecterImportTimeout' )
		);
		$this->importer = new EventImporter( $client, $pageFactory, $repoGroup, $config );
	}

	public function execute( $subPage ) {
		$this->setHeaders();
		$this->checkPermissions();
		$this->requireNamedUser();
		$out = $this->getOutput();
		$request = $this->getRequest();
		$out->addModuleStyles( [ 'mediawiki.ui.button' ] );

		$sites = $this->sites();
		if ( !$sites ) {
			$out->addHTML( Html::errorBox( $this->msg( 'communecter-import-no-sites' )->parse() ) );
			return;
		}
		$site = self::normalizeSite( $request->getText( 'site', $sites[0] ) ) ?: $sites[0];
		$slug = trim( $request->getText( 'slug', (string)$this->config->get( 'CommunecterImportDefaultSlug' ) ) );
		$out->addHTML( $this->searchForm( $slug, $site, $sites ) );
		if ( $slug === '' ) {
			return;
		}

		try {
			if ( $request->wasPosted() && $request->getVal( 'import' ) !== null ) {
				if ( !$this->getUser()->matchEditToken( $request->getVal( 'token' ) ) ) {
					$out->addHTML( Html::errorBox( $this->msg( 'sessionfailure' )->escaped() ) );
				} else {
					$this->runImport( $request->getArray( 'events', [] ), $request->getBool( 'update' ), $site );
				}
			}
			$events = $this->importer->getClient()->listEvents( $slug );
			$out->addHTML( $this->eventsForm( $slug, $site, $events, $request->getBool( 'past' ) ) );
		} catch ( CommunecterException $e ) {
			$out->addHTML( Html::errorBox( $this->msg( 'communecter-import-error-' . $e->getMessage() )->escaped() ) );
		}
	}

	protected function getGroupName() {
		return 'pagetools';
	}

	/** Normalise la saisie libre (« tiers-lieux.org », « https://x.fr/ ») en https://domaine, ou '' si invalide. */
	public static function normalizeSite( string $input ): string {
		$host = strtolower( trim( $input ) );
		$host = preg_replace( '#^https?://#', '', $host );
		$host = rtrim( explode( '/', $host )[0], '.' );
		if ( !preg_match( '/^(?=.{1,253}$)([a-z0-9-]+\.)+[a-z]{2,}(:\d{1,5})?$/', $host ) ) {
			return '';
		}
		return 'https://' . $host;
	}

	/** Sites proposés dans le formulaire (option CommunecterImportSites), normalisés. */
	private function sites(): array {
		$sites = [];
		foreach ( (array)$this->config->get( 'CommunecterImportSites' ) as $url ) {
			$url = self::normalizeSite( (string)$url );
			if ( $url !== '' ) {
				$sites[] = $url;
			}
		}
		return array_values( array_unique( $sites ) );
	}

	private function searchForm( string $slug, string $site, array $sites ): string {
		$row = static function ( string $html ): string {
			return Html::rawElement( 'p', [], $html );
		};
		$label = function ( string $for, string $msg ): string {
			return Html::element( 'label', [ 'for' => $for ], $this->msg( $msg )->text() ) . ' ';
		};
		$siteRow = $label( 'cmi-site', 'communecter-import-site' ) .
			Html::input( 'site', preg_replace( '#^https://#', '', $site ), 'text', [
				'id' => 'cmi-site', 'size' => 30, 'list' => 'cmi-sites', 'placeholder' => preg_replace( '#^https://#', '', $sites[0] ),
			] ) . Html::rawElement( 'datalist', [ 'id' => 'cmi-sites' ],
				implode( '', array_map( static function ( $url ) {
					return Html::element( 'option', [ 'value' => preg_replace( '#^https://#', '', $url ) ] );
				}, $sites ) ) );
		$slugRow = $label( 'cmi-slug', 'communecter-import-slug' ) .
			Html::input( 'slug', $slug, 'text', [ 'id' => 'cmi-slug', 'size' => 30, 'placeholder' => 'laPlumeALoup' ] );
		$pastRow = Html::check( 'past', $this->getRequest()->getBool( 'past' ), [ 'id' => 'cmi-past' ] ) . ' ' .
			$label( 'cmi-past', 'communecter-import-show-past' );
		return Html::rawElement( 'form', [ 'method' => 'get', 'action' => $this->getPageTitle()->getLocalURL() ],
			$row( $siteRow ) . $row( $slugRow ) . $row( $pastRow ) .
			$row( Html::submitButton( $this->msg( 'communecter-import-list' )->text(), [ 'class' => 'mw-ui-button' ] ) )
		);
	}

	private function eventsForm( string $slug, string $site, array $events, bool $showPast ): string {
		$today = gmdate( 'Y-m-d' );
		$rows = '';
		foreach ( $events as $id => $evt ) {
			$day = EventPageBuilder::day( $evt['startDate'] ?? null, $evt['timeZone'] ?? null );
			if ( !$showPast && $day !== '' && $day < $today ) {
				continue;
			}
			$title = EventImporter::titleFor( $evt );
			$exists = $title && $title->exists();
			$status = $exists
				? $this->getLinkRenderer()->makeKnownLink( $title, $this->msg( 'communecter-import-exists' )->text() )
				: $this->msg( 'communecter-import-new' )->escaped();
			$rows .= Html::rawElement( 'tr', [],
				Html::rawElement( 'td', [], Html::check( 'events[]', false, [ 'value' => $id, 'id' => "cmi-$id" ] ) ) .
				Html::rawElement( 'td', [], Html::element( 'label', [ 'for' => "cmi-$id" ], $evt['name'] ) ) .
				Html::element( 'td', [], $day ) .
				Html::rawElement( 'td', [], $status )
			);
		}
		if ( $rows === '' ) {
			return Html::element( 'p', [], $this->msg( 'communecter-import-none' )->text() );
		}

		$head = Html::rawElement( 'tr', [],
			Html::element( 'th', [], '' ) .
			Html::element( 'th', [], $this->msg( 'communecter-import-col-name' )->text() ) .
			Html::element( 'th', [], $this->msg( 'communecter-import-col-date' )->text() ) .
			Html::element( 'th', [], $this->msg( 'communecter-import-col-status' )->text() )
		);
		return Html::rawElement( 'form', [ 'method' => 'post', 'action' => $this->getPageTitle()->getLocalURL() ],
			Html::hidden( 'slug', $slug ) .
			Html::hidden( 'site', $site ) .
			Html::hidden( 'past', $showPast ? '1' : '' ) .
			Html::hidden( 'token', $this->getUser()->getEditToken() ) .
			Html::rawElement( 'table', [ 'class' => 'wikitable' ], $head . $rows ) .
			Html::rawElement( 'p', [],
				Html::check( 'update', false, [ 'id' => 'cmi-update' ] ) . ' ' .
				Html::element( 'label', [ 'for' => 'cmi-update' ], $this->msg( 'communecter-import-update' )->text() )
			) .
			Html::submitButton( $this->msg( 'communecter-import-submit' )->text(), [
				'name' => 'import', 'class' => 'mw-ui-button mw-ui-progressive',
			] )
		);
	}

	private function runImport( array $ids, bool $update, string $site ): void {
		$out = $this->getOutput();
		$items = '';
		foreach ( array_unique( array_filter( $ids, 'is_string' ) ) as $id ) {
			if ( !CommunecterClient::isValidId( $id ) ) {
				continue;
			}
			try {
				[ $result, $title ] = $this->importer->import( $id, $this->getUser(), $update, $site );
				$link = $this->getLinkRenderer()->makeKnownLink( $title );
				$items .= Html::rawElement( 'li', [], $this->msg( 'communecter-import-result-' . $result )->rawParams( $link )->escaped() );
			} catch ( CommunecterException $e ) {
				$items .= Html::rawElement( 'li', [], Html::element( 'code', [], $id ) . ' : ' .
					$this->msg( 'communecter-import-error-' . $e->getMessage() )->escaped() );
			}
		}
		if ( $items !== '' ) {
			$out->addHTML( Html::successBox( Html::rawElement( 'ul', [], $items ) ) );
		}
	}
}
