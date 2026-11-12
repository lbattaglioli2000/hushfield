import type { ReactNode } from 'react';
import { useEffect, useState } from 'react';
import { OnTray } from '@/components/play/signal-tray';
import RadioDialog from '@/components/radio/radio-dialog';
import RadioKey from '@/components/radio/radio-key';
import { ResultCard } from '@/components/radio/signal-card';
import type {
    BookSpine,
    BookText,
    Copy,
    EventBus,
    GameEvents,
    HeldBook,
    ShelfView,
} from '@/game';
import { paginate } from '@/game/shared/pages';
import { useFleeting } from '@/hooks/use-fleeting';
import { useGameBus } from '@/hooks/use-game-bus';

const RESULT_MS = 5000;

/** Characters on a page of the reader: about what fits its glass without scrolling. */
const PAGE_CHARS = 700;

/**
 * Books: the shelf panel (a library category, or a bookshelf in a home) that
 * opens when the player walks up to one, the reader, and the outcome of
 * checking out or shelving. Belongings lists the player's own books
 * (BelongingsBooks). Everything goes through the bus; the server judges.
 */
export default function Books({ bus, copy }: { bus: EventBus; copy: Copy }) {
    const [shelf, setShelf] = useState<ShelfView | null>(null);
    const [held, setHeld] = useState<HeldBook[]>([]);
    const [busy, setBusy] = useState(false);
    const [result, setResult] =
        useFleeting<GameEvents['books-result']>(RESULT_MS);

    useGameBus(bus, 'shelf', ({ shelf: next }) => setShelf(next));
    useGameBus(bus, 'books', ({ books }) => setHeld(books));
    useGameBus(bus, 'books-result', (next) => {
        setBusy(false);
        setResult(next);
    });

    const request = <
        K extends 'check-out-book' | 'shelve-book' | 'unshelve-book',
    >(
        event: K,
        payload: GameEvents[K],
    ) => {
        setBusy(true);
        setResult(null);
        bus.emit(event, payload);
    };

    const close = () => {
        setShelf(null);
        bus.emit('close-shelf', {});
    };

    const resultLine = result && (
        <ResultCard result={result} data-test="books-result" />
    );

    return (
        <>
            <OnTray>{shelf === null && resultLine}</OnTray>
            {shelf && (
                <ShelfPanel
                    shelf={shelf}
                    held={held}
                    copy={copy}
                    busy={busy}
                    result={resultLine}
                    onClose={close}
                    onRead={(from) => bus.emit('read-book', { from })}
                    request={request}
                />
            )}
            <Reader bus={bus} />
        </>
    );
}

