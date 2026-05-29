# WhatsApp Guest Messaging — Frontend Developer Spec

Build an **Event → WhatsApp Messaging** section in the SnapShare SPA. All endpoints require Bearer token auth (`auth:api`).

Base path: `/api/events/{event_id}/whatsapp`

---

## Guest List

### List guests
`GET /api/events/{event_id}/whatsapp/guests`

Response:
```json
{
  "message": "WhatsApp guests fetched successfully",
  "data": {
    "guests": [
      { "id": 1, "full_name": "Jane Doe", "phone": "972501234567" }
    ],
    "total": 1
  }
}
```

### Add guest
`POST /api/events/{event_id}/whatsapp/guests`

Body:
```json
{ "full_name": "Jane Doe", "phone": "972501234567" }
```

Phone is normalized server-side (non-digits stripped). Returns `201`.

### Update guest
`PUT /api/events/{event_id}/whatsapp/guests/{guest_id}`

Body (partial allowed):
```json
{ "full_name": "Jane Smith", "phone": "972521234567" }
```

### Delete guest
`DELETE /api/events/{event_id}/whatsapp/guests/{guest_id}`

### Import CSV
`POST /api/events/{event_id}/whatsapp/guests/import`

Multipart form field: `file` (`.csv` or `.txt`, max 2MB)

Expected columns (header row required):
```
full_name,phone
Jane Doe,972501234567
```

Response:
```json
{
  "message": "WhatsApp guests imported successfully",
  "data": {
    "imported": 10,
    "skipped": 2,
    "errors": ["Row 5: Invalid phone number"]
  }
}
```

### Download import template
`GET /api/events/{event_id}/whatsapp/guests/import-template`

Returns CSV file attachment.

---

## Campaigns (max 3 per event)

### List campaigns
`GET /api/events/{event_id}/whatsapp/campaigns`

Response:
```json
{
  "message": "WhatsApp campaigns fetched successfully",
  "data": {
    "campaigns": [
      {
        "id": 1,
        "message": "Hello!",
        "send_mode": "immediate",
        "scheduled_at": null,
        "status": 3,
        "recipient_count": 42,
        "sent_count": 42,
        "failed_count": 0,
        "queued_at": "2026-05-24T10:00:00.000000Z",
        "started_at": "2026-05-24T10:00:01.000000Z",
        "completed_at": "2026-05-24T10:00:30.000000Z",
        "created_at": "2026-05-24T10:00:00.000000Z"
      }
    ],
    "remaining_sends": 2
  }
}
```

**Campaign status values:** `0` pending, `1` queued, `2` sending, `3` completed, `4` partial, `5` failed, `6` cancelled

### Create send
`POST /api/events/{event_id}/whatsapp/campaigns`

Body:
```json
{
  "message": "Hello from our event!",
  "send_mode": "immediate",
  "guest_ids": [1, 2, 5]
}
```

- `guest_ids` optional — omit or empty = all guests selected
- `send_mode`: `"immediate"` or `"scheduled"`
- When scheduled, include `"scheduled_at": "2026-05-25T14:00:00"` (must be future)

Response `202`:
```json
{
  "message": "WhatsApp campaign queued successfully",
  "data": {
    "campaign": { "id": 1, "status": 1, "recipient_count": 42 },
    "remaining_sends": 2
  }
}
```

Error when limit reached: `"You have reached the maximum of 3 WhatsApp messages for this event"`

### Campaign detail
`GET /api/events/{event_id}/whatsapp/campaigns/{campaign_id}`

Response includes per-recipient delivery status:
```json
{
  "data": {
    "campaign": { "...": "..." },
    "recipients": [
      {
        "guest_id": 1,
        "full_name": "Jane Doe",
        "status": 1,
        "sent_at": "2026-05-24T10:00:05.000000Z",
        "error_message": null
      }
    ]
  }
}
```

**Recipient status values:** `0` pending, `1` sent, `2` failed

### Cancel scheduled send
`POST /api/events/{event_id}/whatsapp/campaigns/{campaign_id}/cancel`

Only works when campaign `status === 0` (pending).

---

## UI Requirements

### 1. Guest list table
- Columns: checkbox (send selection), full name, phone, actions (edit/delete)
- Inline or modal edit for name/phone
- "Add guest" button
- "Import CSV" upload + "Download template" link
- Empty state when no guests

### 2. Send message form
- Message textarea (4096 char counter)
- Guest checklist — **all checked by default**; user can uncheck individuals
- Toggle: "Send now" vs "Schedule"
- Date/time picker when scheduled (must be future)
- Submit disabled when: no guests, no message, 0 recipients selected, or `remaining_sends === 0`
- Banner: "You have {remaining_sends} of 3 messages remaining for this event"

### 3. Send history
- Table: date, message preview, recipients, sent/failed counts, status
- Cancel button for pending scheduled sends (`status === 0`)
- Detail view with per-guest delivery status

---

## Notes

- Legacy `POST /api/whatsapp/send-bulk` has been removed; use campaigns API instead.
- Scheduled sends are picked up by the server scheduler every minute (`whatsapp:dispatch-scheduled`).
- Production requires a persistent queue driver (`database` or `redis`) and `php artisan queue:work`.
