<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Books: staff write them, the library lends them out from a shelf per
     * category, and residents keep their copies in their belongings or on
     * bookshelves at home (furniture that holds books).
     */
    public function up(): void
    {
        // A category of the library, such as "New arrivals". The library map
        // has a shelf for it: a fixture of kind "library" named its slug.
        Schema::create('library_shelves', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('books', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body');
            // A swatch from App\Domain\Books\BookCover.
            $table->string('cover');
            // The resident credited on the spine, if any. Credit goes when their account does.
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            // Categories with books can't be deleted.
            $table->foreignId('library_shelf_id')->constrained()->restrictOnDelete();
            $table->foreignId('written_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable()->index();
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamps();
        });

        // One copy a resident holds. Copies are never handed between residents,
        // so the holder never changes. `shelf_id` is the owned bookshelf it sits on.
        Schema::create('book_copies', function (Blueprint $table) {
            $table->id();
            // Books are never deleted: withdrawn, at most.
            $table->foreignId('book_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('source');
            $table->foreignId('given_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('shelf_id')->nullable()->constrained('inventory_items')->nullOnDelete();
            $table->timestamp('shelved_at')->nullable();
            $table->timestamp('created_at');

            // One copy of each book per resident, however they got it.
            $table->unique(['book_id', 'user_id']);
            $table->index(['shelf_id', 'shelved_at']);
        });

        Schema::table('items', function (Blueprint $table) {
            // Furniture only: how many books it holds; null for furniture that isn't a bookshelf.
            $table->unsignedSmallInteger('holds_books')->nullable()->after('shape');
        });

        // Here rather than in a seeder, so the live site has it too.
        DB::table('library_shelves')->insert(['slug' => 'new-arrivals', 'name' => 'New arrivals', 'position' => 0, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn('holds_books');
        });

        Schema::dropIfExists('book_copies');
        Schema::dropIfExists('books');
        Schema::dropIfExists('library_shelves');
    }
};
