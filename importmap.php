<?php

/**
 * Returns the importmap for this application.
 *
 * - "path" is a path inside the asset mapper system. Use the
 *     "debug:asset-map" command to see the full list of paths.
 *
 * - "entrypoint" (JavaScript only) set to true for any module that will
 *     be used as an "entrypoint" (and passed to the importmap() Twig function).
 *
 * The "importmap:require" command can be used to add new entries to this file.
 */
return [
    'app' => [
        'path' => './assets/app.js',
        'entrypoint' => true,
    ],
    'bootstrap' => [
        'version' => '5.3.8',
    ],
    'chart.js' => [
        'version' => '4.5.1',
    ],
    '@popperjs/core' => [
        'version' => '2.11.8',
    ],
    '@kurkle/color' => [
        'version' => '0.3.4',
    ],
    'bootstrap/dist/css/bootstrap.min.css' => [
        'version' => '5.3.8',
        'type' => 'css',
    ],
    '@hotwired/stimulus' => [
        'version' => '3.2.2',
    ],
    'leaflet' => [
        'version' => '1.9.4',
    ],
    'leaflet/dist/leaflet.min.css' => [
        'version' => '1.9.4',
        'type' => 'css',
    ],
    // FullCalendar 6.1.22 — fichiers ESM bruts du paquet npm, servis en local.
    // Ne PAS passer par `importmap:require @fullcalendar/*` : le bundling +esm de jsDelivr
    // duplique les internes (core/index.js et core/internal.js) et casse les plugins.
    // Pour mettre à jour : retélécharger les mêmes fichiers depuis cdn.jsdelivr.net/npm/@fullcalendar/<pkg>@<version>/
    // (preact doit rester à la version exigée par @fullcalendar/core, ~10.12.1).
    '@fullcalendar/core' => [
        'path' => './assets/lib/fullcalendar/core/index.js',
    ],
    '@fullcalendar/core/index.js' => [
        'path' => './assets/lib/fullcalendar/core/index.js',
    ],
    '@fullcalendar/core/internal.js' => [
        'path' => './assets/lib/fullcalendar/core/internal.js',
    ],
    '@fullcalendar/core/preact.js' => [
        'path' => './assets/lib/fullcalendar/core/preact.js',
    ],
    '@fullcalendar/core/locales/fr' => [
        'path' => './assets/lib/fullcalendar/core/locales/fr.js',
    ],
    '@fullcalendar/daygrid' => [
        'path' => './assets/lib/fullcalendar/daygrid/index.js',
    ],
    'preact' => [
        'version' => '10.12.1',
    ],
    'preact/compat' => [
        'version' => '10.12.1',
    ],
    'preact/hooks' => [
        'version' => '10.12.1',
    ],
];
