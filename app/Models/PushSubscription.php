<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One browser's web push address. The server POSTs to the endpoint, so only the browsers' own
 * push services are accepted (see ENDPOINT_RULE).
 */
#[Fillable(['user_id', 'endpoint', 'public_key', 'auth_token'])]
#[Hidden(['endpoint', 'public_key', 'auth_token'])]
class PushSubscription extends Model
{
    public const ENDPOINT_RULE = 'regex:#^https://(fcm\.googleapis\.com|web\.push\.apple\.com|[a-z0-9.-]+\.push\.services\.mozilla\.com|[a-z0-9.-]+\.notify\.windows\.com)/#';

    /** A guest can have several devices, but no one needs more than this */
    public const MAX_PER_USER = 5;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
