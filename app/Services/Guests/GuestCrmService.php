<?php

namespace App\Services\Guests;

use App\Exceptions\SeatingHttpException;
use App\Models\EventGuest;
use App\Models\EventGuestGroup;
use App\Models\SeatingAssignment;
use App\Models\SeatingPlan;
use App\Services\Enums\EventGuestStatusEnum;
use App\Services\Events\EventService;
use App\Services\Users\UserService;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Host guest list (CRM): CRUD, groups/sides, RSVP, bulk edits, mapped import with
 * preview + dedupe, and CSV export. Shares the event_guests identity with WhatsApp.
 * Phone is optional here (seating-only guests); WhatsApp sends skip guests without a phone.
 */
class GuestCrmService
{
    public const MAX_IMPORT_ROWS = 5000;
    public const MAX_PARTY_SIZE = 30;
    public const SIDES = ['bride', 'groom', 'shared'];

    /** Dark, contrast-safe swatches (white text passes AA on all of them). */
    public const GROUP_COLORS = ['#8f3a44', '#365646', '#3b4a68', '#6b4b27', '#574775', '#2d5a62', '#7a4527', '#4a4743'];

    private const SIDE_ALIASES = [
        'bride'  => ['bride', 'כלה', 'צד כלה', 'צד הכלה', 'הכלה'],
        'groom'  => ['groom', 'חתן', 'צד חתן', 'צד החתן', 'החתן'],
        'shared' => ['shared', 'both', 'משותף', 'שניהם', 'משותפים', 'כללי'],
    ];

    private const STATUS_ALIASES = [
        EventGuestStatusEnum::CONFIRMED => ['1', 'confirmed', 'yes', 'y', 'מגיע', 'מגיעה', 'מגיעים', 'כן', 'אישר', 'אישרה', 'אישרו', 'מאשר', 'מאושר', 'v', '✓'],
        EventGuestStatusEnum::DECLINED  => ['2', 'declined', 'no', 'n', 'לא', 'לא מגיע', 'לא מגיעה', 'לא מגיעים', 'סירב', 'x'],
        EventGuestStatusEnum::MAYBE     => ['3', 'maybe', 'אולי', 'לא בטוח', 'לא בטוחה', 'אולי מגיע'],
        EventGuestStatusEnum::CANCELLED => ['4', 'cancelled', 'canceled', 'בוטל', 'ביטל', 'ביטלה'],
        EventGuestStatusEnum::INVITED   => ['0', 'invited', 'pending', 'הוזמן', 'הוזמנה', 'ממתין', 'טרם ענה', 'לא ענה', ''],
    ];

    public function __construct(private ?EventService $event_service = null)
    {
        $this->event_service ??= new EventService(new UserService());
    }

    public function assertAccess(int $event_id, int $user_id): void
    {
        $this->event_service->assertEventAccess($event_id, $user_id);
    }

    // ───────────────────────── list / stats ─────────────────────────

    public function list(int $event_id, int $user_id): array
    {
        $this->assertAccess($event_id, $user_id);

        $tables = $this->seatedTableMap($event_id);
        $guests = EventGuest::where('event_id', $event_id)->orderBy('id')->get();
        $formatted = $guests->map(fn (EventGuest $g) => $this->format($g, $tables))->values();

        return [
            'guests' => $formatted,
            'groups' => $this->groups($event_id),
            'stats'  => $this->stats($formatted),
        ];
    }

    public function stats(Collection $formatted): array
    {
        $by = fn (int $status) => $formatted->where('status', $status);
        $heads = fn (Collection $c) => (int) $c->sum('party_size');
        $active = $formatted->whereNotIn('status', [EventGuestStatusEnum::DECLINED, EventGuestStatusEnum::CANCELLED]);

        return [
            'invitations'    => $formatted->count(),
            'people_invited' => $heads($formatted),
            'confirmed'      => $heads($by(EventGuestStatusEnum::CONFIRMED)),
            'declined'       => $heads($by(EventGuestStatusEnum::DECLINED)),
            'maybe'          => $heads($by(EventGuestStatusEnum::MAYBE)),
            'pending'        => $heads($by(EventGuestStatusEnum::INVITED)),
            'cancelled'      => $heads($by(EventGuestStatusEnum::CANCELLED)),
            'seated'         => $heads($active->whereNotNull('table_id')),
            'unseated'       => $heads($active->whereNull('table_id')),
            'without_phone'  => $formatted->whereNull('phone')->count(),
        ];
    }

