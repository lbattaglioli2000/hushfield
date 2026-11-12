import { describe, expect, it } from 'vite-plus/test';
import { paginate } from './pages';

describe('paginate', () => {
    it('keeps paragraphs whole, packing as many onto a page as fit', () => {
        expect(paginate('aaaa\n\nbbbb\n\ncccccc', 10)).toEqual([
            'aaaa\n\nbbbb',
            'cccccc',
        ]);
    });

    it('starts a paragraph on a new page rather than splitting one that fits a page', () => {
        expect(paginate('aaaaaa\n\nbbbbbbbb', 10)).toEqual([
            'aaaaaa',
            'bbbbbbbb',
        ]);
    });

    it('breaks a paragraph longer than a page between words, losing none of them', () => {
        const long = 'one two three four five six seven';
        const pages = paginate(`intro\n\n${long}`, 12);

        expect(pages[0]).toBe('intro');
        expect(pages.every((page) => page.length <= 12)).toBe(true);
        expect(pages.slice(1).join(' ')).toBe(long);
    });

    it('keeps a single line break inside a paragraph, and gives an empty book one page', () => {
        expect(paginate('a line\nanother', 50)).toEqual(['a line\nanother']);
        expect(paginate('', 50)).toEqual(['']);
    });
});
