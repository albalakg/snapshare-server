<?php

namespace App\Services\Enums;

use App\Services\Enums\BaseEnum;

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
    const FAILED_TO_START_DOWNLOAD_PROCESS = 'Failed to start the download process for all the requested files';
    const USER_NOT_AUTHORIZED_TO_DELETE = 'Not authorized to delete this user';
    const RESET_PASSWORD_REQUEST_NOT_FOUND = 'Reset Password request not found';
    const DOWNLOAD_EVENT_ASSET_START_FAILED = 'Failed to prepare event assets for download';
    const FAILED_TO_DELETE_EVENT_ASSETS_FOLDER = 'Failed to delete event\'s assets folder';
    const ORDER_CALLBACK_PAYLOAD_INVALID = 'Order callback payload is invalid';
    const ORDER_IN_INVALID_STATUS_WHILE_SETTING_TO_IN_PROGRESS = 'Order isn\'t in "in_progress" status while receiving the order confirmation';

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


    // Validations
    const INVALID_PASSWORD = 'Password is required and must be minimum 8 characters, at least one lowercase letter, uppercase letter and one number';
}