    // ───────────────────────── CRUD ─────────────────────────

    public function create(int $event_id, int $user_id, array $data): array
    {
        $this->assertAccess($event_id, $user_id);
        $attrs = $this->sanitize($event_id, $data, null);
        $guest = EventGuest::create(array_merge(['event_id' => $event_id], $attrs));

        return $this->format($guest->fresh(), $this->seatedTableMap($event_id));
    }

    public function update(int $event_id, int $guest_id, int $user_id, array $data): array
    {
        $this->assertAccess($event_id, $user_id);
        $guest = $this->findGuest($event_id, $guest_id);
        $guest->fill($this->sanitize($event_id, $data, $guest));
        $guest->save();
        $this->unseatInactive($event_id, [$guest->id]);

        return $this->format($guest->fresh(), $this->seatedTableMap($event_id));
    }

    public function deleteMany(int $event_id, array $guest_ids, int $user_id): array
    {
        $this->assertAccess($event_id, $user_id);
        $ids = $this->validIds($event_id, $guest_ids);

        DB::transaction(function () use ($event_id, $ids) {
            $this->removeAssignments($event_id, $ids);
            EventGuest::whereIn('id', $ids)->delete();
        });

        return ['deleted' => count($ids)];
    }

    /**
     * Bulk set status / group / side for many guests at once.
     */
    public function bulkUpdate(int $event_id, int $user_id, array $guest_ids, array $changes): array
    {
        $this->assertAccess($event_id, $user_id);
        $ids = $this->validIds($event_id, $guest_ids);
        $set = [];

        if (array_key_exists('status', $changes)) {
            $set['status'] = $this->assertStatus($changes['status']);
        }
        if (array_key_exists('side', $changes)) {
            $set['side'] = $this->assertSide($changes['side']);
        }
        if (array_key_exists('group_id', $changes)) {
            $set['group_id'] = $this->assertGroupId($event_id, $changes['group_id']);
        }

        if ($set !== []) {
            EventGuest::whereIn('id', $ids)->update($set);
            $this->unseatInactive($event_id, $ids);
        }

        return ['updated' => count($ids)];
    }

    // ───────────────────────── groups ─────────────────────────

    public function groups(int $event_id): array
    {
        $counts = EventGuest::where('event_id', $event_id)
            ->whereNotNull('group_id')
            ->select('group_id', DB::raw('COUNT(*) as c'), DB::raw('SUM(party_size) as heads'))
            ->groupBy('group_id')
            ->get()
            ->keyBy('group_id');

        return EventGuestGroup::where('event_id', $event_id)
            ->orderBy('sort_order')->orderBy('id')
            ->get()
            ->map(fn (EventGuestGroup $g) => [
                'id'         => $g->id,
                'name'       => $g->name,
                'side'       => $g->side,
                'color'      => $g->color,
                'sort_order' => $g->sort_order,
                'guests'     => (int) ($counts[$g->id]->c ?? 0),
                'people'     => (int) ($counts[$g->id]->heads ?? 0),
            ])->values()->all();
    }

    public function createGroup(int $event_id, int $user_id, array $data): array
    {
        $this->assertAccess($event_id, $user_id);
        $group = $this->findOrCreateGroup($event_id, (string) ($data['name'] ?? ''), $data['side'] ?? null, $data['color'] ?? null);

        return collect($this->groups($event_id))->firstWhere('id', $group->id);
    }

