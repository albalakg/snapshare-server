<?php

namespace App\Services\Messaging;

use App\Models\Event;
use App\Models\EventGuest;
use App\Models\EventHub;
use App\Services\Enums\EventGuestStatusEnum;
use App\Services\Guests\GuestCrmService;
use App\Services\Guests\PhoneNormalizer;
use Carbon\Carbon;
use Exception;

/**
 * D8: one write path for RSVP answers into event_guests, used by the WhatsApp bot, the personal web link
 * (/rsvp/{token}, also the SMS fallback link) and the public hub RSVP section.
 */
class RsvpService
{
    public const STATUS = ['yes' => EventGuestStatusEnum::CONFIRMED, 'no' => EventGuestStatusEnum::DECLINED, 'maybe' => EventGuestStatusEnum::MAYBE];

    /** Apply answers. $answers: status (yes|no|maybe), party_size, meal, allergies, shuttle, note. */
    public function apply(EventGuest $guest, array $answers, string $source): EventGuest
    {
        $settings = MessagingContext::settings((int) $guest->event_id);
        $cfg = MessagingContext::rsvpConfig($settings);
        if (isset($answers['status']) && isset(self::STATUS[$answers['status']])) {
            $guest->status = self::STATUS[$answers['status']];
        }
        if (isset($answers['party_size']) && (int) $guest->status !== EventGuestStatusEnum::DECLINED) {
            $guest->party_size = max(1, min((int) ($cfg['max_party_size'] ?? 10), (int) $answers['party_size']));
        }
        $meta = (array) ($guest->metadata ?? []);
        $rsvp = (array) ($meta['rsvp'] ?? []);
        if (array_key_exists('meal', $answers)) {
            $meal = trim((string) $answers['meal']);
            $rsvp['meal'] = $meal === '' ? null : mb_substr($meal, 0, 60);
        }
        if (array_key_exists('allergies', $answers)) {
            $al = trim((string) $answers['allergies']);
            $rsvp['allergies'] = ($al === '' || in_array($al, ['אין', 'לא', 'no', 'none'], true)) ? null : mb_substr($al, 0, 200);
        }
        if (array_key_exists('shuttle', $answers)) {
            $rsvp['shuttle'] = (bool) $answers['shuttle'];
        }
        if (array_key_exists('note', $answers)) {
            $note = trim((string) $answers['note']);
            $rsvp['note'] = $note === '' ? null : mb_substr($note, 0, 500);
        }
        $rsvp['answered_at'] = now()->toIso8601String();
        $rsvp['source'] = $source;
        $meta['rsvp'] = $rsvp;
        $guest->metadata = $meta;
        $guest->rsvp_responded_at = now();
        $guest->rsvp_source = $source;
        $guest->save();

        return $guest;
    }

    public function publicShow(string $token): array
    {
        $guest = $this->guestFromToken($token);
        $event = Event::find($guest->event_id);
        $settings = MessagingContext::settings($guest->event_id);
        $vars = (new VariableResolver($event, $settings))->forGuest($guest);
        $cfg = MessagingContext::rsvpConfig($settings);

        return [
            'guest' => [
                'first_name' => $vars['first_name'], 'party_size' => (int) $guest->party_size, 'status' => (int) $guest->status,
                'answers' => (array) (($guest->metadata['rsvp'] ?? null) ?: new \stdClass()),
            ],
            'event' => [
                'title' => $vars['event_title'], 'couple_names' => $vars['couple_names'], 'date' => $vars['event_date'], 'day' => $vars['event_day'],
                'time' => $vars['event_time'], 'venue_name' => $vars['venue_name'], 'venue_address' => $vars['venue_address'],
                'waze_link' => $vars['waze_link'], 'hub_link' => $vars['hub_link'],
            ],
            'config' => $this->publicConfig($cfg),
        ];
    }

    public function publicSubmit(string $token, array $data): array
    {
        $guest = $this->guestFromToken($token);
        $this->apply($guest, $this->cleanAnswers($data), 'web');

        return $this->publicShow($token);
    }

    /** Public hub RSVP section (no personal token): match by phone, otherwise add the guest to the CRM. */
    public function hubSubmit(string $slug, array $data): array
    {
        MessagingContext::assertEnabled();
        $hub = EventHub::where('slug', strtolower(trim($slug)))->where('is_published', true)->first();
        if (!$hub) {
            throw new MessagingHttpException('Page not found', 404);
        }
        $name = trim(mb_substr((string) ($data['full_name'] ?? ''), 0, 120));
        if ($name === '') {
            throw new MessagingHttpException('Name is required', 422);
        }
        $crm = new GuestCrmService();
        try {
            $phone = $crm->canonicalPhone((string) ($data['phone'] ?? ''));
        } catch (Exception) {
            throw new MessagingHttpException('Invalid phone number', 422);
        }
        $guest = EventGuest::where('event_id', $hub->event_id)->whereIn('phone_hash', $crm->phoneHashCandidates($phone))->first();
        if (!$guest) {
            if (EventGuest::where('event_id', $hub->event_id)->count() >= 3000) {
                throw new MessagingHttpException('Guest list is full', 422);
            }
            $guest = EventGuest::create([
                'event_id' => $hub->event_id, 'full_name' => $name, 'phone' => $phone, 'phone_hash' => PhoneNormalizer::hash($phone),
                'status' => EventGuestStatusEnum::INVITED, 'party_size' => 1, 'metadata' => ['source' => 'public_rsvp'],
            ]);
        }
        $this->apply($guest, $this->cleanAnswers($data), 'web');

        return ['ok' => true, 'status' => (int) $guest->status, 'party_size' => (int) $guest->party_size];
    }

    public function hubConfig(string $slug): array
    {
        $hub = EventHub::where('slug', strtolower(trim($slug)))->where('is_published', true)->first();
        if (!$hub || !MessagingContext::enabled()) {
            throw new MessagingHttpException('Page not found', 404);
        }

        return ['config' => $this->publicConfig(MessagingContext::rsvpConfig(MessagingContext::settings($hub->event_id)))];
    }

    private function publicConfig(array $cfg): array
    {
        return [
            'ask_party_size' => (bool) $cfg['ask_party_size'], 'max_party_size' => (int) $cfg['max_party_size'],
            'ask_meal' => (bool) $cfg['ask_meal'], 'meals' => array_values($cfg['meals'] ?? []),
            'ask_allergies' => (bool) $cfg['ask_allergies'], 'ask_shuttle' => (bool) $cfg['ask_shuttle'],
        ];
    }

    private function cleanAnswers(array $data): array
    {
        $status = (string) ($data['status'] ?? '');
        if (!isset(self::STATUS[$status])) {
            throw new MessagingHttpException('Please choose whether you are coming', 422);
        }
        $out = ['status' => $status];
        foreach (['party_size', 'meal', 'allergies', 'shuttle', 'note'] as $k) {
            if (array_key_exists($k, $data)) {
                $out[$k] = $data[$k];
            }
        }

        return $out;
    }

    private function guestFromToken(string $token): EventGuest
    {
        MessagingContext::assertEnabled();
        $guest = RsvpToken::resolve($token);
        if (!$guest) {
            throw new MessagingHttpException('This RSVP link is not valid', 404);
        }

        return $guest;
    }
}
