<?php

namespace Database\Seeders;

use App\Domain\Chat\BlockedTermMatchType;
use App\Domain\Chat\BlockedTermSeverity;
use App\Models\BlockedTerm;
use Illuminate\Database\Seeder;

/**
 * The starter blocked terms list. Moderators maintain it from here in Filament.
 *
 * Safe to run again (including in production): terms are matched by term and
 * match type, and a term staff have since edited keeps their severity. A term
 * staff deleted comes back, so take it out of this list too.
 *
 * Severity decides who sees a term: low is hushed only by the strict filter
 * (the default, and always for minors), medium and high by every filter.
 * High marks what moderators should look at first.
 *
 * Choosing a match type: every term is also screened anywhere inside
 * usernames, whatever its type, so "ass" would refuse "Cassie" and "Glass".
 * - exact: whole words, for terms that are never part of an innocent word.
 * - contains: inside words too, for roots that only make offensive words ("fuck").
 * - regex anchored with ^ and $: a short word that is part of innocent ones
 *   ("coon" in "raccoon"), which then matches only that word in chat and only a
 *   username that is nothing else. Regex sees words with repeated letters
 *   collapsed, so a word with a double letter ("boobs") cannot be one.
 * tests/Feature/Chat/BlockedTermSeederTest.php checks the list against innocent chat and usernames.
 */
class BlockedTermSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->terms() as [$severity, $matchType, $terms]) {
            foreach ($terms as $term) {
                BlockedTerm::query()->firstOrCreate(
                    ['term' => $term, 'match_type' => $matchType],
                    ['severity' => $severity],
                );
            }
        }
    }

    /**
     * @return list<array{0: BlockedTermSeverity, 1: BlockedTermMatchType, 2: list<string>}>
     */
    private function terms(): array
    {
        $high = BlockedTermSeverity::High;
        $medium = BlockedTermSeverity::Medium;
        $low = BlockedTermSeverity::Low;
        $exact = BlockedTermMatchType::Exact;
        $contains = BlockedTermMatchType::Contains;
        $regex = BlockedTermMatchType::Regex;

        return [
            // Slurs and hate slogans. "fagot" also matches "faggot": a single letter in a term stretches.
            [$high, $exact, [
                'nigger', 'niggers', 'kike', 'kikes', 'chink', 'chinks', 'gook', 'gooks', 'beaner', 'beaners',
                'dyke', 'dykes', 'trannies', 'lesbo', 'chinaman', 'chinamen', 'towel head', 'porch monkey',
                'jungle bunny', 'camel jockey', 'heil hitler', 'sieg heil', 'white power', 'white pride',
                'gas the jews', 'kkk', '1488',
            ]],
            [$high, $contains, [
                'nigga', 'sandnigger', 'fagot', 'wetback', 'raghead', 'towelhead', 'zipperhead', 'redskin', 'tranny', 'shemale',
            ]],
            // "nazi" is anchored for the names that contain it: Nazir, Nazim, Ashkenazi.
            [$high, $regex, [
                '^fags?$', '^spics?$', '^coons?$', '^homos?$', '^japs?$', '^pakis?$', '^dagos?$', '^troons?$',
                '^(neo)?nazis?$',
            ]],

            // Telling someone to hurt themselves.
            [$high, $exact, [
                'kill yourself', 'kill your self', 'kill urself', 'kill ur self', 'kill yourselves',
                'hang yourself', 'neck yourself', 'unalive yourself', 'slit your wrists', 'drink bleach',
            ]],
            [$high, $regex, ['^kys$']],

            // Asking for pictures, secrecy, or time alone: how grooming starts.
            [$high, $exact, [
                'nudes', 'send nude', 'send nudes', 'send pic', 'send pics', 'send a pic', 'send me pics',
                'send me a pic', 'send me a picture', 'show me your body',
                'dont tell your parents', 'dont tell your mom', 'dont tell your mum', 'dont tell your dad',
                'our little secret', 'are you home alone',
            ]],
            [$high, $contains, ['pedophil', 'paedophil']],
            [$high, $regex, ['^pa?edos?$', '^sext(s|ed|ing)?$']],

            // Strong swearing and sexual words.
            [$medium, $exact, ['kiss my ass', 'your ass', 'pussy', 'pussies']],
            [$medium, $contains, [
                'fuck', 'shit', 'bitch', 'bastard', 'cunt', 'asshole', 'arsehole', 'dickhead', 'cocksucker',
                'bellend', 'slut', 'whore', 'skank', 'jizz', 'porn', 'blowjob', 'handjob', 'dildo', 'orgasm', 'retard',
            ]],
            [$medium, $regex, [
                '^(fk|fck|fuk|fuq|phuk|phuck)(s|er|ers|ed|ing|in)?$',
                '^dicks?$', '^cocks?$', '^pricks?$', '^twats?$', '^wank(s|er|ers|ed|ing)?$',
                '^tit(s|y|ies)?$', '^cum(s|ing|shot|shots)?$',
            ]],

            // Mild swearing, insults, innuendo, and drugs, for the strict filter.
            [$low, $exact, [
                'idiot', 'stupid', 'moron', 'morons', 'dammit', 'wtf', 'gtfo', 'go to hell', 'what the hell',
                'boobs', 'boobies', 'penis', 'vagina', 'boner',
            ]],
            [$low, $contains, ['dumbass', 'jackass', 'smartass', 'damn', 'piss', 'bollocks', 'douche', 'sexy', 'cocaine']],
            [$low, $regex, ['^crap(s|y)?$', '^arse$', '^stfu$', '^sex$', '^horny$', '^heroin$', '^meth$']],

            // Moving a conversation off Hushfield, which the strict filter stops along with links and phone numbers.
            [$low, $exact, ['snap me', 'my snap', 'discord', 'telegram', 'skype', 'facetime', 'add me on']],
            [$low, $contains, ['snapchat', 'whatsapp', 'instagram']],
            [$low, $regex, ['^insta$', '^kik$']],
        ];
    }
}
