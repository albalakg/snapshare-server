<?php

namespace App\Services\Users;

use Exception;
use App\Models\User;
use Illuminate\Support\Carbon;
use App\Mail\ForgotPasswordMail;
use App\Services\Enums\LogsEnum;
use App\Services\Enums\MailEnum;
use App\Services\Enums\RoleEnum;
use App\Models\UserResetPassword;
use App\Services\Enums\StatusEnum;
use App\Services\Enums\MessagesEnum;
use App\Services\Helpers\LogService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Models\UserEmailConfirmation;
use App\Services\Events\EventService;
use App\Services\Helpers\MailService;
use App\Services\Orders\StoreService;
use App\Services\Helpers\TokenService;
use Illuminate\Database\Eloquent\Collection;

class UserService
{
    public function __construct(
        private ?MailService $mail_service = null,
        private ?EventService $event_service = null,
        private ?StoreService $order_service = null
    ) {}

    /**
     * @param User $user
     * @return ?User
     */
    public function getProfile(User $user): ?User
    {
        $user->event = $this->event_service->getEventByUser($user->id);
        $user->order = null;
        
        if($user->event) {
            $user->order = $this->order_service->find($user->event->order_id)
                ->only(['order_number', 'subscription', 'price', 'created_at']);
        }

        return $user;
    }

    /**
     * @param User $user
     * @return void
     */
    public function logout(User $user)
    {
        $token = $user->token();
        if ($token) {
            $token->revoke();
        }
    }

    /**
     * @param array $data
     * @return User
     */
    public function createUser(array $data): User
    {
        $new_user = new User();
        $new_user->role_id = $data['role_id'];
        $new_user->first_name = $data['first_name'];
        $new_user->last_name = $data['last_name'];
        $new_user->email = $data['email'];
        $new_user->password = $data['password'];
        $new_user->status = StatusEnum::ACTIVE;
        $new_user->save();

        return $new_user;
    }

    /**
     * @param array $data
     * @return User
     */
    public function signup(array $data): User
    {
        $new_user = new User();
        $new_user->role_id = RoleEnum::USER_ID;
        $new_user->first_name = $data['first_name'];
        $new_user->last_name = $data['last_name'];
        $new_user->email = $data['email'];
        $new_user->password = $data['password'];
        $new_user->status = StatusEnum::PENDING;
        $new_user->save();

        $this->sendEmailConfirmation($new_user);
        return $new_user;
    }

    /**
     * @param string $email
     * @param string $token
     * @return bool
     */
    public function confirmEmail(string $email, string $token): bool
    {
        $user_email_confirmation = UserEmailConfirmation::where('email', $email)
            ->where('token', $token)
            ->first();

        if (!$user_email_confirmation) {
            return false;
        }

        $user_email_confirmation->update(['verified_at' => now()]);
        $this->updateStatus(StatusEnum::ACTIVE, $user_email_confirmation->user_id);
        return true;
    }

    /**
     * @param string $email
     * @return void
     */
    public function forgotPassword(string $email): void
    {
        $user = User::where('email', $email)->first();
        if (!$user || !$user->isActive()) {
            return;
        }
        
        if (!$this->canResetPassword($email)) {
            LogService::init()->info(LogsEnum::MAX_PASSWORD_RESET_ATTEMPTS, ['user_id' => $user->id]);
            return;
        }
        
        $this->deactivateUsersResetPasswords($email);
        $forgot_password_request = UserResetPassword::create([
            'token'       => TokenService::generate(),
            'email'       => $email,
            'status'      => StatusEnum::PENDING,
            'created_at'  => now()
        ]);
        
        $forgot_password_request->user_name = $user->first_name;
        LogService::init()->info(LogsEnum::FORGOT_PASSWORD_REQUEST, ['user_id' => $user->id]);
        $this->mail_service->delay()->send($email, MailEnum::FORGOT_PASSWORD, [
            'first_name' => $user->first_name,
            'reset_url' => config('app.client_url') . "/reset-password?token={$forgot_password_request->token}",
        ]);
    }

    /**
     * @param string $token
     * @param string $password
     * @return void
     */
    public function resetPassword(string $token, string $password)
    {
        $reset_password_request = UserResetPassword::where('token', $token)
            ->where('status', StatusEnum::PENDING)
            ->where('created_at', '>=', Carbon::now()->subHour()->toDateTimeString())
            ->first();

        if (!$reset_password_request) {
            throw new Exception(MessagesEnum::RESET_PASSWORD_REQUEST_NOT_FOUND);
        }

        if (!$user = User::where('email', $reset_password_request->email)->first()) {
            throw new Exception(MessagesEnum::USER_NOT_FOUND);
        }

        if (!$user->isActive()) {
            throw new Exception(MessagesEnum::USER_NOT_ACTIVE);
        }

        $this->updatePassword($user, $password);

        $reset_password_request->update([
            'status' => StatusEnum::ACTIVE,
            'verified_at' => now()
        ]);
    }

