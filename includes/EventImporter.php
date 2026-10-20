<?php

namespace MediaWiki\Extension\CommunecterImport;

use CommentStoreComment;
use ContentHandler;
use MediaWiki\Config\Config;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;
use MediaWiki\User\UserIdentity;
use RepoGroup;

/**
 * Crée ou met à jour les pages wiki des événements Communecter / tiers-lieux.org.
 */
class EventImporter {
	public const CREATED = 'created';
	public const UPDATED = 'updated';
	public const SKIPPED = 'skipped';

	private CommunecterClient $client;
	private WikiPageFactory $pageFactory;
	private RepoGroup $repoGroup;
	private Config $config;

	public function __construct(
		CommunecterClient $client,
		WikiPageFactory $pageFactory,
		RepoGroup $repoGroup,
		Config $config
	) {
		$this->client = $client;
		$this->pageFactory = $pageFactory;
		$this->repoGroup = $repoGroup;
		$this->config = $config;
	}

	public function getClient(): CommunecterClient {
		return $this->client;
	}

	/** Titre de la page d'un événement du résumé, pour savoir si elle existe déjà. */
	public static function titleFor( array $evt ): ?Title {
		return Title::newFromText( EventPageBuilder::title( $evt ) );
	}

	/**
	 * @return array{0:string,1:Title} [ résultat, titre ]
	 */
	public function import( string $eventId, UserIdentity $user, bool $update ): array {
		// Le contenu vient toujours de Communecter, jamais de la requête du navigateur.
		$evt = $this->client->getEvent( $eventId );
		$title = self::titleFor( $evt );
		if ( !$title || !$title->canExist() ) {
			throw new CommunecterException( 'invalid-title' );
		}
		$exists = $title->exists();
		if ( $exists && !$update ) {
			return [ self::SKIPPED, $title ];
		}

		$image = '';
		if ( $this->config->get( 'CommunecterImportImportImages' ) ) {
			$image = $this->uploadImage( $evt, $user );
		}
		$text = EventPageBuilder::build( $evt, [
			'template' => $this->config->get( 'CommunecterImportTemplate' ),
			'image' => $image,
			'ficheUrl' => $this->ficheUrl( $evt ),
		] );

		$page = $this->pageFactory->newFromTitle( $title );
		if ( $exists ) {
			$current = $page->getContent();
			$old = $current ? ContentHandler::getContentText( $current ) : '';
			$text = EventPageBuilder::merge( (string)$old, $text );
		}
		$updater = $page->newPageUpdater( $user );
		$updater->setContent( SlotRecord::MAIN, ContentHandler::makeContent( $text, $title ) );
		$updater->saveRevision(
			CommentStoreComment::newUnsavedComment(
				$exists ? 'Mise à jour depuis tiers-lieux.org' : 'Import depuis tiers-lieux.org'
			),
			$exists ? EDIT_UPDATE : EDIT_NEW
		);
		if ( !$updater->wasSuccessful() ) {
			throw new CommunecterException( 'save-failed' );
		}
		return [ $exists ? self::UPDATED : self::CREATED, $title ];
	}

	private function ficheUrl( array $evt ): string {
		$slug = (string)( $evt['slug'] ?? '' );
		if ( !preg_match( '/^[A-Za-z0-9_.-]+$/', $slug ) ) {
			return '';
		}
		return rtrim( (string)$this->config->get( 'CommunecterImportSiteUrl' ), '/' ) . '/profil/' . $slug;
	}

	/**
	 * Téléverse l'image de l'événement. Un échec n'empêche pas la création de la page.
	 *
	 * @return string Nom du fichier sur le wiki, ou '' sans image
	 */
	private function uploadImage( array $evt, UserIdentity $user ): string {
		$path = (string)( $evt['profilImageUrl'] ?? '' );
		$downloaded = $this->client->downloadImage( $path );
		if ( !$downloaded ) {
			return '';
		}
		[ $bytes, $mime ] = $downloaded;
		[ $bytes, $ext ] = $this->toAcceptedFormat( $bytes, $mime );
		if ( $bytes === null ) {
			return '';
		}

		$name = 'Evenement_' . $evt['id'] . '.' . $ext;
		$title = Title::makeTitleSafe( NS_FILE, $name );
		if ( !$title ) {
			return '';
		}
		$file = $this->repoGroup->getLocalRepo()->newFile( $title );
		if ( $file->exists() ) {
			return $name;
		}
		$tmp = tempnam( sys_get_temp_dir(), 'cmi' );
		try {
			file_put_contents( $tmp, $bytes );
			$status = $file->upload(
				$tmp,
				'Image de l\'événement, importée depuis tiers-lieux.org',
				'',
				0,
				false,
				false,
				$user
			);
			return $status->isOK() ? $name : '';
		} finally {
			@unlink( $tmp );
		}
	}

	/**
	 * Le wiki n'accepte pas forcément WebP : on convertit en JPEG (GD) quand l'extension n'est pas autorisée.
	 *
	 * @return array{0:?string,1:string} [ contenu, extension ]
	 */
	private function toAcceptedFormat( string $bytes, string $mime ): array {
		$extensions = array_map( 'strtolower', (array)$this->config->get( 'FileExtensions' ) );
		$native = [ 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp' ];
		$ext = $native[$mime] ?? null;
		if ( $ext !== null && in_array( $ext, $extensions, true ) ) {
			return [ $bytes, $ext ];
		}
		if ( !in_array( 'jpg', $extensions, true ) || !function_exists( 'imagecreatefromstring' ) ) {
			return [ null, '' ];
		}
		$image = @imagecreatefromstring( $bytes );
		if ( !$image ) {
			return [ null, '' ];
		}
		// JPEG n'a pas de transparence : fond blanc.
		$canvas = imagecreatetruecolor( imagesx( $image ), imagesy( $image ) );
		imagefill( $canvas, 0, 0, imagecolorallocate( $canvas, 255, 255, 255 ) );
		imagecopy( $canvas, $image, 0, 0, 0, 0, imagesx( $image ), imagesy( $image ) );
		ob_start();
		imagejpeg( $canvas, null, 88 );
		$jpeg = ob_get_clean();
		return [ $jpeg ?: null, 'jpg' ];
	}
}
