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
- L'entrée JS est [assets/app.js](assets/app.js) (Bootstrap + CSS + controllers), chargée via `{{ importmap('app') }}` dans [templates/base.html.twig](templates/base.html.twig). Seule exception : la page publique d'un plan partagé (`/p/{token}`, `race/public.html.twig`, autonome) charge l'entrée légère [assets/race_public.js](assets/race_public.js) (`{{ importmap('race_public') }}`, déclarée `entrypoint` dans `importmap.php`), qui enregistre elle-même `route-preview` et `race-public` (sans Bootstrap ni les autres controllers).
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
| `race_splits_controller.js` | `race-splits` | Plan de course, section « Temps de passage » (partial `race/_scenarios.html.twig`, rendu serveur = référence) : onglets accessibles des scénarios (`#scenario-{id}` dans l'URL), temps fixés (input avec `name="splits[id]"`, posté) / auto (sans `name`, jamais posté), recalcul en direct du tableau à chaque frappe sauf durée incomplète, erreurs de format visibles en fin de saisie, indicateur et confirmations de modifications non enregistrées (quitter, répartir, dupliquer, supprimer, imprimer), messages flash déplacés en tête de la section au retour d'une action ; stratégie d'allure (select `data-sc-strategy`, intensité en radios `data-sc-intensity` masquées pour « Régulier » mais toujours postées, phrase d'explication) recalculée en direct, écart de chaque allure de tronçon avec l'allure moyenne en mouvement ; données insérées via `textContent` uniquement. Ses fonctions exportées `fillAuto()`, `pacedEfforts()`, `pacingMultiplier()` et `compute()` sont le miroir exact de `SplitCalculator::fillAutoFromEfforts()`, `pacedEfforts()`, `PacingStrategy::multiplier()` et `compute()` (voir plus bas) |
| `race_calendar_controller.js` | `race-calendar` | Courses (`/courses?view=calendar`) : calendrier mensuel FullCalendar, importé dynamiquement (`import()`) ; un clic émet `race-calendar:select` pour ouvrir la modale d'édition |
| `route_preview_controller.js` | `route-preview` | Page d'une course (`/courses/{id}`) et page publique `/p/{token}` : aperçu en lecture seule du parcours GPX (carte Leaflet + mini profil Chart.js, ~600 points, repères numérotés) ; libellés échappés avant les tooltips Leaflet (HTML). L'édition reste dans `race_route_controller.js` |
| `race_weather_controller.js` | `race-weather` | Page d'une course, carte « Météo du jour J » (partial `race/_race_weather.html.twig`) : charge `GET /courses/{id}/weather` (`RaceWeatherController`, JSON, propriétaire seulement, `?retry=1` pour relancer Open-Meteo après un échec ; contrat dans `RaceWeatherService::forRace()`) après l'affichage ; états `forecast` (J à J+15), `observed` (course passée), `climate` (tendance des 10 dernières années, pas une prévision), `no_date` / `no_location` / `unavailable` ; `textContent` / `setAttribute` uniquement |
| `training_calendar_controller.js` | `training-calendar` | Calendrier général (`/calendar`) : activités + courses datées en option, chargées par période depuis `/calendar/events` ; FullCalendar importé dynamiquement ; clic = vue rapide en modale (Bootstrap `Modal`), contenu inséré via `textContent` uniquement ; la mini-carte réutilise `map_controller` (créée à `shown.bs.modal`, détruite à la fermeture) |
| `activity_fuel_controller.js` | `activity-fuel` | Détail activité : carte Ravitaillement, juste sous le profil altimétrique (partial `activities/_fuel.html.twig`, rendu serveur en lecture seule puis activé) ; steppers aliments (unités) / boissons (pas de 250 ml), glucides par unité modifiables, apports personnalisés ; recalcul en direct, enregistrement auto de tout l'état en JSON (600 ms après la dernière modification et à `pagehide`, requêtes `fetch` keepalive), statut `role="status"` |
| `weekly_goals_controller.js` | `weekly-goals` | Paramètres > Profil, objectifs par sport : ajoute une ligne depuis le menu « Ajouter un sport » (gabarit `<template>` du prototype, `__name__` remplacé par `new<n>` dans les attributs, sans `innerHTML`), la retire, masque les sports déjà utilisés, focus et annonce `role="status"` ; après une erreur, amène la première ligne en erreur à l'écran |
| `race_share_controller.js` | `race-share` | Réglages du partage du plan (`/courses/{id}/partage`, `race/share.html.twig`) : « Copier le lien » (Clipboard API, sinon sélection + `execCommand('copy')`, sinon lien sélectionné et invitation à copier à la main ; statut `role="status"`), compteurs de caractères, heure prévue de chaque point selon le scénario choisi (valeur `times` : point -> scénario -> libellé), indicateur de modifications non enregistrées, confirmations (régénérer ; quitter / activer / désactiver avec une saisie non enregistrée) ; `textContent` uniquement |
| `race_public_controller.js` | `race-public` | Page publique `/p/{token}` (enregistré dans `assets/race_public.js`, **pas** dans `index.js`) : le jour de la course (de minuit du jour du départ à minuit après l'arrivée prévue, horloge de l'appareil), met en avant le prochain passage prévu (`data-at` = heure locale flottante de chaque point, classes `is-next` / `is-past`, `aria-current="step"`, bannière `role="status"`), rafraîchi toutes les 30 s ; `textContent` uniquement. La carte et le profil de cette page réutilisent `route_preview_controller.js` |
| `weather_activity_controller.js` | *(non enregistré)* | Météo d'une activité côté client - absent d'`index.js` et d'aucun template (la météo d'activité est faite côté serveur par `WeatherService`) |

