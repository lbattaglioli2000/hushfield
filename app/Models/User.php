<?php

namespace App\Models;

use App\Domain\Chat\FilterLevel;
use App\Domain\Identity\StaffRole;
use App\Domain\Moderation\SanctionType;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $username
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property int $birth_year
 * @property bool $is_minor
 * @property StaffRole|null $role
 * @property FilterLevel $chat_filter_level
 * @property bool $direct_messages_allowed
 * @property bool $friend_requests_allowed
 * @property Carbon|null $banned_until
 * @property int|null $last_room_instance_id
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Avatar|null $avatar
 * @property-read WorldSession|null $worldSession
 * @property-read RoomInstance|null $lastRoomInstance
 */
#[Fillable(['username', 'email', 'password', 'birth_year', 'is_minor', 'chat_filter_level', 'direct_messages_allowed', 'friend_requests_allowed'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token', 'birth_year'])]
class User extends Authenticatable implements FilamentUser, MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Stored in banned_until for bans without an end date.
     */
    public const string PERMANENT_BAN_UNTIL = '9999-12-31 23:59:59';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'birth_year' => 'integer',
            'is_minor' => 'boolean',
            'role' => StaffRole::class,
            'chat_filter_level' => FilterLevel::class,
            'direct_messages_allowed' => 'boolean',
            'friend_requests_allowed' => 'boolean',
            'banned_until' => 'datetime',
        ];
    }

    /** @return HasOne<Avatar, $this> */
    public function avatar(): HasOne
    {
        return $this->hasOne(Avatar::class);
    }

    /** @return HasOne<WorldSession, $this> */
    public function worldSession(): HasOne
    {
        return $this->hasOne(WorldSession::class);
    }

    /** @return BelongsTo<RoomInstance, $this> */
    public function lastRoomInstance(): BelongsTo
    {
        return $this->belongsTo(RoomInstance::class, 'last_room_instance_id');
    }

    /** @return HasMany<Sanction, $this> */
    public function sanctions(): HasMany
    {
        return $this->hasMany(Sanction::class);
    }

    /** @return HasMany<ChatMessage, $this> */
    public function chatMessages(): HasMany
    {
        return $this->hasMany(ChatMessage::class);
    }

    public function isBanned(): bool
    {
        return $this->banned_until !== null && $this->banned_until->isFuture();
    }

    public function isMuted(): bool
    {
        return $this->sanctions()->active()->where('type', SanctionType::Mute)->exists();
    }

    public function isStaff(): bool
    {
        return $this->role !== null;
    }

    public function isAdmin(): bool
    {
        return $this->role === StaffRole::Admin;
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->isStaff() && $this->hasVerifiedEmail();
    }

    public function ageBracket(): string
    {
        return $this->is_minor ? '13–17' : '18+';
    }
}
