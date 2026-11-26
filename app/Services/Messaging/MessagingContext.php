<?php

namespace App\Services\Messaging;

use App\Models\Event;
use App\Models\EventWhatsAppSetting;
use App\Services\Events\EventService;
use Exception;

/** Access + entitlement + settings helpers shared by the messaging services. */
class MessagingContext
{
    public static function enabled(): bool
    {
        return (bool) config('whatsapp_messaging.enabled', false);
    }

    public static function assertEnabled(): void
    {
        if (!self::enabled()) {
            throw new MessagingHttpException('WhatsApp messaging is not enabled', 403, ['enabled' => false]);
        }
    }

    public static function event(int $event_id, int $user_id): Event
    {
        self::assertEnabled();
        try {
            return (new EventService())->assertEventAccess($event_id, $user_id);
        } catch (MessagingHttpException $e) {
            throw $e;
        } catch (Exception $e) {
            throw new MessagingHttpException('Event not found', 404);
        }
    }

    public static function settings(int $event_id): EventWhatsAppSetting
    {
        return EventWhatsAppSetting::firstOrCreate(['event_id' => $event_id], [
            'quiet_hours_enabled' => true,
            'shabbat_guard_enabled' => true,
            'sms_fallback_enabled' => false,
            'rsvp_config' => TemplateCatalog::defaultRsvpConfig(),
            'variables' => [],
        ]);
    }

    public static function rsvpConfig(EventWhatsAppSetting $s): array
    {
        return array_merge(TemplateCatalog::defaultRsvpConfig(), (array) ($s->rsvp_config ?? []));
    }
}
