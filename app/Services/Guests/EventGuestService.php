<?php

namespace App\Services\Guests;

use App\Models\EventGuest;
use App\Services\Enums\EventGuestStatusEnum;
use App\Services\Enums\MessagesEnum;
use App\Services\Events\EventService;
use App\Services\Users\UserService;
use Exception;
use Illuminate\Http\UploadedFile;

class EventGuestService
{
    private const MAX_IMPORT_ROWS = 5000;

    private const HEADER_ALIASES = [
        'full_name' => ['full_name', 'fullname', 'name', 'full name', 'שם מלא', 'שם'],
        'phone'     => ['phone', 'phone_number', 'phone number', 'mobile', 'mobile_number', 'mobile number', 'טלפון', 'מספר טלפון', 'נייד'],
    ];

    private const RTL_MARK = "\u{200F}";

    public function __construct(
        private ?EventService $event_service = null,
    ) {
        $this->event_service ??= new EventService(new UserService());
    }

    public function list(int $event_id, int $user_id): array
    {
        $this->event_service->assertEventAccess($event_id, $user_id);

        $guests = EventGuest::where('event_id', $event_id)
            ->orderBy('id')
            ->get()
            ->map(fn (EventGuest $guest) => $this->formatGuest($guest));

        return [
            'guests' => $guests,
            'total'  => $guests->count(),
        ];
    }

    public function create(int $event_id, int $user_id, array $data): array
    {
        $this->event_service->assertEventAccess($event_id, $user_id);

        $phone = PhoneNormalizer::validate($data['phone']);
        $phone_hash = PhoneNormalizer::hash($phone);

        if ($this->phoneExists($event_id, $phone_hash)) {
            throw new Exception(MessagesEnum::WHATSAPP_GUEST_PHONE_EXISTS);
        }

        $email = EmailNormalizer::validate($data['email'] ?? null);
        $email_hash = $email ? EmailNormalizer::hash($email) : null;

        if ($email_hash && $this->emailExists($event_id, $email_hash)) {
            throw new Exception(MessagesEnum::GUEST_EMAIL_EXISTS);
        }

        $guest = EventGuest::create([
            'event_id'   => $event_id,
            'full_name'  => trim($data['full_name']),
            'phone'      => $phone,
            'phone_hash' => $phone_hash,
            'email'      => $email,
            'email_hash' => $email_hash,
            'status'     => $data['status'] ?? EventGuestStatusEnum::INVITED,
            'party_size' => max(1, (int) ($data['party_size'] ?? 1)),
            'group_key'  => $data['group_key'] ?? null,
            'metadata'   => $data['metadata'] ?? null,
        ]);

        return $this->formatGuest($guest);
    }

    public function update(int $event_id, int $guest_id, int $user_id, array $data): array
    {
        $this->event_service->assertEventAccess($event_id, $user_id);
        $guest = $this->findGuestForEvent($event_id, $guest_id);

        if (array_key_exists('full_name', $data)) {
            $guest->full_name = trim($data['full_name']);
        }

        if (array_key_exists('phone', $data)) {
            $phone = PhoneNormalizer::validate($data['phone']);
            $phone_hash = PhoneNormalizer::hash($phone);

            if ($phone_hash !== $guest->phone_hash && $this->phoneExists($event_id, $phone_hash, $guest_id)) {
                throw new Exception(MessagesEnum::WHATSAPP_GUEST_PHONE_EXISTS);
            }

            $guest->phone = $phone;
            $guest->phone_hash = $phone_hash;
        }

        if (array_key_exists('email', $data)) {
            $email = EmailNormalizer::validate($data['email']);
            $email_hash = $email ? EmailNormalizer::hash($email) : null;

            if ($email_hash !== $guest->email_hash && $email_hash && $this->emailExists($event_id, $email_hash, $guest_id)) {
                throw new Exception(MessagesEnum::GUEST_EMAIL_EXISTS);
            }

            $guest->email = $email;
            $guest->email_hash = $email_hash;
        }

        if (array_key_exists('status', $data)) {
            $guest->status = (int) $data['status'];
        }

        if (array_key_exists('party_size', $data)) {
            $guest->party_size = max(1, (int) $data['party_size']);
        }

        if (array_key_exists('group_key', $data)) {
            $guest->group_key = $data['group_key'];
        }

        if (array_key_exists('metadata', $data)) {
            $guest->metadata = $data['metadata'];
        }

        $guest->save();

        return $this->formatGuest($guest);
    }

    public function delete(int $event_id, int $guest_id, int $user_id): void
    {
        $this->deleteMany($event_id, [$guest_id], $user_id);
    }

    public function deleteMany(int $event_id, array $guest_ids, int $user_id): array
    {
        $this->event_service->assertEventAccess($event_id, $user_id);

        $guest_ids = array_values(array_unique($guest_ids));
        $valid_ids = EventGuest::where('event_id', $event_id)
            ->whereIn('id', $guest_ids)
            ->pluck('id')
            ->all();

        if (count($valid_ids) !== count($guest_ids)) {
            throw new Exception(MessagesEnum::WHATSAPP_GUEST_NOT_FOUND);
        }

        EventGuest::whereIn('id', $valid_ids)->delete();

        return ['deleted' => count($valid_ids)];
    }

