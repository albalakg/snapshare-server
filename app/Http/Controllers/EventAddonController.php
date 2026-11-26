<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\SeatingResponses;
use App\Services\Addons\AddonPurchaseService;
use App\Services\Helpers\LogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EventAddonController extends Controller
{
    use SeatingResponses;

    private function svc(): AddonPurchaseService
    {
        return new AddonPurchaseService();
    }

    public function catalog(int $event_id)
    {
        return $this->respond('Add-ons fetched', fn () => $this->svc()->catalog($event_id, Auth::id()));
    }

    public function checkout(Request $request, int $event_id)
    {
        $data = $request->validate([
            'product'  => ['required', 'string', 'max:32'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:10'],
        ]);

        return $this->respond('Checkout created', fn () => $this->svc()->checkout($event_id, Auth::id(), $data['product'], (int) ($data['quantity'] ?? 1)), 201);
    }

    public function show(int $event_id, string $uid)
    {
        return $this->respond('Purchase fetched', fn () => $this->svc()->show($event_id, Auth::id(), $uid));
    }

    public function showByUid(string $uid)
    {
        return $this->respond('Purchase fetched', fn () => $this->svc()->showForUser(Auth::id(), $uid));
    }

    public function devComplete(Request $request, int $event_id, string $uid)
    {
        $data = $request->validate(['outcome' => ['nullable', 'in:success,failure,cancel']]);

        return $this->respond('Mock payment completed', fn () => $this->svc()->devComplete($event_id, Auth::id(), $uid, $data['outcome'] ?? 'success'));
    }

    /** PayPlus server-to-server callback for add-on purchases (same payload shape as plan orders). */
    public function callback(Request $request)
    {
        (new LogService('payment'))->info('Addon callback received', ['page_request_uid' => $request->input('transaction.payment_page_request_uid') ?? $request->input('page_request_uid')]);
        $data = [
            'page_request_uid' => $request->input('transaction.payment_page_request_uid') ?? $request->input('page_request_uid'),
            'approval_number'  => data_get($request->all(), 'transaction.approval_number') ?? $request->input('approval_number'),
            'browser'          => $request->input('browser') ?? $request->userAgent(),
            'hash'             => $request->input('hash') ?? $request->header('hash'),
        ];

        return $this->respond('Add-on payment confirmed', fn () => $this->svc()->confirmCallback($data, (string) $request->getContent()));
    }
}