`weather_constants.js` n'est pas un controller : il exporte les icônes et libellés WMO (fr/en) pour les controllers météo. `activity_fuel_calculator.js` non plus : c'est le miroir JS exact d'`ActivityFuelCalculator` (calcul en entiers) ; toute règle de calcul du ravitaillement se modifie des deux côtés.

**Temps de passage : deux implémentations à garder identiques.** `race_splits_controller.js` (`fillAuto()`, `compute()`, `parseDuration()`) reproduit `SplitCalculator::fillAutoFromEfforts()`, `SplitCalculator::compute()` et `DurationFormat::parse()` : mêmes règles, même ordre des opérations flottantes, mêmes arrondis dans le calcul (`floor(x / 10 + 0.5) * 10` pour les temps auto, `floor(x * 100 + 0.5) / 100` pour les allures et vitesses ; pas de `round()` de PHP, dont le résultat a changé en PHP 8.4). Toute modification d'une règle de calcul (répartition, erreurs, barrières) se fait **dans les deux fichiers à la fois**, puis se vérifie : rendu serveur et recalcul JS doivent donner exactement les mêmes cellules. Idem pour les **stratégies d'allure** : formules et coefficients dans l'enum `App\Entity\PacingStrategy` (`multiplier()`, `COEFFICIENTS`, `CONSERVATIVE_UNTIL` ; + `PacingIntensity`), miroir `PACING_COEFFICIENTS` / `pacingMultiplier()` / `pacedEfforts()` dans `race_splits_controller.js` ; poids d'un tronçon = effort × m(x), x = milieu du tronçon / distance de l'arrivée, donc seuls les temps auto bougent (temps fixés et temps visé exacts) ; l'écart d'allure `paceDeviationPct` de `compute()` est un % entier `floor(r * 100 - 100 + 0.5)`. `PacingStrategyTest` relit le fichier JS pour comparer les constantes (garder le format `nom: { light: x, medium: y, strong: z }`).

Pour attacher un controller Stimulus à un élément :
```html
<canvas data-controller="weekly-chart"
        data-weekly-chart-data-value="{{ weeklyVolume|json_encode }}">
```

