/*
 * Entrée JS de la page publique d'un plan de course partagé (/p/{token}, templates/race/public.html.twig), chargée par
 * {{ importmap('race_public') }} à la place de "app" : volontairement légère pour les proches sur mobile (ni Bootstrap
 * ni les autres controllers de l'application). Les deux controllers sont enregistrés ici, pas dans controllers/index.js.
 */
import { application } from './controllers/application.js';
import RoutePreviewController from './controllers/route_preview_controller.js';
import RacePublicController from './controllers/race_public_controller.js';

application.register('route-preview', RoutePreviewController);
application.register('race-public', RacePublicController);
