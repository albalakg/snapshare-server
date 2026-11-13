<?php

namespace App\Services\Trivia;

class TriviaTemplates
{
    public static function catalog(): array
    {
        return array_map(function (array $template) {
            return [
                'id' => $template['id'],
                'title' => $template['title'],
                'description' => $template['description'],
                'event' => $template['event'],
                'question_count' => count($template['questions']),
            ];
        }, array_values(self::packs()));
    }

    public static function find(string $template_id): ?array
    {
        return self::packs()[$template_id] ?? null;
    }

    private static function packs(): array
    {
        $sideConfig = [
            'sides' => [
                ['name' => 'עמית', 'color' => '#DC5B66', 'initial' => 'ע'],
                ['name' => 'גל', 'color' => '#1F6F6A', 'initial' => 'ג'],
            ],
        ];

        return [
            'couple' => [
                'id' => 'couple',
                'title' => 'כמה אתם מכירים את הזוג',
                'description' => 'חתונה: איפה נפגשו, מי אמר, זה או זה, תמונות מהאלבום ושתי אמיתות ושקר.',
                'event' => 'wedding',
                'game' => [
                    'title' => 'כמה אתם מכירים את עמית וגל',
                    'theme' => 'elegant',
                    'pace' => 'host',
                    'teams_mode' => 'sides',
                    'team_labels' => ['a' => 'צד עמית', 'b' => 'צד גל'],
                    'prize_text' => 'הפרס: בקבוק יין וצילום עם עמית וגל',
                ],
                'questions' => [
                    [
                        'type' => 'choice',
                        'prompt' => 'איפה נפגשו עמית וגל לראשונה?',
                        'time_limit_seconds' => 20,
                        'max_points' => 1000,
                        'fun_fact' => 'זה היה בערב חברים אצל משותף.',
                        'options' => [
                            ['label' => 'בצבא', 'is_correct' => false],
                            ['label' => 'באוניברסיטה', 'is_correct' => true],
                            ['label' => 'בטיול לחו״ל', 'is_correct' => false],
                            ['label' => 'בעבודה', 'is_correct' => false],
                        ],
                    ],
                    [
                        'type' => 'who',
                        'prompt' => 'מי יותר סביר שיאחר לאירוע?',
                        'time_limit_seconds' => 20,
                        'max_points' => 1000,
                        'config' => $sideConfig,
                        'options' => [
                            ['label' => 'עמית', 'is_correct' => true],
                            ['label' => 'גל', 'is_correct' => false],
                        ],
                    ],
                    [
                        'type' => 'whosaid',
                        'prompt' => '«בוא נזמין את כולם» — מי אמר/ה?',
                        'time_limit_seconds' => 20,
                        'max_points' => 1000,
                        'config' => $sideConfig,
                        'options' => [
                            ['label' => 'עמית', 'is_correct' => false],
                            ['label' => 'גל', 'is_correct' => true],
                        ],
                    ],
                    [
                        'type' => 'thisorthat',
                        'prompt' => 'חוף או הרים לירח דבש?',
                        'time_limit_seconds' => 15,
                        'max_points' => 0,
                        'config' => [
                            'sides' => [
                                ['name' => 'חוף', 'color' => '#C9A36A', 'initial' => 'ח'],
                                ['name' => 'הרים', 'color' => '#1F6F6A', 'initial' => 'ה'],
                            ],
                        ],
                        'options' => [
                            ['label' => 'חוף', 'is_correct' => false],
                            ['label' => 'הרים', 'is_correct' => false],
                        ],
                    ],
                    [
                        'type' => 'twothruths',
                        'prompt' => 'שתי אמיתות ושקר על הזוג — מה השקר?',
                        'time_limit_seconds' => 25,
                        'max_points' => 1000,
                        'fun_fact' => 'השקר הוא על הכלב — אין להם עדיין.',
                        'config' => [
                            'truths' => [
                                ['label' => 'הכירו דרך חברים משותפים', 'lie' => false],
                                ['label' => 'יש להם כלב בשם לונה', 'lie' => true],
                                ['label' => 'הצעה הייתה בטיול צפון', 'lie' => false],
                            ],
                        ],
                        'options' => [
                            ['label' => 'הכירו דרך חברים משותפים', 'is_correct' => false],
                            ['label' => 'יש להם כלב בשם לונה', 'is_correct' => true],
                            ['label' => 'הצעה הייתה בטיול צפון', 'is_correct' => false],
                        ],
                    ],
                    [
                        'type' => 'truefalse',
                        'prompt' => 'עמית וגל התארסו באותה שנה שבה נפגשו.',
                        'time_limit_seconds' => 15,
                        'max_points' => 1000,
                        'options' => [
                            ['label' => 'נכון', 'is_correct' => false],
                            ['label' => 'לא נכון', 'is_correct' => true],
                        ],
                    ],
                    [
                        'type' => 'number',
                        'prompt' => 'כמה אורחים הוזמנו לחתונה?',
                        'time_limit_seconds' => 25,
                        'max_points' => 1000,
                        'config' => ['min' => 50, 'max' => 400, 'correct' => 180, 'unit' => 'אורחים'],
                        'options' => [],
                    ],
                    [
                        'type' => 'poll',
                        'prompt' => 'איזה שיר חייבים לרקוד?',
                        'time_limit_seconds' => 20,
                        'max_points' => 0,
                        'options' => [
                            ['label' => 'מזרחית', 'is_correct' => false],
                            ['label' => 'פופ ישראלי', 'is_correct' => false],
                            ['label' => 'קלאסיקה של שנות ה־90', 'is_correct' => false],
                            ['label' => 'הפתעה מהדיג׳יי', 'is_correct' => false],
                        ],
                    ],
                    [
                        'type' => 'multi',
                        'prompt' => 'מה נכון לגבי הזוג? (כמה תשובות)',
                        'time_limit_seconds' => 25,
                        'max_points' => 1000,
                        'options' => [
                            ['label' => 'גרים בתל אביב', 'is_correct' => true],
                            ['label' => 'אוהבים סושי', 'is_correct' => true],
                            ['label' => 'יש להם ילדים', 'is_correct' => false],
                            ['label' => 'משחקים טניס ביחד', 'is_correct' => false],
                        ],
                    ],
                    [
                        'type' => 'photo',
                        'prompt' => 'באיזו תמונה מהאלבום צולם הרגע הזה?',
                        'time_limit_seconds' => 20,
                        'max_points' => 1000,
                        'media_asset_id' => null,
                        'options' => [
                            ['label' => 'בחופה', 'is_correct' => true],
                            ['label' => 'בקבלת פנים', 'is_correct' => false],
                            ['label' => 'בריקודים', 'is_correct' => false],
                            ['label' => 'בצילומי זוגיות', 'is_correct' => false],
                        ],
                    ],
                ],
            ],
            'birthday' => [
                'id' => 'birthday',
                'title' => 'יום הולדת',
                'description' => 'זכרונות, זה או זה, סקרים ונחש מספר.',
                'event' => 'birthday',
                'game' => [
                    'title' => 'כמה אתם מכירים את החוגג/ת',
                    'theme' => 'neon',
                    'pace' => 'host',
                    'teams_mode' => 'none',
                    'team_labels' => null,
                    'prize_text' => 'הפרס: עוגה וברכה מהבמה',
                ],
                'questions' => [
                    [
                        'type' => 'choice',
                        'prompt' => 'מה האוכל האהוב על החוגג/ת?',
                        'time_limit_seconds' => 20,
                        'max_points' => 1000,
                        'options' => [
                            ['label' => 'פיצה', 'is_correct' => true],
                            ['label' => 'סושי', 'is_correct' => false],
                            ['label' => 'המבורגר', 'is_correct' => false],
                            ['label' => 'פסטה', 'is_correct' => false],
                        ],
                    ],
                    [
                        'type' => 'thisorthat',
                        'prompt' => 'ים או בריכה?',
                        'time_limit_seconds' => 15,
                        'max_points' => 0,
                        'options' => [
                            ['label' => 'ים', 'is_correct' => false],
                            ['label' => 'בריכה', 'is_correct' => false],
                        ],
                    ],
                    [
                        'type' => 'number',
                        'prompt' => 'באיזה גיל הם מרגישים?',
                        'time_limit_seconds' => 20,
                        'max_points' => 1000,
                        'config' => ['min' => 5, 'max' => 99, 'correct' => 25, 'unit' => ''],
                        'options' => [],
                    ],
                    [
                        'type' => 'poll',
                        'prompt' => 'איזו מסיבה הכי מתאימה?',
                        'time_limit_seconds' => 20,
                        'max_points' => 0,
                        'options' => [
                            ['label' => 'שקטה בבית', 'is_correct' => false],
                            ['label' => 'מועדון', 'is_correct' => false],
                            ['label' => 'ברבקיו', 'is_correct' => false],
                            ['label' => 'טיול', 'is_correct' => false],
                        ],
                    ],
                    [
                        'type' => 'truefalse',
                        'prompt' => 'החוגג/ת תמיד מאחר/ת.',
                        'time_limit_seconds' => 15,
                        'max_points' => 1000,
                        'options' => [
                            ['label' => 'נכון', 'is_correct' => true],
                            ['label' => 'לא נכון', 'is_correct' => false],
                        ],
                    ],
                ],
            ],
        ];
    }
}
