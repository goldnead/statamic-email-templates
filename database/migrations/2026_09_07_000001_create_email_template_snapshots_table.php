<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The one table in the suite that holds what actually went out.
 *
 * One row per send, not per recipient: a campaign to 800 people is one row
 * here, and the 800 recipients keep hanging off the sending addon's own table
 * (`marketing_messages` carries a `subscription_id` per recipient, and always
 * did). Nothing personal is stored — the body keeps its `{{ … }}` placeholders
 * and is never the rendered mail of a named contact. That is what makes this
 * table free of a deletion concept: there is nothing in it that belongs to a
 * person who could ask for it to be removed.
 *
 * `owner_type` / `owner_id` are free strings rather than a polymorphic relation
 * to a model, because the three consumers hold three different kinds of thing:
 * a campaign row (integer id), a notification type (a handle) and an
 * automation node (a pair of UUIDs). A `morphTo` would force all three into one
 * Eloquent shape they do not share, and two of them have no model at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_template_snapshots', function (Blueprint $table) {
            $table->id();

            // Who sent it. Deliberately not the recipient, and not the
            // per-recipient row either — see the class docblock.
            $table->string('owner_type', 191)->nullable();
            $table->string('owner_id', 191)->nullable();

            // The brand this went out under, so a later re-render uses the
            // sender and the shell of the brand that sent it.
            $table->string('brand')->nullable();

            $table->string('template_slug')->nullable();
            $table->string('template_source')->nullable();

            // The template as it was, with placeholders intact.
            $table->text('subject');
            $table->longText('body');
            $table->longText('plain_text')->nullable();

            // The layout reference. `layout` is the entry's handle, `layout_view`
            // the Blade view that handle resolved to on the day. Both are kept:
            // the handle survives a renamed view, the view name says which shell
            // the mail actually wore when the map has changed since.
            $table->string('layout')->nullable();
            $table->string('layout_view')->nullable();

            // The from-identity of the moment. A brand that changes its sender
            // address next year must not rewrite the history of what went out.
            $table->string('sender_name')->nullable();
            $table->string('sender_email')->nullable();

            // sha256 over the rendering-relevant fields. Two sends of an
            // unchanged template from the same owner land on the same row and
            // bump the counters below instead of writing a duplicate.
            $table->char('content_hash', 64);

            $table->unsignedInteger('send_count')->default(1);
            $table->timestamp('first_sent_at')->nullable();
            $table->timestamp('last_sent_at')->nullable();

            $table->timestamps();

            $table->unique(['owner_type', 'owner_id', 'content_hash'], 'email_tpl_snapshots_owner_hash_unique');
            $table->index(['owner_type', 'owner_id'], 'email_tpl_snapshots_owner_index');
            $table->index('last_sent_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_template_snapshots');
    }
};
