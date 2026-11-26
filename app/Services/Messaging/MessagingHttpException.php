<?php

namespace App\Services\Messaging;

use App\Exceptions\SeatingHttpException;

/** Domain error with an HTTP status (reuses the guest CRM/seating error envelope). */
class MessagingHttpException extends SeatingHttpException
{
}