    public function updateGroup(int $event_id, int $group_id, int $user_id, array $data): array
    {
        $this->assertAccess($event_id, $user_id);
        $group = EventGuestGroup::where('event_id', $event_id)->find($group_id);
        if (!$group) {
            throw new SeatingHttpException('Group not found', 404);
        }

        if (array_key_exists('name', $data)) {
            $name = $this->cleanName((string) $data['name'], 80);
            if ($name === '') {
                throw new SeatingHttpException('Group name is required', 422);
            }
            $taken = EventGuestGroup::where('event_id', $event_id)->where('name', $name)->where('id', '!=', $group_id)->exists();
            if ($taken) {
                throw new SeatingHttpException('A group with this name already exists', 422);
            }
            $group->name = $name;
        }
        if (array_key_exists('side', $data)) {
            $group->side = $this->assertSide($data['side']);
        }
        if (array_key_exists('color', $data)) {
            $group->color = $this->assertColor($data['color']);
        }
        if (array_key_exists('sort_order', $data)) {
            $group->sort_order = max(0, (int) $data['sort_order']);
        }
        $group->save();

        return collect($this->groups($event_id))->firstWhere('id', $group->id);
    }

    public function deleteGroup(int $event_id, int $group_id, int $user_id): void
    {
        $this->assertAccess($event_id, $user_id);
        $group = EventGuestGroup::where('event_id', $event_id)->find($group_id);
        if (!$group) {
            throw new SeatingHttpException('Group not found', 404);
        }
        EventGuest::where('event_id', $event_id)->where('group_id', $group_id)->update(['group_id' => null]);
        $group->delete();
    }

    // ───────────────────────── import ─────────────────────────

    /**
     * Rows were parsed + column-mapped in the browser (xlsx/csv) and arrive as
     * [{full_name, phone, email, party_size, group, side, status, notes}].
     * Returns per-row action (new | update | skip | duplicate_in_file | error) without writing.
     */
    public function previewImport(int $event_id, int $user_id, array $rows, string $on_duplicate = 'skip'): array
    {
        $this->assertAccess($event_id, $user_id);

        return $this->analyzeImport($event_id, $rows, $on_duplicate);
    }

    public function commitImport(int $event_id, int $user_id, array $rows, string $on_duplicate = 'skip'): array
    {
        $this->assertAccess($event_id, $user_id);
        $analysis = $this->analyzeImport($event_id, $rows, $on_duplicate);
        $created = 0;
        $updated = 0;
        $groups_created = 0;

        DB::transaction(function () use ($event_id, $analysis, &$created, &$updated, &$groups_created) {
            $group_ids = [];
            foreach ($analysis['rows'] as $row) {
                if (!in_array($row['action'], ['new', 'update'], true)) {
                    continue;
                }
                $v = $row['values'];
                $group_id = null;
                if ($v['group'] !== null) {
                    $key = mb_strtolower($v['group']);
                    if (!isset($group_ids[$key])) {
                        $existed = EventGuestGroup::where('event_id', $event_id)->where('name', $v['group'])->exists();
                        $group_ids[$key] = $this->findOrCreateGroup($event_id, $v['group'], $v['side'])->id;
                        $groups_created += $existed ? 0 : 1;
                    }
                    $group_id = $group_ids[$key];
                }

                $attrs = [
                    'full_name'  => $v['full_name'],
                    'party_size' => $v['party_size'],
                    'status'     => $v['status'],
                ];
                if ($v['phone'] !== null) {
                    $attrs['phone'] = $v['phone'];
                    $attrs['phone_hash'] = PhoneNormalizer::hash($v['phone']);
                }
                if ($v['email'] !== null) {
                    $attrs['email'] = $v['email'];
                    $attrs['email_hash'] = EmailNormalizer::hash($v['email']);
                }
                if ($v['side'] !== null) {
                    $attrs['side'] = $v['side'];
                }
                if ($group_id !== null) {
                    $attrs['group_id'] = $group_id;
                }
                if ($v['notes'] !== null) {
                    $attrs['notes'] = $v['notes'];
                }

                if ($row['action'] === 'update') {
                    $guest = EventGuest::where('event_id', $event_id)->find($row['match_id']);
                    if ($guest) {
                        // Keep existing RSVP unless the file states one explicitly.
                        if (!$v['status_given']) {
                            unset($attrs['status']);
                        }
                        $guest->fill($attrs)->save();
                        $updated++;
                    }
                } else {
                    EventGuest::create(array_merge([
                        'event_id'   => $event_id,
                        'phone'      => null,
                        'phone_hash' => null,
                    ], $attrs));
                    $created++;
                }
            }
        });

        $this->unseatInactive($event_id, null);

        return [
            'created'        => $created,
            'updated'        => $updated,
            'skipped'        => $analysis['summary']['skip'] + $analysis['summary']['duplicate_in_file'],
            'errors'         => $analysis['summary']['error'],
            'groups_created' => $groups_created,
            'summary'        => $analysis['summary'],
        ];
    }

