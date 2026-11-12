import type { EventBus } from './bus';
import type { Copy } from './copy';
import { userMessage } from './net/http';
import type { BooksUpdate, WorldNet } from './net/WorldNet';

/** The shelf the player has open: a library category, or a bookshelf in a home. */
type OpenShelf =
    | { kind: 'library'; slug: string }
    | { kind: 'home'; piece: number };

/**
 * Connects the shelf, reader and Belongings overlays to the server. A shelf
 * opens when the player walks up to it: a library shelf (a fixture) or a
 * bookshelf in a home (RoomScene emits 'use-bookshelf'). It closes when its
 * room or piece is gone. Returns the cleanup and a way to load the player's
 * books once the world session exists.
 */
export function connectBooks(
    net: WorldNet,
    bus: EventBus,
    copy: Copy,
): { loadBooks(): void; dispose(): void } {
    let open: OpenShelf | null = null;
    let room: string | null = null;

    const fail = (error: unknown) =>
        bus.emit('books-result', {
            ok: false,
            message: userMessage(error, copy),
        });

    const close = () => {
        if (open === null) return;

        open = null;
        bus.emit('shelf', { shelf: null });
    };

    /** Fetches the open shelf again; a refusal (walked off, put away) closes it. */
    const load = (shelf: OpenShelf) => {
        open = shelf;
        const request =
            shelf.kind === 'library'
                ? net.libraryShelf(shelf.slug)
                : net.homeShelf(shelf.piece);

        request
            .then((view) => {
                if (open === shelf) bus.emit('shelf', { shelf: view });
            })
            .catch((error: unknown) => {
                if (open !== shelf) return;

                close();
                fail(error);
            });
    };

    const applied = (update: BooksUpdate) => {
        bus.emit('books', { books: update.books });

        if (update.shelf && open) bus.emit('shelf', { shelf: update.shelf });

        bus.emit('books-result', { ok: true, message: update.message });
    };

    const cleanups = [
        bus.on('use-fixture', ({ kind, name }) => {
            if (kind === 'library') load({ kind: 'library', slug: name });
        }),
        bus.on('use-bookshelf', ({ piece }) => load({ kind: 'home', piece })),
        bus.on('shelf-changed', ({ piece }) => {
            if (open?.kind === 'home' && open.piece === piece) load(open);
        }),
        // A home shelf put away or carried to the yard leaves with its panel.
        bus.on('furniture', ({ placed }) => {
            const shelf = open;

            if (
                shelf?.kind === 'home' &&
                !placed.some((p) => p.piece === shelf.piece)
            ) {
                close();
            }
        }),
        bus.on('room', ({ slug }) => {
            if (room !== null && room !== slug) close();

            room = slug;
        }),
        bus.on('close-shelf', () => {
            open = null;
        }),
        bus.on('check-out-book', ({ slug, book }) => {
            net.checkOutBook(slug, book).then(applied).catch(fail);
        }),
        bus.on('shelve-book', ({ piece, copy: held }) => {
            net.shelveBook(piece, held).then(applied).catch(fail);
        }),
        bus.on('unshelve-book', ({ copy: held }) => {
            net.unshelveBook(held)
                .then((update) => {
                    applied(update);

                    if (open?.kind === 'home') load(open);
                })
                .catch(fail);
        }),
        bus.on('read-book', ({ from }) => {
            net.readBook(from)
                .then((book) => bus.emit('book', { book }))
                .catch(fail);
        }),
    ];

    return {
        loadBooks() {
            // Without the list Belongings shows no books; the game itself is unaffected.
            net.books()
                .then((books) => bus.emit('books', { books }))
                .catch(() => undefined);
        },
        dispose() {
            cleanups.forEach((cleanup) => cleanup());
        },
    };
}
