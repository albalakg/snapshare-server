<?php

namespace App\Services\Enums;

use App\Services\Enums\BaseEnum;

class LogsEnum extends BaseEnum 
{
    const EVENT_SET_INACTIVE = 'Event marked as inactive';
    const EVENT_SET_ACTIVE = 'Event marked as active';
    const EVENT_WARNED = 'Event been warned';
    const EVENT_STARTED = 'Event started';
    const EVENT_STARTS_IN_5_DAYS = 'Event starts in 5 days';
    const EVENT_STARTS_IN_8_HOURS = 'Event starts in 8 hours';
    const EVENT_ENDED = 'Event ended';
    const EVENT_DISABLED = 'Event disabled';
    const FAILED_TO_DELETE_EVENT_ASSET = 'Failed to delete event asset';
    const FAILED_TO_DELETE_EVENT = 'Failed to delete event';
    const FILE_SERVICE_ERROR = 'File service failed';
    const MAX_PASSWORD_RESET_ATTEMPTS = 'Email have reached maximum forgot reset attempts';
    const FORGOT_PASSWORD_REQUEST = 'Submitted a forgot password request for user';
}
