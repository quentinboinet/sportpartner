# Sport & Health SaaS Boilerplate

**Symfony 7 + Vue.js 3 + Strava OAuth + Stripe Billing**

Un starter kit production-ready pour lancer une application SaaS dans le domaine du sport et de la santé. Auth complète, dashboard de progression, intégration Strava, paiements Stripe, gestion des unités — tout est là, rien à câbler.

---

## Ce qui est inclus

### Authentification
- Inscription, connexion, déconnexion
- Vérification d'email (Symfony Mailer)
- Mot de passe oublié / réinitialisation
- Gestion du profil utilisateur
- Sécurité via `security.yaml` avec voters

### Intégration Strava
- OAuth2 : connexion en 1 clic avec ton compte Strava
- Import des activités (course, trail, vélo, marche)
- Synchronisation automatique via webhook Strava
- Normalisation des données (distance, dénivelé, FC, cadence)
- Refresh automatique du token Strava expiré

### Dashboard & visualisations
- Volume hebdomadaire (km / heures)
- Charge d'entraînement (ATL, CTL, TSB)
- Courbe de progression sur 30/90/365 jours
- Composants Chart.js encapsulés en Vue.js
- Responsive mobile-first (Tailwind CSS)

### Stripe Billing
- Plans freemium / premium configurables
- Création d'abonnements Stripe Checkout
- Portail client Stripe (gestion de la carte, annulation)
- Webhooks : `customer.subscription.updated`, `invoice.payment_failed`, etc.
- Guard Symfony pour restreindre les features aux plans payants

### Gestion des unités
- km ↔ miles, kg ↔ lbs, kcal ↔ kJ
- Préférences par utilisateur persistées en base
- Composable Vue.js `useUnits()` pour affichage cohérent côté front
- Service PHP `UnitConverter` côté back

### Qualité & DevOps
- Docker Compose complet (PHP 8.3, Nginx, MySQL 8, Redis)
- GitHub Actions : tests PHPUnit + PHPStan niveau 6 + PHP CS Fixer
- Fixtures de démo (utilisateurs, activités, abonnements)
- PHPUnit avec tests unitaires et fonctionnels
- `.env.example` documenté, prêt à copier

---

## Stack technique

| Couche | Technologie |
|--------|-------------|
| Back-end | Symfony 7, API Platform, Doctrine ORM |
| Front-end | Vue.js 3, Inertia.js, Tailwind CSS 3 |
| Base de données | MySQL 8 |
| Cache / Queue | Redis |
| Paiements | Stripe SDK PHP |
| Sport API | Strava API v3 |
| Build | Vite 5 |
| Tests | PHPUnit 11 |
| Conteneurisation | Docker Compose |
| CI | GitHub Actions |

---

## Prérequis

