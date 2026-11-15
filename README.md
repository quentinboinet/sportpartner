# SportPartner

SportPartner est une application web de suivi sportif. Chaque utilisateur crée un compte, connecte son compte Strava, et l'application importe ses activités pour les afficher : tableau de bord, liste et détail des activités (carte, dénivelé, allure, fréquence cardiaque, météo), statistiques par période, liste de courses à faire et suivi nutritionnel. L'interface est en français (par défaut) et en anglais.

Application Symfony rendue côté serveur (Twig) avec un peu de JavaScript via Stimulus. Pas de SPA, pas de Node.js.

---

## Fonctionnalités (par page)

| Route | Page | Ce qu'elle fait |
|---|---|---|
| `/register`, `/login`, `/logout` | Inscription / connexion | Inscription par e-mail, prénom, nom et mot de passe. Connexion par formulaire Symfony avec CSRF. Le « se souvenir de moi » (7 jours) est configuré dans `security.yaml`, mais le formulaire n'a pas de case `_remember_me` : il ne s'active donc jamais. |
| `/` | Tableau de bord | 10 dernières activités, graphique du volume hebdomadaire sur 12 semaines, objectifs de la semaine (distance et nombre de séances, 150 km et 7 séances par défaut), bannière de connexion Strava et bouton de synchro. Widget météo : géolocalisation du navigateur, prévisions Open-Meteo et nom de la ville via Nominatim (OpenStreetMap). |
| `/activities` | Activités | Liste paginée (20 par page), filtres par sport et par période (7 jours, 30 jours ou tout). Totaux : distance, charge estimée et calories estimées (approximations, voir « Limites »). |
| `/activities/{id}` | Détail d'une activité | Tracé GPS sur une carte Leaflet (tuiles OpenStreetMap), profil de dénivelé, allure par km, courbe de fréquence cardiaque, répartition estimée par zones FC, météo au départ (Open-Meteo : API de prévisions pour une activité du jour ou de la veille, archive historique au-delà ; uniquement si l'activité a un tracé GPS). Les flux Strava (altitude, splits, FC) et la météo sont récupérés à la première ouverture de la page, puis stockés en base. |
| `/stats` | Statistiques | Périodes : 7 jours, mois, saison (année en cours, par défaut), tout, ou plage personnalisée. Volume par sport, répartition par sport (donut), évolution de l'allure en course à pied sur 12 semaines, zones FC estimées, records personnels (5 km, 10 km et semi-marathon en course à pied, plus longue sortie vélo, plus gros dénivelé en trail ou randonnée). |
| `/courses` | Courses | Courses de l'utilisateur (nom, lieu, distance, dénivelé, année ou date précise, site web, notes), filtrables par année et par intention (`want_to_do` « Je veux la faire », `registered` « Je suis inscrit », `bookmark` « Mémo »). Deux vues : liste ou calendrier mensuel FullCalendar (`?view=calendar`, les courses sans date sont listées à part). Ajout et modification dans une modale, changement d'intention, marquer comme faite, suppression (`POST /courses`, `/courses/{id}/edit`, `/courses/{id}/intent`, `/courses/{id}/toggle`, `/courses/{id}/delete`). |
| `/nutrition` | Nutrition | Repas du jour, objectifs caloriques et macros calculés à partir du poids du profil (70 kg par défaut), dépense estimée (métabolisme de base et sport), graphique apports / dépense de la semaine. **Lecture seule : il n'existe aucun formulaire pour saisir un repas.** |
| `/calendar` | Calendrier | Grille mensuelle des activités. La route fonctionne, mais le lien du menu est commenté dans `templates/base.html.twig`. |
| `/settings` | Réglages | Onglet « Profil » fonctionnel : pseudo, âge, poids, FC repos, FC max, VMA, FTP et objectifs hebdomadaires. Les onglets Préférences, Zones FC, Appareils, Apparence et Compte affichent « bientôt disponible ». |
| `/switch-locale/{fr\|en}` | Langue | Enregistre la langue en session (`LocaleSubscriber`). |
| `/oauth/strava`, `/oauth/strava/callback`, `/strava/sync` | Strava | Connexion OAuth2 puis synchronisation manuelle (voir plus bas). |

