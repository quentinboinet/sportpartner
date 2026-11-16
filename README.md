# SportPartner

SportPartner est une application web de suivi sportif. Chaque utilisateur crée un compte, connecte son compte Strava, et l'application importe ses activités pour les afficher : tableau de bord, liste et détail des activités (carte, dénivelé, allure, fréquence cardiaque, météo), statistiques par période, liste de courses à faire et suivi nutritionnel. L'interface est en français (par défaut) et en anglais.

Application Symfony rendue côté serveur (Twig) avec un peu de JavaScript via Stimulus. Pas de SPA, pas de Node.js.

---

## Fonctionnalités (par page)

| Route | Page | Ce qu'elle fait |
|---|---|---|
| `/register`, `/login`, `/logout` | Inscription / connexion | Inscription par e-mail, prénom, nom et mot de passe. Connexion par formulaire Symfony avec CSRF. Le « se souvenir de moi » (7 jours) est configuré dans `security.yaml`, mais le formulaire n'a pas de case `_remember_me` : il ne s'active donc jamais. |
| `/` | Tableau de bord | 10 dernières activités (allure en /km, vitesse en km/h pour le vélo), graphique du volume hebdomadaire sur 12 semaines ISO consécutives (semaines vides à zéro), tuiles distance / temps / D+ / charge et objectifs de la semaine EN COURS, tendance par rapport à la semaine précédente affichée dès qu'une activité est enregistrée cette semaine (distance et nombre de séances, 150 km et 7 séances par défaut, puis une ligne par objectif par sport : voir « Objectifs hebdomadaires par sport »), bannière de connexion Strava et bouton de synchro. Bandeau d'usure du matériel sous l'en-tête (absent si rien n'est usé) : matériel en service à 80 % de sa durée de vie (orange, « bientôt à remplacer » / « révision à prévoir » pour un vélo) ou à 100 % (rouge, « à remplacer » / « à réviser »), le plus usé d'abord, 3 lignes au plus puis « et N autres », lien vers l'onglet Matériel ; la fermeture est mémorisée dans le navigateur (`localStorage`) par matériel et par statut, et l'alerte revient si le statut s'aggrave. Widget météo : géolocalisation du navigateur, prévisions Open-Meteo et nom de la ville via Nominatim (OpenStreetMap). |
| `/activities` | Activités | Liste paginée (20 par page), filtres par sport et par période (7 jours, 30 jours ou tout). Totaux : distance, charge estimée et calories estimées (approximations, voir « Limites »). Pastille du matériel rattaché (chaussures, vélo) sur chaque ligne. |
| `/activities/{id}` | Détail d'une activité | Tracé GPS sur une carte Leaflet (tuiles OpenStreetMap), profil de dénivelé, allure par km, courbe de fréquence cardiaque, répartition estimée par zones FC, météo au départ (Open-Meteo : API de prévisions pour une activité du jour ou de la veille, archive historique au-delà ; uniquement si l'activité a un tracé GPS). Les flux Strava (altitude, splits, FC) et la météo sont récupérés à la première ouverture de la page, puis stockés en base. Choix du matériel de l'activité (liste du matériel non retiré compatible avec le sport, enregistrée au changement) : `POST /activities/{id}/gear` (`ActivityGearController`, CSRF `gear`). Carte « Ravitaillement » sous le profil altimétrique (tous les sports sauf musculation et natation) : aliments (unités) et boissons (ml) consommés, glucides totaux, débit en g/h comparé à la cible 60-90 g/h (jauge), hydratation en ml/h ; glucides par unité modifiables (la dernière valeur saisie est reprise sur les activités suivantes) et apports personnalisés (« + Autre »). Enregistrement automatique de tout l'état en JSON : `POST /activities/{id}/fuel` (`ActivityFuelController`, CSRF `activity_fuel` dans l'en-tête `X-CSRF-Token`, 422 si la saisie est invalide). |
| `/stats` | Statistiques | Onglets « Vue d'ensemble » / « Comparer ». Périodes : 7 jours, mois, saison (année en cours, par défaut), tout, ou plage personnalisée (le dernier jour et les activités du jour comptent). Volume par sport, répartition par sport (donut), évolution de l'allure en course à pied sur 12 semaines, zones FC estimées, records personnels (5 km, 10 km et semi-marathon en course à pied, plus longue sortie vélo, plus gros dénivelé en trail ou randonnée). Sous les totaux, pour 7 jours, mois et saison : écart avec la période précédente (voir « Statistiques : comparer deux périodes ») et lien « Comparer en détail ». |
| `/stats/comparer` | Statistiques, vue Comparer | Deux périodes côte à côte, préréglées ou libres, tous les sports ou un seul : totaux, allure ou vitesse, FC, records de la période, moyennes par semaine, courbes de cumul, barres par tranche, détail par sport. Voir « Statistiques : comparer deux périodes ». |
| `/courses` | Courses | Courses de l'utilisateur (nom, type route ou trail, lieu, distance, dénivelé, année ou date précise, site web, notes), filtrables par statut (`want_to_do` « Je veux la faire », `registered` « Je suis inscrit », `bookmark` « Mémo »), par type (`?type=road|trail` ; les courses antérieures au champ n'ont pas de type) et par année. Deux vues : liste ou calendrier mensuel FullCalendar (`?view=calendar`, les courses sans date sont listées à part). Ajout et modification dans une modale, changement d'intention, marquer comme faite, suppression (`POST /courses`, `/courses/{id}/edit`, `/courses/{id}/intent`, `/courses/{id}/toggle`, `/courses/{id}/delete`). |
| `/courses/{id}/plan` | Plan de course | Import d'un parcours GPX (15 Mo et 200 000 points max, DOCTYPE/entités refusés) : tracé simplifié (~5 000 points max), distance, D+/D-, altitude min/max ; complète distance et D+ de la course s'ils étaient vides. Carte Leaflet et profil Chart.js. Repères (point ou ravito avec temps d'arrêt, barrière horaire au format `H:MM` pouvant dépasser 24 h, notes) placés au km ou par clic sur le profil, et tableau des tronçons entre repères (distance, D+, D-, pente moyenne). Routes `POST /courses/{id}/route` (champ `gpx`), `/courses/{id}/route/delete`, `/courses/{id}/checkpoints`, `/courses/{id}/checkpoints/{checkpointId}/edit` et `/delete` (`RacePlanController`, CSRF `race_plan`). Sous les repères, section « Temps de passage » : scénarios, temps de passage et fiche imprimable (voir « Plan de course : scénarios et temps de passage »), puis section « Nutrition » (voir « Plan de course : nutrition »). |
| `/courses/{id}/partage` | Partager le plan | Réglages du lien public envoyé aux proches (propriétaire seulement) : activer / désactiver, copier le lien, aperçu, régénérer, scénario partagé, nom affiché, options (allures, barrières) et messages pour les proches (général, départ, chaque repère, arrivée). Voir « Partage du plan de course ». |
| `/p/{token}` | Plan partagé (public) | Page sans compte pour les proches : horaires prévus du scénario partagé, frise des passages avec les messages, carte et profil, prochain passage estimé le jour de la course, bouton « Ajouter à mon agenda » (`/p/{token}/calendar.ics`). Voir « Partage du plan de course ». |
| `/sante`, `/sante/exercices/{id}`, `/sante/seance`, `/sante/historique` | Santé | Exercices à faire chez soi (renforcement, gainage, équilibre, mobilité, étirements) : bibliothèque filtrable, fiches, exercices personnels, lien vidéo personnel, séance guidée avec minuteur, historique et activité créée à la fin. Voir « Espace Santé ». |
| `/nutrition` | Nutrition | Repas du jour, objectifs caloriques et macros calculés à partir du poids du profil (70 kg par défaut), dépense estimée (métabolisme de base et sport), graphique apports / dépense de la semaine. **Lecture seule : il n'existe aucun formulaire pour saisir un repas.** |
| `/calendar` | Calendrier | Calendrier FullCalendar (mois, semaine, année) des activités réalisées, avec en option les courses datées (`?races=1`). Les événements sont chargés par période via `GET /calendar/events?start=&end=&races=0\|1` (JSON, 400 jours max) ; les heures Strava (UTC) sont converties dans le fuseau de l'activité. Clic = vue rapide en modale (activité : stats + mini-carte ; course : statut, type, compte à rebours, notes, liens vers le plan de course). Panneau latéral : bilan de la période affichée et 5 prochaines courses. `?date=` et `?races=` sont tenus à jour dans l'URL. |
| `/settings` | Réglages | Onglet « Profil » fonctionnel : pseudo, âge, poids, FC repos, FC max, VMA, FTP et objectifs hebdomadaires (tous sports confondus et par sport) ; photo de profil (voir « Photo de profil »). Onglet « Matériel » (`?tab=gear`, alias `?tab=materiel`) : chaussures (course, trail, marche, randonnée) et vélos, marque choisie dans la table de référence `gearBrand`, modèle, surnom, date d'achat, km déjà parcourus, durée de vie (700 km proposés pour des chaussures, vide = usure non suivie), matériel par défaut (un par type). Chaque carte affiche le total (km avant suivi + activités rattachées), le nombre d'activités, la dernière sortie et l'usure (bon état < 80 %, à surveiller, à remplacer >= 100 %). Actions : modifier (le type est figé), mettre à la retraite / réactiver, supprimer (les activités sont conservées, sans matériel), rattacher le matériel par défaut aux activités sans matériel des sports de son type (depuis la date d'achat). Bloc « Importer depuis Strava » si le compte est connecté (sinon lien de connexion), badge « Strava » sur le matériel importé : voir « Import du matériel Strava ». Routes `POST /settings/gear`, `/settings/gear/{id}/edit`, `/retire`, `/delete`, `/assign-defaults`, `/settings/gear/import-strava` (`GearController`, CSRF `gear`). Les onglets Préférences, Zones FC, Appareils, Apparence et Compte affichent « bientôt disponible ». |
| `/notifications` | Cloche « À faire » | Liste en JSON de la cloche de la barre du haut (toutes les pages connectées) : courses proches, matériel à remplacer ou à rattacher, objectifs de la semaine, ravitaillement à noter, Strava. Voir « Cloche « À faire » ». |
| `/switch-locale/{fr\|en}` | Langue | Enregistre la langue en session (`LocaleSubscriber`). |
| `/oauth/strava`, `/oauth/strava/callback`, `/strava/sync` | Strava | Connexion OAuth2 puis synchronisation manuelle (voir plus bas). |

