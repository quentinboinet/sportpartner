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
| `/stats` | Statistiques | Périodes : 7 jours, mois, saison (année en cours, par défaut), tout, ou plage personnalisée. Volume par sport, répartition par sport (donut), évolution de l'allure en course à pied sur 12 semaines, zones FC estimées, records personnels (5 km, 10 km et semi-marathon en course à pied, plus longue sortie vélo, plus gros dénivelé en trail ou randonnée). |
| `/courses` | Courses | Courses de l'utilisateur (nom, type route ou trail, lieu, distance, dénivelé, année ou date précise, site web, notes), filtrables par statut (`want_to_do` « Je veux la faire », `registered` « Je suis inscrit », `bookmark` « Mémo »), par type (`?type=road|trail` ; les courses antérieures au champ n'ont pas de type) et par année. Deux vues : liste ou calendrier mensuel FullCalendar (`?view=calendar`, les courses sans date sont listées à part). Ajout et modification dans une modale, changement d'intention, marquer comme faite, suppression (`POST /courses`, `/courses/{id}/edit`, `/courses/{id}/intent`, `/courses/{id}/toggle`, `/courses/{id}/delete`). |
| `/courses/{id}/plan` | Plan de course | Import d'un parcours GPX (15 Mo et 200 000 points max, DOCTYPE/entités refusés) : tracé simplifié (~5 000 points max), distance, D+/D-, altitude min/max ; complète distance et D+ de la course s'ils étaient vides. Carte Leaflet et profil Chart.js. Repères (point ou ravito avec temps d'arrêt, barrière horaire au format `H:MM` pouvant dépasser 24 h, notes) placés au km ou par clic sur le profil, et tableau des tronçons entre repères (distance, D+, D-, pente moyenne). Routes `POST /courses/{id}/route` (champ `gpx`), `/courses/{id}/route/delete`, `/courses/{id}/checkpoints`, `/courses/{id}/checkpoints/{checkpointId}/edit` et `/delete` (`RacePlanController`, CSRF `race_plan`). Sous les repères, section « Temps de passage » : scénarios, temps de passage et fiche imprimable (voir « Plan de course : scénarios et temps de passage »). |
| `/courses/{id}/partage` | Partager le plan | Réglages du lien public envoyé aux proches (propriétaire seulement) : activer / désactiver, copier le lien, aperçu, régénérer, scénario partagé, nom affiché, options (allures, barrières) et messages pour les proches (général, départ, chaque repère, arrivée). Voir « Partage du plan de course ». |
| `/p/{token}` | Plan partagé (public) | Page sans compte pour les proches : horaires prévus du scénario partagé, frise des passages avec les messages, carte et profil, prochain passage estimé le jour de la course, bouton « Ajouter à mon agenda » (`/p/{token}/calendar.ics`). Voir « Partage du plan de course ». |
| `/nutrition` | Nutrition | Repas du jour, objectifs caloriques et macros calculés à partir du poids du profil (70 kg par défaut), dépense estimée (métabolisme de base et sport), graphique apports / dépense de la semaine. **Lecture seule : il n'existe aucun formulaire pour saisir un repas.** |
| `/calendar` | Calendrier | Calendrier FullCalendar (mois, semaine, année) des activités réalisées, avec en option les courses datées (`?races=1`). Les événements sont chargés par période via `GET /calendar/events?start=&end=&races=0\|1` (JSON, 400 jours max) ; les heures Strava (UTC) sont converties dans le fuseau de l'activité. Clic = vue rapide en modale (activité : stats + mini-carte ; course : statut, type, compte à rebours, notes, liens vers le plan de course). Panneau latéral : bilan de la période affichée et 5 prochaines courses. `?date=` et `?races=` sont tenus à jour dans l'URL. |
| `/settings` | Réglages | Onglet « Profil » fonctionnel : pseudo, âge, poids, FC repos, FC max, VMA, FTP et objectifs hebdomadaires (tous sports confondus et par sport) ; photo de profil (voir « Photo de profil »). Onglet « Matériel » (`?tab=gear`, alias `?tab=materiel`) : chaussures (course, trail, marche, randonnée) et vélos, marque choisie dans la table de référence `gearBrand`, modèle, surnom, date d'achat, km déjà parcourus, durée de vie (700 km proposés pour des chaussures, vide = usure non suivie), matériel par défaut (un par type). Chaque carte affiche le total (km avant suivi + activités rattachées), le nombre d'activités, la dernière sortie et l'usure (bon état < 80 %, à surveiller, à remplacer >= 100 %). Actions : modifier (le type est figé), mettre à la retraite / réactiver, supprimer (les activités sont conservées, sans matériel), rattacher le matériel par défaut aux activités sans matériel des sports de son type (depuis la date d'achat). Bloc « Importer depuis Strava » si le compte est connecté (sinon lien de connexion), badge « Strava » sur le matériel importé : voir « Import du matériel Strava ». Routes `POST /settings/gear`, `/settings/gear/{id}/edit`, `/retire`, `/delete`, `/assign-defaults`, `/settings/gear/import-strava` (`GearController`, CSRF `gear`). Les onglets Préférences, Zones FC, Appareils, Apparence et Compte affichent « bientôt disponible ». |
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

