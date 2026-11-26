<?php

namespace App\Services\Messaging;

/**
 * SnapShare ready templates (PLAN.md §5). Bodies use named variables ({{first_name}}).
 * System templates are submitted once to SnapShare's WABA (`php artisan whatsapp-messaging:submit-system-templates`);
 * the mock driver treats them as approved.
 *
 * Button types: quick_reply (payload handled by the RSVP bot) | url (link variable appended to the client URL).
 */
class TemplateCatalog
{
    public const VARIABLES = [
        'first_name'    => 'שם פרטי',
        'full_name'     => 'שם מלא',
        'couple_names'  => 'שמות בעלי השמחה',
        'event_title'   => 'שם האירוע',
        'event_day'     => 'יום בשבוע',
        'event_date'    => 'תאריך',
        'event_time'    => 'שעה',
        'venue_name'    => 'שם המקום',
        'venue_address' => 'כתובת',
        'waze_link'     => 'קישור Waze',
        'hub_link'      => 'דף האירוע',
        'rsvp_link'     => 'קישור אישור הגעה אישי',
        'album_link'    => 'האלבום החי',
        'table_label'   => 'מספר שולחן',
        'table_link'    => 'קישור "השולחן שלי"',
        'party_size'    => 'מספר מוזמנים',
        'gift_link'     => 'קישור למתנה',
    ];