    private function analyzeImport(int $event_id, array $rows, string $on_duplicate): array
    {
        if (count($rows) > self::MAX_IMPORT_ROWS) {
            throw new SeatingHttpException('Too many rows (max ' . self::MAX_IMPORT_ROWS . ')', 422);
        }
        $on_duplicate = $on_duplicate === 'update' ? 'update' : 'skip';

        // Existing identity indexes for dedupe.
        $existing = EventGuest::where('event_id', $event_id)->get(['id', 'full_name', 'phone_hash', 'email_hash']);
        $by_phone = $existing->whereNotNull('phone_hash')->keyBy('phone_hash');
        $by_email = $existing->whereNotNull('email_hash')->keyBy('email_hash');
        $by_name = [];
        foreach ($existing as $g) {
            $by_name[$this->nameKey((string) $g->full_name)][] = $g->id;
        }

        $seen_phone = [];
        $seen_email = [];
        $out = [];
        $summary = ['new' => 0, 'update' => 0, 'skip' => 0, 'duplicate_in_file' => 0, 'error' => 0, 'warnings' => 0, 'people' => 0];

        foreach (array_values($rows) as $i => $raw) {
            $line = (int) ($raw['_line'] ?? ($i + 2));
            $errors = [];
            $warnings = [];
            $values = $this->parseImportRow(is_array($raw) ? $raw : [], $errors, $warnings);

            $match_id = null;
            $action = 'new';

            if ($errors !== []) {
                $action = 'error';
            } else {
                $phone_hashes = $values['phone'] !== null ? $this->phoneHashCandidates($values['phone']) : [];
                $email_hash = $values['email'] !== null ? EmailNormalizer::hash($values['email']) : null;

                $dup_in_file = false;
                foreach ($phone_hashes as $h) {
                    if (isset($seen_phone[$h])) {
                        $dup_in_file = true;
                        $warnings[] = ['code' => 'duplicate_in_file', 'message' => 'מספר הטלפון מופיע גם בשורה ' . $seen_phone[$h]];
                    }
                }
                if (!$dup_in_file && $email_hash && isset($seen_email[$email_hash])) {
                    $dup_in_file = true;
                    $warnings[] = ['code' => 'duplicate_in_file', 'message' => 'המייל מופיע גם בשורה ' . $seen_email[$email_hash]];
                }

                if ($dup_in_file) {
                    $action = 'duplicate_in_file';
                } else {
                    foreach ($phone_hashes as $h) {
                        if ($by_phone->has($h)) {
                            $match_id = $by_phone[$h]->id;
                            break;
                        }
                    }
                    if ($match_id === null && $email_hash && $by_email->has($email_hash)) {
                        $match_id = $by_email[$email_hash]->id;
                    }

                    if ($match_id !== null) {
                        $action = $on_duplicate === 'update' ? 'update' : 'skip';
                    } elseif (!empty($by_name[$this->nameKey($values['full_name'])])) {
                        // Same name, no shared phone/email: never merge silently, just warn.
                        $warnings[] = ['code' => 'name_match', 'message' => 'קיים כבר אורח בשם הזה (ללא טלפון/מייל תואם)'];
                    }

                    // The email may belong to a different existing guest than the phone match.
                    if ($action === 'update' && $email_hash && $by_email->has($email_hash) && $by_email[$email_hash]->id !== $match_id) {
                        $warnings[] = ['code' => 'email_conflict', 'message' => 'המייל שייך לאורח אחר ולא יעודכן'];
                        $values['email'] = null;
                    }

                    foreach ($phone_hashes as $h) {
                        $seen_phone[$h] = $line;
                    }
                    if ($email_hash) {
                        $seen_email[$email_hash] = $line;
                    }
                }
            }

            $summary[$action]++;
            if ($warnings !== []) {
                $summary['warnings']++;
            }
            if (in_array($action, ['new', 'update'], true)) {
                $summary['people'] += $values['party_size'];
            }

            $out[] = [
                'line'     => $line,
                'action'   => $action,
                'match_id' => $match_id,
                'values'   => $values,
                'errors'   => $errors,
                'warnings' => $warnings,
            ];
        }

        return ['rows' => $out, 'summary' => $summary, 'on_duplicate' => $on_duplicate];
    }

