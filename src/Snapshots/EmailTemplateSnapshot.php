<?php

namespace Goldnead\EmailTemplates\Snapshots;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One send, held as the template that went out.
 *
 * Consumers do not need this class. They talk to {@see Snapshots}, which is the
 * stable surface; this is what comes back from it, and the only members a
 * consumer should read are the columns below plus {@see previewUrl()}.
 *
 * @property int $id
 * @property string|null $owner_type
 * @property string|null $owner_id
 * @property string|null $brand
 * @property string|null $template_slug
 * @property string|null $template_source
 * @property string $subject
 * @property string $body
 * @property string|null $plain_text
 * @property string|null $layout
 * @property string|null $layout_view
 * @property string|null $sender_name
 * @property string|null $sender_email
 * @property string $content_hash
 * @property int $send_count
 * @property Carbon|null $first_sent_at
 * @property Carbon|null $last_sent_at
 */
class EmailTemplateSnapshot extends Model
{
    protected $table = 'email_template_snapshots';

    protected $guarded = [];

    protected $casts = [
        'send_count' => 'integer',
        'first_sent_at' => 'datetime',
        'last_sent_at' => 'datetime',
    ];

    /**
     * The Control Panel URL that shows this snapshot with placeholder data.
     *
     * A consumer's detail page can put this straight into an `<iframe src>` and
     * is then done — no route, no controller and no renderer of its own.
     */
    public function previewUrl(): string
    {
        return cp_route('email-templates.snapshots.preview', ['snapshot' => $this->getKey()]);
    }
}