    public static function all(): array
    {
        return [
            'save_the_date' => [
                'title' => 'Save the Date — שריינו תאריך', 'stage' => 'pre', 'category' => 'marketing',
                'header' => 'שריינו את התאריך 💛',
                'body' => "היי {{first_name}}!\nשמחים לשתף ולשריין איתך את היום המיוחד שלנו — {{couple_names}} מתחתנים!\n📅 יום {{event_day}}, {{event_date}}\n📍 {{venue_name}}, {{venue_address}}\n\nפתחנו דף מיוחד עם פרטים והוספה ליומן:\n{{hub_link}}",
                'footer' => 'להסרה מעדכונים השיבו הסר',
                'buttons' => [['type' => 'url', 'text' => 'לדף האירוע והיומן', 'link' => 'hub_link']],
            ],
            'rsvp_invite' => [
                'title' => 'אישור הגעה + שאלון מנות חכם', 'stage' => 'rsvp', 'category' => 'marketing',
                'header' => 'הזמנה לחתונה של {{couple_names}} 🎉',
                'body' => "היי {{first_name}}!\nמתרגשים להזמין אותך לחגוג איתנו את {{event_title}}.\n📅 יום {{event_day}}, {{event_date}} בשעה {{event_time}}\n📍 {{venue_name}}, {{venue_address}}\n\nנודה לאישור הגעה קצר בלחיצה (15 שניות, כולל בחירת מנה):",
                'footer' => 'נשמח לראותכם! להסרה השיבו הסר',
                'buttons' => [
                    ['type' => 'quick_reply', 'text' => 'מגיע/ה ✅', 'payload' => 'rsvp_yes'],
                    ['type' => 'quick_reply', 'text' => 'אולי 🤔', 'payload' => 'rsvp_maybe'],
                    ['type' => 'quick_reply', 'text' => 'לא אוכל להגיע ❌', 'payload' => 'rsvp_no'],
                ],
            ],
            'rsvp_nudge' => [
                'title' => 'תזכורת אישור הגעה (ללא-עונים בלבד)', 'stage' => 'rsvp', 'category' => 'marketing',
                'header' => 'תזכורת לפני סגירת הושבה 💌',
                'body' => "היי {{first_name}}, תזכורת קטנה מאיתנו!\nאנחנו סוגרים את סידורי השולחנות ל{{event_title}} ב-{{event_date}}.\nהאם תצליחו להגיע לחגוג איתנו?",
                'footer' => 'לעדכון לחצו על אחד הכפתורים',
                'buttons' => [
                    ['type' => 'quick_reply', 'text' => 'מאשר/ת הגעה ✅', 'payload' => 'rsvp_yes'],
                    ['type' => 'quick_reply', 'text' => 'לא אוכל להגיע ❌', 'payload' => 'rsvp_no'],
                ],
            ],
            'reminder_pre_event' => [
                'title' => 'תזכורת קרובה לאירוע + ניווט וחניה', 'stage' => 'dayof', 'category' => 'utility',
                'header' => 'מחר חוגגים! ✨',
                'body' => "היי {{first_name}}, מחר זה קורה!\nמתרגשים לראות אותך ב{{event_title}}.\n⏰ קבלת פנים: {{event_time}}\n📍 {{venue_name}}, {{venue_address}}\n🚗 ניווט ישיר ב-Waze: {{waze_link}}\n\nנתראה ברחבה!",
                'footer' => 'SnapShare · עדכון אירוע',
                'buttons' => [['type' => 'url', 'text' => 'פרטי האירוע', 'link' => 'hub_link']],
            ],
            'table_assignment' => [
                'title' => 'יום האירוע — "השולחן שלך הוא..."', 'stage' => 'dayof', 'category' => 'utility',
                'header' => 'השולחן שלך מחכה לך 🪑',
                'body' => "היום חוגגים, {{first_name}}! 🥂\nמחכים לראות אותך הערב ב{{venue_name}} החל מ-{{event_time}}.\n\n🪑 השולחן שלך: {{table_label}} (עבור {{party_size}} אורחים)\n📸 העלאת תמונות לאלבום החי מהאירוע:\n{{album_link}}",
                'footer' => 'נוצר אוטומטית ממפת ההושבה של SnapShare',
                'buttons' => [['type' => 'url', 'text' => 'לאלבום החי', 'link' => 'album_link']],
            ],
            'table_page_link' => [
                'title' => 'יום האירוע — קישור "השולחן שלי" עם מפת האולם', 'stage' => 'dayof', 'category' => 'utility',
                'header' => 'איפה יושבים? 🪑',
                'body' => "היי {{first_name}}, מחכים לך הערב ב{{venue_name}} 🥂\nהשולחן שלך: {{table_label}}\nבקישור תמצאו את השולחן מסומן על מפת האולם:\n{{table_link}}",
                'footer' => 'נוצר אוטומטית ממפת ההושבה של SnapShare',
                'buttons' => [['type' => 'url', 'text' => 'השולחן שלי', 'link' => 'table_link']],
            ],
            'live_album_cta' => [
                'title' => 'קישור לאלבום החי + צילום במהלך הערב', 'stage' => 'dayof', 'category' => 'utility',
                'header' => 'האלבום החי פתוח 📸',
                'body' => "הרחבה בוערת! 🔥\nצילמתם תמונה או סרטון יפה של {{couple_names}}?\nהעלו ישר לאלבום החי של האירוע:\n{{album_link}}",
                'footer' => 'SnapShare Live Album',
                'buttons' => [['type' => 'url', 'text' => 'העלאת תמונות עכשיו', 'link' => 'album_link']],
            ],
            'gift_link' => [
                'title' => 'מתנה דיגיטלית (Bit / PayBox)', 'stage' => 'post', 'category' => 'marketing',
                'header' => 'אפשרות למתנה דיגיטלית 🎁',
                'body' => "היי {{first_name}},\nלנוחיות מי ששאל או לא יכול להגיע פיזית לשמוח איתנו ב{{event_title}}, מצורף קישור למתנה דיגיטלית:\n{{gift_link}}\nהעיקר הבריאות והשמחה ❤️",
                'footer' => 'להסרה השיבו הסר',
                'buttons' => [],
            ],
            'thank_you_gallery' => [
                'title' => 'הודעת תודה לאחר האירוע + קישור לגלריה', 'stage' => 'post', 'category' => 'utility',
                'header' => 'תודה ענקית שבאתם! 💛',
                'body' => "היי {{first_name}},\nתודה ענקית שבאת לחגוג ולשמח אותנו ב{{event_title}}! היה ערב בלתי נשכח.\nכל התמונות מהאירוע כבר מחכות לך באלבום:\n{{album_link}}",
                'footer' => 'באהבה, SnapShare',
                'buttons' => [['type' => 'url', 'text' => 'צפייה והורדת תמונות', 'link' => 'album_link']],
            ],
            'schedule_change' => [
                'title' => 'עדכון דחוף — שינוי שעה / מיקום', 'stage' => 'dayof', 'category' => 'utility',
                'header' => 'עדכון חשוב לגבי האירוע ℹ️',
                'body' => "היי {{first_name}},\nעדכון קצר וחשוב לגבי {{event_title}} ב-{{event_date}}:\nשעת קבלת הפנים עודכנה ל-{{event_time}} ב{{venue_name}}.\nלכל הפרטים וניווט: {{hub_link}}",
                'footer' => 'תודה על ההבנה!',
                'buttons' => [],
            ],
            'custom_manual' => [
                'title' => 'הודעה ידנית מותאמת אישית', 'stage' => 'manual', 'category' => 'marketing',
                'header' => null,
                'body' => "היי {{first_name}},\nרצינו לעדכן אותך ש...",
                'footer' => 'להסרה השיבו הסר',
                'buttons' => [],
                'custom' => true, // a body edit always needs Meta approval
            ],
        ];
    }

    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /** Default automations (PLAN §6 + mock). Disabled until the host turns them on. */
    public static function automations(): array
    {
        return [
            ['key' => 'save_the_date',     'template_key' => 'save_the_date',      'title' => 'Save the Date',                    'offset_days' => -60, 'send_time' => '18:00', 'segment' => ['statuses' => [0, 1, 3]]],
            ['key' => 'rsvp_invite',       'template_key' => 'rsvp_invite',        'title' => 'שליחת אישור הגעה + שאלון',        'offset_days' => -30, 'send_time' => '17:30', 'segment' => ['statuses' => [0]]],
            ['key' => 'rsvp_nudge',        'template_key' => 'rsvp_nudge',         'title' => 'תזכורת אוטומטית ללא-עונים',        'offset_days' => -14, 'send_time' => '18:30', 'segment' => ['statuses' => [0, 3]]],
            ['key' => 'reminder_pre_event', 'template_key' => 'reminder_pre_event', 'title' => 'תזכורת יום לפני + Waze וחניה',    'offset_days' => -1,  'send_time' => '10:00', 'segment' => ['statuses' => [1]]],
            ['key' => 'table_assignment',  'template_key' => 'table_assignment',   'title' => 'יום האירוע — מספר שולחן',          'offset_days' => 0,   'send_time' => '14:00', 'segment' => ['statuses' => [1], 'has_table' => true]],
            ['key' => 'live_album_cta',    'template_key' => 'live_album_cta',     'title' => 'העלאת תמונות לאלבום החי',          'offset_days' => 0,   'send_time' => '20:30', 'segment' => ['statuses' => [1]]],
            ['key' => 'thank_you_gallery', 'template_key' => 'thank_you_gallery',  'title' => 'תודה שבאתם + קישור לגלריה',        'offset_days' => 1,   'send_time' => '11:00', 'segment' => ['statuses' => [1]]],
        ];
    }

    /** Default RSVP bot / web questionnaire (PLAN §5 rsvp builder). */
    public static function defaultRsvpConfig(): array
    {
        return [
            'ask_party_size' => true,
            'max_party_size' => 10,
            'ask_meal' => true,
            'meals' => ['רגיל', 'צמחוני', 'טבעוני', 'ללא גלוטן', 'ילדים'],
            'ask_allergies' => true,
            'ask_shuttle' => false,
            'thank_you_yes' => 'תודה! שמרנו לך מקום 💛 נשלח תזכורת קרובה לאירוע.',
            'thank_you_no' => 'תודה שעדכנת, נתגעגע! 💛',
            'thank_you_maybe' => 'אין בעיה, נחזור אליך בהמשך 🙂',
        ];
    }
}
