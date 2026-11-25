<?php

namespace App\Http\Controllers;

use App\Exceptions\SeatingHttpException;
use App\Http\Controllers\Concerns\SeatingResponses;
use App\Http\Requests\BulkGuestCrmRequest;
use App\Http\Requests\DeleteEventGuestsRequest;
use App\Http\Requests\GuestGroupRequest;
use App\Http\Requests\ImportGuestRowsRequest;
use App\Http\Requests\SaveGuestCrmRequest;
use App\Services\Guests\GuestCrmService;
use Exception;
use Illuminate\Support\Facades\Auth;

class EventGuestCrmController extends Controller
{
    use SeatingResponses;

    private function service(): GuestCrmService
    {
        return new GuestCrmService();
    }

    public function index(int $event_id)
    {
        return $this->respond('Guests fetched', fn () => $this->service()->list($event_id, Auth::id()));
    }

    public function store(int $event_id, SaveGuestCrmRequest $request)
    {
        return $this->respond('Guest created', fn () => $this->service()->create($event_id, Auth::id(), $request->validated()), 201);
    }

    public function update(int $event_id, int $guest_id, SaveGuestCrmRequest $request)
    {
        return $this->respond('Guest updated', fn () => $this->service()->update($event_id, $guest_id, Auth::id(), $request->validated()));
    }

    public function destroy(int $event_id, int $guest_id)
    {
        return $this->respond('Guest deleted', fn () => $this->service()->deleteMany($event_id, [$guest_id], Auth::id()));
    }

    public function destroyMany(int $event_id, DeleteEventGuestsRequest $request)
    {
        return $this->respond('Guests deleted', fn () => $this->service()->deleteMany($event_id, $request->guestIds(), Auth::id()));
    }

    public function bulkUpdate(int $event_id, BulkGuestCrmRequest $request)
    {
        return $this->respond('Guests updated', fn () => $this->service()->bulkUpdate(
            $event_id,
            Auth::id(),
            $request->input('guest_ids'),
            (array) ($request->validated()['changes'] ?? [])
        ));
    }

    public function importPreview(int $event_id, ImportGuestRowsRequest $request)
    {
        return $this->respond('Import preview', fn () => $this->service()->previewImport(
            $event_id,
            Auth::id(),
            $request->input('rows'),
            (string) $request->input('on_duplicate', 'skip')
        ));
    }

    public function importCommit(int $event_id, ImportGuestRowsRequest $request)
    {
        return $this->respond('Guests imported', fn () => $this->service()->commitImport(
            $event_id,
            Auth::id(),
            $request->input('rows'),
            (string) $request->input('on_duplicate', 'skip')
        ));
    }

    public function export(int $event_id)
    {
        try {
            $csv = $this->service()->exportCsv($event_id, Auth::id());

            return response($csv, 200, [
                'Content-Type'        => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="guests-' . $event_id . '.csv"',
                'Cache-Control'       => 'no-store',
            ]);
        } catch (SeatingHttpException $ex) {
            return response()->json(['message' => $ex->getMessage(), 'status' => false], $ex->status());
        } catch (Exception $ex) {
            return $this->errorResponse($ex);
        }
    }

    public function groups(int $event_id)
    {
        return $this->respond('Groups fetched', function () use ($event_id) {
            $this->service()->assertAccess($event_id, Auth::id());

            return $this->service()->groups($event_id);
        });
    }

    public function storeGroup(int $event_id, GuestGroupRequest $request)
    {
        return $this->respond('Group created', fn () => $this->service()->createGroup($event_id, Auth::id(), $request->validated()), 201);
    }

    public function updateGroup(int $event_id, int $group_id, GuestGroupRequest $request)
    {
        return $this->respond('Group updated', fn () => $this->service()->updateGroup($event_id, $group_id, Auth::id(), $request->validated()));
    }

    public function destroyGroup(int $event_id, int $group_id)
    {
        return $this->respond('Group deleted', function () use ($event_id, $group_id) {
            $this->service()->deleteGroup($event_id, $group_id, Auth::id());

            return null;
        });
    }
}
