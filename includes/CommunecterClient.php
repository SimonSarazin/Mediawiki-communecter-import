<?php

namespace MediaWiki\Extension\CommunecterImport;

use MediaWiki\Http\HttpRequestFactory;

/**
 * Client minimal de l'API publique Communecter (backend de tiers-lieux.org). Lecture seule.
 */
class CommunecterClient {
	private const ID_PATTERN = '/^[a-f0-9]{24}$/';
	private const SLUG_PATTERN = '/^[A-Za-z0-9_.-]{1,100}$/';
	private const IMAGE_MAX_BYTES = 9 * 1024 * 1024;

	private $http;
	private $baseUrl;
	private $userAgent;
	private $timeout;

	public function __construct( HttpRequestFactory $http, string $baseUrl, string $userAgent, int $timeout = 15 ) {
		$this->http = $http;
		$this->baseUrl = rtrim( $baseUrl, '/' );
		$this->userAgent = $userAgent;
		$this->timeout = $timeout;
	}

	public static function isValidId( string $id ): bool {
		return (bool)preg_match( self::ID_PATTERN, $id );
	}

	public static function isValidSlug( string $slug ): bool {
		return (bool)preg_match( self::SLUG_PATTERN, $slug );
	}

	/**
	 * Identifiant et type de l'élément (organisation, projet…) d'un slug.
	 *
	 * @return array{0:string,1:string} [ id, type ]
	 */
	public function resolveSlug( string $slug ): array {
		if ( !self::isValidSlug( $slug ) ) {
			throw new CommunecterException( 'invalid-slug' );
		}
		$data = $this->getJson( '/co2/slug/getinfo/key/' . rawurlencode( $slug ) );
		$id = $data['contextId'] ?? '';
		$type = $data['contextType'] ?? '';
		if ( !is_string( $id ) || !self::isValidId( $id ) || !is_string( $type ) || !preg_match( '/^[a-z]+$/', $type ) ) {
			throw new CommunecterException( 'unknown-slug' );
		}
		return [ $id, $type ];
	}

	/**
	 * Événements d'un tiers-lieu (version résumée : sans description ni type), du plus ancien au plus récent.
	 *
	 * @return array[] indexés par identifiant ; chaque entrée porte au minimum id, name, startDate
	 */
	public function listEvents( string $slug ): array {
		[ $id, $type ] = $this->resolveSlug( $slug );
		$data = $this->getJson( '/co2/element/getdatadetail/type/' . $type . '/id/' . $id . '/dataName/events' );
		$events = [];
		foreach ( $data as $key => $evt ) {
			if ( !is_array( $evt ) ) {
				continue;
			}
			$evtId = (string)( $evt['id'] ?? $key );
			if ( !self::isValidId( $evtId ) || empty( $evt['name'] ) ) {
				continue;
			}
			$evt['id'] = $evtId;
			$events[$evtId] = $evt;
		}
		uasort( $events, static function ( $a, $b ) {
			return strcmp( (string)( $a['startDate'] ?? '' ), (string)( $b['startDate'] ?? '' ) );
		} );
		return $events;
	}

	/**
	 * Fiche complète d'un événement (type, description, adresse, image…).
	 */
	public function getEvent( string $id ): array {
		if ( !self::isValidId( $id ) ) {
			throw new CommunecterException( 'invalid-id' );
		}
		$data = $this->getJson( '/co2/element/get/type/events/id/' . $id );
		$evt = $data['map'] ?? null;
		if ( empty( $data['result'] ) || !is_array( $evt ) || empty( $evt['name'] ) ) {
			throw new CommunecterException( 'event-not-found' );
		}
		$evt['id'] = $id;
		return $evt;
	}

	/**
	 * Télécharge une image de l'événement. Refuse toute adresse hors du serveur Communecter.
	 *
	 * @return array{0:string,1:string}|null [ contenu, type MIME ] ou null
	 */
	public function downloadImage( string $path ): ?array {
		$url = $this->absoluteUrl( $path );
		if ( $url === null ) {
			return null;
		}
		$req = $this->http->create( $url, [ 'timeout' => $this->timeout, 'userAgent' => $this->userAgent ], __METHOD__ );
		if ( !$req->execute()->isOK() ) {
			return null;
		}
		$body = $req->getContent();
		$mime = trim( explode( ';', (string)$req->getResponseHeader( 'content-type' ) )[0] );
		if ( $body === null || $body === '' || strlen( $body ) > self::IMAGE_MAX_BYTES || strpos( $mime, 'image/' ) !== 0 ) {
			return null;
		}
		return [ $body, $mime ];
	}

	private function absoluteUrl( string $path ): ?string {
		if ( $path === '' ) {
			return null;
		}
		$url = preg_match( '#^https?://#i', $path ) ? $path : $this->baseUrl . '/' . ltrim( $path, '/' );
		$host = parse_url( $url, PHP_URL_HOST );
		$base = parse_url( $this->baseUrl, PHP_URL_HOST );
		return ( $host !== null && $host === $base ) ? $url : null;
	}

	private function getJson( string $path ): array {
		$req = $this->http->create(
			$this->baseUrl . $path,
			[ 'timeout' => $this->timeout, 'userAgent' => $this->userAgent ],
			__METHOD__
		);
		$req->setHeader( 'Accept', 'application/json' );
		$status = $req->execute();
		if ( !$status->isOK() ) {
			throw new CommunecterException( 'unreachable' );
		}
		$data = json_decode( (string)$req->getContent(), true );
		if ( !is_array( $data ) ) {
			throw new CommunecterException( 'bad-response' );
		}
		return $data;
	}
}
