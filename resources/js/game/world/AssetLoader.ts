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
 * while the new manifest names the file that replaced it. Art is filtered
 * NEAREST, so anything that scales it keeps its pixels square.
 */
export class AssetLoader {
    private queue: Promise<void> = Promise.resolve();
    /** Keys already loaded or given up on, so a missing file isn't fetched on every snapshot. */
    private readonly tried = new Set<string>();

    constructor(
        private readonly scene: Phaser.Scene,
        public manifest: AssetManifest,
        private readonly refetch: () => Promise<AssetManifest>,
    ) {}

    /** The files that have art and haven't been tried yet. */
    missing(files: AssetFile[]): AssetFile[] {
        return files.filter(
            (file) =>
                !this.tried.has(file.key) &&
                !this.scene.textures.exists(file.key) &&
                file.url(this.manifest) !== undefined,
        );
    }

    /** Forgets a texture, so it is loaded again if it is wanted again. */
    forget(key: string): void {
        this.tried.delete(key);
    }

    /** Resolves once every file is loaded, or has failed twice (it is then drawn as a placeholder). */
    load(files: AssetFile[]): Promise<void> {
        const run = async () => {
            const failed = await this.batch(files);

            if (failed.length > 0) {
                try {
                    this.manifest = await this.refetch();
                    await this.batch(failed, true);
                } catch {
                    // Drawn as placeholders.
                }
            }

            files.forEach((file) => this.tried.add(file.key));
        };

        this.queue = this.queue.then(run, run);

        return this.queue;
    }

    /**
     * Loads the files not loaded yet, and resolves with the ones that failed.
     * A retry asks for a fresh URL (the bucket ignores the query): an image
     * first opened without CORS, say as an admin preview or straight in a tab,
     * is cached without CORS headers, and the game's crossorigin load of the
     * same URL then fails against that cached copy.
     */
    private batch(files: AssetFile[], retry = false): Promise<AssetFile[]> {
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

            const onLoad = (key: string) => {
                if (wanted.some((w) => w.key === key)) {
                    this.scene.textures
                        .get(key)
                        .setFilter(Phaser.Textures.FilterMode.NEAREST);
                }
            };

            loader.on(Phaser.Loader.Events.FILE_LOAD_ERROR, onError);
            this.scene.textures.on(Phaser.Textures.Events.ADD, onLoad);
            loader.once(Phaser.Loader.Events.COMPLETE, () => {
                loader.off(Phaser.Loader.Events.FILE_LOAD_ERROR, onError);
                this.scene.textures.off(Phaser.Textures.Events.ADD, onLoad);
                resolve(failed);
            });

            for (const file of wanted) {
                let url = file.url(this.manifest) as string;

                if (retry) url += url.includes('?') ? '&retry' : '?retry';

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
