<?php

/*
 * Statamic's and Laravel's account mails as templates.
 *
 * `title`, `trigger` and the placeholders appear in the Control Panel.
 * `subject`, `preview` and `body` are the shipped templates that
 * `php please email-templates:import --source=Statamic --locale=en` creates.
 * Same keys as resources/lang/de/core_mails.php.
 */

return [

    'password_reset' => [
        'title' => 'Password reset (website)',
        'trigger' => 'Forgot password on the website',
        'subject' => 'Your new password for {{ site_name }}',
        'preview' => 'Use this link to set a new password.',
        'body' => '<p>Hello {{ user.name }},</p>'
            .'<p>a new password was requested for your account at {{ site_name }}. Use this link to set it:</p>'
            .'<p><a href="{{ url }}">Set a new password</a></p>'
            .'<p>The link is valid for {{ expires_in }}. If you did not ask for a new password, there is nothing to do. Your current password stays valid.</p>',
    ],

    'password_reset_cp' => [
        'title' => 'Password reset (Control Panel)',
        'trigger' => 'Forgot password in the Control Panel',
        'subject' => 'Your new Control Panel password for {{ site_name }}',
        'preview' => 'Use this link to set a new password.',
        'body' => '<p>Hello {{ user.name }},</p>'
            .'<p>a new password was requested for your Control Panel access at {{ site_name }}. Use this link to set it:</p>'
            .'<p><a href="{{ url }}">Set a new password</a></p>'
            .'<p>The link is valid for {{ expires_in }}. If you did not ask for a new password, there is nothing to do.</p>',
    ],

    'activate_account' => [
        'title' => 'Activate account (invitation)',
        'trigger' => 'New account created, activation invitation',
        'subject' => 'Your account at {{ site_name }}',
        'preview' => 'Set your password and your account is ready.',
        'body' => '<p>Hello {{ user.name }},</p>'
            .'<p>an account at {{ site_name }} has been created for you. Set your password with this link, then you can sign in:</p>'
            .'<p><a href="{{ url }}">Activate account</a></p>'
            .'<p>{{ message }}</p>'
            .'<p>The link is valid for {{ expires_in }}.</p>',
    ],

    'verification_code' => [
        'title' => 'Verification code',
        'trigger' => 'Re-confirmation without a two-factor app',
        'subject' => 'Your verification code for {{ site_name }}',
        'preview' => 'Paste the code into the window where you asked for it.',
        'body' => '<p>Hello {{ user.name }},</p>'
            .'<p>here is your code. Paste it into the window where you asked for it:</p>'
            .'<p><strong>{{ code }}</strong></p>'
            .'<p>If you did not ask for a code, you can ignore this email.</p>',
    ],

    'verify_email' => [
        'title' => 'Verify email address',
        'trigger' => 'New account confirms its address (Laravel)',
        'subject' => 'Confirm your email address for {{ site_name }}',
        'preview' => 'One click and your account is unlocked.',
        'body' => '<p>Hello {{ user.name }},</p>'
            .'<p>please confirm that this address belongs to you:</p>'
            .'<p><a href="{{ url }}">Confirm email address</a></p>'
            .'<p>The link is valid for {{ expires_in }}. If you did not create an account, there is nothing to do.</p>',
    ],

    'placeholders' => [
        'url_password-reset' => 'Link to the form for the new password',
        'url_activate' => 'Link that activates the account',
        'url_verify' => 'Link that confirms the address',
        'user_name' => 'The person\'s name; their email address when there is none',
        'user_email' => 'The person\'s email address',
        'site_name' => 'Name of the site (app.name)',
        'expires_in' => 'How long the link is valid, e.g. "1 hour"',
        'expires_minutes' => 'How long the link is valid, in minutes',
        'message' => 'Message written in the Control Panel when inviting (empty otherwise)',
        'code' => 'The verification code',
    ],

];
