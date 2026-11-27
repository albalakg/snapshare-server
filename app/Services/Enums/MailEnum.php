<?php

namespace App\Services\Enums;

use App\Mail\ApplicationErrorMail;
use App\Mail\AssetsReadyForDownloadMail;
use App\Mail\ContactConfirmationMail;
use App\Mail\EventDeactivateMail;
use App\Mail\EventFinishedMail;
use App\Mail\EventStartedMail;
use App\Mail\EventPendingStartNotificationMail;
use App\Mail\EventStartNotificationFirstMail;
use App\Mail\EventStartNotificationSecondMail;
use App\Mail\EventWarningBeforeDeactivationMail;
use App\Mail\ForgotPasswordMail;
use App\Mail\NewSupportRequestAdminMail;
use App\Mail\NewUserSignupAdminMail;
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
    const EVENT_STARTS_IN_5_DAYS = EventStartNotificationFirstMail::class;
    const EVENT_STARTS_IN_1_DAY = EventStartNotificationSecondMail::class;
    const EVENT_PENDING_STARTS_IN_1_DAY = EventPendingStartNotificationMail::class;
    const EVENT_FINISHED = EventFinishedMail::class;
    const EVENT_WARNING_BEFORE_DEACTIVATION = EventWarningBeforeDeactivationMail::class;
    const EVENT_DISABLED = EventDeactivateMail::class;
    const ASSETS_READY_FOR_DOWNLOAD = AssetsReadyForDownloadMail::class;
    const SUBSCRIPTION_UPGRADED = SubscriptionUpgradedMail::class;
    const CONTACT_CONFIRMATION = ContactConfirmationMail::class;
    const APPLICATION_ERROR = ApplicationErrorMail::class;
    const NEW_USER_SIGNUP_ADMIN = NewUserSignupAdminMail::class;
    const NEW_SUPPORT_REQUEST_ADMIN = NewSupportRequestAdminMail::class;

    // v2 (t141u/t142u) — new emails, only sent when EMAILS_V2_ENABLED. See App\Services\Mail\MailCatalog.
    const WELCOME_GOOGLE = \App\Mail\V2\WelcomeGoogleMail::class;
    const PASSWORD_CHANGED = \App\Mail\V2\PasswordChangedMail::class;
    const FREE_ORDER_CONFIRMED = \App\Mail\V2\FreeOrderConfirmedMail::class;
    const SETUP_NUDGE = \App\Mail\V2\SetupNudgeMail::class;
    const HUB_PUBLISHED = \App\Mail\V2\HubPublishedMail::class;
    const SAVE_THE_DATE_READY = \App\Mail\V2\SaveTheDateReadyMail::class;
    const FIRST_UPLOADS = \App\Mail\V2\FirstUploadsMail::class;
    const ADDON_RECEIPT = \App\Mail\V2\AddonReceiptMail::class;
    const ADDON_FAILED = \App\Mail\V2\AddonFailedMail::class;
    const QUOTA_ALERT = \App\Mail\V2\QuotaAlertMail::class;
    const AI_RUNS_LOW = \App\Mail\V2\AiRunsLowMail::class;
    const SEATING_NUDGE = \App\Mail\V2\SeatingNudgeMail::class;
    const TABLE_LINKS_READY = \App\Mail\V2\TableLinksReadyMail::class;
    const WHATSAPP_READY = \App\Mail\V2\WhatsAppReadyMail::class;
    const TEMPLATE_REVIEWED = \App\Mail\V2\TemplateReviewedMail::class;
    const SEND_HEADS_UP = \App\Mail\V2\SendHeadsUpMail::class;
    const SEND_SUMMARY = \App\Mail\V2\SendSummaryMail::class;
    const RSVP_DIGEST = \App\Mail\V2\RsvpDigestMail::class;
    const REPLIES_REVIEW = \App\Mail\V2\RepliesReviewMail::class;
    const SEND_PROBLEM = \App\Mail\V2\SendProblemMail::class;
    const ADMIN_ADDON_PURCHASE = \App\Mail\V2\AdminAddonPurchaseMail::class;
    const ADMIN_PAYMENT_ALERT = \App\Mail\V2\AdminPaymentAlertMail::class;
    const ADMIN_PROVIDER_ALERT = \App\Mail\V2\AdminProviderAlertMail::class;
}