### Layer structure
- **Controllers** ([src/Controller/](src/Controller/)) - par domaine : `Activities/` (`/activities`, `/activities/{id}` ; `ActivityGearController` : `POST /activities/{id}/gear`, CSRF `gear` ; `ActivityFuelController` : `POST /activities/{id}/fuel` en JSON, route `app_activity_fuel`, CSRF `activity_fuel` dans l'en-tête `X-CSRF-Token`, remplace toutes les lignes en une transaction, 403 si l'activité n'est pas à l'utilisateur, 422 si la saisie est invalide), `Auth/` (`/login`, `/logout`, `/register`), `Calendar/` (`/calendar` + `/calendar/events` en JSON par période), `Dashboard/` (`/` ; bandeau d'usure du matériel, script inline sans Stimulus, fermeture mémorisée en `localStorage` par matériel et statut ; objectifs par sport sous les anneaux de la carte « Objectifs de la semaine », partial `dashboard/_sport_goals.html.twig`), `Nutrition/` (`/nutrition`), `OAuth/` (`/oauth/strava`, `/oauth/strava/callback`, `/strava/sync`), `Race/` (`RaceController` : `/courses` en GET liste/calendrier, POST ajout, + `edit`, `toggle`, `intent`, `delete` ; `RacePlanController` : `/courses/{id}/plan`, import/suppression GPX `/route`, repères `/checkpoints`, routes `app_race_*`, CSRF `race_plan` ; `RaceScenarioController` : scénarios du plan, `POST /courses/{id}/scenarios` (ajout, 6 au plus) et `/courses/{id}/scenarios/{sid}/save` (champs `name`, `start_time`, `target`, `strategy` et `strategy_intensity` (valeur exacte d'un cas de `PacingStrategy` / `PacingIntensity`, sinon flash `flash_scenario_invalid` et rien d'enregistré ; absents = inchangés ; à l'ajout, absents = stratégie proposée selon le type de course), `splits[<checkpointId>]` : remplace tous les temps fixés, vide = repère auto), `/distribute` (efface les temps fixés), `/duplicate` (copie aussi la stratégie), `/delete`, routes `app_race_scenario_*`, CSRF `race_plan` (champ `_token`), redirection vers `app_race_plan#scenario-{sid}` ; `GET /courses/{id}/plan/print?scenario={sid}&format=band` (`app_race_plan_print`, fiche A4 ou bracelet, page autonome sans `base.html.twig`, version enregistrée du scénario) ; nom par défaut et suffixe de copie traduits (`race_plan.sc_default_name`, `sc_copy_suffix`) ; `RaceShareController` : réglages du lien de partage public, `GET /courses/{id}/partage` (`app_race_share`, ne crée rien), `POST /courses/{id}/partage` (`app_race_share_save` : `display_name`, `scenario`, `show_paces`, `show_cutoffs`, `message`, `start_message`, `finish_message`, `messages[<checkpointId>]`, validés par `ShareSettingsParser`, erreur = page réaffichée en 422 et rien d'écrit), `/partage/toggle` (`app_race_share_toggle`, champ `enabled` = `1` active, sinon désactive ; crée le partage au besoin, réactiver garde le jeton), `/partage/regenerate` (`app_race_share_regenerate`, nouveau jeton), CSRF `race_share` (champ `_token`), 403 pour la course d'un autre utilisateur ; `RacePublicController` (sans `#[IsGranted]`) : `GET /p/{token}` (`app_race_public`) et `GET /p/{token}/calendar.ics` (`app_race_public_ics`), voir « Plan partagé (page publique) » plus bas), `Settings/` (`/settings` avec onglets `?tab=` ; l'onglet Profil enregistre aussi les objectifs hebdomadaires globaux et par sport, partials `settings/_weekly_goals.html.twig` et `_weekly_goal_row.html.twig`, conflit d'index unique (autre onglet) rattrapé par un message ; `GearController` : `POST /settings/gear` + `/{id}/edit`, `retire`, `delete`, `assign-defaults`, `import-strava` (import Strava synchrone), routes `app_gear_*`, CSRF `gear`, retour sur `?tab=gear` ; `AvatarController` : photo de profil, `POST /settings/avatar` (champ `avatar`), `/settings/avatar/strava`, `/settings/avatar/delete`, `GET /settings/avatar/image` (photo de l'utilisateur connecté seulement, cache privé immuable si `?v=` est la version courante), routes `app_avatar_*`, CSRF `avatar`, retour sur `?tab=profile`), `Stats/` (`/stats`), et `LocaleController` (`/switch-locale/{fr|en}`). Les dossiers `Api/` et `Billing/` sont vides. Vérifier avec `php bin/console debug:router`.
- **Services** ([src/Service/](src/Service/)) - `Strava/StravaService` (OAuth, refresh auto du token, synchro des activités, streams altitude/FC, splits), `Weather/WeatherService` (météo d'une activité via Open-Meteo, stockée sur l'activité ; `Weather/RaceWeatherService` : météo du jour J d'une course pour `RaceWeatherController`, prévision, observation ou tendance climatique selon la date), `Units/UnitConverter` (conversions, allure), `Route/` (`GpxParser` : GPX → tracé ré-échantillonné `[distanceM, ele, lat, lon]`, 15 Mo / 200 000 points max ; `RouteAnalyzer` : altitude/position interpolées, D+/D- avec hystérésis, tronçons entre repères ; `DurationFormat` : lecture `h:mm`, `h:mm:ss`, `3h15` et affichage des durées, allures et heures (`formatClock` : heure + décalage J+n) ; `SplitCalculator` : calcul pur des temps de passage, `compute()` (tableau : heures, allures, marges de barrière `ok`/`tight` < 15 min/`missed`, erreurs `not_increasing`/`after_finish`/`stops_exceed_target`), `fillAuto()` (temps auto entre les temps fixés et le temps visé, au prorata de l'effort km + D+ / 100 pondéré par la stratégie d'allure (`pacedEfforts()`), arrondis à 10 s, à la seconde quand deux temps se colleraient ; avec des temps fixés cohérents, `compute()` ne signale aucune erreur, quelle que soit la stratégie), `distribute()` (= `fillAuto()` sans temps fixé), `compute()` donne aussi `paceDeviationPct` (écart en % entier avec l'allure moyenne en mouvement), `segmentEfforts()` (point d'extension pour un modèle d'allure en phase 3) ; `ScenarioViewBuilder` : données de la section et de la fiche, `build(Race)` / `buildWith()`, voir son docblock pour la forme exacte), `Gear/` (`GearUsageProvider` : usage de tout le matériel d'un utilisateur en une requête agrégée, `wearAlerts()` pour le bandeau d'usure du tableau de bord ; `GearUsage` : total, ratio et statut d'usure `ok`/`warn` >= 80 %/`worn` >= 100 %/`none` ; `StravaGearImporter` : import du matériel Strava, tout est lu avant d'écrire en une transaction, testé avec `MockHttpClient` ; `GearBrandMatcher` : marque Strava -> `gearBrand`, sinon « Autre »). `StravaService` rattache aux activités qu'il crée le matériel importé correspondant à leur `gear_id`, sinon le matériel par défaut. `Nutrition/` (ravitaillement d'une activité : `ActivityFuelCalculator` pur, cible 60-90 g/h en constantes, statut sur le débit arrondi, bornes incluses ; `FuelPayloadValidator` : contrat JSON du POST ; `FuelCatalog` : apports proposés, glucides par défaut, limites ; `ActivityFuelViewBuilder` : données de la carte, glucides proposés = dernière valeur enregistrée par l'utilisateur pour l'apport, sinon défaut). `User/` (`AvatarProcessor` : photo de profil contrôlée avant décodage, finfo + `getimagesize`, JPEG/PNG/WebP/GIF fixe, 5 Mo, 40 mégapixels, mémoire, puis carré de 256 px au plus en WebP sans métadonnées ; `StravaAvatarFetcher` : photo du profil Strava, téléchargement https durci, testé avec `MockHttpClient`). `Goals/` (`WeeklyVolumeSeries` : série du tableau de bord, 12 semaines ISO consécutives jusqu'à la semaine en cours (dernier élément), semaines vides à zéro, `week` / `year` tirés de `yearweek` ; anneaux et tuiles portent donc sur la semaine en cours ; `WeeklyGoalProgress` : avancement des objectifs par sport de la semaine en cours pour le tableau de bord, semaine lundi 00:00:00 -> dimanche 23:59:59 dans le fuseau de PHP comme `YEARWEEK(startDate, 1)`, une requête agrégée par sport `ActivityRepository::sumBySportBetween`, activités sans sport comptées avec « other », valeurs réelles et barre plafonnée à 100 % (`GoalMetric`) ; `WeeklyGoalReconciler` : un sport retiré puis rajouté dans la même saisie réutilise sa ligne (sinon l'INSERT passe avant le DELETE et heurte l'index unique), positions, sports en double). `Share/` (plan de course partagé, tout pur : `ShareToken` : 24 octets de `random_bytes` en base64url, 32 caractères `[A-Za-z0-9_-]`, `regenerate()` toujours différent, `isValid()` avant toute requête ; `ShareSettingsParser` : validation du formulaire des réglages, longueurs comptées après CRLF -> LF ; `PublicPlanBuilder` : données de la page publique en LISTE BLANCHE (scalaires seulement, voir son docblock), `selectScenario()` = scénario choisi sinon le premier par position ; `PlanCalendar` : fichier .ics RFC 5545). `Auth/` et `Billing/` sont vides.
- **Entities** ([src/Entity/](src/Entity/)) - `User` (tokens Strava, champs Stripe et `subscriptionPlan` inutilisés hors affichage), `Activity`, `Sport`, `AthleteProfile`, `Meal` (+ enum `MealType`), `Race` (+ enums `RaceIntent` statut, `RaceType` route/trail nullable), `RaceRoute` (OneToOne vers `Race`, non mappé côté `Race`), `RaceCheckpoint` (+ enum `CheckpointType` ; `notes` privées, `publicMessage` (500) affiché sur le plan partagé, migration `Version20240030_CreateRaceShare`), `RaceShare` (lien de partage public du plan : OneToOne vers `Race` non mappé côté `Race`, unique, `ON DELETE CASCADE` ; `scenario` ManyToOne nullable `ON DELETE SET NULL` (null = premier scénario) ; `token` VARCHAR(32), index unique `UNIQ_RACE_SHARE_TOKEN` (collation insensible à la casse : la comparaison exacte est refaite par `RaceShareRepository::findActiveByToken()`) ; `enabled`, `displayName` (60), `showPaces` (faux par défaut), `showCutoffs` (vrai par défaut), `message` (1000), `startMessage` / `finishMessage` (500), `createdAt` / `updatedAt` ; migration `Version20240030_CreateRaceShare`), `RaceScenario` (scénario du plan : nom, heure de départ, temps visé en secondes, position, stratégie d'allure `strategy` (+ enum `PacingStrategy` : even/conservative/negative/aggressive/ultra, formules et `suggestedFor(RaceType)`) et `strategyIntensity` (+ enum `PacingIntensity` : light/medium/strong), défauts SQL `even` / `medium` posés par la migration `Version20240029_AddScenarioStrategy` ; ManyToOne vers `Race`, `ON DELETE CASCADE`), `RaceScenarioSplit` (temps FIXÉ d'un repère pour un scénario, secondes depuis le départ ; unique `scenario_id` + `checkpoint_id`, `ON DELETE CASCADE` des deux côtés ; les temps auto ne sont jamais stockés ; non mappé en collection, chargé en une requête par `RaceScenarioSplitRepository::findForScenarios()` ; migration `Version20240023_CreateRaceScenarios`), `Gear` (matériel d'un utilisateur, + enum `GearType` chaussures/vélo et sports associés ; au plus un `isDefault` par utilisateur et par type, garanti par le code ; `stravaGearId` unique par utilisateur et `stravaLastActivityId` pour le matériel importé, migration `Version20240025_AddGearStravaId`), `GearBrand` (table de référence remplie par la migration `Version20240024_CreateGear`, pas de fixtures), `Activity.gear` (ManyToOne nullable, `ON DELETE SET NULL`), `ActivityFuel` (+ enum `FuelKind` food/drink ; ravitaillement, ManyToOne vers `Activity` non mappé côté `Activity`, `ON DELETE CASCADE`, unique `activity_id` + `itemKey`, migration `Version20240026_CreateActivityFuel`), `UserAvatar` (photo de profil en LONGBLOB, OneToOne vers `User` non mappé côté `User`, `ON DELETE CASCADE` ; `User.avatarUpdatedAt`, NULL sans photo, sert de version à l'URL de l'image pour l'afficher sans requête ; migration `Version20240027_CreateUserAvatar`), `WeeklyGoal` (objectif hebdomadaire par sport : distance km, séances, durée en minutes, D+ m, `position` ; unique `user_id` + `sport_id`, `ON DELETE CASCADE` des deux côtés, au moins une cible par `#[Assert\Callback]` avec messages du domaine `messages` ; `User.weeklyGoals` OneToMany indexé par id, `orphanRemoval` ; l'objectif global reste dans `AthleteProfile` ; migration `Version20240028_CreateWeeklyGoals`).
- **Repositories** ([src/Repository/](src/Repository/)) - Activity, ActivityFuel, Gear, GearBrand, Meal, Race, RaceCheckpoint, RaceRoute, RaceScenario, RaceScenarioSplit, RaceShare, Sport, User, UserAvatar, WeeklyGoal.
- **Autres** - `Form/ProfileType` (profil + objectifs hebdomadaires : `CollectionType` `weeklyGoals` de `WeeklyGoalType`, `property_path` `user.weeklyGoals`, `by_reference` false ; `DurationHoursType` + `DataTransformer/HoursToMinutesTransformer` pour la durée en heures, `SportHiddenType` pour le sport d'une ligne ; messages d'`invalid_message` traduits dans le type car le validateur ne les cherche que dans le domaine `validators`, absent du projet), `EventSubscriber/LocaleSubscriber` (locale stockée en session, défaut `fr`), `Twig/SidebarExtension` (stats du jour dans la sidebar), `Twig/ActivityFuelExtension` (fonction `activity_fuel(activity)` : données de la carte Ravitaillement, null pour la musculation et la natation), `Twig/AvatarExtension` (`user_avatar_url(user)` : URL versionnée de la photo de profil, null sans photo ou pour un autre utilisateur que celui connecté, sans requête ; `avatar_max_upload_bytes()` / `avatar_max_upload_mb()` : 5 Mo ou moins selon `upload_max_filesize` / `post_max_size`), `Twig/RaceDurationExtension` (filtres `race_duration`, `race_duration_input`, `race_pace` et fonction `race_clock(start, secondes)`, délèguent à `DurationFormat`). Les dossiers `src/DTO/` et `src/EventListener/` sont vides.
- **Styles** - [assets/styles/app.css](assets/styles/app.css), chargé par `asset()` dans `base.html.twig` (le CSS Bootstrap est importé par `app.js`).
- **Templates** ([templates/](templates/)) - `activities/`, `auth/`, `calendar/`, `dashboard/`, `nutrition/`, `race/`, `settings/`, `stats/`.
- **Traductions** - [translations/messages.fr.yaml](translations/messages.fr.yaml) et [messages.en.yaml](translations/messages.en.yaml) : toute nouvelle clé doit être ajoutée dans les deux.

### Auth & access control
- Form login Symfony (`/login`, `/logout`) géré par [LoginController](src/Controller/Auth/LoginController.php), inscription par `RegisterController`. La déconnexion se fait depuis le menu du compte (bloc utilisateur en bas de la barre latérale et du menu mobile, partial [templates/_sidebar_user.html.twig](templates/_sidebar_user.html.twig) : dropdown Bootstrap vers le haut avec profil, paramètres et `logout_path()`). Le remember-me (7 jours) est configuré mais inactif : le formulaire de connexion n'a pas de champ `_remember_me`.
- Contrôle d'accès par `access_control` dans [config/packages/security.yaml](config/packages/security.yaml) (tout est `ROLE_USER` sauf `/login`, `/register`, `/oauth`, `/reset-password`, `/webhooks` et `/p/` (plans partagés, règle placée avant `^/`), publics) et par `#[IsGranted('ROLE_USER')]` sur les controllers. `StravaController` (`/oauth/*`, `/strava/sync`) appelle lui-même `denyAccessUnlessGranted('ROLE_USER')`. Il n'y a **aucun Voter** (`src/Security/Voter/` est vide) ni contrôle par plan.
- Tokens Strava stockés dans `User` avec expiry ; refresh automatique par `StravaService` avant la synchro.

### Plan partagé (page publique)
`/p/{token}` et `/p/{token}/calendar.ics` (`RacePublicController`) sont accessibles sans compte, par le seul jeton. Règles à garder en modifiant cette page (chacune est testée : `tests/Unit/Service/Share/`, E2E) :
- **Rien de privé** : le template ne reçoit que `PublicPlanBuilder::build()` (liste blanche de scalaires), jamais d'entité. Jamais l'e-mail ou l'identifiant de l'utilisateur, les notes privées (course, repères), les autres scénarios, le nom ou la stratégie du scénario, le lien ou le nom du fichier GPX, le site, les données Strava, ni le statut de la course. Les messages publics des repères (`RaceCheckpoint::publicMessage`) sont distincts des notes. Toute nouvelle donnée affichée s'ajoute explicitement au builder et à son test.
- **Même 404** pour un jeton mal formé (sans requête), inconnu, régénéré ou un partage désactivé (`RaceShareRepository::findActiveByToken()`).
- **En-têtes** : `X-Robots-Tag: noindex, nofollow` (+ meta robots), `Referrer-Policy: strict-origin-when-cross-origin` (pas `no-referrer` : les tuiles OSM exigent un Referer ; le jeton du chemin ne sort pas du site), `Cache-Control: no-store, private` (désactiver prend effet aussitôt), `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`.
- **Pas de session** : page autonome (n'étend pas `base.html.twig`), ni `app.user`, ni `app.flashes`, ni `csrf_token()` ; langue par `?lang=fr|en`, sinon `Accept-Language` (fr / en), sinon fr (jamais la session ; `LocaleSwitcher::runWithLocale()`). `LocaleSubscriber` ne lit la session que si elle existe déjà ; en dev, seuls les cookies du profileur (`main_*_profile_token`) apparaissent.
- **Échappement** : Twig autoescape (jamais `|raw`), `textContent` en JS ; les infobulles Leaflet de `route-preview` sont échappées par `esc()`.

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
| `RaceScenario` | `raceScenario` |
| `RaceScenarioSplit` | `raceScenarioSplit` |
| `GearBrand` | `gearBrand` |
| `Gear` | `gear` |
| `ActivityFuel` | `activityFuel` |
| `UserAvatar` | `userAvatar` |
| `WeeklyGoal` | `weeklyGoal` |
| `RaceShare` | `raceShare` |

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
- Matériel : import Strava manuel (bouton de l'onglet Matériel) et synchrone. Le scope OAuth inclut `profile:read_all` depuis cet import (`StravaService::SCOPE`) : un compte connecté avant doit reconnecter Strava pour que le matériel jamais utilisé soit aussi importé.
- Pas de paiement : aucun Stripe/billing. Les colonnes Stripe de `User` et `subscriptionPlan`/`isPro()` ne servent qu'à afficher un libellé (Pro/Amateur).
- Pas de vérification d'email ni de « mot de passe oublié » (la règle `^/reset-password` n'a aucune route).
- Pas de fixtures ; tests unitaires seulement (`tests/Unit/`), aucun test fonctionnel.
- Plan de course : phases 1 (GPX + repères) et 2 (scénarios, temps de passage, stratégies d'allure, fiche imprimable / bracelet) faites, ainsi que le lien de partage public pour les proches (horaires PRÉVUS seulement : aucun suivi en direct). Restent la phase 3 (modèle d'allure auto tiré de l'historique, à brancher sur `SplitCalculator::segmentEfforts()`) et la phase 4 (comparaison après course).
- Upload GPX en local : `upload_max_filesize` de WAMP souvent à 2M (Docker : 20M).
- Photo de profil : la limite affichée est min(5 Mo, `upload_max_filesize`, `post_max_size`), donc 2 Mo sous WAMP. En prod, il faut GD avec le WebP (sinon sortie JPEG) et l'extension `exif` (sinon l'orientation des photos de téléphone n'est pas corrigée).
- Le schéma de la base locale diverge déjà du mapping Doctrine (index, types de colonnes) : vérifier `doctrine:schema:update --dump-sql` avant d'écrire une migration.
