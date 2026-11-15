# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Tech Stack

**Backend :** Symfony 7.4 (PHP 8.3+), Doctrine ORM 3, form login Symfony (sessions), traductions fr/en  
**Frontend :** Twig + Bootstrap 5 + Stimulus + Chart.js + Leaflet (via Symfony Asset Mapper - aucun bundler Node.js)  
**Infrastructure locale :** Docker Compose - `php` (PHP 8.3-FPM alpine), `nginx` (http://localhost:8080), `mysql` (MySQL 8.0, port 3306), `mailhog` (SMTP 1025, UI http://localhost:8025)  
**Production :** Alwaysdata (PHP 8.3), déployée par GitHub Actions  
**APIs externes :** Strava API v3 (OAuth2 + synchro des activités), Open-Meteo (`api.open-meteo.com` prévisions, `archive.open-meteo.com` historique), Nominatim OpenStreetMap (géocodage inverse, widget météo), tuiles OpenStreetMap (cartes Leaflet), Bootstrap Icons via jsDelivr

## Development Commands

Commandes à lancer dans les conteneurs Docker :

```bash
docker compose up -d                                            # Démarre les services
docker compose exec php bin/console doctrine:migrations:migrate
```

> Il n'existe **aucune fixture** (`src/DataFixtures/` absent) : `doctrine:fixtures:load` échoue (« Could not find any fixture services to load »).

**Assets (Asset Mapper - pas de npm run dev) :**
```bash
# En dev : les assets sont servis dynamiquement par Symfony, aucune compilation nécessaire
# En prod uniquement :
docker compose exec php bin/console asset-map:compile
```

**Ajouter un package JS :**
```bash
php bin/console importmap:require nom-du-package
```
Exception : FullCalendar est servi depuis des fichiers ESM locaux (`assets/lib/fullcalendar/`). Ne **pas** passer par `importmap:require @fullcalendar/*` (voir le commentaire dans [importmap.php](importmap.php)).

**PHP tooling :**
```bash
php vendor/bin/phpunit            # Tests (suites Unit + Functional)
composer analyse                  # PHPStan niveau 6 sur src/
composer cs-check                 # PHP-CS-Fixer (PSR-12 + Symfony) en dry-run sur src/ et tests/
composer cs-fix                   # Applique PHP-CS-Fixer
```

**Lancer un seul test :**
```bash
php vendor/bin/phpunit tests/Unit/Service/UnitConverterTest.php
php vendor/bin/phpunit --filter testMethodName
```

### Règles qualité
- **PHPStan niveau 6 doit rester à 0 erreur** (imposé par la CI, sans baseline). Ne pas ajouter de baseline, d'`ignoreErrors` ni de `@phpstan-ignore`, et ne pas baisser le niveau.
- Dans un controller protégé, récupérer l'utilisateur avec `#[CurrentUser] User $user` plutôt que `$this->getUser()` (typé `UserInterface|null`).
- PHP-CS-Fixer n'est **pas** imposé par la CI. Ne pas reformater du code que l'on ne modifie pas.
- **Tirets : uniquement le tiret classique `-`.** Jamais de tiret cadratin (U+2014), de tiret demi-cadratin (U+2013) ni de signe moins typographique (U+2212, y compris pour un D- ou une marge négative), nulle part dans le projet : code, commentaires, templates, traductions, doc, messages de commit. Exception : les fichiers tiers dans `vendor/`, `assets/vendor/` et `assets/lib/`. Vérification en bash (ne doit rien renvoyer, indépendant de la locale) : `grep -rn $'\xe2\x80\x94\|\xe2\x80\x93\|\xe2\x88\x92' --exclude-dir={vendor,var,.git,lib} . | grep -v "^./assets/vendor"`

## Architecture

### Frontend (Bootstrap + Stimulus + Asset Mapper)
- **Pas de Node.js / npm / Vite.** Les assets sont gérés par Symfony Asset Mapper.
- L'entrée JS est [assets/app.js](assets/app.js) (Bootstrap + CSS + controllers), chargée via `{{ importmap('app') }}` dans [templates/base.html.twig](templates/base.html.twig).
- Les dépendances JS (Bootstrap, Chart.js, @hotwired/stimulus, Leaflet, FullCalendar, Preact) sont dans [importmap.php](importmap.php).
- Les controllers Stimulus sont dans [assets/controllers/](assets/controllers/) et **enregistrés à la main** dans [assets/controllers/index.js](assets/controllers/index.js) (`application.register(...)`). [assets/controllers.json](assets/controllers.json) est vide : un nouveau controller non ajouté à `index.js` ne sera pas chargé.
- Les templates Twig sont dans [templates/](templates/), organisés par domaine.

### Stimulus controllers
| Fichier | data-controller | Rôle |
|---|---|---|
| `weekly_chart_controller.js` | `weekly-chart` | Dashboard : volume hebdomadaire (barres km) + courbe de « forme » (Chart.js) |
| `weather_controller.js` | `weather` | Dashboard : météo actuelle via géolocalisation navigateur (Open-Meteo + Nominatim), modale de prévisions |
| `map_controller.js` | `map` | Détail activité : tracé (polyline Strava décodée) sur carte Leaflet / tuiles OSM |
| `elevation_chart_controller.js` | `elevation-chart` | Détail activité : profil d'altitude |
| `heartrate_chart_controller.js` | `heartrate-chart` | Détail activité : fréquence cardiaque sur la distance |
| `pace_chart_controller.js` | `pace-chart` | Détail activité : allure par split (km) |
| `volume_chart_controller.js` | `volume-chart` | Stats : volume par période, empilé par sport |
| `donut_chart_controller.js` | `donut-chart` | Stats : répartition par sport (doughnut) |
| `pace_trend_controller.js` | `pace-trend` | Stats : évolution de l'allure de course par semaine |
| `energy_donut_controller.js` | `energy-donut` | Nutrition : kcal consommées vs objectif (doughnut) |
| `balance_chart_controller.js` | `balance-chart` | Nutrition : apports vs dépenses par jour (barres) |
| `race_route_controller.js` | `race-route` | Plan de course (`/courses/{id}/plan`) : tracé GPX sur Leaflet, profil Chart.js avec bulles de repères ; clic sur le profil = nouveau repère au km, clic sur une bulle = édition |
| `race_calendar_controller.js` | `race-calendar` | Courses (`/courses?view=calendar`) : calendrier mensuel FullCalendar, importé dynamiquement (`import()`) ; un clic émet `race-calendar:select` pour ouvrir la modale d'édition |
| `training_calendar_controller.js` | `training-calendar` | Calendrier général (`/calendar`) : activités + courses datées en option, chargées par période depuis `/calendar/events` ; FullCalendar importé dynamiquement ; clic = vue rapide en modale (Bootstrap `Modal`), contenu inséré via `textContent` uniquement ; la mini-carte réutilise `map_controller` (créée à `shown.bs.modal`, détruite à la fermeture) |
| `weather_activity_controller.js` | *(non enregistré)* | Météo d'une activité côté client - absent d'`index.js` et d'aucun template (la météo d'activité est faite côté serveur par `WeatherService`) |

