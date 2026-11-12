# Books and the library, for staff

Staff write Hushfield's books in the admin. Residents find them on the
library's shelves, check out a copy, and keep it on a bookshelf at home,
where visitors can read it too.

## How a book gets to a reader

1. **Write it.** In `/admin`, go to **World → Books** and press **Write a
   book**. Give it a title, the text, a cover colour and a library shelf.
   **Credited to** is optional: pick a resident to show them as its author,
   or leave it empty.
2. **Publish it.** Open the book and press **Publish**. Until then it's a
   draft: nobody in the game can see it.
3. **Players check it out.** In the Hushfield Public Library, a player walks
   up to the shelf for its category, opens it, and can **Read** any book
   there or **Check out** a copy. Their copy goes into their **Belongings**.
4. **Players keep it at home.** At home, a player walks up to their own
   bookshelf and picks **Put on shelf**. They can **Take back** a book at any
   time.
5. **Visitors read it.** A visitor who walks up to someone's bookshelf sees
   its books and can read them, but can never take them.

A resident holds one copy of a book at a time, however they got it.

## Writing

- Plain text only. Leave a blank line between paragraphs. The game splits
  the text into pages to fit the screen.
- Titles up to 60 characters, text up to 20,000 (about 30 pages in the
  game).
- You can save a draft as often as you like. Drafts are never checked.

## Publishing, and what gets checked

When you press **Publish**, the title and the text are checked:

- **A blocked word stops it.** The words are the ones under **Settings →
  Blocked Terms**, the same list that guards chat. Reword it and try again.
- **A link, email address or phone number asks you first.** Players aged 13
  to 17 read books too, so the game warns you. If it's fine, such as a
  made-up number in a story, tick **I checked it. Publish it anyway.** Any
  run of seven or more digits looks like a phone number, so a span of years
  like `1923-1931` triggers it too.

Editing a book that is already published runs the same checks when you
save. A published edit goes live straight away.

## Withdrawing

**Withdraw** takes a book out of the game at once. Moderators can do it as
well as admins, and both give a reason, which goes in the audit log.

- It leaves the library shelves.
- Copies people already have stay with them, but show as a plain **Withdrawn
  book** with no title or author, and nobody can open them.
- **Restore** puts it back the way it was.

Books are never deleted, so their history stays.

## Giving a copy

On a book's page, under **Copies**, press **Give a copy** and pick a
resident. It goes into their Belongings. Someone who already holds the book
can't get a second copy. A user's **Books** tab lists every book they hold.

## Library shelves (categories)

**World → Library shelves** lists the categories. Each one needs a shelf on
the library map before players can reach its books.

1. Add the category here. Its **slug**, such as `town-history`, is how the
   map finds it, so it can't change afterwards.
2. Ask for a shelf named that slug on the library map (see
   [Maps in Tiled](art/maps-in-tiled.md#library-shelves)).
3. **Shelf on the map** turns green once the new map is live.

Books on a category with no shelf yet stay out of reach.

## Bookshelves in homes

Any furniture can be a bookshelf. In **Economy → Item catalog**, open the
piece and set **Holds books** to how many books fit on it. Colours of the
piece get the same number. You can't set it lower than the fullest copy of
that shelf in anyone's home.

## Switching it all off

**Settings → Feature flags → Books** turns books off in the game: nobody can
browse, read, check out or shelve one. Everyone keeps the copies they hold,
and the admin keeps working.
