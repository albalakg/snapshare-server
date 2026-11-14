<?php

namespace App\Services\Trivia;

use Exception;
use Illuminate\Http\Response;

class TriviaIncompleteQuestionsException extends Exception
{
    /**
     * @param array<int, array{question_id:int|null, index:int, field:string, message?:string}> $errors
     */
    public function __construct(
        public readonly array $errors,
        ?string $message = null,
    ) {
        parent::__construct(
            $message ?? self::buildHebrewMessage($errors),
            Response::HTTP_UNPROCESSABLE_ENTITY
        );
    }

    /**
     * @param array<int, array{question_id:int|null, index:int, field:string, message?:string}> $errors
     */
    public static function buildHebrewMessage(array $errors): string
    {
        $by_index = [];
        foreach ($errors as $error) {
            $index = (int) ($error['index'] ?? 0);
            $reason = self::hebrewReasonForField((string) ($error['field'] ?? ''));
            if ($reason === null) {
                continue;
            }
            $by_index[$index] ??= [];
            if (!in_array($reason, $by_index[$index], true)) {
                $by_index[$index][] = $reason;
            }
        }

        ksort($by_index);

        $parts = [];
        foreach ($by_index as $index => $reasons) {
            $parts[] = 'שאלה ' . ($index + 1) . ' (' . implode(', ', $reasons) . ')';
        }

        if ($parts === []) {
            return 'יש להשלים את השאלות לפני שמתחילים';
        }

        return 'יש להשלים את השאלות הבאות לפני שמתחילים: ' . implode(', ', $parts);
    }

    private static function hebrewReasonForField(string $field): ?string
    {
        if ($field === 'prompt') {
            return 'חסר נוסח השאלה';
        }

        if (preg_match('/^options\.\d+\.label$/', $field) === 1) {
            return 'חסרה תווית אפשרות';
        }

        if ($field === 'options') {
            return 'חסרות אפשרויות או תשובה נכונה';
        }

        if ($field === 'config.correct') {
            return 'חסרה תשובה מספרית';
        }

        if ($field === 'config') {
            return 'טווח מספרים לא תקין';
        }

        return 'חסר פרט נדרש';
    }
}
