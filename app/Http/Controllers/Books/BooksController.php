<?php

namespace App\Http\Controllers\Books;

use App\Actions\Books\ReadBook;
use App\Actions\Books\ShelveBook;
use App\Actions\Books\UnshelveBook;
use App\Domain\Books\Bookshelves;
use App\Features\WorldFlag;
use App\Http\Controllers\Controller;
use App\Http\Responses\WorldResponse;
use App\Models\BookCopy;
use App\Models\InventoryItem;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The books a resident holds (their Belongings), and bookshelves at home:
 * browsed and read by anyone standing beside one, arranged only by its owner.
 */
final class BooksController extends Controller
{
    /** Every copy the resident holds, on a shelf or not, newest first. */
    public function index(Request $request): JsonResponse
    {
        return WorldResponse::json(['books' => $this->held($this->user($request))]);
    }

    public function read(Request $request, BookCopy $copy, ReadBook $readBook): JsonResponse
    {
        return WorldResponse::json(['book' => $readBook->copy($this->user($request), $copy)]);
    }

    /** Off its shelf, back into the owner's belongings. */
    public function unshelve(Request $request, BookCopy $copy, UnshelveBook $unshelveBook): JsonResponse
    {
        $user = $this->user($request);
        $copy = $unshelveBook($user, $copy);

        return WorldResponse::json([
            'message' => __('hushfield.book_unshelved', ['title' => $copy->book->title]),
            'books' => $this->held($user),
        ]);
    }

    /** A bookshelf at home, for someone standing beside it. */
    public function shelf(Request $request, InventoryItem $piece, Bookshelves $shelves): JsonResponse
    {
        WorldFlag::Books->ensureActive();
        $user = $this->user($request);
        $shelves->ensureBeside($user, $piece);

        return WorldResponse::json(['shelf' => $shelves->view($user, $piece)]);
    }

    public function shelve(Request $request, InventoryItem $piece, BookCopy $copy, ShelveBook $shelveBook, Bookshelves $shelves): JsonResponse
    {
        $user = $this->user($request);
        $shelveBook($user, $piece, $copy);

        return WorldResponse::json([
            'message' => __('hushfield.book_shelved', ['title' => $copy->book->title]),
            'shelf' => $shelves->view($user, $piece),
            'books' => $this->held($user),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function held(User $user): array
    {
        return array_values($user->bookCopies()->with('book.author')->latest('id')->get()
            ->map(fn (BookCopy $copy): array => $copy->toPayload())
            ->all());
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
