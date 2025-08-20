<?php

namespace App\Services\ActionGates;

use App\Models\Event;
use App\Services\Enums\MessagesEnum;
use App\Services\Enums\StatusEnum;

class EventActionsGate {
    static function canUpdateEventStatus(Event $event, int $status): bool 
    {
        if($status === StatusEnum::READY) {
            if(!$event->name || !$event->starts_at || !$event->isPending()) {
                throw new Exception(MessagesEnum::EVENT_READY_MISSING_INFO);
            }
        }

        return true;
    }
}