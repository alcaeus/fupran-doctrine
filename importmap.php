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
    '@hotwired/stimulus' => [
        'version' => '3.2.2',
    ],
    '@symfony/stimulus-bundle' => [
        'path' => './vendor/symfony/stimulus-bundle/assets/dist/loader.js',
    ],
    '@hotwired/turbo' => [
        'version' => '7.3.0',
    ],
    'bootstrap' => [
        'version' => '5.3.3',
    ],
    '@popperjs/core' => [
        'version' => '2.11.8',
    ],
    'bootstrap/dist/css/bootstrap.min.css' => [
        'version' => '5.3.3',
        'type' => 'css',
    ],
    'leaflet' => [
        'version' => '1.9.4',
    ],
    'leaflet/dist/leaflet.min.css' => [
        'version' => '1.9.4',
        'type' => 'css',
    ],
    '@symfony/ux-leaflet-map' => [
        'path' => './vendor/symfony/ux-leaflet-map/assets/dist/map_controller.js',
    ],
    '@mongodb-js/charts-embed-dom' => [
        'version' => '3.3.1',
    ],
    '@looker/chatty' => [
        'version' => '2.3.9',
    ],
    'bson' => [
        'version' => '5.5.1',
    ],
    'lodash' => [
        'path' => './vendor/lodash-es/lodash-es.index.js',
    ],
    'debug' => [
        'version' => '2.6.9',
    ],
    'es6-promise/auto' => [
        'version' => '4.2.8',
    ],
    'ms' => [
        'version' => '2.0.0',
    ],
    'lodash-es' => [
        'version' => '4.17.21',
    ],
];