    private function parseImportRow(array $raw, array &$errors, array &$warnings): array
    {
        $name = $this->cleanName((string) ($raw['full_name'] ?? ''), 120);
        if ($name === '') {
            $first = $this->cleanName((string) ($raw['first_name'] ?? ''), 60);
            $last = $this->cleanName((string) ($raw['last_name'] ?? ''), 60);
            $name = trim($first . ' ' . $last);
        }
        if ($name === '') {
            $errors[] = ['field' => 'full_name', 'message' => 'חסר שם'];
        }

        $phone = null;
        $phone_raw = trim((string) ($raw['phone'] ?? ''));
        if ($phone_raw !== '') {
            try {
                $phone = $this->canonicalPhone($phone_raw);
            } catch (Exception) {
                $errors[] = ['field' => 'phone', 'message' => 'מספר טלפון לא תקין'];
            }
        }

        $email = null;
        $email_raw = trim((string) ($raw['email'] ?? ''));
        if ($email_raw !== '') {
            try {
                $email = EmailNormalizer::validate($email_raw);
            } catch (Exception) {
                $warnings[] = ['code' => 'email_invalid', 'message' => 'כתובת מייל לא תקינה — תיובא בלי מייל'];
            }
        }

        $party_raw = trim((string) ($raw['party_size'] ?? ''));
        $party = 1;
        if ($party_raw !== '') {
            $digits = preg_replace('/[^\d]/u', '', $party_raw);
            if ($digits === '' || (int) $digits < 1) {
                $warnings[] = ['code' => 'party_invalid', 'message' => 'מספר מגיעים לא מובן — נקבע 1'];
            } else {
                $party = min(self::MAX_PARTY_SIZE, (int) $digits);
                if ((int) $digits > self::MAX_PARTY_SIZE) {
                    $warnings[] = ['code' => 'party_capped', 'message' => 'מספר מגיעים הוגבל ל-' . self::MAX_PARTY_SIZE];
                }
            }
        }

        $side = null;
        $side_raw = trim((string) ($raw['side'] ?? ''));
        if ($side_raw !== '') {
            $side = $this->sideFromText($side_raw);
            if ($side === null) {
                $warnings[] = ['code' => 'side_unknown', 'message' => 'צד לא מזוהה: ' . mb_substr($side_raw, 0, 20)];
            }
        }

        $status_raw = trim((string) ($raw['status'] ?? ''));
        $status = EventGuestStatusEnum::INVITED;
        $status_given = false;
        if ($status_raw !== '') {
            $parsed = $this->statusFromText($status_raw);
            if ($parsed === null) {
                $warnings[] = ['code' => 'status_unknown', 'message' => 'סטטוס לא מזוהה — נקבע "הוזמן"'];
            } else {
                $status = $parsed;
                $status_given = true;
            }
        }

        $group = $this->cleanName((string) ($raw['group'] ?? ''), 80);
        $notes = trim(mb_substr((string) ($raw['notes'] ?? ''), 0, 1000));

        return [
            'full_name'    => $name,
            'phone'        => $phone,
            'email'        => $email,
            'party_size'   => $party,
            'side'         => $side,
            'status'       => $status,
            'status_given' => $status_given,
            'group'        => $group === '' ? null : $group,
            'notes'        => $notes === '' ? null : $notes,
        ];
    }

    // ───────────────────────── export ─────────────────────────

