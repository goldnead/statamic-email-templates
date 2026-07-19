<?php

return [
    'collection_title' => 'E-Mail-Vorlagen',
    'blueprint_title' => 'E-Mail-Vorlage',
    'tab_content' => 'Inhalt',
    'field_title' => 'Titel',
    'field_subject' => 'Betreff',
    'field_subject_instructions' => 'Die Betreffzeile der E-Mail. Unterstützt Merge-Variablen wie {{ contact.first_name }}.',
    'field_preview' => 'Vorschautext',
    'field_preview_instructions' => 'Preheader: kurzer Vorschautext, den Mail-Clients neben dem Betreff anzeigen. Unterstützt Merge-Variablen wie {{ contact.first_name }}.',
    'field_layout' => 'Layout',
    'field_layout_instructions' => 'Welches Layout diese E-Mail umschließt. Leer = Standard-Layout.',
    'field_layout_default' => 'Standard-Layout',
    'field_body' => 'Inhalt',
    'field_body_instructions' => 'Der E-Mail-Inhalt, als Rich-Text verfasst. Wird für Versand und Vorschau zu HTML gerendert.',
    'field_plain_text' => 'Nur-Text',
    'field_plain_text_instructions' => 'Optionale Nur-Text-Variante für Clients, die kein HTML darstellen.',
    'field_description' => 'Beschreibung',
    'field_description_instructions' => 'Interne Notiz. Wird nicht an Empfänger gesendet.',
    'live_preview_target' => 'E-Mail',
    'live_preview_empty' => 'Keine Live-Vorschau verfügbar. Öffne die Vorschau aus der Bearbeitung einer Vorlage.',
];
