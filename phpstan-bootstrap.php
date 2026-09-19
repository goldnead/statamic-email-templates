<?php

/**
 * Macht die View-Pruefung von Larastan ueberhaupt erst moeglich.
 *
 * Larastan pruegt `View::make('email-templates::branded', …)` gegen den echten
 * View-Finder: `ViewStringType` ruft `view()->exists($literal)`. In einer
 * Anwendung kennt der Finder den Namensraum, weil der ServiceProvider des
 * Addons ihn per `loadViewsFrom()` anmeldet. Bei der Analyse dieses Pakets fuer
 * sich laeuft kein ServiceProvider — der Finder hat keinen Hinweis auf
 * `email-templates::`, `exists()` sagt nein, und der Literal gilt als blosser
 * `string`.
 *
 * Das ist kein Fund am Code, sondern eine Luecke im Aufbau der Analyse. Hier
 * bekommt der Finder denselben Hinweis, den der ServiceProvider ihm zur Laufzeit
 * gibt. Ein Tippfehler im View-Namen faellt damit weiterhin auf — im Gegenteil,
 * erst jetzt faellt er auf.
 *
 * Aufgefallen am 19.09.2026: die CI installiert mit `composer update` ein
 * neueres Larastan als das Lockfile, und dieses neuere prueft `view-string`.
 * Lokal war es gruen, in der CI rot.
 */

use Illuminate\Contracts\View\Factory as ViewFactory;
use Larastan\Larastan\ApplicationResolver;

$app = ApplicationResolver::resolve();

/** @var ViewFactory $views */
$views = $app->make(ViewFactory::class);

$views->getFinder()->addNamespace('email-templates', __DIR__.'/resources/views');
