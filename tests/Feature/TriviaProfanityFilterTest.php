<?php

namespace Tests\Feature;

use App\Services\Trivia\ProfanityFilter;
use Tests\TestCase;

class TriviaProfanityFilterTest extends TestCase
{
    private ProfanityFilter $filter;

    protected function setUp(): void
    {
        parent::setUp();
        ProfanityFilter::forgetCache();
        $this->filter = new ProfanityFilter();
    }

    public function test_blocks_hebrew_and_english_with_normalisation(): void
    {
        $blocked = [
            'fuck', 'FuCkInG', 'f u c k', 'f.u.c.k', 'F*U*C*K', 'fuuuuck', 'fu ck', 'sh1t', 'a55hole', '@ss', 'b1tch',
            'motherfucker', 'sharmuta', 'kus emek',
            'זונה', 'זוווונה', 'ז ו נ ה', 'והזונה', 'זֹונָה', 'שרמוטה', 'כוס אמק', 'כוסאמק', 'כּוּס אִמָּא שלך',
            'תזדיין', 'זין', 'זיין', 'מניאק', 'חרא',
        ];
        foreach ($blocked as $text) {
            $this->assertTrue($this->filter->check($text)['flagged'], "should block: {$text}");
        }
    }

    public function test_common_words_are_not_false_positives(): void
    {
        $clean = [
            'כוס יין', 'נרים כוסית', 'להרים כוסית לחיים', 'כוסות', 'כוסברה', 'מזין', 'הזין את הכלב', 'זינוק', 'זינה',
            'מזונות', 'מזל טוב', 'אהבה', 'שמחה', 'חתונה', 'שולחן 7', 'נועה', 'דני', 'סבבה', 'ממש', 'מפגש', 'שמוליק',
            'classic', 'assistant', 'as', 'pass the glass', 'bass', 'assassin', 'harass', 'Scunthorpe', 'cumin',
            'Dick Van Dyke', 'I love you', 'Mazal tov',
        ];
        foreach ($clean as $text) {
            $this->assertFalse($this->filter->check($text)['flagged'], "false positive: {$text}");
        }
    }

    public function test_masks_only_the_offending_words(): void
    {
        $this->assertSame('יא בן ****', $this->filter->mask('יא בן זונה'));
        $this->assertSame('******* awesome', $this->filter->mask('FuCkInG awesome'));
        $this->assertSame('מזל טוב', $this->filter->mask('מזל טוב'));
    }
}
