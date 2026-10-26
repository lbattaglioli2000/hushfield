<?php

namespace App\Console\Commands;

use App\Domain\Assets\AssetPublisher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('hushfield:prune-assets')]
#[Description('Delete art in the bucket that no recent build names')]
final class PruneAssetsCommand extends Command
{
    public function handle(AssetPublisher $publisher): int
    {
        $deleted = $publisher->prune();
        $this->info('Pruned '.count($deleted).' files.');

        return self::SUCCESS;
    }
}
