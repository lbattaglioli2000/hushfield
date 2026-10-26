<?php

namespace App\Features;

use App\Exceptions\WorldActionRefused;
use Filament\Support\Contracts\HasLabel;

/**
 * Every global kill switch staff can flip. Add a case here (and its Pennant
 * feature class) to put a new flag on the admin Feature flags page; actions
 * it guards call ensureActive().
 */
enum WorldFlag: string implements HasLabel
{
    case Chat = ChatEnabled::class;
    case Joins = JoinsEnabled::class;
    case Shop = ShopEnabled::class;
    case Fishing = FishingEnabled::class;

    public function getLabel(): string
    {
        return match ($this) {
            self::Chat => 'Chat',
            self::Joins => 'Room joins',
            self::Shop => 'Shop',
            self::Fishing => 'Fishing',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Chat => 'Kill switch for chat in every room. When off, nobody can send messages.',
            self::Joins => 'Kill switch for joining rooms. When off, players already in a room stay but nobody new can join.',
            self::Shop => 'Kill switch for shop purchases. When off, nobody can buy; belongings and the stipend still work.',
            self::Fishing => 'Kill switch for fishing at the Pier. When off, nobody can cast or land a catch, so fishing pays nothing.',
        };
    }

    public function isActive(): bool
    {
        return WorldFlags::active($this->value);
    }

    /**
     * @throws WorldActionRefused saying what is switched off, when it is.
     */
    public function ensureActive(): void
    {
        if (! $this->isActive()) {
            throw match ($this) {
                self::Chat => WorldActionRefused::chatPaused(),
                self::Joins => WorldActionRefused::joinsPaused(),
                self::Shop => WorldActionRefused::shopClosed(),
                self::Fishing => WorldActionRefused::fishingClosed(),
            };
        }
    }
}
