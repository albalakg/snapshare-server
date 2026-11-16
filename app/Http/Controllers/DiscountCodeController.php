<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDiscountCodeRequest;
use App\Http\Requests\UpdateDiscountCodeRequest;
use App\Services\Enums\MessagesEnum;
use App\Services\Orders\DiscountCodeService;
use Exception;

class DiscountCodeController extends Controller
{
    public function index(DiscountCodeService $discount_code_service)
    {
        try {
            return $this->successResponse(
                MessagesEnum::DISCOUNT_CODES_FETCHED_SUCCESS,
                $discount_code_service->all()
            );
        } catch (Exception $ex) {
            return $this->errorResponse($ex);
        }
    }

    public function store(StoreDiscountCodeRequest $request, DiscountCodeService $discount_code_service)
    {
        try {
            $discount = $discount_code_service->create($request->validated());

            return $this->successResponse(
                MessagesEnum::DISCOUNT_CODE_CREATED_SUCCESS,
                $discount
            );
        } catch (Exception $ex) {
            return $this->errorResponse($ex);
        }
    }

    public function update(UpdateDiscountCodeRequest $request, int $id, DiscountCodeService $discount_code_service)
    {
        try {
            $discount = $discount_code_service->update($id, $request->validated());

            return $this->successResponse(
                MessagesEnum::DISCOUNT_CODE_UPDATED_SUCCESS,
                $discount
            );
        } catch (Exception $ex) {
            return $this->errorResponse($ex);
        }
    }
}
