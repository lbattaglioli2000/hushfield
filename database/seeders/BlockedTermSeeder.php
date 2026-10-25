<?php

namespace Database\Seeders;

use App\Domain\Chat\BlockedTermMatchType;
use App\Domain\Chat\BlockedTermSeverity;
use App\Models\BlockedTerm;
use Illuminate\Database\Seeder;

/**
 * A tiny placeholder list so the filter has something to do locally. Moderators maintain the real list in Filament.
 */
class BlockedTermSeeder extends Seeder
{
    public function run(): void
    {
        $terms = [
            ['term' => 'idiot', 'match_type' => BlockedTermMatchType::Exact, 'severity' => BlockedTermSeverity::Low],
            ['term' => 'stupid', 'match_type' => BlockedTermMatchType::Exact, 'severity' => BlockedTermSeverity::Low],
            ['term' => 'kill yourself', 'match_type' => BlockedTermMatchType::Exact, 'severity' => BlockedTermSeverity::High],
            ['term' => 'kys', 'match_type' => BlockedTermMatchType::Exact, 'severity' => BlockedTermSeverity::High],
        ];

        foreach ($terms as $term) {
            BlockedTerm::query()->firstOrCreate(['term' => $term['term'], 'match_type' => $term['match_type']], $term);
        }
    }
}
