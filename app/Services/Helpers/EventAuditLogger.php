<?php

namespace App\Services\Helpers;

use Illuminate\Support\Facades\Auth;
use Throwable;

class EventAuditLogger
{
    public const CHANNEL = 'event';

    public static function success(string $action, ?int $event_id, array $context = []): void
    {
        self::write($action, 'success', $event_id, $context);
    }

    public static function failure(string $action, ?int $event_id, array $context = []): void
    {
        self::write($action, 'failure', $event_id, $context);
    }

    private static function write(string $action, string $result, ?int $event_id, array $context = []): void
    {
        if (array_key_exists('user_id', $context)) {
            $userId = $context['user_id'] ?? 'GUEST';
        } else {
            $userId = Auth::id() ?? 'Worker';
        }

        $parts = [
            'EVENT_AUDIT',
            "action={$action}",
            "result={$result}",
            'event_id=' . ($event_id ?? 'n/a'),
            "user_id={$userId}",
        ];

        foreach ($context as $key => $value) {
            if ($key === 'user_id') {
                continue;
            }
            if ($value instanceof Throwable) {
                $value = $value->getMessage();
            } elseif (is_array($value) || is_object($value)) {
                $value = json_encode($value);
            } elseif (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            } elseif ($value === null) {
                $value = 'null';
            }
            $parts[] = "{$key}={$value}";
        }

        $logger = new LogService(self::CHANNEL);
        $logContext = array_merge($context, [
            'action' => $action,
            'result' => $result,
            'event_id' => $event_id,
            'user_id' => $userId,
        ]);

        $message = implode(' | ', $parts);
        if ($result === 'failure') {
            $logger->error($message, $logContext);
            return;
        }

        $logger->info($message, $logContext);
    }
}
