<?php

namespace App\Http\Responses;

use App\Domain\Economy\DailyStipend;
use App\Domain\Economy\Wallet;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;

/**
 * A resident's coins, stipend status and inventory: the answer to every
 * economy request, so the client redraws from one shape.
 */
final readonly class WalletResponse
{
    public function __construct(
        private Wallet $wallet,
        private DailyStipend $stipend,
    ) {}

    /**
     * @param  array<string, mixed>  $extra  Whatever else the request answers, such as how a reel went.
     */
    public function for(User $user, ?string $message = null, int $status = 200, array $extra = []): JsonResponse
    {
        return WorldResponse::json([
            ...$extra,
            'message' => $message,
            'wallet' => [
                'balance' => $this->wallet->balance($user),
                'stipend' => [
                    'amount' => $this->stipend->amount(),
                    'available' => $this->stipend->isAvailable($user),
                    'next_at' => $this->stipend->nextAt()->toIso8601String(),
                ],
                // One entry per item, newest first, with every piece of it the resident owns, oldest first.
                'inventory' => $user->inventory()->with('item.publishedArt')->latest('id')->get()
                    ->groupBy('item_id')
                    ->map(fn (Collection $pieces): array => [
                        'item' => $pieces->first()->item->toPayload(),
                        'pieces' => $pieces->sortBy('id')->values()->modelKeys(),
                    ])
                    ->values()
                    ->all(),
            ],
        ], $status);
    }
}
