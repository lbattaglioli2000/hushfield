<?php

namespace App\Console\Commands;

use App\Domain\Assets\AssetBuilder;
use App\Domain\Assets\InvalidAssetException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('hushfield:build-assets')]
#[Description('Check the art in resources/art, build what ships with content-hashed names, and write the asset index and map tilesets')]
final class BuildAssetsCommand extends Command
{
    public function handle(AssetBuilder $builder): int
    {
        try {
            $index = $builder->build();
        } catch (InvalidAssetException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Built '.count($index['files'])." assets, version {$index['version']}.");

        return self::SUCCESS;
    }
}