function ShelfPanel({
    shelf,
    held,
    copy,
    busy,
    result,
    onClose,
    onRead,
    request,
}: {
    shelf: ShelfView;
    held: HeldBook[];
    copy: Copy;
    busy: boolean;
    result: ReactNode;
    onClose: () => void;
    onRead: (from: GameEvents['read-book']['from']) => void;
    request: <K extends 'check-out-book' | 'shelve-book' | 'unshelve-book'>(
        event: K,
        payload: GameEvents[K],
    ) => void;
}) {
    const home = shelf.kind === 'home' ? shelf : null;
    // The owner's books not on this shelf, which they can put on it while it has room.
    const addable =
        home?.own && home.books.length < home.holds
            ? held.filter(
                  (book) => book.shelf !== home.piece && !book.withdrawn,
              )
            : [];

    return (
        <RadioDialog
            open
            onOpenChange={(next) => !next && onClose()}
            band={
                home
                    ? `${home.own ? 'Your' : `${home.owner}'s`} ${home.name} · ${home.books.length} of ${home.holds}`
                    : 'Hushfield Public Library'
            }
            title={home ? 'Bookshelf' : shelf.name}
            className="sm:max-w-2xl"
            data-test="shelf-panel"
        >
            {result}
            {shelf.books.length === 0 ? (
                <p className="text-bakelite/60">{copy.shelf_empty}</p>
            ) : (
                <ul className="grid max-h-[50vh] gap-2 overflow-y-auto pr-1">
                    {shelf.kind === 'library'
                        ? shelf.books.map((book) => (
                              <Spine key={book.book} book={book} copy={copy}>
                                  <RadioKey
                                      size="sm"
                                      variant="glass"
                                      onClick={() =>
                                          onRead({
                                              slug: shelf.slug,
                                              book: book.book,
                                          })
                                      }
                                      data-test={`read-${book.book}`}
                                  >
                                      Read
                                  </RadioKey>
                                  <RadioKey
                                      size="sm"
                                      disabled={
                                          busy || shelf.held.includes(book.book)
                                      }
                                      onClick={() =>
                                          request('check-out-book', {
                                              slug: shelf.slug,
                                              book: book.book,
                                          })
                                      }
                                      data-test={`check-out-${book.book}`}
                                  >
                                      {shelf.held.includes(book.book)
                                          ? 'Yours'
                                          : 'Check out'}
                                  </RadioKey>
                              </Spine>
                          ))
                        : shelf.books.map((book) => (
                              <Spine key={book.copy} book={book} copy={copy}>
                                  {!book.withdrawn && (
                                      <RadioKey
                                          size="sm"
                                          variant="glass"
                                          onClick={() =>
                                              onRead({ copy: book.copy })
                                          }
                                          data-test={`read-${book.book}`}
                                      >
                                          Read
                                      </RadioKey>
                                  )}
                                  {shelf.own && (
                                      <RadioKey
                                          size="sm"
                                          disabled={busy}
                                          onClick={() =>
                                              request('unshelve-book', {
                                                  copy: book.copy,
                                              })
                                          }
                                          data-test={`unshelve-${book.book}`}
                                      >
                                          Take back
                                      </RadioKey>
                                  )}
                              </Spine>
                          ))}
                </ul>
            )}
            {home && addable.length > 0 && (
                <section className="grid gap-2 border-t border-bakelite/10 pt-3">
                    <p className="text-[0.65rem] tracking-[0.2em] text-bakelite/55 uppercase">
                        Add a book
                    </p>
                    <ul className="grid max-h-[25vh] gap-2 overflow-y-auto pr-1">
                        {addable.map((book) => (
                            <Spine key={book.copy} book={book} copy={copy}>
                                <RadioKey
                                    size="sm"
                                    disabled={busy}
                                    onClick={() =>
                                        request('shelve-book', {
                                            piece: home.piece,
                                            copy: book.copy,
                                        })
                                    }
                                    data-test={`shelve-${book.book}`}
                                >
                                    Put on shelf
                                </RadioKey>
                            </Spine>
                        ))}
                    </ul>
                </section>
            )}
        </RadioDialog>
    );
}

/** One book as a spine: its cover colour, title and author, and what can be done with it. */
function Spine({
    book,
    copy,
    note,
    children,
}: {
    book: BookSpine;
    copy: Copy;
    note?: string;
    children: ReactNode;
}) {
    const byline = book.withdrawn
        ? copy.book_withdrawn
        : [book.author, note].filter(Boolean).join(' · ');

    return (
        <li
            className="flex items-center gap-3 rounded-md border border-bakelite/10 bg-bakelite/[0.03] p-2"
            data-test={`spine-${book.book}`}
        >
            <span
                aria-hidden
                className="h-10 w-3 shrink-0 rounded-sm shadow-[inset_-2px_0_0_rgb(0_0_0/0.3),inset_1px_0_0_rgb(255_255_255/0.2)]"
                style={{ backgroundColor: book.cover }}
            />
            <div className="min-w-0 flex-1">
                <p className="truncate font-sans text-base leading-tight font-bold tracking-wide amber-glow uppercase">
                    {book.withdrawn ? 'Withdrawn book' : book.title}
                </p>
                {byline && (
                    <p className="truncate text-[0.65rem] tracking-[0.15em] text-bakelite/55 uppercase">
                        {byline}
                    </p>
                )}
            </div>
            <div className="flex shrink-0 gap-2">{children}</div>
        </li>
    );
}

