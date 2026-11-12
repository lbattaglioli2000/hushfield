<?php

namespace Database\Factories;

use App\Domain\Books\BookCover;
use App\Models\Book;
use App\Models\LibraryShelf;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A draft on the first library shelf (the migration's "New arrivals").
 *
 * @extends Factory<Book>
 */
class BookFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => rtrim(fake()->sentence(3), '.'),
            'body' => fake()->paragraphs(3, true),
            'cover' => BookCover::Moss,
            'library_shelf_id' => fn (): int => LibraryShelf::query()->orderBy('position')->orderBy('id')->value('id'),
        ];
    }

    public function published(): static
    {
        return $this->state(['published_at' => now()]);
    }

    public function withdrawn(): static
    {
        return $this->state(['published_at' => now(), 'withdrawn_at' => now()]);
    }
}
