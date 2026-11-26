<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\SeatingResponses;
use App\Models\WhatsAppTemplate;
use App\Services\Messaging\AutomationService;
use App\Services\Messaging\MessagingContext;
use App\Services\Messaging\MessagingHttpException;
use App\Services\Messaging\MessagingService;
use App\Services\Messaging\SendService;
use App\Services\Messaging\TemplateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** WhatsApp guest messaging — host API (/api/events/{event_id}/messaging/*). */
class EventMessagingController extends Controller
{
    use SeatingResponses;

    private function svc(): MessagingService
    {
        return new MessagingService();
    }

    private function sends(): SendService
    {
        return new SendService();
    }

    public function overview(int $event_id)
    {
        return $this->respond('Overview fetched', fn () => $this->svc()->overview($event_id, Auth::id()));
    }

    public function settings(int $event_id)
    {
        return $this->respond('Settings fetched', fn () => $this->svc()->settings($event_id, Auth::id()));
    }

    public function updateSettings(int $event_id, Request $request)
    {
        $data = $request->validate([
            'quiet_hours_enabled' => 'sometimes|boolean', 'shabbat_guard_enabled' => 'sometimes|boolean', 'sms_fallback_enabled' => 'sometimes|boolean',
            'variables' => 'sometimes|array', 'rsvp_config' => 'sometimes|array',
        ]);

        return $this->respond('Settings saved', fn () => $this->svc()->updateSettings($event_id, Auth::id(), $data));
    }

    // templates
    public function templates(int $event_id)
    {
        return $this->respond('Templates fetched', function () use ($event_id) {
            MessagingContext::event($event_id, Auth::id());

            return ['templates' => (new TemplateService())->list($event_id)];
        });
    }

    public function storeTemplate(int $event_id, Request $request)
    {
        $data = $this->contentRules($request) + $request->validate(['title' => 'required|string|max:160', 'base_key' => 'nullable|string|max:64']);

        return $this->respond('Template submitted', function () use ($event_id, $data) {
            MessagingContext::event($event_id, Auth::id());
            $content = TemplateService::cleanContent($data);
            $tpl = (new TemplateService())->resolveForContent($event_id, $data['base_key'] ?? null, $data['title'], $data['category'] ?? 'marketing', $content);

            return (new TemplateService())->format($tpl);
        }, 201);
    }

    public function resubmitTemplate(int $event_id, int $template_id, Request $request)
    {
        $data = $this->contentRules($request) + $request->validate(['title' => 'sometimes|string|max:160']);

        return $this->respond('Template resubmitted', function () use ($event_id, $template_id, $data) {
            MessagingContext::event($event_id, Auth::id());
            $tpl = WhatsAppTemplate::where('event_id', $event_id)->where('id', $template_id)->first();
            if (!$tpl) {
                throw new MessagingHttpException('Template not found', 404);
            }
            $content = TemplateService::cleanContent($data, $tpl->toArray());
            $new = (new TemplateService())->resolveForContent($event_id, $tpl->base_key, $data['title'] ?? $tpl->title, $data['category'] ?? $tpl->category, $content);

            return (new TemplateService())->format($new);
        });
    }

    // queue / sends
    public function queue(int $event_id, Request $request)
    {
        return $this->respond('Queue fetched', fn () => $this->sends()->queue($event_id, Auth::id(), $request->query('filter')));
    }

    public function preview(int $event_id, Request $request)
    {
        $data = $this->contentRules($request) + $request->validate(['template_key' => 'nullable|string|max:64', 'segment' => 'sometimes|array', 'scheduled_at' => 'nullable|string|max:40', 'send_id' => 'nullable|integer']);

        return $this->respond('Preview ready', fn () => $this->sends()->preview($event_id, Auth::id(), $data, $data['send_id'] ?? null));
    }

    public function storeSend(int $event_id, Request $request)
    {
        $data = $this->contentRules($request) + $request->validate([
            'template_key' => 'nullable|string|max:64', 'title' => 'nullable|string|max:160', 'segment' => 'sometimes|array',
            'scheduled_at' => 'nullable|string|max:40', 'send_now' => 'sometimes|boolean', 'override_quiet' => 'sometimes|boolean',
        ]);

        return $this->respond('Message scheduled', fn () => $this->sends()->create($event_id, Auth::id(), $data), 201);
    }

    public function showSend(int $event_id, int $send_id)
    {
        return $this->respond('Send fetched', fn () => $this->sends()->show($event_id, $send_id, Auth::id()));
    }

    public function updateSend(int $event_id, int $send_id, Request $request)
    {
        $data = $this->contentRules($request) + $request->validate([
            'expected_version' => 'required|integer', 'action' => 'sometimes|string|in:update,pause,resume,cancel,send_now',
            'title' => 'sometimes|string|max:160', 'segment' => 'sometimes|array', 'scheduled_at' => 'sometimes|string|max:40', 'override_quiet' => 'sometimes|boolean',
        ]);

        return $this->respond('Send updated', fn () => $this->sends()->update($event_id, $send_id, Auth::id(), $data));
    }

    public function recipients(int $event_id, int $send_id, Request $request)
    {
        return $this->respond('Recipients fetched', fn () => $this->sends()->recipients($event_id, $send_id, Auth::id(), $request->query('status')));
    }

    public function audit(int $event_id, int $send_id)
    {
        return $this->respond('Audit fetched', fn () => $this->sends()->auditLog($event_id, $send_id, Auth::id()));
    }

    public function guestPending(int $event_id, int $guest_id)
    {
        return $this->respond('Guest messages fetched', fn () => $this->sends()->guestPending($event_id, $guest_id, Auth::id()));
    }

    public function testSend(int $event_id, Request $request)
    {
        $data = $this->contentRules($request) + $request->validate(['phone' => 'required|string|max:32', 'template_key' => 'nullable|string|max:64']);

        return $this->respond('Test sent', fn () => $this->svc()->testSend($event_id, Auth::id(), $data));
    }

    // automations
    public function automations(int $event_id)
    {
        return $this->respond('Automations fetched', fn () => (new AutomationService())->list($event_id, Auth::id()));
    }

    public function updateAutomation(int $event_id, int $automation_id, Request $request)
    {
        $data = $request->validate(['enabled' => 'sometimes|boolean', 'offset_days' => 'sometimes|integer', 'send_time' => 'sometimes|string|max:5', 'segment' => 'sometimes|array']);

        return $this->respond('Automation saved', fn () => (new AutomationService())->update($event_id, $automation_id, Auth::id(), $data));
    }

    // analytics + inbox
    public function analytics(int $event_id)
    {
        return $this->respond('Analytics fetched', fn () => $this->svc()->analytics($event_id, Auth::id()));
    }

    public function inbox(int $event_id)
    {
        return $this->respond('Inbox fetched', fn () => $this->svc()->inbox($event_id, Auth::id()));
    }

    public function thread(int $event_id, int $guest_id)
    {
        return $this->respond('Thread fetched', fn () => $this->svc()->thread($event_id, $guest_id, Auth::id()));
    }

    public function reply(int $event_id, int $guest_id, Request $request)
    {
        $data = $request->validate(['body' => 'required|string|max:1000']);

        return $this->respond('Reply sent', fn () => $this->svc()->reply($event_id, $guest_id, Auth::id(), $data['body']));
    }

    public function applySuggestion(int $event_id, int $message_id, Request $request)
    {
        $data = $request->validate(['apply' => 'required|boolean', 'changes' => 'sometimes|array']);

        return $this->respond('Suggestion handled', fn () => $this->svc()->applySuggestion($event_id, $message_id, Auth::id(), (bool) $data['apply'], $data['changes'] ?? null));
    }

    // dev (mock only)
    public function devInbound(int $event_id, Request $request)
    {
        $data = $request->validate(['guest_id' => 'required|integer', 'text' => 'nullable|string|max:1000', 'payload' => 'nullable|string|max:64']);

        return $this->respond('Simulated', fn () => $this->svc()->devInbound($event_id, Auth::id(), $data));
    }

    public function devTemplate(int $event_id, int $template_id, Request $request)
    {
        $data = $request->validate(['status' => 'required|string|in:approved,rejected']);

        return $this->respond('Template reviewed', fn () => $this->svc()->devTemplate($event_id, Auth::id(), $template_id, $data['status']));
    }

    public function devDelivery(int $event_id, int $send_id, Request $request)
    {
        $data = $request->validate(['status' => 'required|string|in:delivered,read,failed']);

        return $this->respond('Delivery simulated', fn () => $this->svc()->devDelivery($event_id, Auth::id(), $send_id, $data['status']));
    }

    public function devEntitlement(int $event_id, Request $request)
    {
        $data = $request->validate(['pro_addon' => 'sometimes|boolean', 'topups' => 'sometimes|integer']);

        return $this->respond('Entitlement updated', fn () => $this->svc()->devEntitlement($event_id, Auth::id(), $data));
    }

    private function contentRules(Request $request): array
    {
        return $request->validate([
            'header' => 'sometimes|nullable|string|max:60', 'body' => 'sometimes|string|max:1024', 'footer' => 'sometimes|nullable|string|max:60',
            'buttons' => 'sometimes|array|max:3', 'category' => 'sometimes|string|in:marketing,utility',
        ]);
    }
}
