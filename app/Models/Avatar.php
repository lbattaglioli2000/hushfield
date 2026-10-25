<?php

namespace App\Models;

use Database\Factories\AvatarFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Placeholder appearance. The paper-doll layering system will replace sprite_key later.
 *
 * @property int $id
 * @property int $user_id
 * @property string $body_color
 * @property string $sprite_key
 */
#[Fillable(['body_color', 'sprite_key'])]
class Avatar extends Model
{
    /** @use HasFactory<AvatarFactory> */
    use HasFactory;

    public const array BODY_COLORS = [
        '#e8a87c', '#c38d9e', '#41b3a3', '#85cdca', '#e27d60', '#a3b18a',
        '#b8a1d9', '#f2c14e', '#7fa7d9', '#d98f8f', '#9bc995', '#c9b79c',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array{body_color: string, sprite_key: string}
     */
    public function toPayload(): array
    {
        return [
            'body_color' => $this->body_color,
            'sprite_key' => $this->sprite_key,
        ];
    }
}