`weather_constants.js` n'est pas un controller : il exporte les icônes et libellés WMO (fr/en) pour les controllers météo.

Pour attacher un controller Stimulus à un élément :
```html
<canvas data-controller="weekly-chart"
        data-weekly-chart-data-value="{{ weeklyVolume|json_encode }}">
```

### Layer structure
- **Controllers** ([src/Controller/](src/Controller/)) - par domaine : `Activities/` (`/activities`, `/activities/{id}`), `Auth/` (`/login`, `/logout`, `/register`), `Calendar/` (`/calendar` + `/calendar/events` en JSON par période), `Dashboard/` (`/`), `Nutrition/` (`/nutrition`), `OAuth/` (`/oauth/strava`, `/oauth/strava/callback`, `/strava/sync`), `Race/` (`RaceController` : `/courses` en GET liste/calendrier, POST ajout, + `edit`, `toggle`, `intent`, `delete` ; `RacePlanController` : `/courses/{id}/plan`, import/suppression GPX `/route`, repères `/checkpoints`, routes `app_race_*`, CSRF `race_plan`), `Settings/` (`/settings`), `Stats/` (`/stats`), et `LocaleController` (`/switch-locale/{fr|en}`). Les dossiers `Api/` et `Billing/` sont vides. Vérifier avec `php bin/console debug:router`.
- **Services** ([src/Service/](src/Service/)) - `Strava/StravaService` (OAuth, refresh auto du token, synchro des activités, streams altitude/FC, splits), `Weather/WeatherService` (météo d'une activité via Open-Meteo, stockée sur l'activité), `Units/UnitConverter` (conversions, allure), `Route/` (`GpxParser` : GPX → tracé ré-échantillonné `[distanceM, ele, lat, lon]`, 15 Mo / 200 000 points max ; `RouteAnalyzer` : altitude/position interpolées, D+/D- avec hystérésis, tronçons entre repères). `Auth/` et `Billing/` sont vides.
- **Entities** ([src/Entity/](src/Entity/)) - `User` (tokens Strava, champs Stripe et `subscriptionPlan` inutilisés hors affichage), `Activity`, `Sport`, `AthleteProfile`, `Meal` (+ enum `MealType`), `Race` (+ enums `RaceIntent` statut, `RaceType` route/trail nullable), `RaceRoute` (OneToOne vers `Race`, non mappé côté `Race`), `RaceCheckpoint` (+ enum `CheckpointType`).
- **Repositories** ([src/Repository/](src/Repository/)) - Activity, Meal, Race, RaceCheckpoint, RaceRoute, Sport, User.
- **Autres** - `Form/ProfileType`, `EventSubscriber/LocaleSubscriber` (locale stockée en session, défaut `fr`), `Twig/SidebarExtension` (stats du jour dans la sidebar). Les dossiers `src/DTO/` et `src/EventListener/` sont vides.
- **Styles** - [assets/styles/app.css](assets/styles/app.css), chargé par `asset()` dans `base.html.twig` (le CSS Bootstrap est importé par `app.js`).
- **Templates** ([templates/](templates/)) - `activities/`, `auth/`, `calendar/`, `dashboard/`, `nutrition/`, `race/`, `settings/`, `stats/`.
- **Traductions** - [translations/messages.fr.yaml](translations/messages.fr.yaml) et [messages.en.yaml](translations/messages.en.yaml) : toute nouvelle clé doit être ajoutée dans les deux.

