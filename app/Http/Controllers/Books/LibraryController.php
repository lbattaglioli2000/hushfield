<?php

namespace App\Http\Controllers\Books;

use App\Actions\Books\CheckOutBook;
use App\Actions\Books\ReadBook;
use App\Domain\Books\Library;
use App\Features\WorldFlag;
use App\Http\Controllers\Controller;
use App\Http\Responses\WorldResponse;
use App\Models\Book;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A shelf of the Hushfield Public Library, for a player standing at it:
 * `{shelf}` is its category's slug, which names its fixture on the map.
 */
final class LibraryController extends Controller
{
    public function show(Request $request, string $shelf, Library $library): JsonResponse
    {
        WorldFlag::Books->ensureActive();
        $user = $this->user($request);

        return WorldResponse::json(['shelf' => $library->view($user, $library->ensureAt($user, $shelf))]);
    }

    public function read(Request $request, string $shelf, Book $book, ReadBook $readBook): JsonResponse
    {
        return WorldResponse::json(['book' => $readBook->fromLibrary($this->user($request), $shelf, $book)]);
    }

    public function checkOut(Request $request, string $shelf, Book $book, CheckOutBook $checkOut, Library $library): JsonResponse
    {
        $user = $this->user($request);
        $checkOut($user, $shelf, $book);

        return WorldResponse::json([
            'message' => __('hushfield.book_checked_out', ['title' => $book->title]),
            'shelf' => $library->view($user, $library->ensureAt($user, $shelf)),
            'books' => BooksController::held($user),
        ], 201);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
