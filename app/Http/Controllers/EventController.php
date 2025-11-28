<?php

namespace App\Http\Controllers;

use Exception;
use App\Services\Enums\StatusEnum;
use App\Services\Users\UserService;
use App\Services\Enums\MessagesEnum;
use App\Services\Helpers\LogService;
use Illuminate\Support\Facades\Auth;
use App\Services\Events\EventService;
use App\Services\Helpers\MailService;
use App\Services\Orders\StoreService;
use App\Http\Requests\UploadFileRequest;
use App\Http\Requests\CreateEventRequest;
use App\Http\Requests\UpdateEventRequest;
use App\Http\Requests\DeleteEventAssetsRequest;

class EventController extends Controller
{
    public function find(int $event_id)
    {
        try {
            $event_service = new EventService();
            $response = $event_service->find($event_id);
            return $this->successResponse(MessagesEnum::EVENT_FOUND_SUCCESS, $response);
        } catch (Exception $ex) {
            return $this->errorResponse($ex);
        }
    }

    public function uploadFile(int $event_id, UploadFileRequest $request)
    {
        try {
            $event_service = new EventService(
                new UserService(),
                new StoreService()
            );
            $response = $event_service->uploadFile($event_id, $request);
            return $this->successResponse(MessagesEnum::EVENT_FILE_UPLOADED_SUCCESS, $response);

        } catch (Exception $ex) {
            return $this->errorResponse($ex);
        }
    }

    public function authenticatedUploadFile(int $event_id, UploadFileRequest $request)
    {
        try {
            $event_service = new EventService(
                new UserService(),
                new StoreService()
            );
            $response = $event_service->uploadFile($event_id, $request, Auth::user()->id);
            return $this->successResponse(MessagesEnum::EVENT_FILE_UPLOADED_SUCCESS, $response);

        } catch (Exception $ex) {
            return $this->errorResponse($ex);
        }
    }

    public function createByAdmin(CreateEventRequest $request)
    {
        try {
            $event_service = new EventService();
            $response = $event_service->createByAdmin($request->validated());
            return $this->successResponse(MessagesEnum::EVENT_CREATED_SUCCESS, $response);

        } catch (Exception $ex) {
            return $this->errorResponse($ex);
        }
    }

    public function assets(int $event_id)
    {
        try {
            $event_service = new EventService(new UserService());
            $response = $event_service->getEventAssets($event_id, Auth::user()->id);
            return $this->successResponse(MessagesEnum::EVENT_FOUND_SUCCESS, $response);
        } catch (Exception $ex) {
            return $this->errorResponse($ex);
        }
    }

    public function galleryAssets(int $event_id)
    {
        try {
            $event_service = new EventService(new UserService());
            $response = $event_service->getEventAssetsForGallery($event_id, Auth::user()->id);
            return $this->successResponse(MessagesEnum::EVENT_FOUND_SUCCESS, $response);
        } catch (Exception $ex) {
            return $this->errorResponse($ex);
        }
    }

    public function deleteAssets(int $event_id, DeleteEventAssetsRequest $request)
    {
        try {
            $event_service = new EventService(new UserService());
            $response = $event_service->deleteEventAssets($event_id, $request->validated(), Auth::user()->id);
            return $this->successResponse(MessagesEnum::DELETED_EVENT_ASSET_SUCCESS, $response);
        } catch (Exception $ex) {
            return $this->errorResponse($ex);
        }
    }

    public function downloadAssets(int $event_id, DeleteEventAssetsRequest $request)
    {
        try {
            $event_service = new EventService(new UserService(), mail_service: new MailService());
            $response = $event_service->downloadEventAssets($event_id, $request->validated(), Auth::user()->id);
            return $this->successResponse(MessagesEnum::DOWNLOAD_EVENT_ASSET_START_SUCCESS, $response);
        } catch (Exception $ex) {
            return $this->errorResponse($ex);
        }
    }

    public function getDownloadStatus(int $event_id)
    {
        try {
            $event_service = new EventService(new UserService());
            $response = $event_service->getActiveDownloadProcess($event_id, Auth::user()->id);
            return $this->successResponse(MessagesEnum::EVENT_FOUND_SUCCESS, $response);
        } catch (Exception $ex) {
            return $this->errorResponse($ex);
        }
    }

    public function getBaseInfo(string $event_path)
    {
        try {
            $event_service = new EventService();
            $response = $event_service->getBaseInfo($event_path);
            return $this->successResponse(MessagesEnum::EVENT_FOUND_SUCCESS, $response);
        } catch (Exception $ex) {
            return $this->errorResponse($ex);
        }
    }

    public function getBaseGallery(string $event_path)
    {
        try {
            $event_service = new EventService();
            $response = $event_service->getBaseGallery($event_path);
            return $this->successResponse(MessagesEnum::EVENT_FOUND_SUCCESS, $response);
        } catch (Exception $ex) {
            return $this->errorResponse($ex);
        }
    }

    public function update(int $event_id, UpdateEventRequest $request)
    {
        try {
            $event_service = new EventService(
                new UserService()
            );
            $response = $event_service->update($event_id, $request->validated(), Auth::user()->id);
            return $this->successResponse(MessagesEnum::EVENT_UPDATED_SUCCESS, $response);
        } catch (Exception $ex) {
            return $this->errorResponse($ex);
        }
    }

    public function ready(int $event_id)
    {
        try {
            $event_service = new EventService();
            $response = $event_service->updateStatus(StatusEnum::READY, $event_id);
            return $this->successResponse(MessagesEnum::EVENT_UPDATED_SUCCESS, $response);
        } catch (Exception $ex) {
            return $this->errorResponse($ex);
        }
    }

    public function pending(int $event_id)
    {
        try {
            $event_service = new EventService();
            $response = $event_service->updateStatus(StatusEnum::PENDING, $event_id);
            return $this->successResponse(MessagesEnum::EVENT_UPDATED_SUCCESS, $response);
        } catch (Exception $ex) {
            return $this->errorResponse($ex);
        }
    }

    public function delete(int $event_id)
    {
        try {
            $event_service = new EventService(
                new UserService()
            );
            $event_service->delete($event_id, Auth::user()->id);
            return response()->json(['message' => MessagesEnum::EVENT_DELETED_SUCCESS]);
        } catch (Exception $ex) {
            return $this->errorResponse($ex);
        }
    }
}
