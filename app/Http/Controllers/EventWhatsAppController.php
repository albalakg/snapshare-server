<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateWhatsAppCampaignRequest;
use App\Http\Requests\DeleteEventGuestsRequest;
use App\Http\Requests\ImportEventGuestsRequest;
use App\Http\Requests\StoreEventGuestRequest;
use App\Http\Requests\UpdateEventGuestRequest;
use App\Services\Enums\MessagesEnum;
use App\Services\Enums\WhatsAppSendModeEnum;
use App\Services\Guests\EventGuestService;
use App\Services\WhatsApp\WhatsAppCampaignService;
use Exception;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class EventWhatsAppController extends Controller
{
    public function listGuests(int $event_id)
    {
        try {
            $service = new EventGuestService();
            $response = $service->list($event_id, Auth::user()->id);

            return $this->successResponse(MessagesEnum::WHATSAPP_GUESTS_FETCHED, $response);
        } catch (Exception $ex) {
            return $this->errorResponse($ex);
        }
    }

    public function storeGuest(int $event_id, StoreEventGuestRequest $request)
    {
        try {
            $service = new EventGuestService();
            $response = $service->create($event_id, Auth::user()->id, $request->validated());

            return $this->successResponse(MessagesEnum::WHATSAPP_GUEST_CREATED, $response, Response::HTTP_CREATED);
        } catch (Exception $ex) {
            return $this->errorResponse($ex);
        }
    }

    public function updateGuest(int $event_id, int $guest_id, UpdateEventGuestRequest $request)
    {
        try {
            $service = new EventGuestService();
            $response = $service->update($event_id, $guest_id, Auth::user()->id, $request->validated());

            return $this->successResponse(MessagesEnum::WHATSAPP_GUEST_UPDATED, $response);
        } catch (Exception $ex) {
            return $this->errorResponse($ex);
        }
    }

    public function deleteGuest(int $event_id, int $guest_id)
    {
        try {
            $service = new EventGuestService();
            $service->delete($event_id, $guest_id, Auth::user()->id);

            return $this->successResponse(MessagesEnum::WHATSAPP_GUEST_DELETED);
        } catch (Exception $ex) {
            return $this->errorResponse($ex);
        }
    }

    public function deleteGuests(int $event_id, DeleteEventGuestsRequest $request)
    {
        try {
            $service = new EventGuestService();
            $response = $service->deleteMany($event_id, $request->guestIds(), Auth::user()->id);

            return $this->successResponse(MessagesEnum::WHATSAPP_GUESTS_DELETED, $response);
        } catch (Exception $ex) {
            return $this->errorResponse($ex);
        }
    }

    public function importGuests(int $event_id, ImportEventGuestsRequest $request)
    {
        try {
            $service = new EventGuestService();
            $response = $service->importFromCsv($event_id, Auth::user()->id, $request->file('file'));

            return $this->successResponse(MessagesEnum::WHATSAPP_GUESTS_IMPORTED, $response);
        } catch (Exception $ex) {
            return $this->errorResponse($ex);
        }
    }

    public function importTemplate(int $event_id)
    {
        try {
            $service = new EventGuestService();
            $service->list($event_id, Auth::user()->id);

            return response($service->getTemplateCsv(), Response::HTTP_OK, [
                'Content-Type'        => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="whatsapp-guests-template.csv"',
            ]);
        } catch (Exception $ex) {
            return $this->errorResponse($ex);
        }
    }

    public function quota(int $event_id)
    {
        try {
            $service = new WhatsAppCampaignService();
            $response = $service->quota($event_id, Auth::user()->id);

            return $this->successResponse(MessagesEnum::WHATSAPP_QUOTA_FETCHED, $response);
        } catch (Exception $ex) {
            return $this->errorResponse($ex);
        }
    }

    public function listCampaigns(int $event_id)
    {
        try {
            $service = new WhatsAppCampaignService();
            $response = $service->list($event_id, Auth::user()->id);

            return $this->successResponse(MessagesEnum::WHATSAPP_CAMPAIGNS_FETCHED, $response);
        } catch (Exception $ex) {
            return $this->errorResponse($ex);
        }
    }

    public function showCampaign(int $event_id, int $campaign_id)
    {
        try {
            $service = new WhatsAppCampaignService();
            $response = $service->find($event_id, $campaign_id, Auth::user()->id);

            return $this->successResponse(MessagesEnum::WHATSAPP_CAMPAIGN_FOUND, $response);
        } catch (Exception $ex) {
            return $this->errorResponse($ex);
        }
    }

    public function storeCampaign(int $event_id, CreateWhatsAppCampaignRequest $request)
    {
        try {
            $service = new WhatsAppCampaignService();
            $response = $service->create($event_id, Auth::user()->id, $request->validated());

            $message = $request->input('send_mode') === WhatsAppSendModeEnum::SCHEDULED
                ? MessagesEnum::WHATSAPP_CAMPAIGN_SCHEDULED
                : MessagesEnum::WHATSAPP_CAMPAIGN_QUEUED;

            return $this->successResponse($message, $response, Response::HTTP_ACCEPTED);
        } catch (Exception $ex) {
            return $this->errorResponse($ex);
        }
    }

    public function cancelCampaign(int $event_id, int $campaign_id)
    {
        try {
            $service = new WhatsAppCampaignService();
            $response = $service->cancel($event_id, $campaign_id, Auth::user()->id);

            return $this->successResponse(MessagesEnum::WHATSAPP_CAMPAIGN_CANCELLED, $response);
        } catch (Exception $ex) {
            return $this->errorResponse($ex);
        }
    }
}
