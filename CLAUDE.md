# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Tech Stack

**Backend:** Symfony 7.0 (PHP 8.3+), Doctrine ORM 3, JWT Auth (lexik/jwt), Nelmio CORS  
**Frontend:** Bootstrap 5 + Stimulus (via Symfony Asset Mapper — aucun bundler Node.js)  
**Infrastructure:** Docker Compose — PHP 8.3-FPM, Nginx, MySQL 8, Redis 7, MailHog  
**External:** Strava OAuth2 (activity sync), Stripe (subscriptions)

## Development Commands

All commands run inside Docker containers:

```bash
docker compose up -d                                            # Start all services
docker compose exec php bin/console doctrine:migrations:migrate
docker compose exec php bin/console doctrine:fixtures:load
```

**Assets (Asset Mapper — pas de npm run dev) :**
```bash
# En dev : les assets sont servis dynamiquement par Symfony, aucune compilation nécessaire
# En prod uniquement :
docker compose exec php bin/console asset-map:compile
```

**Ajouter un package JS :**
```bash
php bin/console importmap:require nom-du-package
```

**PHP tooling :**
```bash
composer test       # PHPUnit (Unit + Functional suites)
composer analyse    # PHPStan level 6 sur /src
composer cs-fix     # PHP-CS-Fixer (PSR-12 + Symfony) sur /src et /tests
composer cs-check   # Dry-run code style check
```

**Run a single test:**
```bash
docker compose exec php bin/phpunit tests/Unit/Service/UnitConverterTest.php
docker compose exec php bin/phpunit --filter testMethodName
```

## Architecture

### Frontend (Bootstrap + Stimulus + Asset Mapper)
- **Pas de Node.js / npm / Vite.** Les assets sont gérés par Symfony Asset Mapper.
- L'entrée JS est [assets/app.js](assets/app.js), chargée via `{{ importmap('app') }}` dans le layout.
- Les dépendances JS (Bootstrap, Chart.js, @hotwired/stimulus) sont dans [importmap.php](importmap.php).
- Les controllers Stimulus sont dans [assets/controllers/](assets/controllers/) et enregistrés dans [assets/controllers/index.js](assets/controllers/index.js).
- Les templates Twig sont dans [templates/](templates/), organisés par domaine.

### Stimulus controllers
| Fichier | data-controller | Rôle |
|---|---|---|
| `weekly_chart_controller.js` | `weekly-chart` | Graphique Chart.js volume hebdomadaire |

Pour attacher un controller Stimulus à un élément :
```html
<canvas data-controller="weekly-chart"
        data-weekly-chart-data-value="{{ weeklyVolume|json_encode }}">
```

### Layer structure
- **Controllers** ([src/Controller/](src/Controller/)) — organisés par domaine : `Auth/`, `Dashboard/`, `OAuth/`, `Billing/`, `Api/`
- **Services** ([src/Service/](src/Service/)) — toute la logique métier : `Strava/` (OAuth, sync), `Billing/` (Stripe), `Auth/`, `Units/`
- **Entities** ([src/Entity/](src/Entity/)) — `User` (tokens Strava & Stripe, plan enum), `Activity`
- **Templates** ([templates/](templates/)) — Twig par domaine : `auth/`, `dashboard/`

### Auth & access control
- Form login Symfony (`/login`, `/logout`) géré par [LoginController](src/Controller/Auth/LoginController.php).
- Symfony Voters dans [src/Security/](src/Security/) pour le contrôle d'accès par plan (freemium).
- Tokens Strava stockés dans `User` avec expiry ; auto-refresh par `StravaService`.

### Webhooks
Routes Strava et Stripe déclarées en `PUBLIC_ACCESS` dans [config/packages/security.yaml](config/packages/security.yaml). Ne pas les mettre derrière `ROLE_USER`.

## Conventions base de données

### Nommage des tables — camelCase obligatoire

Toutes les tables utilisent le **camelCase** (ex: `athleteProfile`, `raceIntent`).  
MySQL sur Linux est **sensible à la casse** : `Race` ≠ `race`. Sans annotation explicite, Doctrine utilise le nom de la classe PHP comme nom de table (PascalCase), ce qui peut diverger de la migration.

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

**État actuel des tables :**

| Entité | Table |
|---|---|
| `User` | `users` |
| `Activity` | `activities` |
| `Sport` | `sports` |
| `AthleteProfile` | `AthleteProfile` |
| `Meal` | `Meal` |
| `Race` | `race` |

> `AthleteProfile` et `Meal` correspondent car la migration crée la table en PascalCase et Doctrine aussi.  
> Si tu renommes ces tables un jour, pense à mettre à jour l'annotation ET la migration.

## Environment

Copier `.env.example` vers `.env` et renseigner :
- `DATABASE_URL` — MySQL
- `JWT_SECRET_KEY`, `JWT_PUBLIC_KEY`, `JWT_PASSPHRASE`
- `STRAVA_CLIENT_ID`, `STRAVA_CLIENT_SECRET`
- `STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET`
- `MAILER_DSN` — MailHog en local : `smtp://localhost:1025`