La barre latérale affiche aussi la distance et la charge du jour (extension Twig `SidebarExtension`). La charge est un TRIMP de Banister : elle n'apparaît que si l'activité a une FC moyenne et que la FC max est renseignée dans le profil.

Toutes les pages sauf `/login` et `/register` exigent d'être connecté. Dans `config/packages/security.yaml`, `^/oauth` est en `PUBLIC_ACCESS` (comme `^/reset-password` et `^/webhooks`, qui n'ont aucune route), mais `StravaController` vérifie lui-même `ROLE_USER`. Les autres controllers portent aussi `#[IsGranted('ROLE_USER')]`.

### Synchronisation Strava

- La connexion se fait par OAuth2 avec le scope `read,activity:read_all`. Le callback enregistre les jetons dans `User`, puis lance tout de suite une première synchro.
- La synchro (`StravaService::syncUserActivities`) appelle `GET /api/v3/athlete/activities` et importe **les 30 derniers jours** seulement. Les activités déjà importées sont ignorées (détection par `stravaId`). Les types Strava sont regroupés en 8 sports : `run`, `trail`, `ride`, `swim`, `walk`, `hike`, `weight_training`, `other`.
- Le jeton est rafraîchi automatiquement quand il expire dans moins de 5 minutes.
- La synchro est **manuelle** : bouton du tableau de bord (`/strava/sync`). Il n'y a ni webhook ni tâche planifiée.

---

## Stack