La barre latérale affiche aussi la distance et la charge du jour (extension Twig `SidebarExtension`). La charge est un TRIMP de Banister : elle n'apparaît que si l'activité a une FC moyenne et que la FC max est renseignée dans le profil.

Toutes les pages sauf `/login`, `/register` et les plans partagés `/p/{token}` exigent d'être connecté. Dans `config/packages/security.yaml`, `^/p/` est en `PUBLIC_ACCESS` (avant la règle `^/`) : l'accès se fait par le seul jeton du lien. `^/oauth` est aussi en `PUBLIC_ACCESS` (comme `^/reset-password` et `^/webhooks`, qui n'ont aucune route), mais `StravaController` vérifie lui-même `ROLE_USER`. Les autres controllers portent aussi `#[IsGranted('ROLE_USER')]`.

### Synchronisation Strava

- La connexion se fait par OAuth2 avec le scope `read,activity:read_all,profile:read_all` (`StravaService::SCOPE`). `profile:read_all` a été ajouté avec l'import du matériel : il fait apparaître vélos et chaussures dans `GET /athlete`. Un compte connecté avant doit **reconnecter Strava** (`/oauth/strava`, lien « Reconnecter Strava » de l'onglet Matériel) pour l'obtenir ; sans lui, l'import fonctionne mais ne trouve que le matériel déjà utilisé dans une activité. Le callback enregistre les jetons dans `User`, puis lance tout de suite une première synchro.
- La synchro (`StravaService::syncUserActivities`) appelle `GET /api/v3/athlete/activities` et importe **les 30 derniers jours** seulement. Les activités déjà importées sont ignorées (détection par `stravaId`). Les types Strava sont regroupés en 8 sports : `run`, `trail`, `ride`, `swim`, `walk`, `hike`, `weight_training`, `other`.
- Chaque **nouvelle** activité reçoit le matériel importé de Strava qui correspond à son `gear_id` ; à défaut, le matériel par défaut de l'utilisateur pour son sport (chaussures pour `run`, `trail`, `walk`, `hike` ; vélo pour `ride`), sauf si elle précède sa date d'achat. Les activités déjà importées ne sont pas modifiées.
- Le jeton est rafraîchi automatiquement quand il expire dans moins de 5 minutes.
- La synchro est **manuelle** : bouton du tableau de bord (`/strava/sync`). Il n'y a ni webhook ni tâche planifiée.

### Import du matériel Strava

Bouton « Importer depuis Strava » de l'onglet Matériel (`POST /settings/gear/import-strava`, `StravaGearImporter`), synchrone (délai HTTP de 15 s par requête, 180 s au total) :

- **Lecture d'abord, rien n'est écrit en cas d'erreur** : toutes les activités Strava (`GET /athlete/activities`, 200 par page jusqu'à une page incomplète, 50 pages au plus), `GET /athlete` (au mieux, pour le matériel jamais utilisé), puis un seul `GET /gear/{id}` par matériel. 401/403 : message demandant de reconnecter Strava ; 429 : réessayer dans 15 minutes ; réseau ou 5xx : réessayer plus tard. Un matériel introuvable (404) est ignoré et compté.
- **Matériel** créé ou mis à jour par (utilisateur, `stravaGearId`) : type d'après l'identifiant (`b...` vélo, `g...` chaussures) ; marque rapprochée de `gearBrand` sans tenir compte de la casse, des accents ni de la ponctuation (« HOKA ONE ONE » = Hoka, « New-Balance » = New Balance, « Decathlon Kiprun » = Kiprun, une marque maison Decathlon non valable pour le type = Decathlon), sinon « Autre » (`GearBrandMatcher`) ; modèle = `model_name`, sinon `name` ; surnom = `name` s'il diffère de « marque modèle ». Un matériel saisi à la main de même type, marque et modèle est repris (s'il est le seul) au lieu d'être dupliqué. À la mise à jour, la marque « Autre » et l'absence de surnom n'écrasent pas la saisie locale ; durée de vie, date d'achat et notes restent locales (700 km pour des chaussures créées). Retiré sur Strava = mis à la retraite ; une retraite locale n'est jamais annulée. Le matériel `primary` de Strava devient le matériel par défaut de son type seulement si l'utilisateur n'en a pas déjà un en service.
- **Activités déjà importées** : celles dont l'activité Strava a un `gear_id` reçoivent le matériel importé si elles n'en ont pas ; avec « Remplacer les rattachements existants », toujours. Les activités Strava sans matériel ne sont pas modifiées.
- **Kilométrage initial** = max(0, total Strava - distance des activités rattachées ici), recalculé à chaque import : le total affiché égale celui de Strava. `stravaLastActivityId` garde l'id de l'activité Strava la plus récente vue à l'import : quand la synchro importe plus tard une activité antérieure (déjà comptée dans ce total), sa distance est retirée du kilométrage initial pour ne pas la compter deux fois.