    public function exportCsv(int $event_id, int $user_id): string
    {
        $data = $this->list($event_id, $user_id);
        $groups = collect($data['groups'])->keyBy('id');
        $labels = [0 => 'הוזמן', 1 => 'מגיע', 2 => 'לא מגיע', 3 => 'אולי', 4 => 'בוטל'];
        $sides = ['bride' => 'צד כלה', 'groom' => 'צד חתן', 'shared' => 'משותף'];

        $fh = fopen('php://temp', 'r+');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, ['שם מלא', 'טלפון', 'מייל', 'מספר מגיעים', 'סטטוס', 'קבוצה', 'צד', 'שולחן', 'הערות']);
        foreach ($data['guests'] as $g) {
            fputcsv($fh, array_map([$this, 'csvSafe'], [
                $g['full_name'],
                $g['phone'] ?? '',
                $g['email'] ?? '',
                $g['party_size'],
                $labels[$g['status']] ?? '',
                $g['group_id'] ? ($groups[$g['group_id']]['name'] ?? '') : '',
                $sides[$g['side']] ?? '',
                $g['table_label'] ?? '',
                $g['notes'] ?? '',
            ]));
        }
        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);

        return $csv;
    }

    /** Prevent spreadsheet formula injection in exported cells. */
    public function csvSafe($value): string
    {
        $s = (string) $value;
        if ($s !== '' && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true) && !preg_match('/^\+?\d[\d\s-]*$/', $s)) {
            return "'" . $s;
        }

        return $s;
    }

    // ───────────────────────── seating hooks ─────────────────────────

    /** Declined / cancelled guests lose their seat. Pass null to check the whole event. */
    public function unseatInactive(int $event_id, ?array $guest_ids): int
    {
        $plan = SeatingPlan::where('event_id', $event_id)->first();
        if (!$plan) {
            return 0;
        }
        $q = EventGuest::where('event_id', $event_id)
            ->whereIn('status', [EventGuestStatusEnum::DECLINED, EventGuestStatusEnum::CANCELLED]);
        if ($guest_ids !== null) {
            $q->whereIn('id', $guest_ids);
        }
        $ids = $q->pluck('id')->all();
        if ($ids === []) {
            return 0;
        }

        return SeatingAssignment::where('plan_id', $plan->id)->whereIn('guest_id', $ids)->delete();
    }

    private function removeAssignments(int $event_id, array $guest_ids): void
    {
        $plan = SeatingPlan::where('event_id', $event_id)->first();
        if ($plan) {
            SeatingAssignment::where('plan_id', $plan->id)->whereIn('guest_id', $guest_ids)->delete();
        }
    }

    private function seatedTableMap(int $event_id): array
    {
        $plan = SeatingPlan::where('event_id', $event_id)->first();
        if (!$plan) {
            return [];
        }

        return DB::table('seating_assignments as a')
            ->join('seating_tables as t', 't.id', '=', 'a.table_id')
            ->where('a.plan_id', $plan->id)
            ->get(['a.guest_id', 'a.table_id', 'a.seat', 't.label'])
            ->keyBy('guest_id')
            ->map(fn ($r) => ['table_id' => (int) $r->table_id, 'label' => $r->label, 'seat' => $r->seat === null ? null : (int) $r->seat])
            ->all();
    }

    // ───────────────────────── helpers ─────────────────────────

    public function format(EventGuest $g, array $tables = []): array
    {
        $t = $tables[$g->id] ?? null;

        return [
            'id'          => $g->id,
            'full_name'   => $g->full_name,
            'phone'       => $g->phone,
            'email'       => $g->email,
            'status'      => (int) $g->status,
            'party_size'  => (int) $g->party_size,
            'side'        => $g->side,
            'group_id'    => $g->group_id ? (int) $g->group_id : null,
            'notes'       => $g->notes,
            'table_id'    => $t['table_id'] ?? null,
            'table_label' => $t['label'] ?? null,
            'seat'        => $t['seat'] ?? null,
            'created_at'  => optional($g->created_at)->toIso8601String(),
            'updated_at'  => optional($g->updated_at)->toIso8601String(),
        ];
    }

    private function sanitize(int $event_id, array $data, ?EventGuest $guest): array
    {
        $attrs = [];
        $guest_id = $guest?->id;

        if ($guest === null || array_key_exists('full_name', $data)) {
            $name = $this->cleanName((string) ($data['full_name'] ?? ''), 120);
            if ($name === '') {
                throw new SeatingHttpException('Guest name is required', 422);
            }
            $attrs['full_name'] = $name;
        }

        if (array_key_exists('phone', $data)) {
            $raw = trim((string) ($data['phone'] ?? ''));
            if ($raw === '') {
                $attrs['phone'] = null;
                $attrs['phone_hash'] = null;
            } else {
                try {
                    $phone = $this->canonicalPhone($raw);
                } catch (Exception) {
                    throw new SeatingHttpException('Invalid phone number', 422);
                }
                $hash = PhoneNormalizer::hash($phone);
                if ($this->phoneTaken($event_id, $phone, $guest_id)) {
                    throw new SeatingHttpException('A guest with this phone number already exists for this event', 422);
                }
                $attrs['phone'] = $phone;
                $attrs['phone_hash'] = $hash;
            }
        } elseif ($guest === null) {
            $attrs['phone'] = null;
            $attrs['phone_hash'] = null;
        }

        if (array_key_exists('email', $data)) {
            try {
                $email = EmailNormalizer::validate($data['email'] ?? null);
            } catch (Exception) {
                throw new SeatingHttpException('Invalid email address', 422);
            }
            $hash = $email ? EmailNormalizer::hash($email) : null;
            if ($hash) {
                $q = EventGuest::where('event_id', $event_id)->where('email_hash', $hash);
                if ($guest_id) {
                    $q->where('id', '!=', $guest_id);
                }
                if ($q->exists()) {
                    throw new SeatingHttpException('A guest with this email address already exists for this event', 422);
                }
            }
            $attrs['email'] = $email;
            $attrs['email_hash'] = $hash;
        }

        if (array_key_exists('status', $data)) {
            $attrs['status'] = $this->assertStatus($data['status']);
        } elseif ($guest === null) {
            $attrs['status'] = EventGuestStatusEnum::INVITED;
        }

        if (array_key_exists('party_size', $data)) {
            $attrs['party_size'] = max(1, min(self::MAX_PARTY_SIZE, (int) $data['party_size']));
        } elseif ($guest === null) {
            $attrs['party_size'] = 1;
        }

        if (array_key_exists('side', $data)) {
            $attrs['side'] = $this->assertSide($data['side']);
        }

        if (array_key_exists('group_id', $data)) {
            $attrs['group_id'] = $this->assertGroupId($event_id, $data['group_id']);
        } elseif (!empty($data['group_name'])) {
            $attrs['group_id'] = $this->findOrCreateGroup($event_id, (string) $data['group_name'], $data['side'] ?? null)->id;
        }

        if (array_key_exists('notes', $data)) {
            $notes = trim(mb_substr((string) ($data['notes'] ?? ''), 0, 1000));
            $attrs['notes'] = $notes === '' ? null : $notes;
        }

        return $attrs;
    }

    /** Store Israeli numbers in local 0XX form; other international numbers as digits. */
    public function canonicalPhone(string $raw): string
    {
        $digits = PhoneNormalizer::validate($raw);
        if (str_starts_with($digits, '972') && strlen($digits) >= 11 && strlen($digits) <= 12) {
            $digits = '0' . substr($digits, 3);
        } elseif (strlen($digits) === 9 && $digits[0] === '5') {
            $digits = '0' . $digits; // 52xxxxxxx typed without the leading zero
        }

        return $digits;
    }

    /** Hashes for the same phone in local and international forms (legacy rows may hold either). */
    public function phoneHashCandidates(string $canonical): array
    {
        $forms = [$canonical];
        if (str_starts_with($canonical, '0')) {
            $forms[] = '972' . substr($canonical, 1);
        }

        return array_values(array_unique(array_map(fn ($p) => PhoneNormalizer::hash($p), $forms)));
    }

    private function phoneTaken(int $event_id, string $canonical, ?int $exclude_id): bool
    {
        $q = EventGuest::where('event_id', $event_id)->whereIn('phone_hash', $this->phoneHashCandidates($canonical));
        if ($exclude_id) {
            $q->where('id', '!=', $exclude_id);
        }

        return $q->exists();
    }

    private function findOrCreateGroup(int $event_id, string $name, ?string $side = null, ?string $color = null): EventGuestGroup
    {
        $name = $this->cleanName($name, 80);
        if ($name === '') {
            throw new SeatingHttpException('Group name is required', 422);
        }
        $existing = EventGuestGroup::where('event_id', $event_id)->where('name', $name)->first();
        if ($existing) {
            return $existing;
        }
        $count = EventGuestGroup::where('event_id', $event_id)->count();
        if ($count >= 200) {
            throw new SeatingHttpException('Too many groups', 422);
        }

        return EventGuestGroup::create([
            'event_id'   => $event_id,
            'name'       => $name,
            'side'       => $side === null ? null : $this->assertSide($side),
            'color'      => $color ? $this->assertColor($color) : self::GROUP_COLORS[$count % count(self::GROUP_COLORS)],
            'sort_order' => $count,
        ]);
    }

    private function findGuest(int $event_id, int $guest_id): EventGuest
    {
        $guest = EventGuest::where('event_id', $event_id)->find($guest_id);
        if (!$guest) {
            throw new SeatingHttpException('Guest not found', 404);
        }

        return $guest;
    }

    private function validIds(int $event_id, array $guest_ids): array
    {
        $guest_ids = array_values(array_unique(array_map('intval', $guest_ids)));
        $valid = EventGuest::where('event_id', $event_id)->whereIn('id', $guest_ids)->pluck('id')->all();
        if (count($valid) !== count($guest_ids)) {
            throw new SeatingHttpException('Guest not found', 404);
        }

        return $valid;
    }

    private function assertStatus($status): int
    {
        $s = (int) $status;
        if (!in_array($s, [0, 1, 2, 3, 4], true)) {
            throw new SeatingHttpException('Invalid status', 422);
        }

        return $s;
    }

    private function assertSide($side): ?string
    {
        if ($side === null || $side === '') {
            return null;
        }
        if (!in_array($side, self::SIDES, true)) {
            throw new SeatingHttpException('Invalid side', 422);
        }

        return $side;
    }

    private function assertGroupId(int $event_id, $group_id): ?int
    {
        if ($group_id === null || $group_id === '' || (int) $group_id === 0) {
            return null;
        }
        if (!EventGuestGroup::where('event_id', $event_id)->where('id', (int) $group_id)->exists()) {
            throw new SeatingHttpException('Group not found', 404);
        }

        return (int) $group_id;
    }

    private function assertColor($color): ?string
    {
        if ($color === null || $color === '') {
            return null;
        }
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', (string) $color)) {
            throw new SeatingHttpException('Invalid color', 422);
        }

        return strtolower((string) $color);
    }

    private function sideFromText(string $raw): ?string
    {
        $k = $this->nameKey($raw);
        foreach (self::SIDE_ALIASES as $side => $aliases) {
            foreach ($aliases as $a) {
                if ($k === $this->nameKey($a)) {
                    return $side;
                }
            }
        }

        return null;
    }

    private function statusFromText(string $raw): ?int
    {
        $k = $this->nameKey($raw);
        foreach (self::STATUS_ALIASES as $status => $aliases) {
            foreach ($aliases as $a) {
                if ($k === $this->nameKey($a)) {
                    return (int) $status;
                }
            }
        }

        return null;
    }

    private function cleanName(string $v, int $max): string
    {
        // Strip bidi marks / control chars, collapse whitespace.
        $v = preg_replace('/[\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}\p{Cc}]/u', '', $v) ?? '';
        $v = trim(preg_replace('/\s+/u', ' ', $v) ?? '');

        return mb_substr($v, 0, $max);
    }

    private function nameKey(string $v): string
    {
        $v = mb_strtolower($this->cleanName($v, 200), 'UTF-8');

        return preg_replace('/["\'׳״`.\-]/u', '', $v) ?? $v;
    }
}
