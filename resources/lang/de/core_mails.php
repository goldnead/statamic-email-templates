<?php

/*
 * Die Konto-Mails von Statamic und Laravel als Vorlagen.
 *
 * `title`, `trigger` und die Platzhalter erscheinen im Control Panel.
 * `subject`, `preview` und `body` sind die mitgelieferten Vorlagen, die
 * `php please email-templates:import --source=Statamic --locale=de` anlegt.
 * Schlüsselgleich mit resources/lang/en/core_mails.php.
 */

return [

    'password_reset' => [
        'title' => 'Passwort zurücksetzen (Website)',
        'trigger' => 'Passwort vergessen auf der Website',
        'subject' => 'Neues Passwort für {{ site_name }}',
        'preview' => 'Mit diesem Link legst du ein neues Passwort fest.',
        'body' => '<p>Hallo {{ user.name }},</p>'
            .'<p>für dein Konto bei {{ site_name }} wurde ein neues Passwort angefordert. Über diesen Link legst du es fest:</p>'
            .'<p><a href="{{ url }}">Neues Passwort festlegen</a></p>'
            .'<p>Der Link gilt {{ expires_in }}. Hast du kein neues Passwort angefordert, musst du nichts tun. Dein bisheriges Passwort bleibt dann gültig.</p>',
    ],

    'password_reset_cp' => [
        'title' => 'Passwort zurücksetzen (Control Panel)',
        'trigger' => 'Passwort vergessen im Control Panel',
        'subject' => 'Neues Passwort für das Control Panel von {{ site_name }}',
        'preview' => 'Mit diesem Link legst du ein neues Passwort fest.',
        'body' => '<p>Hallo {{ user.name }},</p>'
            .'<p>für deinen Zugang zum Control Panel von {{ site_name }} wurde ein neues Passwort angefordert. Über diesen Link legst du es fest:</p>'
            .'<p><a href="{{ url }}">Neues Passwort festlegen</a></p>'
            .'<p>Der Link gilt {{ expires_in }}. Hast du kein neues Passwort angefordert, musst du nichts tun.</p>',
    ],

    'activate_account' => [
        'title' => 'Konto aktivieren (Einladung)',
        'trigger' => 'Neues Konto angelegt, Einladung zum Aktivieren',
        'subject' => 'Dein Konto bei {{ site_name }}',
        'preview' => 'Leg dein Passwort fest, dann ist dein Konto bereit.',
        'body' => '<p>Hallo {{ user.name }},</p>'
            .'<p>für dich wurde ein Konto bei {{ site_name }} angelegt. Leg über diesen Link dein Passwort fest, dann kannst du dich anmelden:</p>'
            .'<p><a href="{{ url }}">Konto aktivieren</a></p>'
            .'<p>{{ message }}</p>'
            .'<p>Der Link gilt {{ expires_in }}.</p>',
    ],

    'verification_code' => [
        'title' => 'Bestätigungscode',
        'trigger' => 'Erneute Bestätigung ohne Zwei-Faktor-App',
        'subject' => 'Dein Bestätigungscode für {{ site_name }}',
        'preview' => 'Kopiere den Code in das Fenster, in dem du ihn angefordert hast.',
        'body' => '<p>Hallo {{ user.name }},</p>'
            .'<p>hier ist dein Code. Kopiere ihn in das Fenster, in dem du ihn angefordert hast:</p>'
            .'<p><strong>{{ code }}</strong></p>'
            .'<p>Hast du keinen Code angefordert, kannst du diese Mail ignorieren.</p>',
    ],

    'verify_email' => [
        'title' => 'E-Mail-Adresse bestätigen',
        'trigger' => 'Neues Konto bestätigt seine Adresse (Laravel)',
        'subject' => 'Bestätige deine E-Mail-Adresse für {{ site_name }}',
        'preview' => 'Ein Klick, dann ist dein Konto freigeschaltet.',
        'body' => '<p>Hallo {{ user.name }},</p>'
            .'<p>bitte bestätige, dass diese Adresse dir gehört:</p>'
            .'<p><a href="{{ url }}">E-Mail-Adresse bestätigen</a></p>'
            .'<p>Der Link gilt {{ expires_in }}. Hast du kein Konto angelegt, musst du nichts tun.</p>',
    ],

    'placeholders' => [
        'url_password-reset' => 'Link zum Formular für das neue Passwort',
        'url_activate' => 'Link zum Aktivieren des Kontos',
        'url_verify' => 'Link, der die Adresse bestätigt',
        'user_name' => 'Name der Person; ohne Namen ihre E-Mail-Adresse',
        'user_email' => 'E-Mail-Adresse der Person',
        'site_name' => 'Name der Seite (app.name)',
        'expires_in' => 'Wie lange der Link gilt, z. B. „1 Stunde“',
        'expires_minutes' => 'Wie lange der Link gilt, in Minuten',
        'message' => 'Nachricht, die im Control Panel beim Einladen geschrieben wurde (sonst leer)',
        'code' => 'Der Bestätigungscode',
    ],

];