### Auth & access control
- Form login Symfony (`/login`, `/logout`) géré par [LoginController](src/Controller/Auth/LoginController.php), inscription par `RegisterController`. Le remember-me (7 jours) est configuré mais inactif : le formulaire de connexion n'a pas de champ `_remember_me`.
- Contrôle d'accès par `access_control` dans [config/packages/security.yaml](config/packages/security.yaml) (tout est `ROLE_USER` sauf `/login`, `/register`, `/oauth`, `/reset-password` et `/webhooks`, publics) et par `#[IsGranted('ROLE_USER')]` sur les controllers. `StravaController` (`/oauth/*`, `/strava/sync`) appelle lui-même `denyAccessUnlessGranted('ROLE_USER')`. Il n'y a **aucun Voter** (`src/Security/Voter/` est vide) ni contrôle par plan.
- Tokens Strava stockés dans `User` avec expiry ; refresh automatique par `StravaService` avant la synchro.

### Webhooks
Aucune route webhook n'existe aujourd'hui, mais `^/webhooks` est déjà en `PUBLIC_ACCESS` dans [security.yaml](config/packages/security.yaml). Si un webhook (ex. Strava) est ajouté, le placer sous `/webhooks` et ne pas le mettre derrière `ROLE_USER`.

## Conventions base de données

### Nommage des tables - camelCase obligatoire

Toute **nouvelle** table utilise le **camelCase** (ex: `athleteProfile`, `raceIntent`). Les tables existantes ne suivent pas toutes cette règle (voir ci-dessous) : ne pas les renommer sans migration.  
MySQL sur Linux (prod) est **sensible à la casse** : `Race` ≠ `race`. En local sous Windows, MySQL ne l'est pas, donc une erreur de casse ne se voit qu'en prod. Sans annotation explicite, Doctrine utilise le nom de la classe PHP comme nom de table (PascalCase), ce qui peut diverger de la migration.

