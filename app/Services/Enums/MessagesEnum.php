<?php

namespace App\Services\Enums;

class MessagesEnum extends BaseEnum
{
    // Errors
    const INVALID_CREDENTIALS = 'Email or password is invalid';

    const PASSWORD_INCORRECT = 'Password is incorrect';

    const USER_LOGIN_UNAUTHORIZED = 'Unauthorized to login';

    const MAINTENANCE_VALIDATION = 'Sorry, Unauthorized to login during maintenance mode';

    const ORDER_ALREADY_IN_PROGRESS = 'Order already in progress';

    const USER_NOT_FOUND = 'User not found';

    const USER_NOT_ACTIVE = 'User not active';

    const SUPPORT_TICKET_NOT_FOUND = 'Support Ticket not active';

    const USER_NEW_PASSWORD_MATCH_OLD = 'Can\'t update new password that matches the old password';

    const ORDER_NOT_FOUND = 'Order not found';

    const SUBSCRIPTION_NOT_FOUND = 'Subscription not found';

    const EVENT_NOT_FOUND = 'Event not found';

    const EVENTS_ASSETS_NOT_FOUND = 'Event Assets not found';

    const EVENT_NOT_AUTHORIZED = 'Not authorized to modify this event';

    const EVENT_READY_MISSING_INFO = 'Can\'t set event to ready without name and start date';

    const FAILED_TO_START_DOWNLOAD_PROCESS = 'Failed to start the download process for all the requested files';

    const USER_NOT_AUTHORIZED_TO_DELETE = 'Not authorized to delete this user';

    const RESET_PASSWORD_REQUEST_NOT_FOUND = 'Reset Password request not found';

    const GOOGLE_SIGNIN_REQUIRED = 'This account uses Google sign-in. Please sign in with Google.';

    const GOOGLE_AUTH_INVALID_REDIRECT = 'Invalid redirect URL';

    const GOOGLE_AUTH_STATE_INVALID = 'Invalid or expired OAuth state';

    const GOOGLE_AUTH_CODE_INVALID = 'Invalid or expired authorization code';

    const GOOGLE_ACCOUNT_CONFLICT = 'This email is linked to a different Google account';

    const DOWNLOAD_EVENT_ASSET_START_FAILED = 'Failed to prepare event assets for download';

    const FAILED_TO_DELETE_EVENT_ASSETS_FOLDER = 'Failed to delete event\'s assets folder';

    const ORDER_CALLBACK_PAYLOAD_INVALID = 'Order callback payload is invalid';

    const ORDER_IN_INVALID_STATUS_WHILE_SETTING_TO_IN_PROGRESS = 'Order isn\'t in "in_progress" status while receiving the order confirmation';

    const EVENT_CONFIG_NOT_FOUND = 'Event config not found';

    const EVENT_VIDEO_UPLOAD_DISABLED = 'Video uploads are not enabled for this event';

    const WHATSAPP_GUEST_NOT_FOUND = 'Guest not found';

    const WHATSAPP_GUEST_PHONE_EXISTS = 'A guest with this phone number already exists for this event';

    const GUEST_EMAIL_EXISTS = 'A guest with this email address already exists for this event';

    const WHATSAPP_GUEST_IMPORT_FAILED = 'Failed to read the import file';

    const WHATSAPP_GUEST_IMPORT_INVALID_HEADERS = 'CSV must include שם מלא and טלפון columns';

    const WHATSAPP_SEND_LIMIT_REACHED = 'You have reached the maximum of 3 WhatsApp messages for this event';

    const WHATSAPP_NO_GUESTS = 'No guests selected for this message';

    const WHATSAPP_CAMPAIGN_NOT_FOUND = 'WhatsApp campaign not found';

    const WHATSAPP_CAMPAIGN_NOT_CANCELLABLE = 'Only pending scheduled campaigns can be cancelled';
    
    const PAYMENT_TRANSACTION_FAILED = 'Payment transaction failed';

    // Info
    const LOGIN_SUCCESS = 'Logged in successfully';