### Partage du plan de course

Lien public à envoyer aux proches pour savoir quand et où encourager. Bouton « Partager » sur la page de la course et sur le plan de course.

- **Réglages** (`/courses/{id}/partage`, `RaceShareController`, propriétaire seulement : 403 sinon, CSRF `race_share`) : `POST /courses/{id}/partage/toggle` (champ `enabled` : `1` active, sinon désactive ; le lien répond alors 404, et le réactiver redonne le même lien), `/courses/{id}/partage/regenerate` (nouveau jeton après confirmation : l'ancien lien répond 404), `POST /courses/{id}/partage` (enregistrement). Le partage (table `raceShare`, une ligne par course) n'est créé qu'à la première action, jamais à l'affichage. Réglages : scénario partagé (s'il est supprimé, le premier scénario par position prend le relais), nom affiché (prénom du profil proposé, jamais l'e-mail ; vide = aucun nom), afficher les allures prévues (non par défaut) et les barrières horaires (oui par défaut), message général (1000 caractères), message du départ, de chaque repère (`raceCheckpoint.publicMessage`, distinct des notes privées ; aussi modifiable dans la fenêtre d'édition du repère sur le plan, champ `public_message`, mêmes règles, champ absent = inchangé) et de l'arrivée (500 caractères chacun). Les longueurs sont limitées dans le navigateur (`maxlength` + compteur) et revérifiées par le serveur (`ShareSettingsParser`, sauts de ligne comptés pour 1) ; une saisie invalide est réaffichée avec ses erreurs (422) et rien n'est écrit. L'heure prévue de chaque point suit le scénario choisi dans la liste. Une icône signale sur le plan de course les repères qui ont un message public.
- **Page publique** (`GET /p/{token}`, `RacePublicController`) : page autonome mobile d'abord (ni menu ni lien vers l'application), entrée JS légère `race_public` (`assets/race_public.js` : Stimulus `route-preview` pour la carte et le profil, `race-public` pour le prochain passage). Langue : `?lang=fr|en`, sinon la meilleure de fr / en selon `Accept-Language`, sinon fr, avec un sélecteur FR / EN (jamais la session). Contenu : nom, date et lieu de la course, nom affiché, heure de départ, arrivée prévue, distance, D+, message général, frise départ → repères → arrivée (km, ravito, heure prévue avec J+1, allure et barrière selon les options, message de chaque point), carte et profil si un GPX existe. Sans scénario ou sans temps visé : « Horaires pas encore définis », la course, le parcours et les messages restent affichés. Le jour de la course, le prochain passage prévu est mis en avant d'après l'horloge de l'appareil (« Prochain passage estimé : Ravito 10 vers 09:48 »), avec la mention que ce ne sont pas des temps en direct.
- **Agenda** : `GET /p/{token}/calendar.ics` (`PlanCalendar`) : un événement iCalendar (RFC 5545 : CRLF, lignes repliées à 75 octets, textes échappés, UID, DTSTAMP) du départ à l'arrivée prévue, en heures locales « flottantes » (la course n'a pas de fuseau), passages prévus dans la description ; sans heure de départ, événement sur la journée.
- **Confidentialité et sécurité** : `PublicPlanBuilder` ne copie qu'une liste blanche de valeurs (jamais l'e-mail ou l'identifiant de l'utilisateur, les notes privées, les autres scénarios, le nom ou la stratégie du scénario, le lien ou le nom du fichier GPX, le site, les données Strava). Jeton : 24 octets de `random_bytes` en base64url (32 caractères), index unique, comparaison exacte ; jeton mal formé (sans requête), inconnu, régénéré ou partage désactivé : la même 404. En-têtes `X-Robots-Tag: noindex, nofollow` (et la balise meta), `Referrer-Policy: strict-origin-when-cross-origin` (les tuiles OpenStreetMap exigent un Referer, le jeton du chemin n'en sort pas), `Cache-Control: no-store, private`. Tous les textes sont échappés (Twig, `textContent` en JS). La page n'ouvre aucune session (ni CSRF, ni message flash, ni `app.user`) et fonctionne sans cookie.
- Migration `Version20240030_CreateRaceShare` (table `raceShare` et colonne `raceCheckpoint.publicMessage`).

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
  Controller/      Activities (+ ActivityGearController, ActivityFuelController), Auth, Calendar, Dashboard, Nutrition, OAuth (Strava), Race (+ RacePlanController, RaceScenarioController, RaceShareController, RacePublicController), Settings (+ GearController, AvatarController), Stats, LocaleController
  Entity/          User, AthleteProfile, Activity, Sport, Meal (+ enum MealType), Race (+ enum RaceIntent), RaceRoute, RaceCheckpoint (+ enum CheckpointType), RaceScenario, RaceScenarioSplit, RaceShare, Gear (+ enum GearType), GearBrand, UserAvatar, ActivityFuel (+ enum FuelKind)
  Repository/      requêtes d'agrégation (stats, volumes, records…)
  Service/         Strava/StravaService, Weather/WeatherService (Open-Meteo), Units/UnitConverter,
                   Route/ (GpxParser, RouteAnalyzer : D+/D- avec seuil d'hystérésis, tronçons entre repères ;
                          SplitCalculator : temps de passage, miroir JS race_splits_controller.js ; ScenarioViewBuilder ; DurationFormat),
                   Gear/ (GearUsageProvider : usage de tout le matériel en une requête, alertes d'usure du tableau de bord ; GearUsage : total, % et statut d'usure ;
                          StravaGearImporter : import du matériel Strava ; GearBrandMatcher : marque Strava -> gearBrand),
                   User/ (AvatarProcessor : contrôle et retraitement des photos de profil ; StravaAvatarFetcher : photo du profil Strava),
                   Nutrition/ (ActivityFuelCalculator : bilan du ravitaillement, calcul en entiers, miroir JS identique
                          assets/controllers/activity_fuel_calculator.js ; FuelPayloadValidator ; FuelCatalog : apports et limites),
                   Share/ (plan partagé : ShareToken, ShareSettingsParser, PublicPlanBuilder : données publiques en liste blanche,
                          PlanCalendar : fichier .ics)
  Form/            ProfileType (réglages du profil)
  Twig/            SidebarExtension (stats du jour), ActivityFuelExtension (activity_fuel() : carte Ravitaillement),
                   AvatarExtension (user_avatar_url() : URL de la photo de profil, sans requête),
                   RaceDurationExtension (race_duration, race_duration_input, race_pace, race_clock() : temps du plan de course)
  EventSubscriber/ LocaleSubscriber (langue en session)
assets/
  app.js                   point d'entrée (Bootstrap + controllers Stimulus)
  race_public.js           point d'entrée léger de la page publique /p/{token} (route-preview + race-public)
  controllers/index.js     enregistrement manuel des controllers Stimulus
  styles/app.css           styles de l'application (chargés par base.html.twig)
  lib/fullcalendar/        FullCalendar 6 en fichiers ESM locaux
templates/                 Twig par domaine
translations/              messages.fr.yaml, messages.en.yaml
migrations/                Version20240001 → Version20240030
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
| `RaceShare` | `raceShare` (lien de partage public du plan, une ligne par course supprimée avec elle ; jeton unique, activé, scénario partagé remis à NULL s'il est supprimé, nom affiché, options, messages du départ, de l'arrivée et général ; migration `Version20240030_CreateRaceShare`) |
| `GearBrand` | `gearBrand` (marques de chaussures et de vélos, insérées par la migration `Version20240024_CreateGear` ; pour en ajouter, écrire une nouvelle migration d'INSERT) |
| `Gear` | `gear` (matériel d'un utilisateur ; `activities.gear_id` repasse à NULL si le matériel est supprimé ; `stravaGearId` unique par utilisateur pour le matériel importé de Strava, migration `Version20240025_AddGearStravaId`) |
| `UserAvatar` | `userAvatar` (photo de profil, une par utilisateur, supprimée avec lui ; `users.avatarUpdatedAt` donne sa version ; migration `Version20240027_CreateUserAvatar`) |
| `ActivityFuel` | `activityFuel` (ravitaillement d'une activité : une ligne par aliment ou boisson, stockée si quantité > 0 ou apport personnalisé, unique par `activity_id` + `itemKey`, supprimée avec l'activité ; migration `Version20240026_CreateActivityFuel`) |
| `WeeklyGoal` | `weeklyGoal` (objectif hebdomadaire par sport : distance, séances, durée en minutes, D+, ordre ; unique par `user_id` + `sport_id`, supprimé avec l'utilisateur ou le sport ; migration `Version20240028_CreateWeeklyGoals`) |

**Controllers Stimulus**

Ils sont enregistrés à la main dans `assets/controllers/index.js`. `assets/controllers.json` est vide.

| `data-controller` | Utilisé dans |
|---|---|
| `weekly-chart`, `weather` | tableau de bord |
| `map`, `elevation-chart`, `pace-chart`, `heartrate-chart`, `activity-fuel` | détail d'une activité (`activity-fuel` : carte Ravitaillement, enregistrement automatique) |
| `volume-chart`, `donut-chart`, `pace-trend` | statistiques |
| `energy-donut`, `balance-chart` | nutrition |
| `race-calendar` | courses (vue calendrier, FullCalendar chargé à la demande par `import()`) |
| `race-route` | plan de course (carte Leaflet, profil Chart.js, repères cliquables) |
| `race-splits` | plan de course, section « Temps de passage » (onglets des scénarios, recalcul en direct, miroir de `SplitCalculator`) |
| `training-calendar` | calendrier général (FullCalendar chargé à la demande, vue rapide en modale) |
| `weekly-goals` | réglages, onglet Profil (lignes des objectifs par sport : ajout depuis le menu, retrait) |
| `race-share` | réglages du partage du plan (copie du lien, compteurs, heures selon le scénario, confirmations) |
| `race-public` | page publique `/p/{token}` (prochain passage prévu le jour de la course) ; enregistré dans `assets/race_public.js`, pas dans `index.js`, avec `route-preview` |

`weather_activity_controller.js` existe mais n'est ni enregistré ni utilisé. `weather_constants.js` est un module partagé, pas un controller, comme `activity_fuel_calculator.js` (miroir JS d'`ActivityFuelCalculator`).

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
- **Estimations** : plusieurs chiffres sont des approximations simples, pas des mesures :
  - charge (« TSS ») déduite de la durée sur la liste des activités (60 par heure) ; TRIMP de Banister (FC moyenne, FC repos et FC max du profil) dans la barre latérale ;
  - calories : 50 kcal/km sur la liste des activités, 8 kcal/min sur la page nutrition ;
  - zones FC déduites du rapport FC moyenne / FC max ;
  - métabolisme de base : poids × 22.
- **Réglages** : seul l'onglet Profil fonctionne.
- **Front** : `weather_activity_controller.js` est du code mort.
- **Plan de course** : phases 1 (parcours GPX et repères) et 2 (scénarios, temps de passage, fiche imprimable) faites. Restent la phase 3 (modèle d'allure automatique tiré de l'historique de l'athlète ; la répartition actuelle est la règle fixe km + D+ / 100, modulée par la stratégie d'allure choisie) et la phase 4 (comparaison après course avec l'activité réalisée).
- **Tests** : couverture minimale (tests unitaires seulement, aucun test fonctionnel).
- **Divers** :
  - le `name` et la `description` de `composer.json` décrivent encore l'ancien boilerplate (« Symfony 7 + Vue.js 3 ») ;
  - le Dockerfile installe Node.js, npm et l'extension Redis, mais aucun service Redis n'existe et le projet n'utilise pas npm ;
  - `.env.example` propose `APP_URL=http://localhost:8000` alors que nginx écoute sur 8080.