**Règle : toujours déclarer `#[ORM\Table(name: '...')]` sur chaque entité.**

```php
#[ORM\Entity(repositoryClass: RaceRepository::class)]
#[ORM\Table(name: 'race')]          // <-- toujours explicite
class Race { ... }
```

**Checklist à chaque nouvelle entité :**
1. Ajouter `#[ORM\Table(name: 'nomEnCamelCase')]` sur la classe
2. Utiliser le même nom dans le `CREATE TABLE` de la migration
3. Vérifier la cohérence casse migration ↔ entité (Linux ≠ Windows)

**État actuel des tables** (toutes les entités ont un `#[ORM\Table]` explicite) :

| Entité | Table |
|---|---|
| `User` | `users` |
| `Activity` | `activities` |
| `Sport` | `sports` |
| `AthleteProfile` | `AthleteProfile` |
| `Meal` | `Meal` |
| `Race` | `race` |
| `RaceRoute` | `raceRoute` |
| `RaceCheckpoint` | `raceCheckpoint` |

> `AthleteProfile` et `Meal` sont en PascalCase car les migrations les créent ainsi (`Version20240008`, `Version20240009`).  
> Si tu renommes ces tables un jour, mets à jour l'attribut ET ajoute une migration.

## Environment

`.env` et `.env.local` sont gitignorés et contiennent les vrais secrets : ne jamais les lire, afficher ni committer. Les modèles versionnés sont `.env.example` (local) et `.env.prod.example` (Alwaysdata).

Variables réellement lues par l'application :
- `APP_ENV`, `APP_DEBUG`, `APP_SECRET`
- `APP_URL` - sert à construire l'URI de redirection Strava (`%env(APP_URL)%/oauth/strava/callback`)
- `DATABASE_URL` - MySQL (Docker : `mysql://app:password@mysql:3306/sport_health?serverVersion=8.0&charset=utf8mb4`)
- `STRAVA_CLIENT_ID`, `STRAVA_CLIENT_SECRET`

Présentes dans les modèles mais lues par aucun code aujourd'hui : `MAILER_DSN` (MailHog depuis les conteneurs : `smtp://mailhog:1025`), `MAILER_FROM_ADDRESS`, `MAILER_FROM_NAME` (symfony/mailer est installé mais ni configuré ni utilisé), `STRAVA_WEBHOOK_VERIFY_TOKEN`.

## Déploiement

**Un push sur `main` part en production.** Le workflow [.github/workflows/deploy.yml](.github/workflows/deploy.yml) :
1. Job `checks` (push et pull request vers `main`, PHP 8.3) : `.env` factice, `composer install`, `cache:warmup --env=dev`, PHPUnit, PHPStan, `lint:container`, `lint:twig`, `lint:yaml`, `doctrine:schema:validate --skip-sync`.
2. Job `deploy` (push sur `main` uniquement, si `checks` passe) : en SSH sur Alwaysdata, `git pull`, `composer install --no-dev`, suppression de `var/cache/prod`, **`doctrine:migrations:migrate` en prod**, `asset-map:compile`, `cache:warmup`.

Conséquences : ne jamais pousser sur `main` sans accord explicite ; une migration est exécutée en prod dès le push ; `composer.json` et `composer.lock` doivent être commités ensemble.

## Limites actuelles
- Synchro Strava **manuelle** (`/strava/sync`) : pas de webhook Strava.
- Pas de paiement : aucun Stripe/billing. Les colonnes Stripe de `User` et `subscriptionPlan`/`isPro()` ne servent qu'à afficher un libellé (Pro/Amateur).
- Pas de vérification d'email ni de « mot de passe oublié » (la règle `^/reset-password` n'a aucune route).
- Pas de fixtures ; tests unitaires seulement (`tests/Unit/`), aucun test fonctionnel.
- Plan de course : phase 1 seulement (GPX + repères). Scénarios de passage, allure auto, impression et comparaison après course restent à faire.
- Upload GPX en local : `upload_max_filesize` de WAMP souvent à 2M (Docker : 20M).
- Le schéma de la base locale diverge déjà du mapping Doctrine (index, types de colonnes) : vérifier `doctrine:schema:update --dump-sql` avant d'écrire une migration.
