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
		$site = $request->getVal( 'site', $sites[0] );
		if ( !in_array( $site, $sites, true ) ) {
			$site = $sites[0];
		}
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

	/** Sites publics autorisés : le premier est celui par défaut. */
	private function sites(): array {
		$list = array_merge(
			[ (string)$this->config->get( 'CommunecterImportSiteUrl' ) ],
			(array)$this->config->get( 'CommunecterImportSites' )
		);
		$clean = [];
		foreach ( $list as $url ) {
			$url = rtrim( (string)$url, '/' );
			if ( preg_match( '#^https://[A-Za-z0-9.-]+(:\d+)?$#', $url ) ) {
				$clean[] = $url;
			}
		}
		return array_values( array_unique( $clean ) ) ?: [ 'https://www.tiers-lieux.org' ];
	}

	private function siteSelect( string $site, array $sites ): string {
		$options = '';
		foreach ( $sites as $url ) {
			$options .= Html::element( 'option', [ 'value' => $url, 'selected' => $url === $site ], $url );
		}
		return Html::rawElement( 'select', [ 'name' => 'site', 'id' => 'cmi-site' ], $options );
	}

	private function searchForm( string $slug, string $site, array $sites ): string {
		return Html::rawElement( 'form', [ 'method' => 'get', 'action' => $this->getPageTitle()->getLocalURL() ],
			Html::element( 'label', [ 'for' => 'cmi-site' ], $this->msg( 'communecter-import-site' )->text() ) . ' ' .
			$this->siteSelect( $site, $sites ) . ' ' .
			Html::element( 'label', [ 'for' => 'cmi-slug' ], $this->msg( 'communecter-import-slug' )->text() ) . ' ' .
			Html::input( 'slug', $slug, 'text', [ 'id' => 'cmi-slug', 'size' => 30, 'placeholder' => 'laPlumeALoup' ] ) . ' ' .
			Html::check( 'past', $this->getRequest()->getBool( 'past' ), [ 'id' => 'cmi-past' ] ) . ' ' .
			Html::element( 'label', [ 'for' => 'cmi-past' ], $this->msg( 'communecter-import-show-past' )->text() ) . ' ' .
			Html::submitButton( $this->msg( 'communecter-import-list' )->text(), [ 'class' => 'mw-ui-button' ] )
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