- Docker & Docker Compose
- PHP 8.3+ (si sans Docker)
- Node.js 20+
- Compte Strava Developer (gratuit) → [strava.com/settings/api](https://www.strava.com/settings/api)
- Compte Stripe (gratuit) → [dashboard.stripe.com](https://dashboard.stripe.com)

---

## Installation en 3 commandes

```bash
# 1. Cloner et configurer l'environnement
git clone https://github.com/ton-compte/sport-health-boilerplate.git
cd sport-health-boilerplate
cp .env.example .env

# 2. Lancer Docker et installer les dépendances
docker compose up -d
docker compose exec php composer install
docker compose exec php npm install && npm run build

# 3. Initialiser la base de données
docker compose exec php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec php bin/console doctrine:fixtures:load --no-interaction
```

L'application est accessible sur `http://localhost:8080`.

Compte démo créé par les fixtures : `admin@example.com` / `password`

---

## Configuration

### Variables d'environnement

Copie `.env.example` en `.env` et renseigne :

```dotenv
# Base de données
DATABASE_URL="mysql://app:password@mysql:3306/sport_health"

# Mailer (pour vérification email)
MAILER_DSN=smtp://localhost:1025  # MailHog en local

# Strava OAuth
STRAVA_CLIENT_ID=your_client_id
STRAVA_CLIENT_SECRET=your_client_secret
STRAVA_WEBHOOK_VERIFY_TOKEN=your_random_token

# Stripe
STRIPE_SECRET_KEY=sk_test_...
STRIPE_PUBLISHABLE_KEY=pk_test_...
STRIPE_WEBHOOK_SECRET=whsec_...
STRIPE_PRICE_ID_MONTHLY=price_...
STRIPE_PRICE_ID_YEARLY=price_...

# App
APP_SECRET=change_me_in_production
APP_URL=http://localhost:8080
```

### Créer l'app Strava

1. Va sur [strava.com/settings/api](https://www.strava.com/settings/api)
2. Crée une application, note le `Client ID` et `Client Secret`
3. Renseigne l'URL de callback : `http://localhost:8080/oauth/strava/callback`
4. Pour les webhooks en local : utilise [ngrok](https://ngrok.com) ou [Stripe CLI](https://stripe.com/docs/stripe-cli)

### Créer les produits Stripe

```bash
# Créer les produits via la CLI Stripe
stripe products create --name="Sport Health Pro"
stripe prices create --unit-amount=790 --currency=eur --recurring[interval]=month --product=prod_xxx
```

Renseigne ensuite le `STRIPE_PRICE_ID_MONTHLY` dans ton `.env`.

---

## Lancer les webhooks en local

```bash
# Terminal 1 — Strava (ngrok)
ngrok http 8080
# Copie l'URL ngrok dans la config Strava webhook

# Terminal 2 — Stripe
stripe listen --forward-to http://localhost:8080/webhooks/stripe
```

---

## Tests

```bash
# Tous les tests
docker compose exec php bin/phpunit

# Tests unitaires seulement
docker compose exec php bin/phpunit --testsuite Unit

# Tests fonctionnels
docker compose exec php bin/phpunit --testsuite Functional

# Analyse statique
docker compose exec php vendor/bin/phpstan analyse

# Code style
docker compose exec php vendor/bin/php-cs-fixer fix --dry-run
```

---

## Structure des plans Stripe

Le boilerplate gère deux plans par défaut, configurables dans `config/packages/billing.yaml` :

| Plan | Fonctionnalités |
|------|-----------------|
| Free | Import manuel, 30 dernières activités, graphiques basiques |
| Pro (7,90€/mois) | Sync Strava auto, historique illimité, tous les graphiques, export CSV |

Pour restreindre une feature au plan Pro, utilise le voter Symfony :

```php
// Dans un Controller
$this->denyAccessUnlessGranted('SUBSCRIPTION_PRO', $this->getUser());

// Ou dans Twig / Inertia (prop passée automatiquement)
// Vue : if (page.props.auth.subscription === 'pro')
```

---

## Personnalisation

### Ajouter un type d'activité

```php
// src/Entity/Activity.php
enum ActivityType: string {
    case Run = 'run';
    case Trail = 'trail';
    case Ride = 'ride';
    case Walk = 'walk';
    case Swim = 'swim'; // ← ajoute ici
}
```

### Ajouter une métrique au dashboard

```javascript
// assets/js/components/charts/MyMetricChart.vue
// Utilise le composable useUnits() pour la conversion automatique
import { useUnits } from '@/composables/useUnits'
const { formatDistance } = useUnits()
```

### Changer la devise

```dotenv
STRIPE_CURRENCY=usd  # eur par défaut
```

---

## Déploiement

### Sur un VPS (Hetzner, OVH…)

```bash
# Copie le docker-compose.prod.yml
cp docker-compose.prod.yml docker-compose.override.yml

# Variables de prod
cp .env .env.local
# Édite .env.local avec les vraies clés

# Lance
docker compose up -d --build
docker compose exec php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec php bin/console cache:warmup --env=prod
```

### Variables supplémentaires en production

```dotenv
APP_ENV=prod
APP_DEBUG=false
DATABASE_URL="mysql://user:password@host:3306/sport_health_prod"
```

---

## Roadmap

Les features suivantes ne sont pas incluses dans le boilerplate mais documentées pour extension :

- [ ] Intégration Garmin Connect API
- [ ] Export PDF des rapports
- [ ] Notifications email hebdomadaires
- [ ] API publique avec clés d'API par utilisateur
- [ ] Multi-tenant (coaches / athlètes)

---

## Support

Ce boilerplate est vendu tel quel, sans garantie de fonctionnement dans tous les environnements.

- **Tier Pro** : support par email sous 48h sur `support@ton-domaine.fr`
- **Documentation complémentaire** : voir le dossier `docs/` (wiki inclus dans le zip)
- **Issues connues** : voir `CHANGELOG.md`

---

## Licence

Usage personnel ou commercial illimité pour l'acheteur. Redistribution ou revente du code source interdite.

Voir `LICENSE.txt` pour les conditions complètes.

---

## Changelog

### v1.0.0 — juin 2025
- Version initiale
- Auth complète Symfony
- OAuth Strava + import activités
- Dashboard Chart.js (volume, charge, progression)
- Stripe Billing (mensuel + annuel)
- Gestion des unités (km/miles, kg/lbs, kcal/kJ)
- Docker Compose + CI GitHub Actions
- Fixtures de démo