/** The books the player holds, for the Belongings panel: when and how each was got, and Read. */
export function BelongingsBooks({
    bus,
    copy,
    held,
}: {
    bus: EventBus;
    copy: Copy;
    held: HeldBook[];
}) {
    return (
        <section
            className="grid gap-2 border-t border-bakelite/10 pt-3"
            data-test="belongings-books"
        >
            <p className="text-[0.65rem] tracking-[0.2em] text-bakelite/55 uppercase">
                Books
            </p>
            {held.length === 0 ? (
                <p className="text-bakelite/60">{copy.books_empty}</p>
            ) : (
                <ul className="grid max-h-[25vh] gap-2 overflow-y-auto pr-1">
                    {held.map((book) => (
                        <Spine
                            key={book.copy}
                            book={book}
                            copy={copy}
                            note={`${book.source === 'checked_out' ? 'Checked out' : 'A gift'} ${new Date(book.acquired_at).toLocaleDateString()}${book.shelf ? ' · on a shelf' : ''}`}
                        >
                            {!book.withdrawn && (
                                <RadioKey
                                    size="sm"
                                    variant="glass"
                                    onClick={() =>
                                        bus.emit('read-book', {
                                            from: { copy: book.copy },
                                        })
                                    }
                                    data-test={`read-held-${book.book}`}
                                >
                                    Read
                                </RadioKey>
                            )}
                        </Spine>
                    ))}
                </ul>
            )}
        </section>
    );
}

/** An open book: its cover, then its pages, turned with the keys or the arrow keys. */
function Reader({ bus }: { bus: EventBus }) {
    const [book, setBook] = useState<BookText | null>(null);
    const [page, setPage] = useState(0);

    useGameBus(bus, 'book', ({ book: next }) => {
        setBook(next);
        setPage(0);
    });

    const pages = book ? paginate(book.body, PAGE_CHARS) : [];
    const last = pages.length;

    useEffect(() => {
        if (!book) return;

        const turn = (event: KeyboardEvent) => {
            if (event.key === 'ArrowRight')
                setPage((p) => Math.min(p + 1, last));
            if (event.key === 'ArrowLeft') setPage((p) => Math.max(p - 1, 0));
        };

        window.addEventListener('keydown', turn);

        return () => window.removeEventListener('keydown', turn);
    }, [book, last]);

    if (!book) return null;

    return (
        <RadioDialog
            open
            onOpenChange={(next) => !next && setBook(null)}
            band={
                book.author ? `By ${book.author}` : 'Hushfield Public Library'
            }
            title={book.title}
            className="sm:max-w-xl"
            data-test="reader"
        >
            {page === 0 ? (
                <div
                    className="grid aspect-[3/4] max-h-[50vh] place-items-center justify-self-center rounded-sm p-6 text-center shadow-[inset_-6px_0_0_rgb(0_0_0/0.25),0_6px_14px_rgb(0_0_0/0.4)]"
                    style={{ backgroundColor: book.cover }}
                    data-test="reader-cover"
                >
                    <div className="grid gap-2">
                        <p className="font-sans text-xl font-bold tracking-wide text-bakelite uppercase drop-shadow">
                            {book.title}
                        </p>
                        {book.author && (
                            <p className="text-xs tracking-[0.2em] text-bakelite/80 uppercase">
                                {book.author}
                            </p>
                        )}
                    </div>
                </div>
            ) : (
                <p
                    className="min-h-[40vh] font-serif text-base leading-relaxed whitespace-pre-line text-bakelite/90"
                    data-test="reader-page"
                >
                    {pages[page - 1]}
                </p>
            )}
            <div className="flex items-center justify-between gap-3">
                <RadioKey
                    size="sm"
                    variant="glass"
                    disabled={page === 0}
                    onClick={() => setPage(page - 1)}
                    data-test="reader-back"
                >
                    Back
                </RadioKey>
                <span className="text-xs text-bakelite/55">
                    {page === 0 ? 'Cover' : `Page ${page} of ${last}`}
                </span>
                <RadioKey
                    size="sm"
                    disabled={page >= last}
                    onClick={() => setPage(page + 1)}
                    data-test="reader-next"
                >
                    {page === 0 ? 'Open' : 'Next'}
                </RadioKey>
            </div>
        </RadioDialog>
    );
}
