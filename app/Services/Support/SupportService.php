<?php

namespace App\Services\Support;

use App\Services\Enums\StatusEnum;
use App\Services\Users\UserService;
use App\Services\Helpers\MailService;
use App\Models\SupportTicket;
use App\Services\Enums\MailEnum;
use App\Services\Enums\MessagesEnum;
use Exception;
use Illuminate\Database\Eloquent\Collection;

/**
 * Support Ticket Life Cycle:
 * Pending -> In Progress -> Closed
 * 
 * 1. Creates automatically with pending status while creating an order
 * 2. User moves the status for ready before the start date
 * 3. When the event starts the status turns to active via a job
 * 4. After 30 days the event becomes disabled and all assets are removed
 */
class SupportService
{
    public function __construct(
        private ?MailService $mail_service = null,
        private ?UserService $user_service = null,
    ) {}

    /**
     * @return Collection
     */
    public function get(): Collection
    {
        return SupportTicket::with('user')->get();
    }

    /**
     * @param int $id
     * @param int $user_id
     * @return ?SupportTicket
     */
    public function find(int $id): ?SupportTicket
    {
        $support_ticket = SupportTicket::with('assets')->first($id);

        if (!$support_ticket) {
            return null;
        }

        return $support_ticket;
    }

    /**
     * @param array $data
     * @return ?SupportTicket
     */
    public function create(array $data): ?SupportTicket
    {
        $support_ticket = new SupportTicket;
        $support_ticket->email = $data['email'];
        $support_ticket->full_name = $data['full_name'];
        $support_ticket->text = $data['text'];
        $support_ticket->status = StatusEnum::PENDING;
        $support_ticket->save();

        $this->mail_service->delay()->send($support_ticket->email, MailEnum::CONTACT_CONFIRMATION, [
            'first_name' => $support_ticket->full_name,
        ]);
        return $support_ticket;
    }
    
    /**
     * @param array $data
     * @return bool
     */
    public function setInProgress(array $data): bool
    {
        $support_ticket = SupportTicket::where('id', $data['support_ticket_id'])->first();
        if(!$support_ticket) {
            throw new Exception(MessagesEnum::SUPPORT_TICKET_NOT_FOUND);
        }

        return $this->updateStatus($support_ticket, StatusEnum::IN_PROGRESS);
    }
    
    /**
     * @param array $data
     * @return bool
     */
    public function closeTicket(array $data): bool
    {
        $support_ticket = SupportTicket::where('id', $data['support_ticket_id'])->first();
        if(!$support_ticket) {
            throw new Exception(MessagesEnum::SUPPORT_TICKET_NOT_FOUND);
        }

        return $this->updateStatus($support_ticket, StatusEnum::CLOSED);
    }

    /**
     * @param int $id
     * @return int
     */
    public function delete(int $id): int
    {
        return SupportTicket::where('id', $id)->delete();
    }

    /**
     * @param int $user_id
     * @return int
     */
    public function deleteByUser(int $user_id): int
    {
        return SupportTicket::where('user_id', $user_id)->delete();
    }

    /**
     * @param string $email
     * @return int
     */
    public function deleteByEmail(string $email): int
    {
        return SupportTicket::where('email', $email)->delete();
    }
    
    /**
     * @param SupportTicket $support_ticket
     * @param int $status
     * @return bool
     */
    private function updateStatus(SupportTicket $support_ticket, int $status): bool
    {
        return $support_ticket->update(['status' => $status]);
    }
}
