import Phaser from 'phaser';
import type { AssetManifest } from '../protocol/types';

/** A texture to load, with where the manifest says it is (undefined: it has no art). */
export type AssetFile = {
    key: string;
    url: (manifest: AssetManifest) => string | undefined;
    spritesheet?: { frameWidth: number; frameHeight: number };
};

/**
 * Loads textures through the scene's loader, one batch at a time. A file that
 * fails to load is tried once more against a freshly fetched manifest: its
 * name carries a content hash, so after a deploy an old URL can be gone
 * while the new manifest names the file that replaced it.
 */
export class AssetLoader {
    private queue: Promise<void> = Promise.resolve();

    constructor(
        private readonly scene: Phaser.Scene,
        public manifest: AssetManifest,
        private readonly refetch: () => Promise<AssetManifest>,
    ) {}

    /** Resolves once every file is loaded, or has failed twice (it is then drawn as a placeholder). */
    load(files: AssetFile[]): Promise<void> {
        const run = async () => {
            const failed = await this.batch(files);

            if (failed.length === 0) return;

            try {
                this.manifest = await this.refetch();
            } catch {
                return;
            }

            await this.batch(failed);
        };

        this.queue = this.queue.then(run, run);

        return this.queue;
    }

    /** Loads the files not loaded yet, and resolves with the ones that failed. */
    private batch(files: AssetFile[]): Promise<AssetFile[]> {
        const wanted = files.filter(
            (file) =>
                !this.scene.textures.exists(file.key) &&
                file.url(this.manifest) !== undefined,
        );

        if (wanted.length === 0) return Promise.resolve([]);

        const loader = this.scene.load;

        return new Promise((resolve) => {
            const failed: AssetFile[] = [];
            const onError = (file: Phaser.Loader.File) => {
                const asset = wanted.find((w) => w.key === file.key);

                if (asset) failed.push(asset);
            };

            loader.on(Phaser.Loader.Events.FILE_LOAD_ERROR, onError);
            loader.once(Phaser.Loader.Events.COMPLETE, () => {
                loader.off(Phaser.Loader.Events.FILE_LOAD_ERROR, onError);
                resolve(failed);
            });

            for (const file of wanted) {
                const url = file.url(this.manifest) as string;

                if (file.spritesheet) {
                    loader.spritesheet(file.key, url, file.spritesheet);
                } else {
                    loader.image(file.key, url);
                }
            }

            loader.start();
        });
    }
}