    public function importFromCsv(int $event_id, int $user_id, UploadedFile $file): array
    {
        $this->event_service->assertEventAccess($event_id, $user_id);

        $handle = fopen($file->getRealPath(), 'r');

        if ($handle === false) {
            throw new Exception(MessagesEnum::WHATSAPP_GUEST_IMPORT_FAILED);
        }

        $header = fgetcsv($handle);

        if ($header !== false && isset($header[0])) {
            $header[0] = $this->stripUtf8Bom((string) $header[0]);
        }

        if (!$header) {
            fclose($handle);
            throw new Exception(MessagesEnum::WHATSAPP_GUEST_IMPORT_INVALID_HEADERS);
        }

        $column_map = $this->mapCsvHeaders($header);
        $imported = 0;
        $skipped = 0;
        $errors = [];
        $row_number = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $row_number++;

            if ($imported + $skipped >= self::MAX_IMPORT_ROWS) {
                $errors[] = 'Import stopped: maximum of ' . self::MAX_IMPORT_ROWS . ' rows reached';
                break;
            }

            if ($this->isEmptyRow($row)) {
                continue;
            }

            try {
                $full_name = trim($row[$column_map['full_name']] ?? '');
                $phone_raw = trim($row[$column_map['phone']] ?? '');

                if ($full_name === '' || $phone_raw === '') {
                    throw new Exception('Missing full name or phone');
                }

                $phone = PhoneNormalizer::validate($phone_raw);
                $phone_hash = PhoneNormalizer::hash($phone);

                if ($this->phoneExists($event_id, $phone_hash)) {
                    $skipped++;
                    continue;
                }

                EventGuest::create([
                    'event_id'   => $event_id,
                    'full_name'  => $full_name,
                    'phone'      => $phone,
                    'phone_hash' => $phone_hash,
                    'status'     => EventGuestStatusEnum::INVITED,
                ]);

                $imported++;
            } catch (Exception $ex) {
                $errors[] = 'Row ' . $row_number . ': ' . $ex->getMessage();
            }
        }

        fclose($handle);

        return compact('imported', 'skipped', 'errors');
    }

    public function getTemplateCsv(): string
    {
        $rtl = self::RTL_MARK;

        return "\xEF\xBB\xBF"
            . $rtl . 'שם מלא,' . $rtl . "טלפון\n"
            . $rtl . 'דוד כהן,' . $rtl . "972501234567\n";
    }

    private function findGuestForEvent(int $event_id, int $guest_id): EventGuest
    {
        $guest = EventGuest::where('event_id', $event_id)->where('id', $guest_id)->first();

        if (!$guest) {
            throw new Exception(MessagesEnum::WHATSAPP_GUEST_NOT_FOUND);
        }

        return $guest;
    }

    private function phoneExists(int $event_id, string $phone_hash, ?int $exclude_guest_id = null): bool
    {
        $query = EventGuest::where('event_id', $event_id)->where('phone_hash', $phone_hash);

        if ($exclude_guest_id) {
            $query->where('id', '!=', $exclude_guest_id);
        }

        return $query->exists();
    }

    private function emailExists(int $event_id, string $email_hash, ?int $exclude_guest_id = null): bool
    {
        $query = EventGuest::where('event_id', $event_id)->where('email_hash', $email_hash);

        if ($exclude_guest_id) {
            $query->where('id', '!=', $exclude_guest_id);
        }

        return $query->exists();
    }

    private function formatGuest(EventGuest $guest): array
    {
        return [
            'id'         => $guest->id,
            'full_name'  => $guest->full_name,
            'phone'      => $guest->phone,
            'email'      => $guest->email,
            'status'     => $guest->status,
            'party_size' => $guest->party_size,
            'group_key'  => $guest->group_key,
            'metadata'   => $guest->metadata ?? [],
        ];
    }

    private function mapCsvHeaders(array $header): array
    {
        $normalized_headers = array_map(fn ($value) => $this->normalizeCsvHeader((string) $value), $header);
        $map = [];

        foreach (self::HEADER_ALIASES as $field => $aliases) {
            foreach ($aliases as $alias) {
                $index = array_search($this->normalizeCsvHeader($alias), $normalized_headers, true);

                if ($index !== false) {
                    $map[$field] = $index;
                    break;
                }
            }
        }

        if (!isset($map['full_name'], $map['phone'])) {
            throw new Exception(MessagesEnum::WHATSAPP_GUEST_IMPORT_INVALID_HEADERS);
        }

        return $map;
    }

    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function normalizeCsvHeader(string $value): string
    {
        $value = $this->stripUtf8Bom(trim($value));
        $value = preg_replace('/^[\x{FEFF}\x{200E}\x{200F}\x{202A}-\x{202E}]+/u', '', $value) ?? $value;
        $value = preg_replace('/[\x{200E}\x{200F}\x{202A}-\x{202E}]+$/u', '', $value) ?? $value;

        return mb_strtolower(trim($value), 'UTF-8');
    }

    private function stripUtf8Bom(string $value): string
    {
        return str_starts_with($value, "\xEF\xBB\xBF") ? substr($value, 3) : $value;
    }
}
