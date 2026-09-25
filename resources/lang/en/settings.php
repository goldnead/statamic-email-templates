<?php

/*
 * The settings screen under Control Panel → Settings.
 *
 * Key-identical to resources/lang/de/settings.php. Field keys are the config
 * path with its dots replaced (`test_send.subject_prefix` →
 * `test_send_subject_prefix`), because a dot in a translation key is a path
 * separator to the translator.
 */

return [

    'groups' => [

        'layout' => [
            'title' => 'Layout',
            'description' => 'Which Blade shell wraps an email. Applies to every send and to the live preview, because both take the same render path. The map from layout handle to Blade view stays under “layouts” in config/email-templates.php: it is a table, not a value, and a handle deleted here would strand every template that chose it. The master switch “enabled” stays in the config file for a different reason: it is read while booting, so a switch here would only take effect on the next deploy.',
        ],

        'snapshots' => [
            'title' => 'Send snapshots',
            'description' => 'What is kept at send time so that campaigns, notifications and automation runs can show which email went out. What is stored is the template with its placeholders, not the finished mail of any recipient. The placeholder values used by the preview live under “preview.sample_data” in config/email-templates.php; they follow whatever variables this installation\'s templates use.',
        ],

        'test_send' => [
            'title' => 'Test email',
            'description' => 'The test email offered next to Save in the Control Panel. It takes the same path as a real send, only to an address typed in by hand.',
        ],

        'core_mails' => [
            'title' => 'Account mails (password, invitation, confirmation)',
            'description' => 'Password reset (website and Control Panel), account activation, verification code and email verification. When on, each of these mails is sent from the published template with its slug (core-password-reset, core-password-reset-cp, core-activate-account, core-verification-code, core-verify-email). Without that template, or while it is a draft, Statamic sends its own mail unchanged. `php please email-templates:import --source=Statamic --locale=en` creates the shipped templates.',
        ],

        'countdown' => [
            'title' => 'Countdown',
            'description' => 'The image countdown, which serves the remaining time as a PNG. The text countdown needs none of this and always works.',
        ],

    ],

    'fields' => [

        'branded_layout' => [
            'label' => 'Default shell',
            'description' => 'The name of an application Blade layout that wraps every email when neither the template nor the default layout says otherwise. Empty means the email goes out unwrapped, carrying only what the template itself contains.',
        ],

        'default_layout' => [
            'label' => 'Default layout',
            'description' => 'A handle from the `layouts` map in the config, given to every template that did not choose a layout of its own. Empty means those templates fall back to the default shell above.',
        ],

        'snapshots_enabled' => [
            'label' => 'Keep what was sent',
            'description' => 'Switched off, nothing is kept at send time, and the detail pages of campaigns, notifications and automation runs stop showing an email from then on. Snapshots already taken stay where they are and remain viewable.',
        ],

        'test_send_subject_prefix' => [
            'label' => 'Test email subject prefix',
            'description' => 'Sits in front of the subject in the inbox so a test email is not mistaken for a real one. Empty means the subject arrives exactly as a recipient would see it — worth doing when checking what is left of the line on a phone.',
        ],

        'core_mails_enabled' => [
            'label' => 'Send account mails from templates',
            'description' => 'Off means these mails go out with the built-in texts, even where a template exists.',
        ],

        'countdown_image' => [
            'label' => 'Serve the countdown as an image',
            'description' => 'Switched off, the PNG endpoint answers 404 and `{{ countdown_image }}` stays empty in every email. The endpoint needs the GD extension; without it on the server it is off anyway.',
        ],

    ],

];