### Photo de profil

Onglet Profil des réglages (`AvatarController`, CSRF `avatar`, retour sur l'onglet Profil). La photo s'affiche aussi dans la barre latérale et le menu mobile (initiales sinon).

- **Envoi** (`POST /settings/avatar`, champ `avatar`) : le fichier choisi est envoyé aussitôt (type et taille contrôlés d'abord dans le navigateur). `AvatarProcessor` vérifie le contenu réel (finfo puis `getimagesize`, jamais l'extension) : JPEG, PNG, WebP ou GIF fixe ; HEIC, SVG, AVIF et images animées refusés ; 5 Mo et 40 mégapixels au plus, vérifiés avant tout décodage (`memory_limit` relevé si besoin, 256 Mo au plus pour l'image décodée). Puis recadrage carré centré, réduction à 256 x 256 (jamais d'agrandissement), orientation EXIF des JPEG (extension `exif`), réencodage en WebP qualité 85, transparence conservée (JPEG sur fond blanc si GD n'écrit pas le WebP) : les métadonnées (position GPS...) disparaissent. La limite affichée est le minimum de 5 Mo, `upload_max_filesize` et `post_max_size` ; un envoi plus gros que `post_max_size` (POST vidé par PHP) donne aussi le message « Image trop lourde ».
- **Photo Strava** (`POST /settings/avatar/strava`, bouton affiché seulement si Strava est connecté, `StravaAvatarFetcher`) : jeton rafraîchi si besoin, `GET /athlete`, champ `profile` (124 x 124), sinon `profile_medium`. Un chemin relatif (image par défaut de Strava) ou une URL non https donne « Pas de photo sur ton profil Strava ». Téléchargement durci : https seulement (redirections suivies une à une, 3 au plus), adresses privées refusées (`NoPrivateNetworkHttpClient`, mêmes plages que l'import GPX par lien), délai de 5 s, 5 Mo lus au plus, puis même retraitement. 401/403 : message demandant de reconnecter Strava ; 429, erreur réseau ou image refusée : message, jamais de page d'erreur.
- **Suppression** : `POST /settings/avatar/delete` (après confirmation).
- **Stockage** : en base (table `userAvatar`, une ligne par utilisateur, image en LONGBLOB), pas sur disque : la photo survit aux déploiements. `users.avatarUpdatedAt` (NULL sans photo) sert de version : `user_avatar_url(user)` (`AvatarExtension`) construit `/settings/avatar/image?v=<timestamp>` sans requête ni chargement de l'image.
- **Image** : `GET /settings/avatar/image` ne sert que la photo de l'utilisateur connecté (404 sans photo), avec `Content-Type`, `Content-Length`, `ETag`, `X-Content-Type-Options: nosniff` et `Cache-Control: private, max-age=31536000, immutable` quand `v` est la version courante (revalidation sinon).
- **Production** : extension GD avec le WebP (sinon sortie JPEG), extension `exif` (sinon l'orientation des photos de téléphone n'est pas corrigée), `upload_max_filesize` à 5M au moins et `post_max_size` un peu au-dessus pour accepter 5 Mo.

### Objectifs hebdomadaires par sport

Section « Objectifs hebdomadaires » de l'onglet Profil, enregistrée avec le même bouton que le profil (`ProfileType`, CSRF du formulaire) :

- **Tous sports confondus** : distance et séances cibles, dans `AthleteProfile` (inchangé).
- **Par sport** (table `weeklyGoal`, `WeeklyGoal`, une ligne par utilisateur et par sport) : distance (km, au dixième), séances, durée (saisie en heures : `5`, `5,5`, `5.5`, `5:30` ou `5h30`, stockée en minutes) et D+ (m), toutes facultatives mais au moins une par sport. `CollectionType` `weeklyGoals` de `WeeklyGoalType` (`property_path` `user.weeklyGoals`, `by_reference` false, adder/remover de `User`, collection indexée par id) ; lignes ajoutées depuis le menu « Ajouter un sport » (sports sans objectif seulement) et retirées par `weekly_goals_controller.js` (gabarit `<template>`, sans `innerHTML`). Le sport d'une ligne enregistrée ne change pas ; un sport retiré puis rajouté dans la même saisie réutilise sa ligne (`WeeklyGoalReconciler`, évite le conflit avec l'index unique). Erreurs affichées sur la ligne (aucune cible, sport en double, bornes, durée illisible), saisie conservée, première erreur amenée à l'écran.
- **Tableau de bord** : sous les anneaux de la carte « Objectifs de la semaine », une ligne par objectif (ordre des réglages) : pour chaque cible renseignée, « 18 / 30 km », « 2 / 3 séances », « 3h10 / 5h » ou « 620 / 1 000 m D+ », une barre (plafonnée à 100 %, valeurs réelles au-delà), « encore 12 km » ou « Atteint » avec une coche. `WeeklyGoalProgress` : semaine du lundi 00:00:00 au dimanche 23:59:59 (mêmes bornes que le volume hebdomadaire et le libellé de semaine), une seule requête agrégée par sport (`ActivityRepository::sumBySportBetween`) ; les activités sans sport comptent pour « Autre ».

### Plan de course : scénarios et temps de passage

Section « Temps de passage » de `/courses/{id}/plan` (`RaceScenarioController`, CSRF `race_plan` ; chaque action revient sur `#scenario-{sid}`, ses messages sont affichés en tête de la section) :

- **Scénarios** : 6 par course au plus, en onglets. Nom (vide = « Objectif A », « Objectif B »... dans la langue de l'interface), heure de départ facultative, temps visé à l'arrivée (`h:mm`, `h:mm:ss` ou `3h15`, au-delà de 24 h possible). Routes `POST /courses/{id}/scenarios` (ajout), `/courses/{id}/scenarios/{sid}/save`, `/distribute`, `/duplicate` et `/delete`.
- **Temps fixés / auto** : un temps saisi est « fixé » et enregistré (`raceScenarioSplit`). Les autres repères sont « auto » : recalculés à chaque affichage, jamais stockés, répartis entre les temps fixés et le temps visé au prorata de l'effort des tronçons (km + D+ / 100, arrêts aux ravitos compris), arrondis à 10 s (à la seconde pour des repères à quelques mètres l'un de l'autre). Le temps visé n'est jamais modifié. « Répartir » efface les temps fixés du scénario.
- **Stratégie d'allure** (par scénario, champs `strategy` et `strategy_intensity`) : Régulier, Départ prudent, Negative split, Prise de risque, Gestion ultra, intensité légère / moyenne / marquée (sans effet pour Régulier). Elle ne façonne que les temps auto : chaque tronçon pèse effort × m(x), x = milieu du tronçon dans la course (0 = départ, 1 = arrivée), m > 1 = plus lent ; temps fixés et temps visé restent exacts. Formules : Régulier m = 1 ; Départ prudent m = 1 + a(1 - x/0,3) avant 30 % (a = 0,02 / 0,04 / 0,06) ; Negative split m = 1 + k(0,5 - x) (k = 0,03 / 0,06 / 0,10) ; Prise de risque m = 1 - k(0,5 - x) (k = 0,02 / 0,04 / 0,08) ; Gestion ultra m = 1 + f(x - 0,5) (f = 0,08 / 0,15 / 0,25). Source unique : enum `App\Entity\PacingStrategy` (+ `PacingIntensity`), miroir JS dans `race_splits_controller.js`. Nouveau scénario : stratégie proposée selon le type de course (route : negative split, trail : départ prudent, sans type : régulier). Le changement est recalculé en direct ; la colonne des allures affiche l'écart de chaque tronçon avec l'allure moyenne en mouvement (« +3 % », « -2 % »). Dupliquer copie la stratégie ; la fiche imprimable et la page de la course l'affichent. Migration `Version20240029_AddScenarioStrategy` (scénarios existants : régulier / moyenne).
- **Tableau** : par repère, temps écoulé, heure (badge J+1 après minuit), arrêt et heure de départ du ravito, allure et vitesse du tronçon, allure moyenne depuis le départ, marge sur la barrière horaire (respectée, serrée sous 15 min, dépassée). Erreurs signalées : temps non croissant, temps après l'arrivée, arrêts qui atteignent le temps visé. Le tableau est recalculé dans le navigateur pendant la saisie (`race-splits`) ; une alerte prévient avant de quitter la page ou d'imprimer avec des modifications non enregistrées.
- **Fiche imprimable** : `GET /courses/{id}/plan/print?scenario={sid}` (`&format=band` pour le bracelet), page autonome sans menu, ouverte dans un nouvel onglet. Fiche A4 (passages, heures, arrêts, allures, barrières, notes des repères) ou bracelet (bandes de 28 mm à découper). Elle reprend la version **enregistrée** du scénario.
- **Page de la course** (`/courses/{id}`) : liste des scénarios (stratégie d'allure, temps visé, départ, arrivée) avec un lien vers chacun et vers sa fiche.

Calcul : `SplitCalculator` (pur, sans base : `compute()`, `fillAuto()`, `distribute()`), `ScenarioViewBuilder` (données de la section et de la fiche), `DurationFormat` (lecture et affichage des durées), extension Twig `RaceDurationExtension` (filtres `race_duration`, `race_duration_input`, `race_pace`, fonction `race_clock()`). `assets/controllers/race_splits_controller.js` reproduit exactement `SplitCalculator::fillAutoFromEfforts()`, `pacedEfforts()`, `compute()` et `PacingStrategy::multiplier()` : toute règle de calcul se modifie des deux côtés (`PacingStrategyTest` vérifie que les constantes JS sont celles de l'enum).

### Plan de course : nutrition

Section « Nutrition » de `/courses/{id}/plan`, sous « Temps de passage » : ce que tu prévois de manger et de boire à chaque point (départ, repères) et sur chaque tronçon (identifié par son point d'arrivée), **par scénario**. Elle suit l'onglet choisi dans « Temps de passage » (et son propre choix « Scénario ») et les temps de passage **enregistrés** du scénario (durées en mouvement, arrêts aux ravitos exclus).

- **Auto + ajustements** : chaque ligne est auto (calculée, jamais stockée) tant qu'elle n'est pas modifiée ; un +/- ou « Ajouter » la rend manuelle (stockée, gardée), le reste est recalculé quand les temps, les cibles ou les préférences changent. « Auto » remet une ligne en calcul, « Tout recalculer » (confirmation) efface toutes les lignes manuelles du scénario.
- **Réglages par scénario** : glucides visés (0-150 g/h, défaut 70), hydratation (0-1500 ml/h, défaut 500), répartition des glucides boisson / gels / solide (%, somme 100 ; défaut route 30/70/0, trail 30/40/30 avec le solide en début de course), « solide plutôt en début de course », produits du calcul auto (gel, solide, boisson ; eau pour le reste du volume), glucides de chaque produit (ceux du ravitaillement des activités : même catalogue, dernière valeur de l'utilisateur proposée) et produits personnalisés (nom, catégorie, glucides), contenance d'une flasque.
- **Calcul** (`RaceNutritionCalculator`, règles dans son docblock) : diffusion d'erreur sur toute la course (le cumul prévu, lignes manuelles comprises à leur valeur réelle, suit cible × temps cumulé), volume au pas de 250 ml, boisson glucidique pour sa part (bornée par le volume visé), gels et solide selon la répartition, unités entières, plafond strict par tronçon (150 % de la cible, ou cible + une unité si c'est plus), tronçon très court = report sur le suivant, solide mangé aux ravitos et points d'assistance. Débits g/h et ml/h par tronçon et en moyenne, statut dans la cible à ± 20 %.
- **À emporter** : du départ jusqu'au premier point d'assistance (nouvelle case « Assistance / sac d'allègement » dans la fenêtre d'un repère), à récupérer à chaque assistance, pris sur place aux ravitos officiels, boissons à remplir entre deux points d'eau en flasques.
- **Partout** : fiche imprimable (« En route / Au point » sous chaque repère, bilan et liste à emporter), bracelet (« 1 gel + 250 ml · ici 1 barre »), page partagée si l'option « Nutrition aux points d'assistance » est cochée (désactivée par défaut ; seulement le scénario partagé et les points d'assistance).
- **Copier le plan nutrition de…** un autre scénario (réglages, produits et lignes manuelles ; confirmation si le scénario a des lignes manuelles) ; « Dupliquer » un scénario copie aussi son plan nutrition. Repère supprimé : ses apports manuels passent sur le tronçon suivant (message) ; ajouté ou déplacé : les apports restent attachés à leur point d'arrivée.
- **Enregistrement** automatique en JSON (`POST /courses/{id}/scenarios/{sid}/nutrition`, CSRF `race_nutrition` en en-tête, tout l'état du scénario, 600 ms après la dernière modification et quand la page est quittée) ; recalcul en direct dans le navigateur par `race_nutrition_calculator.js`, miroir exact du calcul PHP (entiers seulement).
- Migration `Version20240031_CreateRaceNutrition` (tables `raceNutritionPlan` et `raceNutritionItem`, colonnes `raceCheckpoint.assistance` et `raceShare.showNutrition`).

### Partage du plan de course

Lien public à envoyer aux proches pour savoir quand et où encourager. Bouton « Partager » sur la page de la course et sur le plan de course.

- **Réglages** (`/courses/{id}/partage`, `RaceShareController`, propriétaire seulement : 403 sinon, CSRF `race_share`) : `POST /courses/{id}/partage/toggle` (champ `enabled` : `1` active, sinon désactive ; le lien répond alors 404, et le réactiver redonne le même lien), `/courses/{id}/partage/regenerate` (nouveau jeton après confirmation : l'ancien lien répond 404), `POST /courses/{id}/partage` (enregistrement). Le partage (table `raceShare`, une ligne par course) n'est créé qu'à la première action, jamais à l'affichage. Réglages : scénario partagé (s'il est supprimé, le premier scénario par position prend le relais), nom affiché (prénom du profil proposé, jamais l'e-mail ; vide = aucun nom), afficher les allures prévues (non par défaut) et les barrières horaires (oui par défaut), message général (1000 caractères), message du départ, de chaque repère (`raceCheckpoint.publicMessage`, distinct des notes privées ; aussi modifiable dans la fenêtre d'édition du repère sur le plan, champ `public_message`, mêmes règles, champ absent = inchangé) et de l'arrivée (500 caractères chacun). Les longueurs sont limitées dans le navigateur (`maxlength` + compteur) et revérifiées par le serveur (`ShareSettingsParser`, sauts de ligne comptés pour 1) ; une saisie invalide est réaffichée avec ses erreurs (422) et rien n'est écrit. L'heure prévue de chaque point suit le scénario choisi dans la liste. Une icône signale sur le plan de course les repères qui ont un message public.
- **Page publique** (`GET /p/{token}`, `RacePublicController`) : page autonome mobile d'abord (ni menu ni lien vers l'application), entrée JS légère `race_public` (`assets/race_public.js` : Stimulus `route-preview` pour la carte et le profil, `race-public` pour le prochain passage). Langue : `?lang=fr|en`, sinon la meilleure de fr / en selon `Accept-Language`, sinon fr, avec un sélecteur FR / EN (jamais la session). Contenu : nom, date et lieu de la course, nom affiché, heure de départ, arrivée prévue, distance, D+, message général, frise départ → repères → arrivée (km, ravito, heure prévue avec J+1, allure et barrière selon les options, message de chaque point), carte et profil si un GPX existe. Sans scénario ou sans temps visé : « Horaires pas encore définis », la course, le parcours et les messages restent affichés. Le jour de la course, le prochain passage prévu est mis en avant d'après l'horloge de l'appareil (« Prochain passage estimé : Ravito 10 vers 09:48 »), avec la mention que ce ne sont pas des temps en direct.
- **Agenda** : `GET /p/{token}/calendar.ics` (`PlanCalendar`) : un événement iCalendar (RFC 5545 : CRLF, lignes repliées à 75 octets, textes échappés, UID, DTSTAMP) du départ à l'arrivée prévue, en heures locales « flottantes » (la course n'a pas de fuseau), passages prévus dans la description ; sans heure de départ, événement sur la journée.
- **Confidentialité et sécurité** : `PublicPlanBuilder` ne copie qu'une liste blanche de valeurs (jamais l'e-mail ou l'identifiant de l'utilisateur, les notes privées, les autres scénarios, le nom ou la stratégie du scénario, le lien ou le nom du fichier GPX, le site, les données Strava). Jeton : 24 octets de `random_bytes` en base64url (32 caractères), index unique, comparaison exacte ; jeton mal formé (sans requête), inconnu, régénéré ou partage désactivé : la même 404. En-têtes `X-Robots-Tag: noindex, nofollow` (et la balise meta), `Referrer-Policy: strict-origin-when-cross-origin` (les tuiles OpenStreetMap exigent un Referer, le jeton du chemin n'en sort pas), `Cache-Control: no-store, private`. Tous les textes sont échappés (Twig, `textContent` en JS). La page n'ouvre aucune session (ni CSRF, ni message flash, ni `app.user`) et fonctionne sans cookie.
- Migration `Version20240030_CreateRaceShare` (table `raceShare` et colonne `raceCheckpoint.publicMessage`).

### Cloche « À faire »

La cloche de la barre du haut liste ce qui mérite l'attention de l'utilisateur, tiré de ses propres données. Rien n'est stocké en base (ni table, ni migration, ni tâche planifiée) et aucun service externe n'est appelé : `GET /notifications` (`NotificationsController`, JSON, `Cache-Control: no-store, private`) recalcule la liste à chaque appel. `notifications_controller.js` la charge après le chargement de la page.

- **Courses** « Je suis inscrit », datées et non faites, une seule ligne par course :
  - J0 « C'est le jour J : … » et J-1 « Demain : … » (urgent, page de la course), quel que soit le plan ;
  - de J-2 à J-15 avec un temps visé : « … dans 7 jours » (page de la course) ;
  - de J-2 à J-15 sans plan complet, compte à rebours et rappel fusionnés (page du plan) : « … dans 7 jours, sans plan de course » (ni GPX, ni repère, ni scénario) ou « … dans 7 jours : pas encore de temps de passage » (GPX, repères ou scénario, mais aucun temps visé) ;
  - de J-16 à J-21 : seulement ces deux rappels du plan ;
  - « Prévision météo du jour J disponible » en sous-titre de J-15 à J0 si la course a un lieu ou un GPX (fenêtre des prévisions de `RaceWeatherService`).
- **Matériel** : les mêmes alertes que le bandeau d'usure du tableau de bord (« à remplacer » / « bientôt à remplacer », « à réviser » / « révision à prévoir » pour un vélo), lien vers la carte du matériel ; « 3 activités récentes sans matériel » pour les activités des 14 derniers jours sans matériel d'un sport qui en reçoit (chaussures : course, trail, marche, randonnée ; vélo : vélo) quand l'utilisateur a du matériel en service de ce type, lien vers la plus récente.
- **Objectifs par sport** (semaine ISO en cours, comme le tableau de bord) : « Objectif vélo atteint cette semaine » ; le dimanche seulement, « Course : il te reste 12 km et 1 séance ». Objectif « Renfo » en séances sans aucune séance faite, du jeudi au samedi : « 0 / 2 séances de renfo cette semaine », lien vers l'espace Santé (clé `health:strength:{semaine ISO}`).
- **Ravitaillement** : activités des 14 derniers jours d'au moins 1h30, d'un sport qui a la carte Ravitaillement, sans rien de noté (hors activités créées par une séance Santé) : les 3 plus récentes, lien vers la carte de l'activité.
- **Strava** : « Connecte ton compte Strava » (en dernier) ; « Reconnecte Strava » si le compte est connecté mais que le jeton a expiré sans jeton de rafraîchissement.
- **Ordre** : urgent (course aujourd'hui ou demain, matériel usé), puis à surveiller, puis informations.
- **Masquer** : bouton par ligne, « Tout marquer comme lu », « Réafficher ». Les clés masquées sont gardées dans le navigateur (`localStorage`, une liste par compte) ; une clé contient l'état de l'alerte (`gear:12:warn` puis `gear:12:worn`, `race:5:noplan:j15` puis `race:5:noplan:j7`...), si bien qu'une alerte masquée revient quand la situation change. Les clés qui ne sont plus renvoyées sont oubliées. Le point rouge n'apparaît que s'il reste des éléments non masqués.

Calcul : `TodoBuilder` (pur, règles et seuils dans son docblock), `TodoProvider` (6 requêtes agrégées au plus), `TodoPresenter` (traductions `notifications.*`, liens).

### Statistiques : comparer deux périodes

`GET /stats/comparer` (`StatsCompareController`, route `app_stats_compare`). Paramètres GET, partageables et à mettre en favori : `preset`, `a_from`, `a_to`, `b_from`, `b_to` (dates `AAAA-MM-JJ`), `sport` (slug de la table `sports`, vide = tous les sports). A est la période la plus récente, B la référence.

- **Préréglages** (`PeriodComparisonResolver`, calcul pur à partir de « maintenant », jours entiers 00:00:00 → 23:59:59 dans le fuseau de PHP, comme les objectifs de la semaine) :
  - `week` « Cette semaine vs semaine dernière », à date : du lundi à aujourd'hui contre les mêmes jours de la semaine dernière (un lundi : un jour contre un jour) ;
  - `month` « Ce mois vs mois dernier » (par défaut), à date : du 1er au jour J contre du 1er au jour J du mois précédent. Si ce mois n'a pas de jour J (30 mars contre février), il est pris jusqu'à son dernier jour ; le dernier jour du mois, les deux mois sont comparés en entier (31 mars : mars contre février, 30 avril : avril contre mars) ;
  - `month_yoy` « Ce mois vs même mois l'an dernier », mêmes règles (29 février 2028 : février 2028 contre février 2027 en entier) ;
  - `ytd` « Cette année vs l'an dernier à date » : du 1er janvier à aujourd'hui contre la même date calendaire l'an dernier ; un 29 février est comparé au 28 février ;
  - `30d` « 30 derniers jours vs 30 précédents » (aujourd'hui compris) ;
  - `last_year` « Année N-1 vs N-2 », années complètes (365 ou 366 jours) ;
  - `custom` « Personnalisé » : deux plages libres (champs date natifs), 5 ans au plus chacune (1 827 jours), entre le 01/01/2000 et le 31/12 de l'année prochaine ; elles peuvent se chevaucher.
- **Saisie invalide** (préréglage inconnu, date absente, mal formée ou impossible, période inversée, trop longue ou hors bornes, tableau au lieu d'une valeur) : retour à « Ce mois vs mois dernier » avec un message ; sport inconnu : tous les sports, avec un message. Jamais d'erreur 500.
- **Indicateurs** (`PeriodComparator`), chacun avec l'écart absolu et en % (pas de % si B vaut 0, « - » si A et B sont vides), calculés sur les valeurs arrondies comme affichées : distance, temps en mouvement, D+, séances ; allure moyenne (temps total ÷ distance des sorties de plus de 100 m) pour la course, le trail, la marche ou la randonnée, vitesse moyenne pour le vélo, et avec « Tous les sports » l'allure de la course et la vitesse du vélo seulement ; FC moyenne pondérée par le temps en mouvement ; plus longue sortie et plus gros D+ ; moyennes par semaine (total × 7 ÷ nombre de jours). Si A et B n'ont pas la même durée, une note le signale et les moyennes par semaine sont mises en avant.
- **Couleurs** : plus de volume = vert, moins = rouge ; allure plus basse (plus rapide) = vert, avec le mot « plus rapide » ; FC toujours neutre. Une flèche, le signe et un texte pour les lecteurs d'écran accompagnent toujours la couleur.
- **Graphiques** (Stimulus `stats-compare`, Chart.js) : cumul jour par jour (J1, J2..., A orange plein, B gris pointillé, la courbe s'arrête à aujourd'hui), barres côte à côte alignées par indice (jours jusqu'à 14 jours, tranches de 7 jours depuis le début de chaque période jusqu'à 120 jours, mois civils au-delà), barres par sport avec « Tous les sports ». Sélecteur distance / temps / D+ / séances (boutons radio, au clavier avec les flèches). Alternatives textuelles : description de chaque graphique avec les chiffres clés, tableau de données des tranches, tableau par sport (distance, temps, séances).
- **Requêtes** : la liste des sports et une seule requête agrégée par jour et par sport pour A et B (`ActivityRepository::sumByDayAndSport`) ; tout le reste est calculé en PHP. La vue d'ensemble ajoute une requête (`getSeasonStats` sur la période précédente) : « Ce mois » est comparé au préréglage `month`, « Saison » à `ytd`, « 7 jours » au même nombre de jours juste avant.

### Espace Santé

Exercices à faire chez soi pour les coureurs et cyclistes : renforcement, gainage, équilibre, mobilité, étirements. Un avertissement est affiché sur les pages : repères généraux, pas un avis médical ; arrêter en cas de douleur, consulter un professionnel en cas de blessure.

- **Bibliothèque** (`/sante`, `HealthController`) : 58 exercices fournis (26 renforcement, 8 gainage, 5 équilibre, 9 mobilité, 10 étirements) et les exercices personnels de l'utilisateur. Filtres catégorie, zone, niveau, matériel (« sans matériel » compris) et recherche sans accents ni casse, appliqués en direct (`health-library`) et par le serveur à partir de l'URL (`HealthLibraryFilter`, mêmes règles). « Afficher les exercices masqués » si l'utilisateur en a masqué.
- **Fiche** (`/sante/exercices/{id}`) : dosage par défaut (séries × répétitions ou secondes, repos), niveau, zones, matériel, « chaque côté », muscles, position de départ, étapes, respiration, erreurs fréquentes, variantes plus facile / plus difficile. Exercice fourni : masquer / réafficher (`POST .../masquer`) et lien vidéo personnel ; exercice personnel : modifier, supprimer (l'historique garde son nom). Lien vidéo (`POST .../video`) : adresse http(s) seulement (`VideoUrl`, `javascript:` et autres schémas refusés), affichée comme simple lien dans un nouvel onglet, jamais intégrée. Aucune vidéo n'est fournie avec les exercices.
- **Exercices personnels** (`/sante/exercices/nouveau`, `/sante/exercices/{id}/modifier`, `HealthExerciseForm`) : mêmes champs que les exercices fournis, longueurs limitées, étapes et erreurs une par ligne. Ils sont écrits dans une langue et affichés tels quels en français comme en anglais.
- **Séance guidée** (`/sante/seance`, `HealthSessionController`, Stimulus `health-session`) : le plateau « Séance en cours » se remplit depuis la bibliothèque ou une fiche (bouton « Ajouter »), dosage modifiable par exercice (prérempli), ordre (Monter / Descendre), retrait. Il est gardé dans le navigateur (`localStorage`, une clé par compte) : instantané, utilisable hors ligne pendant la séance, aucune écriture en base pour un brouillon ; il n'est pas partagé entre appareils. Le lecteur enchaîne « Prépare-toi » (5 s), les séries (compte à rebours pour une série en secondes, bouton « Fait » pour une série en répétitions), les côtés gauche puis droit d'un exercice « chaque côté » (5 s pour changer), les repos (« +15 s », « Passer ») ; pause, précédent, suivant, terminer, progression ; bips courts (Web Audio) et vibration, coupables ; écran gardé allumé (Screen Wake Lock) pendant la séance. Un rechargement reprend la séance en pause à la même étape (`sessionStorage`). Annonces pour les lecteurs d'écran aux transitions seulement ; `prefers-reduced-motion` respecté.
- **Fin de séance** : résumé (durée active, séries faites, valeurs réelles par exercice), ressenti RPE 1 à 10, notes, nom, puis `POST /sante/seance/enregistrer` (JSON, CSRF `health_session` dans l'en-tête `X-CSRF-Token`, contrat dans `HealthSessionPayloadValidator`).
- **Activité** : la case « Ajouter à mes activités » (cochée par défaut) crée une activité locale (sans `stravaId`) : nom de la séance, départ en UTC avec le fuseau du navigateur, temps en mouvement = durée active, temps écoulé = du début à la fin. Sport « Renfo » (`weight_training`) ou « Autre » (`other`), au choix, proposé par la règle : renforcement, gainage et équilibre contre mobilité et étirements, « Renfo » si les premiers sont au moins aussi nombreux (`HealthActivityMapper`). Elle apparaît dans le calendrier, les statistiques, les objectifs de la semaine et le tableau de bord ; sa page renvoie à la séance et n'a pas de carte Ravitaillement. À décocher si la séance est aussi enregistrée avec une montre ou sur Strava : la synchro l'importerait une deuxième fois. La synchro Strava ne modifie ni ne supprime jamais une activité locale.
- **Historique** (`/sante/historique`, `HealthHistoryController`) : liste paginée, détail (prévu / fait, ressenti, notes), « Refaire cette séance » (`/sante/seance?refaire={id}` : préremplit le plateau, après confirmation s'il n'est pas vide), suppression avec la case « Supprimer aussi l'activité » (cochée par défaut).
- **Accès** : tout est derrière `ROLE_USER` ; exercice personnel ou séance d'un autre utilisateur : 404 en lecture, 403 en écriture ; CSRF `health` sur les formulaires.
- **Mettre à jour le contenu fourni** : la migration `Version20240032_CreateHealth` est figée une fois passée en production. Pour corriger un texte, écrire une nouvelle migration `UPDATE healthExerciseText SET ... WHERE locale = 'fr' AND exercise_id = (SELECT id FROM healthExercise WHERE slug = '...')` ; pour ajouter un exercice, une nouvelle migration qui insère un nouveau slug dans `healthExercise` et ses deux lignes `fr` et `en` dans `healthExerciseText` (même forme que `Version20240032`). Ne jamais réutiliser ni renommer un slug.

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
  Controller/      Activities (+ ActivityGearController, ActivityFuelController), Auth, Calendar, Dashboard, Notifications, Nutrition, OAuth (Strava), Race (+ RacePlanController, RaceScenarioController, RaceNutritionController, RaceShareController, RacePublicController), Settings (+ GearController, AvatarController), Stats (+ StatsCompareController), Health (HealthController, HealthSessionController, HealthHistoryController), LocaleController
  Entity/          User, AthleteProfile, Activity, Sport, Meal (+ enum MealType), Race (+ enum RaceIntent), RaceRoute, RaceCheckpoint (+ enum CheckpointType), RaceScenario, RaceScenarioSplit, RaceShare, RaceNutritionPlan, RaceNutritionItem, Gear (+ enum GearType), GearBrand, UserAvatar, ActivityFuel (+ enum FuelKind),
                   HealthExercise, HealthExerciseText, HealthExerciseUserPref, HealthSession, HealthSessionExercise (+ enums HealthCategory, HealthLevel, HealthBodyArea, HealthEquipment, HealthDosageMode)
  Repository/      requêtes d'agrégation (stats, volumes, records…)
  Service/         Strava/StravaService, Weather/WeatherService (Open-Meteo), Units/UnitConverter,
                   Route/ (GpxParser, RouteAnalyzer : D+/D- avec seuil d'hystérésis, tronçons entre repères ;
                          SplitCalculator : temps de passage, miroir JS race_splits_controller.js ; ScenarioViewBuilder ; DurationFormat),
                   Gear/ (GearUsageProvider : usage de tout le matériel en une requête, alertes d'usure du tableau de bord ; GearUsage : total, % et statut d'usure ;
                          StravaGearImporter : import du matériel Strava ; GearBrandMatcher : marque Strava -> gearBrand),
                   User/ (AvatarProcessor : contrôle et retraitement des photos de profil ; StravaAvatarFetcher : photo du profil Strava),
                   Nutrition/ (ActivityFuelCalculator : bilan du ravitaillement, calcul en entiers, miroir JS identique
                          assets/controllers/activity_fuel_calculator.js ; FuelPayloadValidator ; FuelCatalog : apports et limites ;
                          plan nutrition des scénarios : RaceNutritionCalculator (pur, miroir JS race_nutrition_calculator.js),
                          RaceNutritionCatalog, RaceNutritionPayloadValidator, RaceNutritionViewBuilder, RaceNutritionRows, RaceNutritionStore),
                   Share/ (plan partagé : ShareToken, ShareSettingsParser, PublicPlanBuilder : données publiques en liste blanche,
                          PlanCalendar : fichier .ics),
                   Notifications/ (cloche « À faire » : TodoBuilder pur, TodoProvider : requêtes agrégées, TodoPresenter : textes et liens),
                   Stats/ (comparaison de périodes : PeriodComparisonResolver : préréglages et validation ; PeriodComparator :
                          indicateurs et écarts ; ComparisonSeries : cumul et tranches ; ComparisonPresenter / ComparisonFormat : textes fr / en),
                   Health/ (espace Santé : HealthExerciseViewBuilder / HealthExerciseView : exercices visibles dans la langue de
                          l'interface ; HealthLibraryFilter + TextFold : filtres, miroir JS ; HealthExerciseForm : exercices personnels ;
                          VideoUrl ; HealthSessionPayloadValidator + HealthSessionFactory : séance enregistrée ; HealthActivityMapper :
                          sport et activité créée ; HealthLimits : bornes)
  Form/            ProfileType (réglages du profil)
  Twig/            SidebarExtension (stats du jour), ActivityFuelExtension (activity_fuel() : carte Ravitaillement),
                   AvatarExtension (user_avatar_url() : URL de la photo de profil, sans requête),
                   RaceDurationExtension (race_duration, race_duration_input, race_pace, race_clock() : temps du plan de course),
                   HealthExtension (health_session_for_activity(), health_dose(), |health_seconds, |health_duration)
  EventSubscriber/ LocaleSubscriber (langue en session)
assets/
  app.js                   point d'entrée (Bootstrap + controllers Stimulus)
  race_public.js           point d'entrée léger de la page publique /p/{token} (route-preview + race-public)
  controllers/index.js     enregistrement manuel des controllers Stimulus
  styles/app.css           styles de l'application (chargés par base.html.twig)
  lib/fullcalendar/        FullCalendar 6 en fichiers ESM locaux
templates/                 Twig par domaine
translations/              messages.fr.yaml, messages.en.yaml
migrations/                Version20240001 → Version20240032
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
| `RaceRoute` | `raceRoute` (parcours GPX d'une course, 1 par course) |
| `RaceCheckpoint` | `raceCheckpoint` (repères d'une course ; `notes` privées, `publicMessage` affiché sur le plan partagé, migration `Version20240030_CreateRaceShare`) |
| `RaceScenario` | `raceScenario` (scénarios du plan de course : nom, heure de départ, temps visé, ordre, stratégie d'allure `strategy` + `strategyIntensity` ; supprimés avec la course ; migrations `Version20240023_CreateRaceScenarios` et `Version20240029_AddScenarioStrategy`, défauts SQL `even` / `medium`) |
| `RaceScenarioSplit` | `raceScenarioSplit` (temps FIXÉS seulement, en secondes depuis le départ, unique par `scenario_id` + `checkpoint_id`, supprimés avec le scénario ou le repère ; les temps auto ne sont pas stockés ; même migration) |
| `RaceShare` | `raceShare` (lien de partage public du plan, une ligne par course supprimée avec elle ; jeton unique, activé, scénario partagé remis à NULL s'il est supprimé, nom affiché, options, messages du départ, de l'arrivée et général ; migration `Version20240030_CreateRaceShare` ; option `showNutrition`, migration `Version20240031_CreateRaceNutrition`) |
| `RaceNutritionPlan` | `raceNutritionPlan` (plan nutrition d'un scénario, un au plus, supprimé avec lui : cibles, répartition, solide en début de course, flasque, produits auto, produits en JSON ; migration `Version20240031_CreateRaceNutrition`) |
| `RaceNutritionItem` | `raceNutritionItem` (lignes MANUELLES du plan nutrition : un point ou le tronçon qui arrive à un point, apports en JSON ; supprimées avec le plan ou le repère ; les apports auto ne sont pas stockés ; même migration) |
| `GearBrand` | `gearBrand` (marques de chaussures et de vélos, insérées par la migration `Version20240024_CreateGear` ; pour en ajouter, écrire une nouvelle migration d'INSERT) |
| `Gear` | `gear` (matériel d'un utilisateur ; `activities.gear_id` repasse à NULL si le matériel est supprimé ; `stravaGearId` unique par utilisateur pour le matériel importé de Strava, migration `Version20240025_AddGearStravaId`) |
| `UserAvatar` | `userAvatar` (photo de profil, une par utilisateur, supprimée avec lui ; `users.avatarUpdatedAt` donne sa version ; migration `Version20240027_CreateUserAvatar`) |
| `ActivityFuel` | `activityFuel` (ravitaillement d'une activité : une ligne par aliment ou boisson, stockée si quantité > 0 ou apport personnalisé, unique par `activity_id` + `itemKey`, supprimée avec l'activité ; migration `Version20240026_CreateActivityFuel`) |
| `WeeklyGoal` | `weeklyGoal` (objectif hebdomadaire par sport : distance, séances, durée en minutes, D+, ordre ; unique par `user_id` + `sport_id`, supprimé avec l'utilisateur ou le sport ; migration `Version20240028_CreateWeeklyGoals`) |
| `HealthExercise` | `healthExercise` (exercices de l'espace Santé : fournis, `user_id` NULL et `slug` unique, insérés par la migration `Version20240032_CreateHealth` ; ou personnels, supprimés avec l'utilisateur ; catégorie, niveau, zones et matériel en JSON, « chaque côté », dosage par défaut, lien vidéo d'un exercice personnel) |
| `HealthExerciseText` | `healthExerciseText` (textes d'un exercice, une ligne par langue, unique par `exercise_id` + `locale` : `fr` et `en` pour un exercice fourni, une seule langue pour un exercice personnel ; étapes et erreurs en JSON ; même migration) |
| `HealthExerciseUserPref` | `healthExerciseUserPref` (lien vidéo personnel et masquage d'un exercice fourni, unique par `user_id` + `exercise_id`, supprimé avec l'un ou l'autre ; même migration) |
| `HealthSession` | `healthSession` (séance faite : nom, début en UTC et fuseau, durées, RPE, notes ; `activity_id` unique, remis à NULL si l'activité est supprimée ; même migration) |
| `HealthSessionExercise` | `healthSessionExercise` (exercices d'une séance : copie du nom, de la catégorie et du mode, dosage prévu, valeurs faites par série en JSON ; `exercise_id` remis à NULL si l'exercice personnel est supprimé ; même migration) |

**Controllers Stimulus**

Ils sont enregistrés à la main dans `assets/controllers/index.js`. `assets/controllers.json` est vide.

| `data-controller` | Utilisé dans |
|---|---|
| `weekly-chart`, `weather` | tableau de bord |
| `map`, `elevation-chart`, `pace-chart`, `heartrate-chart`, `activity-fuel` | détail d'une activité (`activity-fuel` : carte Ravitaillement, enregistrement automatique) |
| `volume-chart`, `donut-chart`, `pace-trend` | statistiques |
| `stats-compare` | statistiques, vue Comparer (cumul, barres par tranche et par sport, sélecteur d'indicateur, tableau de données) |
| `energy-donut`, `balance-chart` | nutrition |
| `race-calendar` | courses (vue calendrier, FullCalendar chargé à la demande par `import()`) |
| `race-route` | plan de course (carte Leaflet, profil Chart.js, repères cliquables) |
| `race-splits` | plan de course, section « Temps de passage » (onglets des scénarios, recalcul en direct, miroir de `SplitCalculator`) |
| `race-nutrition` | plan de course, section « Nutrition » (suit l'onglet du scénario, steppers, recalcul en direct avec `race_nutrition_calculator.js`, enregistrement automatique) |
| `training-calendar` | calendrier général (FullCalendar chargé à la demande, vue rapide en modale) |
| `weekly-goals` | réglages, onglet Profil (lignes des objectifs par sport : ajout depuis le menu, retrait) |
| `race-share` | réglages du partage du plan (copie du lien, compteurs, heures selon le scénario, confirmations) |
| `notifications` | cloche « À faire » de la barre du haut, sur toutes les pages connectées (chargement après la page, masquer, tout marquer comme lu) |
| `race-public` | page publique `/p/{token}` (prochain passage prévu le jour de la course) ; enregistré dans `assets/race_public.js`, pas dans `index.js`, avec `route-preview` |
| `health-library` | espace Santé, bibliothèque (filtres et recherche en direct, miroir de `HealthLibraryFilter`) |
| `health-tray-add` | pages de l'espace Santé (boutons « Ajouter », nombre d'exercices du plateau, barre « Séance en cours ») |
| `health-session` | espace Santé, séance guidée (plateau, lecteur avec minuteur, fin de séance et enregistrement) |

`weather_activity_controller.js` existe mais n'est ni enregistré ni utilisé. `weather_constants.js` est un module partagé, pas un controller, comme `activity_fuel_calculator.js` (miroir JS d'`ActivityFuelCalculator`) et `race_nutrition_calculator.js` (miroir JS exact de `RaceNutritionCalculator`, calcul en entiers). `health_tray_store.js` (plateau de la séance en `localStorage`) et `health_session_logic.js` (étapes de la séance guidée, valeurs faites, règle du sport) sont aussi des modules partagés.

---

## Démarrage local

### Avec Docker Compose

Services définis dans `docker-compose.yml` :

| Service | Image | Port hôte |
|---|---|---|
| `php` | `docker/php/Dockerfile` (PHP 8.3-FPM Alpine) | - |
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

L'import GPX du plan de course accepte 15 Mo, mais le PHP de WAMP limite souvent les envois à `upload_max_filesize = 2M` (Docker : 20M dans `docker/php/php.ini`) : au-delà, la page affiche « fichier trop volumineux ».

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
| `STRAVA_CLIENT_SECRET` | Secret de l'application Strava | - | oui |

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

Les tests sont unitaires : `tests/Unit/Service/UnitConverterTest.php`, `tests/Unit/Entity/`, `tests/Unit/Service/Route/` (GPX, fixtures dans `tests/fixtures/gpx/`) et `tests/Unit/Service/Nutrition/` (calcul et validation du ravitaillement). `tests/Functional/` est vide (il contient seulement `.gitkeep`, pour que la suite déclarée dans `phpunit.xml.dist` existe en CI).

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
- **Cloche « À faire »** : ni e-mail ni notification push ; les éléments masqués sont gardés dans le navigateur, pas en base (un autre navigateur les réaffiche). Les autorisations Strava accordées n'étant pas enregistrées, un compte connecté avant l'ajout de `profile:read_all` n'est pas signalé comme « à reconnecter ».
- **Estimations** : plusieurs chiffres sont des approximations simples, pas des mesures :
  - charge (« TSS ») déduite de la durée sur la liste des activités (60 par heure) ; TRIMP de Banister (FC moyenne, FC repos et FC max du profil) dans la barre latérale ;
  - calories : 50 kcal/km sur la liste des activités, 8 kcal/min sur la page nutrition ;
  - zones FC déduites du rapport FC moyenne / FC max ;
  - métabolisme de base : poids × 22.
- **Réglages** : seul l'onglet Profil fonctionne.
- **Front** : `weather_activity_controller.js` est du code mort.
- **Plan de course** : phases 1 (parcours GPX et repères) et 2 (scénarios, temps de passage, fiche imprimable) faites, ainsi que le plan nutrition par scénario (il suit les temps de passage enregistrés : enregistrer le scénario pour mettre à jour les durées). Restent la phase 3 (modèle d'allure automatique tiré de l'historique de l'athlète ; la répartition actuelle est la règle fixe km + D+ / 100, modulée par la stratégie d'allure choisie) et la phase 4 (comparaison après course avec l'activité réalisée).
- **Espace Santé** : le plateau de la séance en cours est gardé dans le navigateur (pas partagé entre appareils) ; pas de programmes tout faits ; aucune vidéo fournie (liens personnels seulement) ; l'activité créée n'a ni FC ni calories mesurées. Une séance aussi enregistrée sur Strava compterait deux fois si la case « Ajouter à mes activités » reste cochée.
- **Tests** : couverture minimale (tests unitaires seulement, aucun test fonctionnel).
- **Divers** :
  - le `name` et la `description` de `composer.json` décrivent encore l'ancien boilerplate (« Symfony 7 + Vue.js 3 ») ;
  - le Dockerfile installe Node.js, npm et l'extension Redis, mais aucun service Redis n'existe et le projet n'utilise pas npm ;
  - `.env.example` propose `APP_URL=http://localhost:8000` alors que nginx écoute sur 8080.
