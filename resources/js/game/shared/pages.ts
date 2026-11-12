/**
 * Splits a book's text into pages of about `size` characters, for the
 * reader. Paragraphs (split by blank lines) stay whole where they fit, and a
 * paragraph longer than a page breaks between words. Single line breaks
 * inside a paragraph are kept.
 */
export function paginate(body: string, size: number): string[] {
    const pages: string[] = [];
    let page = '';

    const flush = () => {
        if (page !== '') pages.push(page);
        page = '';
    };

    for (const paragraph of body.split(/\n{2,}/).map((p) => p.trim())) {
        if (paragraph === '') continue;

        const joined = page === '' ? paragraph : `${page}\n\n${paragraph}`;

        if (joined.length <= size) {
            page = joined;

            continue;
        }

        flush();

        // Too long for a page of its own: fill pages word by word.
        for (const word of paragraph.split(/(?<=\s)/)) {
            if (page.length + word.length > size && page.trim() !== '') {
                page = page.trimEnd();
                flush();
            }

            page += word;
        }

        page = page.trimEnd();
    }

    flush();

    return pages.length > 0 ? pages : [''];
}