- **Backend** : PHP ≥ 8.3, Symfony 7 (framework, security, form, validator, translation, http-client), Doctrine ORM 3 et Doctrine Migrations, `beberlei/doctrineextensions` (fonctions DQL `YEAR` et `WEEK`). MySQL 8.
- **Frontend** : Twig, Bootstrap 5.3, Stimulus 3, Chart.js 4, Leaflet 1.9 et FullCalendar 6 (avec Preact 10), chargés via **Symfony Asset Mapper** (importmap, sans bundler ni npm). FullCalendar est servi depuis des fichiers ESM locaux (`assets/lib/fullcalendar/`), pas via `importmap:require` (voir le commentaire dans `importmap.php`). Les icônes Bootstrap Icons viennent du CDN jsDelivr.
- **Services externes** :
  - API Strava v3 (OAuth2 et activités) ;
  - Open-Meteo (`api.open-meteo.com` pour les prévisions, `archive.open-meteo.com` pour l'historique), sans clé d'API ;
  - Nominatim et les tuiles OpenStreetMap, appelés depuis le navigateur.
- **Outillage** : PHPUnit 11, PHPStan 1.12 niveau 6 (avec `phpstan-symfony`), PHP-CS-Fixer (PSR-12 + Symfony).

### Organisation du code

```
src/
  Controller/      Activities, Auth, Calendar, Dashboard, Nutrition, OAuth (Strava), Race, Settings, Stats, LocaleController
  Entity/          User, AthleteProfile, Activity, Sport, Meal (+ enum MealType), Race (+ enum RaceIntent)
  Repository/      requêtes d'agrégation (stats, volumes, records…)
  Service/         Strava/StravaService, Weather/WeatherService (Open-Meteo), Units/UnitConverter
  Form/            ProfileType (réglages du profil)
  Twig/            SidebarExtension (stats du jour)
  EventSubscriber/ LocaleSubscriber (langue en session)
assets/
  app.js                   point d'entrée (Bootstrap + controllers Stimulus)
  controllers/index.js     enregistrement manuel des controllers Stimulus
  styles/app.css           styles de l'application (chargés par base.html.twig)
  lib/fullcalendar/        FullCalendar 6 en fichiers ESM locaux
templates/                 Twig par domaine
translations/              messages.fr.yaml, messages.en.yaml
migrations/                Version20240001 → Version20240019
```

**Tables** (toujours déclarées avec `#[ORM\Table]` ; voir les conventions dans `CLAUDE.md`) :

| Entité | Table |
|---|---|
| `User` | `users` |
| `AthleteProfile` | `AthleteProfile` |
| `Activity` | `activities` |
| `Sport` | `sports` (les 8 sports sont insérés par la migration `Version20240003`) |
| `Meal` | `Meal` |
| `Race` | `race` |

**Controllers Stimulus**

Ils sont enregistrés à la main dans `assets/controllers/index.js`. `assets/controllers.json` est vide.

| `data-controller` | Utilisé dans |
|---|---|
| `weekly-chart`, `weather` | tableau de bord |
| `map`, `elevation-chart`, `pace-chart`, `heartrate-chart` | détail d'une activité |
| `volume-chart`, `donut-chart`, `pace-trend` | statistiques |
| `energy-donut`, `balance-chart` | nutrition |
| `race-calendar` | courses (vue calendrier, FullCalendar chargé à la demande par `import()`) |

`weather_activity_controller.js` existe mais n'est ni enregistré ni utilisé. `weather_constants.js` est un module partagé, pas un controller.

---

## Démarrage local

### Avec Docker Compose

Services définis dans `docker-compose.yml` :

| Service | Image | Port hôte |
|---|---|---|
| `php` | `docker/php/Dockerfile` (PHP 8.3-FPM Alpine) | — |
| `nginx` | `nginx:alpine` | **8080** → http://localhost:8080 |
| `mysql` | `mysql:8.0` (base `sport_health`, utilisateur `app` / `password`) | 3306 |
| `mailhog` | `mailhog/mailhog` | 1025 (SMTP), 8025 (interface web) |

`docker/mysql/init.sql` crée aussi une base `sport_health_test`. MailHog démarre mais l'application n'envoie aucun e-mail pour l'instant.

```bash
cp .env.example .env
# Dans .env : APP_URL=http://localhost:8080 (le port de nginx), APP_SECRET, STRAVA_CLIENT_ID, STRAVA_CLIENT_SECRET
docker compose up -d --build
docker compose exec php composer install          # vendor/ est un volume anonyme du conteneur
docker compose exec php bin/console doctrine:migrations:migrate
```

Ouvrir ensuite http://localhost:8080/register pour créer un compte. Il n'y a pas de fixtures ni de compte de démo.

Les assets n'ont pas besoin d'être compilés en dev : Asset Mapper les sert directement. Pour ajouter une dépendance JS : `php bin/console importmap:require <paquet>`.

### Sans Docker (WAMP ou PHP local)

Le PHP CLI de WAMP (PHP 8.5) suffit pour la console, les tests et PHPStan. Avec un MySQL local :

```bash
cp .env.example .env
# DATABASE_URL="mysql://<user>:<mdp>@127.0.0.1:3306/sport_health?serverVersion=8.0&charset=utf8mb4"
composer install
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate
php -S localhost:8000 -t public                   # APP_URL=http://localhost:8000
```

Un fichier `.env` est obligatoire : Symfony Dotenv refuse de démarrer sans lui. `.env` et `.env.local` sont ignorés par git ; `.env.local` surcharge `.env`.

---

## Variables d'environnement

Les modèles versionnés sont `.env.example` (dev) et `.env.prod.example` (prod).

| Variable | Rôle | Exemple | Requise |
|---|---|---|---|
| `APP_ENV` | Environnement Symfony | `dev` / `prod` | oui |
| `APP_DEBUG` | Mode debug | `true` en dev, `false` en prod | conseillée (sinon déduite de `APP_ENV`) |
| `APP_SECRET` | Secret du framework (`kernel.secret`, utilisé notamment pour signer le cookie « se souvenir de moi ») | chaîne aléatoire de 32 caractères | oui |
| `APP_URL` | URL publique, sans `/` final. Sert uniquement à construire l'URL de callback Strava | `http://localhost:8080` | oui |
| `DATABASE_URL` | Connexion MySQL (Doctrine) | `mysql://app:password@mysql:3306/sport_health?serverVersion=8.0&charset=utf8mb4` | oui |
| `STRAVA_CLIENT_ID` | ID de l'application Strava | `123456` | oui |
| `STRAVA_CLIENT_SECRET` | Secret de l'application Strava | — | oui |

Présentes dans les modèles mais **lues par aucun code aujourd'hui** : `MAILER_DSN`, `MAILER_FROM_ADDRESS`, `MAILER_FROM_NAME` (pas de configuration mailer, aucun envoi d'e-mail) et `STRAVA_WEBHOOK_VERIFY_TOKEN` (pas de webhook).

