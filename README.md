# CommunecterImport (extension MediaWiki)

Importe les événements d'un profil (organisateur) de tiers-lieux.org ou d'un site
équivalent (API Communecter) et crée une page wiki par événement, avec image et description.

Compatible MediaWiki 1.43+ (PHP 8.1+). Pour MediaWiki 1.35, voir la branche `mw-1.35`.

## Installation
1. Copier le dossier dans `extensions/CommunecterImport`.
2. Dans `LocalSettings.php` :

```php
wfLoadExtension( 'CommunecterImport' );
$wgCommunecterImportSites = [ 'https://www.tiers-lieux.org', 'https://extremedefi.00.re' ]; // obligatoire
$wgCommunecterImportTemplate = 'Event'; // ou 'Evenement'
```

Sans `$wgCommunecterImportSites`, la page spéciale affiche un message demandant de le configurer.

## Usage
Page `Spécial:ImportCommunecter` (droit `communecter-import`, accordé aux sysops) :
1. choisir le **site** (champ libre, avec suggestions issues de `Sites`) ;
2. saisir l'**identifiant de l'organisateur** (profil), par exemple `laPlumeALoup` ;
3. cocher les événements à importer, puis valider.

Les événements passés sont masqués par défaut. Une page existante n'est modifiée que si
l'option « mettre à jour » est cochée, et son compte-rendu est alors conservé.
Le site choisi sert au lien vers la fiche de l'événement ; les données viennent toujours de `BaseUrl`.

## Configuration
Toutes les options se règlent dans `LocalSettings.php` avec le préfixe `$wgCommunecterImport`.

| Option | Défaut | Rôle |
|---|---|---|
| `Sites` | `[]` | **À configurer.** Sites proposés dans le formulaire ; le premier est le défaut. |
| `Template` | `Evenement` | Modèle des pages : `Evenement` (movilab.org) ou `Event` (ex. wiki.lafabriquedesmobilites.fr, ExtremeDéfi). `Modèle:Event` accepté. Les paramètres s'adaptent au modèle. |
| `BaseUrl` | `https://www.communecter.org` | API Communecter (backend). |
| `SiteUrl` | `https://www.tiers-lieux.org` | Site utilisé pour le lien si aucun n'est choisi. |
| `DefaultSlug` | `''` | Identifiant proposé par défaut. |
| `ImportImages` | `true` | Téléverser l'image de l'événement. |
| `UserAgent` | `MediaWiki-CommunecterImport/0.1` | User-Agent des requêtes. |
| `Timeout` | `15` | Délai maximal d'une requête (secondes). |

Les images WebP sont converties en JPEG (extension PHP GD) si `$wgFileExtensions` ne les autorise pas.

## État
Code non testé sur une instance MediaWiki : à valider avant production.