    /**
     * @param int $status
     * @param int $user_id
     * @return bool
     */
    public function updateStatus(int $status, int $user_id): bool
    {
        if (!$user = $this->find($user_id)) {
            throw new Exception(MessagesEnum::USER_NOT_FOUND);
        }
        return $user->update(['status' => $status]);
    }

    /**
     * @param array $data
     * @param int $user_id
     * @return User
     */
    public function update(array $data, int $user_id): User
    {
        if (!$user = $this->find($user_id)) {
            throw new Exception(MessagesEnum::USER_NOT_FOUND);
        }

        $user->first_name = $data['first_name'];
        $user->last_name = $data['last_name'];
        $user->save();

        return $user;
    }

    /**
     * @param array $data
     * @param int $user_id
     * @return void
     */
    public function changePassword(array $data, int $user_id)
    {
        if (!$user = $this->find($user_id)) {
            throw new Exception(MessagesEnum::USER_NOT_FOUND);
        }

        $hasher = app('hash');
        if (!$hasher->check($data['current_password'], $user->password)) {
            throw new Exception(MessagesEnum::PASSWORD_INCORRECT);
        }

        $this->updatePassword($user, $data['new_password']);
    }

    /**
     * @return Collection
     */
    public function get(): Collection
    {
        return User::with('event')->get();
    }

    /**
     * @param int $user_id
     * @return ?User
     */
    public function find(int $user_id): ?User
    {
        return User::find($user_id);
    }

    /**
     * @param string $email
     * @return ?User
     */
    public function findByEmail(string $email): ?User
    {
        return User::where('email', $email)->first();
    }

    /**
     * @param int $user_id
     * @return void
     */
    public function delete(int $user_id)
    {
        $user = User::where('id', $user_id)
            ->with('events')
            ->first();

        if (!$user) {
            throw new Exception(MessagesEnum::USER_NOT_FOUND);
        }

        foreach ($user->events as $event) {
            try {
                $this->event_service->delete($event->id, $user_id);
            } catch (Exception $ex) {
                LogService::init()->error($ex, ['error' => LogsEnum::FAILED_TO_DELETE_EVENT]);
            }
        }

        $this->mail_service->send($user->email, MailEnum::USER_DELETED, data: [
            'first_name' => $user->first_name,
        ]);

        $user->update([
            'first_name' => '',
            'last_name' => '',
            'email' => '',
            'password' => '',
            'status' => StatusEnum::INACTIVE,
        ]);

        $user->delete();
    }

    /**
     * @param User $user
     * @return void
     */
    private function sendEmailConfirmation(User $user)
    {
        $token = TokenService::generate();
        $mail_data = [
            'verification_url' => config('app.client_url') . "/email-confirmation?email={$user->email}&token={$token}",
            'order_url' => config('app.client_url') . '/order',
            'first_name' => $user->first_name,
        ];

        $this->mail_service->send($user->email, MailEnum::USER_SIGNUP, $mail_data);
        $this->createEmailConfirmation($user, $token);
    }

    /**
     * @param User $user
     * @param string $token
     * @return UserEmailConfirmation
     */
    private function createEmailConfirmation(User $user, string $token): UserEmailConfirmation
    {
        $email_confirmation = new UserEmailConfirmation;
        $email_confirmation->user_id = $user->id;
        $email_confirmation->email = $user->email;
        $email_confirmation->token = $token;
        $email_confirmation->created_at = now();
        $email_confirmation->save();

        return $email_confirmation;
    }


    /**
     * @param string $current_password
     * @param string $new_password
     * @return bool
     */
    private function isNewPasswordMatchesOldPassword(string $current_password, string $new_password): bool
    {
        return Hash::check($new_password, $current_password);
    }

    /**
     * Check if user has requested to reset his password less then 3 times
     * in the last 24 hours 
     * 
     * @param string $email
     * @return bool
     */
    private function canResetPassword(string $email): bool
    {
        return UserResetPassword::where('email', $email)
            ->where('created_at', '>', Carbon::now()->subMinutes(1440))
            ->count() <= 3;
    }

    /**
     * @param string $email
     * @return int
     */
    private function deactivateUsersResetPasswords(string $email): int
    {
        return UserResetPassword::where('email', $email)
            ->where('status', StatusEnum::PENDING)
            ->update(['status' => StatusEnum::INACTIVE]);
    }

    /**
     * @param User $user
     * @param string $password
     * @return bool
     */
    private function updatePassword(User $user, string $password): bool
    {
        if ($this->isNewPasswordMatchesOldPassword($user->password, $password)) {
            throw new Exception(MessagesEnum::USER_NEW_PASSWORD_MATCH_OLD);
        }

        return $user->update(['password' => $password]);
    }
}