    const LOGOUT_SUCCESS = 'Logged out successfully';

    const SIGNUP_SUCCESS = 'You have Signed Up Successfully';

    const RESET_PASSWORD = 'You have reset your password';

    const FORGOT_PASSWORD = 'An email has been sent to the requested address';

    const CONFIRM_EMAIL = 'You have verified your email successfully';

    const SUPPORT_TICKET_CREATED_SUCCESS = 'Support Ticket created successfully';

    const ORDER_FOUND_SUCCESS = 'Order fetched successfully';

    const ORDER_CREATED_SUCCESS = 'Order created successfully';

    const ORDER_UPDATED_SUCCESS = 'Order updated successfully';

    const ORDER_DELETED_SUCCESS = 'Order deleted successfully';

    const DELETED_EVENT_ASSET_SUCCESS = 'Deleted event asset successfully';

    const BLOCKED_EVENT_ASSET_SUCCESS = 'Blocked event asset successfully';

    const UNBLOCKED_EVENT_ASSET_SUCCESS = 'Unblocked event asset successfully';

    const DOWNLOAD_EVENT_ASSET_START_SUCCESS = 'Started preparing event assets for download successfully';

    const EVENT_FOUND_SUCCESS = 'Event fetched successfully';

    const EVENT_CREATED_SUCCESS = 'Event created successfully';

    const EVENT_UPDATED_SUCCESS = 'Event updated successfully';

    const EVENT_DELETED_SUCCESS = 'Event deleted successfully';

    const EVENT_FILE_UPLOADED_SUCCESS = 'Event file uploaded successfully';

    const EVENT_DOWNLOAD_PROCESS = 'Event Download Process';

    const USER_CREATED_SUCCESS = 'User created successfully';

    const USER_UPDATED_SUCCESS = 'User updated successfully';

    const USER_UPDATED_PASSWORD_SUCCESS = 'User updated password successfully';

    const USER_DELETED_SUCCESS = 'User deleted successfully';

    const USERS_FETCHED_SUCCESS = 'Users fetched successfully';

    const USER_FOUND_SUCCESS = 'User found successfully';

    const EVENT_GALLERY_SETTINGS_UPDATED_SUCCESS = 'Event gallery settings updated successfully';

    const EVENT_QR_CARD_SETTINGS_UPDATED_SUCCESS = 'QR card settings updated successfully';

    const SUBSCRIPTIONS_FETCHED_SUCCESS = 'Subscriptions fetched successfully';

    const WHATSAPP_MESSAGES_QUEUED = 'WhatsApp messages queued successfully';

    const WHATSAPP_GUESTS_FETCHED = 'WhatsApp guests fetched successfully';

    const WHATSAPP_GUEST_CREATED = 'WhatsApp guest created successfully';

    const WHATSAPP_GUEST_UPDATED = 'WhatsApp guest updated successfully';

    const WHATSAPP_GUEST_DELETED = 'WhatsApp guest deleted successfully';

    const WHATSAPP_GUESTS_DELETED = 'WhatsApp guests deleted successfully';

    const WHATSAPP_GUESTS_IMPORTED = 'WhatsApp guests imported successfully';

    const WHATSAPP_QUOTA_FETCHED = 'WhatsApp send quota fetched successfully';

    const WHATSAPP_CAMPAIGNS_FETCHED = 'WhatsApp campaigns fetched successfully';

    const WHATSAPP_CAMPAIGN_FOUND = 'WhatsApp campaign fetched successfully';

    const WHATSAPP_CAMPAIGN_QUEUED = 'WhatsApp campaign queued successfully';

    const WHATSAPP_CAMPAIGN_SCHEDULED = 'WhatsApp campaign scheduled successfully';

    const WHATSAPP_CAMPAIGN_CANCELLED = 'WhatsApp campaign cancelled successfully';

    // Validations
    const INVALID_PASSWORD = 'Password is required and must be minimum 8 characters, at least one lowercase letter, uppercase letter and one number';
}
