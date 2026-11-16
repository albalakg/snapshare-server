<?php

namespace App\Services\Orders;

use App\Models\DiscountCode;
use App\Services\Enums\MessagesEnum;
use App\Services\Enums\StatusEnum;
use Exception;
use Illuminate\Database\Eloquent\Collection;

class DiscountCodeService
{
    public function all(): Collection
    {
        return DiscountCode::orderByDesc('id')->get();
    }

    public function find(int $id): ?DiscountCode
    {
        return DiscountCode::find($id);
    }

    public function findActiveByCode(?string $code): ?DiscountCode
    {
        $normalized = $this->normalize($code);
        if ($normalized === '') {
            return null;
        }

        return DiscountCode::where('code', $normalized)
            ->where('status', StatusEnum::ACTIVE)
            ->first();
    }

    /**
     * @param array{code: string, percent: int, status: int} $data
     */
    public function create(array $data): DiscountCode
    {
        $code = $this->normalize($data['code']);
        if ($code === '' || DiscountCode::where('code', $code)->exists()) {
            throw new Exception(MessagesEnum::DISCOUNT_CODE_ALREADY_EXISTS);
        }

        $discount = new DiscountCode();
        $discount->code = $code;
        $discount->percent = (int) $data['percent'];
        $discount->status = (int) $data['status'];
        $discount->save();

        return $discount;
    }

    /**
     * @param array{percent?: int, status?: int} $data
     */
    public function update(int $id, array $data): DiscountCode
    {
        $discount = $this->find($id);
        if (!$discount) {
            throw new Exception(MessagesEnum::DISCOUNT_CODE_NOT_FOUND);
        }

        if (array_key_exists('percent', $data)) {
            $discount->percent = (int) $data['percent'];
        }

        if (array_key_exists('status', $data)) {
            $discount->status = (int) $data['status'];
        }

        $discount->save();

        return $discount;
    }

    public function normalize(?string $code): string
    {
        return strtoupper(trim((string) $code));
    }

    public function applyPercent(float $price, int $percent): float
    {
        return round($price * (100 - $percent) / 100, 2);
    }
}
