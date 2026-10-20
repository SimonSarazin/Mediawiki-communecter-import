<?php

namespace MediaWiki\Extension\CommunecterImport;

use DateTime;
use DateTimeZone;

/**
 * Transforme un événement Communecter en wikitexte. Logique pure, sans accès MediaWiki.
 */
class EventPageBuilder {
	public const TYPES = [
		'workshop' => 'Atelier', 'competition' => 'Compétition', 'concert' => 'Concert',
		'contest' => 'Concours', 'conference' => 'Conférence', 'debate' => 'Débat',
		'exhibition' => 'Exposition', 'festival' => 'Festival', 'crowdfunding' => 'Financement participatif',
		'fair' => 'Foire', 'course' => 'Formation', 'protest' => 'Manifestation', 'market' => 'Marché',
		'film' => 'Projection', 'getTogether' => 'Rencontre', 'meeting' => 'Réunion',
		'spectacle' => 'Spectacle', 'internship' => 'Stage', 'stand' => 'Stand', 'others' => 'Autre',
	];

	/** Jour ISO (AAAA-MM-JJ) dans le fuseau de l'événement. */
	public static function day( ?string $iso, ?string $timezone ): string {
		if ( !$iso ) {
			return '';
		}
		try {
			$zone = new DateTimeZone( $timezone ?: 'Europe/Paris' );
			return ( new DateTime( $iso ) )->setTimezone( $zone )->format( 'Y-m-d' );
		} catch ( \Exception $e ) {
			return '';
		}
	}

	/** Titre de page : « Nom (AAAA-MM-JJ) », sans caractère interdit dans un titre. */
	public static function title( array $evt ): string {
		$name = trim( preg_replace( '/\s+/', ' ', preg_replace( '/[#<>\[\]|{}_]/', ' ', (string)( $evt['name'] ?? '' ) ) ) );
		$day = self::day( $evt['startDate'] ?? null, $evt['timeZone'] ?? null );
		$title = $day !== '' ? "$name ($day)" : $name;
		return mb_substr( $title, 0, 200 );
	}

	/** Valeur sûre pour un paramètre de modèle : `|` et `{{ }}` casseraient l'appel. */
	public static function param( $value ): string {
		return trim( str_replace(
			[ '|', '{{', '}}' ],
			[ '&#124;', '&#123;&#123;', '&#125;&#125;' ],
			(string)$value
		) );
	}

	/** Texte libre de la page : le contenu vient d'utilisateurs, il ne doit appeler ni modèle ni HTML. */
	public static function body( string $text ): string {
		return preg_replace_callback( '/[<>{}\[\]|]/', static function ( $m ) {
			return '&#' . ord( $m[0] ) . ';';
		}, $text );
	}

	/**
	 * @param array $evt Fiche complète de l'événement
	 * @param array $options template (nom du modèle), image (nom du fichier téléversé), ficheUrl
	 */
	public static function build( array $evt, array $options = [] ): string {
		// Accepte « Event », « Modèle:Event » ou « Template:Event ».
		$template = trim( preg_replace( '/^(mod[èe]le|template)\s*:/iu', '', trim( (string)( $options['template'] ?? '' ) ) ) );
		$image = $options['image'] ?? '';
		$ficheUrl = $options['ficheUrl'] ?? '';

		$organizers = [];
		foreach ( (array)( $evt['organizer'] ?? [] ) as $org ) {
			if ( is_array( $org ) && !empty( $org['name'] ) ) {
				$organizers[] = $org['name'];
			}
		}
		$organizers = implode( ',', $organizers );
		$tags = implode( ',', array_filter( (array)( $evt['tags'] ?? [] ), 'is_string' ) );
		$start = self::day( $evt['startDate'] ?? null, $evt['timeZone'] ?? null );
		$end = self::day( $evt['endDate'] ?? null, $evt['timeZone'] ?? null );
		$place = (string)( $evt['address']['name'] ?? $evt['address']['addressLocality'] ?? '' );
		if ( $place === 'Adresse non renseignée' ) {
			$place = '';
		}
		$full = trim( (string)( $evt['description'] ?? '' ) );
		$summary = trim( (string)( $evt['shortDescription'] ?? '' ) );
		if ( $summary === '' ) {
			$summary = trim( preg_replace( '/\s+/', ' ', $full ) );
		}
		$type = self::TYPES[$evt['type'] ?? 'others'] ?? self::TYPES['others'];

		if ( strcasecmp( $template, 'event' ) === 0 ) {
			$params = [
				[ 'Main_Picture', $image ], [ 'Lang', 'Français' ], [ 'name', $evt['name'] ?? '' ],
				[ 'type', $type ], [ 'description', $summary ], [ 'Tags', $tags ], [ 'Location', $place ],
				[ 'startDate', $start ], [ 'endDate', $end ], [ 'contact', $organizers ],
			];
		} else {
			$params = [
				[ 'Main_Picture', $image ], [ 'name', $evt['name'] ?? '' ], [ 'type', $type ],
				[ 'description', $summary ], [ 'Tags', $tags ], [ 'location', $place ],
				[ 'from', $organizers ], [ 'startDate', $start ], [ 'urlpad', '' ],
				[ 'complement', ( $end !== '' && $end !== $start ) ? "Du $start au $end" : '' ],
			];
		}

		$lines = [ '{{' . $template ];
		foreach ( $params as [ $key, $value ] ) {
			$lines[] = "|$key=" . self::param( $value );
		}
		$lines[] = '}}';
		$lines[] = '';
		if ( $full !== '' ) {
			$lines[] = '== Description ==';
			$lines[] = '';
			$lines[] = preg_replace( '/\n{2,}/', "\n\n", self::body( $full ) );
			$lines[] = '';
		}
		$lines[] = '== Compte-rendu ==';
		$lines[] = '';
		$lines[] = '== Décisions et actions ==';
		$lines[] = '';
		if ( $ficheUrl !== '' ) {
			$lines[] = "Fiche de l'événement : $ficheUrl";
			$lines[] = '';
		}
		return implode( "\n", $lines );
	}

	/**
	 * Met à jour une page existante : seuls l'appel du modèle et la section « Description » sont
	 * régénérés ; le compte-rendu et tout ce qui a été écrit sur le wiki sont conservés.
	 */
	public static function merge( string $existing, string $new ): string {
		$template = '/^\{\{[^\n|{}]+\n[\s\S]*?\n\}\}/';
		$description = '/== Description ==\n[\s\S]*?(?=\n== |$)/';
		if ( !preg_match( $template, $new, $newTemplate ) ) {
			return $existing;
		}
		$text = preg_replace_callback( $template, static fn () => $newTemplate[0], $existing, 1 );
		preg_match( $description, $new, $section );
		$section = $section[0] ?? '';
		if ( preg_match( $description, $text ) ) {
			$text = preg_replace_callback( $description, static fn () => $section, $text, 1 );
		} elseif ( $section !== '' ) {
			$text = preg_replace_callback( '/\n== Compte-rendu ==/', static fn () => "\n$section\n\n== Compte-rendu ==", $text, 1 );
		}
		return $text;
	}
}
