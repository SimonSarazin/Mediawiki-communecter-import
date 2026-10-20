# CommunecterImport (extension MediaWiki)

Importe les événements d'un lieu de tiers-lieux.org (API Communecter) et crée
une page wiki par événement, avec image et description.

## Installation
Copier le dossier dans `extensions/CommunecterImport`, puis dans `LocalSettings.php` :

    wfLoadExtension( 'CommunecterImport' );
    $wgCommunecterImportTemplate = 'Event'; // ou 'Evenement' (« Modèle:Event » accepté)

## Usage
`Spécial:ImportCommunecter` (droit `communecter-import`, accordé aux sysops) :
saisir le slug (ex. `laPlumeALoup`), cocher les événements, importer.
Les pages existantes ne sont modifiées que si l'option est cochée (le compte-rendu est conservé).

## Configuration
`$wgCommunecterImportBaseUrl`, `SiteUrl` (repli pour le lien), `Sites` (**à configurer**, ex. `[ 'https://www.tiers-lieux.org', 'https://extremedefi.00.re' ]` : suggestions du champ « Site », qui reste libre ; le premier est le défaut),  `Template`, `DefaultSlug`, `ImportImages`, `UserAgent`, `Timeout`.
Les images WebP sont converties en JPEG (GD) si `$wgFileExtensions` ne les autorise pas.

## État
Code non testé sur une instance MediaWiki : à valider avant production.
