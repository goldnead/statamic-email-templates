<?php

/*
 * Die Einstellungsseite unter Control Panel → Einstellungen.
 *
 * Schlüsselgleich mit resources/lang/en/settings.php. Die Feldschlüssel sind
 * der Config-Pfad mit ersetzten Punkten (`test_send.subject_prefix` →
 * `test_send_subject_prefix`), weil ein Punkt im Sprachschlüssel für den
 * Übersetzer ein Pfadtrenner ist.
 */

return [

    'groups' => [

        'layout' => [
            'title' => 'Layout',
            'description' => 'Welche Blade-Hülle eine E-Mail umschließt. Wirkt auf jeden Versand und auf die Live-Vorschau, weil beide denselben Render-Weg nehmen. Die Zuordnung von Layout-Handle zu Blade-View steht weiterhin unter „layouts" in config/email-templates.php: sie ist eine Tabelle, kein Wert, und ein hier gelöschter Handle würde jede Vorlage stranden lassen, die ihn gewählt hat. Der Hauptschalter „enabled" steht aus einem anderen Grund nur in der Config: er wird beim Booten gelesen, ein Schalter hier würde erst beim nächsten Deploy wirken.',
        ],

        'snapshots' => [
            'title' => 'Versand-Snapshots',
            'description' => 'Was beim Versand festgehalten wird, damit Kampagnen, Benachrichtigungen und Automations-Läufe später zeigen können, welche Mail rausging. Gespeichert wird die Vorlage mit ihren Platzhaltern, nicht die fertige Mail eines Empfängers. Die Platzhalterwerte der Vorschau stehen unter „preview.sample_data" in config/email-templates.php; sie folgen den Variablen, die die Vorlagen dieser Installation benutzen.',
        ],

        'test_send' => [
            'title' => 'Testmail',
            'description' => 'Die Testmail, die im Control Panel neben „Speichern" liegt. Sie geht denselben Weg wie ein echter Versand, nur an eine selbst eingetippte Adresse.',
        ],

        'countdown' => [
            'title' => 'Countdown',
            'description' => 'Der Bild-Countdown, der die Restzeit als PNG ausliefert. Der Text-Countdown braucht davon nichts und läuft immer.',
        ],

    ],

    'fields' => [

        'branded_layout' => [
            'label' => 'Standard-Hülle',
            'description' => 'Der Name eines Blade-Layouts der Anwendung, das jede Mail umschließt, wenn weder die Vorlage noch das Standard-Layout etwas anderes sagt. Leer heißt: die Mail geht ohne Hülle raus, also nur mit dem Text, den die Vorlage selbst enthält.',
        ],

        'default_layout' => [
            'label' => 'Standard-Layout',
            'description' => 'Ein Handle aus der `layouts`-Tabelle in der Config, das jede Vorlage bekommt, die selbst kein Layout gewählt hat. Leer heißt: solche Vorlagen fallen auf die Standard-Hülle darüber zurück.',
        ],

        'snapshots_enabled' => [
            'label' => 'Versendetes festhalten',
            'description' => 'Ausgeschaltet wird beim Versand nichts mehr festgehalten, und die Detailseiten von Kampagnen, Benachrichtigungen und Automations-Läufen zeigen ab dann keine Mail mehr an. Bereits festgehaltene Versände bleiben liegen und bleiben ansehbar.',
        ],

        'test_send_subject_prefix' => [
            'label' => 'Betreff-Präfix der Testmail',
            'description' => 'Steht im Postfach vor dem Betreff, damit eine Testmail nicht mit einer echten verwechselt wird. Leer heißt: der Betreff kommt an, wie ihn ein Empfänger sähe — sinnvoll, wenn geprüft werden soll, was auf einem Handy noch von der Zeile übrig bleibt.',
        ],

        'countdown_image' => [
            'label' => 'Countdown als Bild ausliefern',
            'description' => 'Ausgeschaltet antwortet der PNG-Endpunkt mit 404 und `{{ countdown_image }}` bleibt in jeder Mail leer. Der Endpunkt braucht die GD-Erweiterung; fehlt sie auf dem Server, ist er ohnehin aus.',
        ],

    ],

];