---

## Configurer l'application Strava

1. Créer une application sur https://www.strava.com/settings/api.
2. Reporter son *Client ID* et son *Client Secret* dans `STRAVA_CLIENT_ID` et `STRAVA_CLIENT_SECRET`.
3. **Authorization Callback Domain** : le domaine d'`APP_URL`, par exemple `localhost` en local, ou le domaine Alwaysdata en prod.

L'URI de redirection envoyée à Strava est construite dans `config/services.yaml` :

```yaml
strava.redirect_uri: '%env(APP_URL)%/oauth/strava/callback'
```

Elle doit donc correspondre exactement à l'URL par laquelle on accède au site, port compris (8080 avec Docker).

---

## Commandes utiles

| Commande | Effet |
|---|---|
| `php vendor/bin/phpunit` | Tests (suites `Unit` et `Functional` de `phpunit.xml.dist`) |
| `php vendor/bin/phpunit tests/Unit/Service/UnitConverterTest.php` | Un seul fichier de test |
| `php vendor/bin/phpunit --filter testPaceCalculation` | Un seul test |
| `composer analyse` | PHPStan niveau 6 sur `src/`. Exige `var/cache/dev/App_KernelDevDebugContainer.xml` : lancer d'abord `php bin/console cache:warmup --env=dev`. Ajouter `--memory-limit=1G` si la mémoire manque. |
| `composer cs-check` / `composer cs-fix` | PHP-CS-Fixer sur `src/` et `tests/` (vérification à blanc / correction) |
| `php bin/console doctrine:migrations:migrate` | Appliquer les migrations |
| `php bin/console doctrine:schema:validate --skip-sync` | Vérifier le mapping Doctrine |
| `php bin/console debug:router` | Lister les routes |
| `php bin/console asset-map:compile` | Compiler les assets (prod uniquement) |


Avec Docker, préfixer par `docker compose exec php` (par exemple `docker compose exec php vendor/bin/phpunit`).

Les tests se limitent aujourd'hui à `tests/Unit/Service/UnitConverterTest.php`. `tests/Functional/` est vide (il contient seulement `.gitkeep`, pour que la suite déclarée dans `phpunit.xml.dist` existe en CI).

---

## Qualité et CI

Le workflow `.github/workflows/deploy.yml` (« Deploy to Alwaysdata ») tourne sur chaque push et chaque pull request vers `main`.

**Job `checks`** (Ubuntu, PHP 8.3) :

1. Création d'un `.env` avec des valeurs factices, puis `composer install`.
2. `php bin/console cache:warmup --env=dev` (container XML pour PHPStan).
3. `vendor/bin/phpunit`.
4. `vendor/bin/phpstan analyse --no-progress --memory-limit=1G`.
5. `lint:container`, `lint:twig templates`, `lint:yaml config`.
6. `doctrine:schema:validate --skip-sync` (aucune base de données nécessaire).

PHP-CS-Fixer n'est pas exécuté en CI.

**Job `deploy`** : ne tourne qu'après la réussite de `checks`, et seulement sur un push vers `main`. Les pull requests ne déploient jamais. Deux déploiements ne se chevauchent jamais (groupe de concurrence `alwaysdata-production-deploy`).

---

## Déploiement (Alwaysdata)

**Tout push sur `main` part en production** dès que les vérifications passent, migrations comprises.

Le job se connecte en SSH (`appleboy/ssh-action`) et exécute dans `~/www/sportpartner` :

