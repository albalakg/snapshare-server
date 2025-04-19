<?php

namespace App\Services\Enums;

use App\Mail\ApplicationErrorMail;
use App\Mail\AssetsReadyForDownloadMail;
use App\Mail\ContactConfirmationMail;
use App\Mail\EventDeactivateMail;
use App\Mail\EventFinishedMail;
use App\Mail\EventStartedMail;
use App\Mail\EventWarningBeforeDeactivationMail;
use App\Mail\ForgotPasswordMail;
use App\Mail\OrderConfirmedMail;
use App\Mail\OrderFailedMail;
use App\Mail\SubscriptionUpgradedMail;
use App\Mail\UserDeletedMail;
use App\Mail\UserSignupMail;
use App\Services\Enums\BaseEnum;

class MailEnum extends BaseEnum
{
    const USER_SIGNUP = UserSignupMail::class;
    const USER_DELETED = UserDeletedMail::class;
    const FORGOT_PASSWORD = ForgotPasswordMail::class;
    const ORDER_CONFIRMED = OrderConfirmedMail::class;
    const ORDER_FAILED = OrderFailedMail::class;
    const EVENT_STARTED = EventStartedMail::class;
    const EVENT_FINISHED = EventFinishedMail::class;
    const EVENT_WARNING_BEFORE_DEACTIVATION = EventWarningBeforeDeactivationMail::class;
    const EVENT_DISABLED = EventDeactivateMail::class;
    const ASSETS_READY_FOR_DOWNLOAD = AssetsReadyForDownloadMail::class;
    const SUBSCRIPTION_UPGRADED = SubscriptionUpgradedMail::class;
    const CONTACT_CONFIRMATION = ContactConfirmationMail::class;
    const APPLICATION_ERROR = ApplicationErrorMail::class;
}
