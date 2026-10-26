<?php

namespace App\Console\Commands;

use App\Domain\Assets\AssetPublisher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('hushfield:publish-assets')]
#[Description('Upload the built art the bucket does not have yet, so the manifest can point browsers at the bucket. Run on every deploy, before the release goes live')]
final class PublishAssetsCommand extends Command
{
    public function handle(AssetPublisher $publisher): int
    {
        $result = $publisher->publish();
        $this->info("Published build {$result['version']}: {$result['uploaded']} uploaded, {$result['skipped']} already there.");

        if (! config('hushfield.assets.base_url')) {
            $this->warn('HUSHFIELD_ASSET_URL is not set, so browsers still fetch art from the app.');
        }

        return self::SUCCESS;
    }
}