```bash
git pull origin main
mkdir -p var/log var/cache
composer install --no-dev --optimize-autoloader --no-interaction
rm -rf var/cache/prod                     # le container de prod n'est jamais revalidé : on le supprime
php bin/console doctrine:migrations:migrate --no-interaction --env=prod
php bin/console asset-map:compile --env=prod
php bin/console cache:warmup --env=prod
```

**Secrets GitHub requis** (Settings → Secrets and variables → Actions) :

| Secret | Contenu |
|---|---|
| `ALWAYSDATA_SSH_HOST` | Hôte SSH Alwaysdata |
| `ALWAYSDATA_SSH_USER` | Utilisateur SSH |
| `ALWAYSDATA_SSH_PASSWORD` | Mot de passe SSH |

**Côté serveur**, à préparer une fois :

- un clone du dépôt dans `~/www/sportpartner` ;
- un site Alwaysdata dont la racine est `public/` ;
- un fichier d'environnement non versionné (`.env`, éventuellement surchargé par `.env.local`), créé à partir de `.env.prod.example` avec `APP_ENV=prod`, `APP_DEBUG=false`, la base MySQL Alwaysdata, `APP_URL` en HTTPS et les identifiants Strava ;
- le domaine de prod déclaré comme *Authorization Callback Domain* dans l'application Strava.

---

## Limites connues et pistes

- **Strava** : la synchro est manuelle et ne couvre que les 30 derniers jours. Il n'y a ni webhook, ni import de l'historique complet, ni tâche planifiée. Le paramètre OAuth `state` est généré mais jamais vérifié au retour du callback. Les variables et la règle d'accès `^/webhooks` existent sans aucun controller derrière.
- **Paiement** : il n'y a ni Stripe ni facturation. Les colonnes `stripeCustomerId`, `stripeSubscriptionId` et `subscriptionPlan` de `User` sont des restes. `User::isPro()` ne sert qu'à choisir le libellé affiché sous le nom (« Membre Pro » ou « Compétiteur amateur »). Aucun contrôle d'accès ne dépend du plan.
- **Comptes** : le « se souvenir de moi » est configuré mais inactif (aucune case `_remember_me` sur le formulaire de connexion). Pas de vérification d'e-mail (les champs `emailVerified` et `emailVerificationToken` ne sont jamais utilisés) ni de « mot de passe oublié » (`^/reset-password` figure dans `security.yaml` sans route). Aucun e-mail n'est envoyé.
- **Données** : aucune fixture et aucun compte de démo. Il faut s'inscrire via `/register`.
- **Nutrition** : la page n'a aucune saisie. Les repas (`Meal`) ne peuvent être créés qu'en base.
- **Estimations** : plusieurs chiffres sont des approximations simples, pas des mesures :
  - charge (« TSS ») déduite de la durée sur la liste des activités (60 par heure) ; TRIMP de Banister (FC moyenne, FC repos et FC max du profil) dans la barre latérale ;
  - calories : 50 kcal/km sur la liste des activités, 8 kcal/min sur la page nutrition ;
  - zones FC déduites du rapport FC moyenne / FC max ;
  - métabolisme de base : poids × 22.
- **Réglages** : seul l'onglet Profil fonctionne. Le calendrier n'est pas accessible depuis le menu.
- **Front** : `weather_activity_controller.js` est du code mort. Le calendrier d'activités (`/calendar`) est une grille Twig et n'utilise pas FullCalendar, réservé pour l'instant au calendrier des courses.
- **Tests** : couverture minimale (un seul fichier de tests unitaires, aucun test fonctionnel).
- **Divers** :
  - le `name` et la `description` de `composer.json` décrivent encore l'ancien boilerplate (« Symfony 7 + Vue.js 3 ») ;
  - le Dockerfile installe Node.js, npm et l'extension Redis, mais aucun service Redis n'existe et le projet n'utilise pas npm ;
  - `.env.example` propose `APP_URL=http://localhost:8000` alors que nginx écoute sur 8080.
