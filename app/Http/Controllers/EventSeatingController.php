<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\SeatingResponses;
use App\Http\Requests\SaveSeatingPlanRequest;
use App\Http\Requests\SeatingAiRunRequest;
use App\Http\Requests\SeatingConstraintRequest;
use App\Services\Seating\SeatingAiService;
use App\Services\Seating\SeatingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EventSeatingController extends Controller
{
    use SeatingResponses;

    private function seating(): SeatingService
    {
        return new SeatingService();
    }

    private function ai(): SeatingAiService
    {
        return new SeatingAiService($this->seating());
    }

    public function show(int $event_id)
    {
        return $this->respond('Seating fetched', fn () => $this->seating()->state($event_id, Auth::id()));
    }

    public function save(int $event_id, SaveSeatingPlanRequest $request)
    {
        return $this->respond('Seating saved', fn () => $this->seating()->save($event_id, Auth::id(), $request->validated()));
    }

    public function storeConstraint(int $event_id, SeatingConstraintRequest $request)
    {
        return $this->respond('Constraint created', fn () => $this->seating()->createConstraint($event_id, Auth::id(), $request->validated()), 201);
    }

    public function updateConstraint(int $event_id, int $constraint_id, SeatingConstraintRequest $request)
    {
        return $this->respond('Constraint updated', fn () => $this->seating()->updateConstraint($event_id, $constraint_id, Auth::id(), $request->validated()));
    }

    public function destroyConstraint(int $event_id, int $constraint_id)
    {
        return $this->respond('Constraint deleted', function () use ($event_id, $constraint_id) {
            $this->seating()->deleteConstraint($event_id, $constraint_id, Auth::id());

            return null;
        });
    }

    public function quota(int $event_id)
    {
        return $this->respond('Quota fetched', function () use ($event_id) {
            $this->seating()->crm()->assertAccess($event_id, Auth::id());

            return $this->ai()->quota($event_id);
        });
    }

    public function runs(int $event_id)
    {
        return $this->respond('Runs fetched', fn () => $this->ai()->runs($event_id, Auth::id()));
    }

    public function run(int $event_id, SeatingAiRunRequest $request)
    {
        return $this->respond('Proposal ready', fn () => $this->ai()->run($event_id, Auth::id(), $request->validated()), 201);
    }

    public function apply(int $event_id, int $run_id, Request $request)
    {
        return $this->respond('Proposal applied', fn () => $this->ai()->apply($event_id, $run_id, Auth::id(), $request->boolean('force')));
    }

    public function discard(int $event_id, int $run_id)
    {
        return $this->respond('Proposal discarded', fn () => $this->ai()->discard($event_id, $run_id, Auth::id()));
    }
}
