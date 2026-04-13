# PayPlus Integration Guide for Cursor

Last updated: 2026-04-03

This file is designed for an AI coding agent (Cursor) to implement a secure PayPlus integration for subscription plan purchases.

## Important note about extraction quality

This guide is based on the official PayPlus documentation pages and official search snippets. Some exact request/response schemas are not fully visible through the public docs renderer/search index. Wherever the docs renderer did not expose the full schema, the field is marked as **VERIFY IN DOCS UI** before shipping.

Use this file as the implementation plan + context pack. During coding, the agent should still open the official docs pages in the browser and verify final field names and example payloads before merging.

---

## 1) Scope of this implementation

Goal:
- Create payment links for the 2 paid subscription plans.
- Store those links in the DB (`subscriptions` table or related payment config table).
- Return them from the subscriptions API.
- Show the payment page inside an iframe when the user clicks `רכוש תוכנית`.
- Replace the current `coming soon` button with a real purchase flow.
- Handle callback / notification flow for success, failure, and cancel-like outcomes.
- Enable invoice generation with `initial_invoice = true`.
- Ensure the client receives the invoice.
- Ensure a merchant/admin email also receives a copy or follow-up notification.
- Build the integration with security-first assumptions.

---

## 2) Official docs pages to use

### Core Payment Pages docs
- Generate Payment Link  
  `https://docs.payplus.co.il/reference/post_paymentpages-generatelink`
- Payment Pages List  
  `https://docs.payplus.co.il/reference/get_paymentpages-list`
- Available Charge Methods  
  `https://docs.payplus.co.il/reference/get_paymentpages-chargemethods`
- Disable Payment Link Request  
  `https://docs.payplus.co.il/reference/post_paymentpages-disable-page-request-uid`
- IPN  
  `https://docs.payplus.co.il/reference/post_paymentpages-ipn`
- IPN FULL  
  `https://docs.payplus.co.il/reference/post_paymentpages-ipn-full`
- Successful Redirect Response  
  `https://docs.payplus.co.il/reference/get_successful-redirect-response`
- Failure Redirect Response  
  `https://docs.payplus.co.il/reference/get_failure-redirect-response`

### Security / auth docs
- Validate Requests Received from PayPlus  
  `https://docs.payplus.co.il/reference/validate-requests-received-from-payplus`
- PayPlus REST API Environment URLs  
  `https://docs.payplus.co.il/reference/payplus-rest-api-urls`

### Invoice / post-payment support docs
- Get Documents By Transaction UID  
  `https://docs.payplus.co.il/reference/post_invoice-getdocuments`

---

## 3) Known endpoints extracted from docs

### Payment Pages endpoints
1. `GET /api/v1.0/PaymentPages/list/`
2. `GET /api/v1.0/PaymentPages/ChargeMethods`
3. `POST /api/v1.0/PaymentPages/generateLink`
4. `POST /api/v1.0/PaymentPages/Disable/{page_request_uid}`
5. `POST /api/v1.0/PaymentPages/ipn`
6. `POST /api/v1.0/PaymentPages/ipn-full`
7. `GET /api/v1.0/successful-redirect-response`
8. `GET /api/v1.0/failure-redirect-response`

### Support endpoint
9. `POST /api/v1.0/Invoice/GetDocuments`

---

## 4) Authentication headers for server-to-server API calls

The official docs search snippets show that PayPlus API requests use these headers:

- `api-key` (required)
- `secret-key` (required)

### Backend rule
Never expose these values to the client.
All calls to PayPlus must be made from backend-only services.

---

## 5) Callback / webhook validation context

From the official PayPlus validation docs/search snippets:

- Incoming payloads from PayPlus include a `hash` header.
- Incoming payloads also include a `user-agent` header.
- The docs explicitly say to use your API secret key to verify that the data was sent by PayPlus.

### Security rule
Do **not** trust callback bodies without verifying the docs-defined hash/signature logic.

### Current limitation
The exact validation algorithm/formula was **not fully exposed** in the public renderer/search snippet available to me.

### Mandatory implementation task
Before shipping, the agent must open the official validation doc in a browser and copy the **exact verification algorithm** into code.

### Minimum safe webhook policy
Even before the exact algorithm is copied from the docs UI:
- Require HTTPS only.
- Reject requests without `hash` header.
- Log `user-agent`, but do **not** rely on it alone for trust.
- Reject malformed JSON.
- Enforce idempotency.
- Compare amounts/currency/subscription references against internal DB state.
- Mark notifications as untrusted until hash verification passes.
- Never upgrade a subscription based only on redirect URL hit from browser.

---

## 6) Known Generate Payment Link request fields extracted from official snippets

The following request fields were visible in official docs/search snippets for `POST /PaymentPages/generateLink`.

### Core fields
- `payment_page_uid` or equivalent field for: **UID of the Payment Page**  
  **VERIFY EXACT FIELD NAME IN DOCS UI**
- `charge_method` — integer enum, default `1`
  - Visible enum values from snippet:
    - `0` = Check (`J2`)
    - `1` = Charge (`J4`)
    - `2` = Approval (`J5`)
    - `3` = Recurring Payments
    - `4` = Refund (`J4`)
    - `5` = **VISIBLE BUT DESCRIPTION TRUNCATED — VERIFY IN DOCS UI**
- `amount` — mentioned by docs page description, exact schema not visible in extracted renderer
- `currency_code` — string, required, default `ILS`

### Redirect / callback related fields
- `refURL_success` — string, nullable  
  docs snippet says default example: `https://www.domain.com/success/`  
  docs note: if sent, PayPlus uses it instead of payment page settings.
- `refURL_failure` — string  
  visible in docs snippet, exact constraints not fully visible.
- `expiry_datetime` — docs snippet says type `string`, but description says `number, minutes until page expired`; this is inconsistent in snippet extraction.  
  **VERIFY EXACT TYPE/FORMAT IN DOCS UI**.

### Email / invoice fields
- `sendEmailApproval` — boolean, required, default `true`  
  docs: send email for successful transaction.
- `sendEmailFailure` — boolean, required, default `false`  
  docs: send email for failed transaction.
- `initial_invoice` — boolean, default `true`, nullable  
  docs note: if Invoice+ is connected and active in the payment page, you can decide per transaction whether to generate invoice/document.

### Tokenization related field
- `create_token` — boolean, default `false`, nullable  
  docs note: relevant if tokenization permission exists.

### Possibly related field from snippet
- `charge_default` — string enum, nullable  
  snippet indicates values `0..5` and says if not set, default is what is set in payment page settings.  
  **VERIFY PURPOSE EXACTLY IN DOCS UI**.

### Customer object
The docs/search snippets clearly reference a `customer` object and a `customer_name` parameter inside it. Full nested schema was not fully exposed in the renderer.

Expected likely fields to verify in docs UI:
- `customer_name`
- `email`
- `phone`
- possibly `vat_number` / `id_number` / address-style fields

**Do not guess final nested field names in production code without checking docs UI.**

---

## 7) Known support endpoint payload extracted from docs

### POST `/api/v1.0/Invoice/GetDocuments`
Visible from official docs snippets:
- body contains a required `filter` object
- `filter.transaction_uid` is used to fetch documents by transaction UID

Use case for us:
- After successful payment/IPN, fetch generated invoice/document by transaction UID.
- Store document metadata in DB.
- Optionally email the document or a notification to the merchant/admin email.

---

## 8) Response data: what is definitely known vs what must be verified

### Definitely known from docs
- Generate link endpoint returns a response containing enough information to use the generated payment request/link.
- Disable endpoint uses `page_request_uid` path param.
- Redirect callbacks exist for both success and failure.
- IPN and IPN FULL endpoints exist for server-to-server payment updates.
- Invoice documents can be fetched by `transaction_uid`.

### Response fields that must be verified in docs UI
For `generateLink`, verify the exact names of:
- generated payment URL field
- generated request UID field (`page_request_uid` or equivalent)
- status/success flag field
- error structure field(s)

For `ipn` and `ipn-full`, verify exact payload fields, especially:
- transaction uid
- page request uid
- payment page uid
- status / result code
- amount
- currency
- customer email
- invoice/document identifiers
- failure reason / cancellation reason
- authorization / approval identifiers

For redirect success/failure callbacks, verify exact query params or body payload fields in docs UI.

---

## 9) Recommended product/data model changes

### Option A — minimal change in existing `subscriptions` table
Add fields like:
- `payplus_payment_page_uid` nullable string
- `payplus_charge_method` nullable int
- `payplus_link_url` nullable text
- `payplus_link_request_uid` nullable string
- `payplus_link_generated_at` nullable datetime
- `payplus_link_expires_at` nullable datetime
- `payplus_link_is_active` boolean default true
- `payplus_currency_code` varchar default `ILS`
- `payplus_initial_invoice` boolean default true

### Option B — cleaner design (recommended)
Keep `subscriptions` as product/plan records, and create a separate table such as `subscription_payment_links`.

Suggested table:
- `id`
- `subscription_id`
- `provider` = `payplus`
- `payment_page_uid`
- `page_request_uid`
- `charge_method`
- `currency_code`
- `amount`
- `payment_url`
- `expires_at`
- `is_active`
- `request_payload_json`
- `response_payload_json`
- `last_generated_at`
- `created_at`
- `updated_at`

Why this is better:
- preserves historical links
- supports regeneration
- supports multiple environments
- supports future providers
- helps audit/debugging/security reviews

---

## 10) Recommended internal backend architecture

### Modules / services
- `PayplusConfigService`
- `PayplusHttpClient`
- `PayplusPaymentPagesService`
- `PayplusWebhookService`
- `PayplusInvoiceService`
- `SubscriptionPaymentLinkService`
- `SubscriptionCheckoutService`

### Responsibilities

#### `PayplusConfigService`
- read env vars
- validate required config at boot
- expose strongly typed config

#### `PayplusHttpClient`
- centralize base URL + headers
- attach `api-key` and `secret-key`
- safe timeout/retry policy
- redact secrets in logs
- normalize provider errors

#### `PayplusPaymentPagesService`
- list payment pages
- list charge methods
- generate payment link
- disable payment link

#### `SubscriptionPaymentLinkService`
- generate-or-reuse plan link
- persist request/response
- expose link via internal API
- invalidate old link if regenerating

#### `PayplusWebhookService`
- verify signature/hash
- parse IPN/IPN FULL callbacks
- enforce idempotency
- update purchase/subscription/payment state

#### `PayplusInvoiceService`
- fetch documents by transaction UID
- persist invoice metadata
- notify merchant email if needed

---

## 11) Recommended application flow

### Flow A — admin/bootstrap preparation
1. Identify the 2 paid plans in your DB.
2. Find the PayPlus payment page UID(s) that should be used.
3. Decide if you use one payment page for all plans or one page per plan.
4. Decide if amount comes from plan price in your DB or from payment page defaults.
5. Decide whether link generation is:
   - eager (generated once and stored), or
   - lazy (generated on first request / regenerated if expired).

### Recommendation
Use **lazy generation with caching**:
- if active non-expired link exists for plan -> return it
- else generate a new link, store it, return it

---

### Flow B — generate payment link for a subscription plan
1. Client requests subscription plans.
2. Backend returns plans.
3. For paid plans, backend optionally includes `checkout.type = 'iframe_payplus'` and `checkout.paymentLinkUrl` if already cached.
4. If link missing/expired, backend generates a new one via PayPlus.
5. Backend stores provider payload + response.
6. Backend returns the generated link to client.

### Minimum request payload recommendation for generateLink
Use only fields you truly need:
- payment page UID
- charge method = charge/J4 unless product requires something else
- amount
- currency_code = `ILS`
- refURL_success
- refURL_failure
- sendEmailApproval = true
- sendEmailFailure = false (or true if you want user failures mailed)
- initial_invoice = true
- customer object (name/email/phone after verifying exact schema)
- expiry field if you want short-lived checkout links

---

### Flow C — client checkout UI
1. Replace `Coming Soon` with `Buy` button (`רכוש תוכנית`).
2. On click, call backend endpoint to get checkout data.
3. Render payment link in iframe.
4. Track UI states:
   - idle
   - loading link
   - iframe shown
   - redirect success observed
   - redirect failure observed
   - timed out / abandoned

### Important UX/security rule
The frontend iframe result is **not authoritative**.
Only backend-verified IPN/IPN FULL should finalize the subscription.

---

### Flow D — payment completion handling
Use **two channels**:

#### 1) Redirect callbacks (browser UX only)
- `refURL_success`
- `refURL_failure`

Purpose:
- show success/failure message to user
- refresh order status screen
- poll backend for final status

#### 2) IPN / IPN FULL (authoritative backend state)
Purpose:
- receive server-to-server update from PayPlus
- verify authenticity
- update DB transaction/subscription state
- fetch invoice/document if success

### Rule
Never activate a subscription only because the browser hit `success` URL.

---

## 12) Success / failure / cancel handling strategy

### Success
Source of truth:
- IPN/IPN FULL verified by backend

State changes:
- mark payment as paid
- mark subscription as active
- store transaction UID
- fetch invoice documents (if generated)
- notify user/admin if needed

### Failure
Source of truth:
- failure redirect for UI
- IPN/IPN FULL / provider status for authoritative backend state

State changes:
- mark payment attempt as failed
- store provider failure code/reason
- do not activate subscription

### Cancel
Important: in the accessible docs content I could confirm **success redirect** and **failure redirect**, but I could **not** confirm a dedicated cancel redirect field/page.

Therefore use this policy unless docs UI confirms a dedicated cancel callback:
- treat browser close/back/abandon as `abandoned` locally in your app
- treat provider-declared non-success terminal status as failure/cancel depending on actual status code
- if user exits iframe and no verified IPN success arrives within timeout window, mark attempt as `abandoned`

### Implementation note
Your internal payment state enum should support:
- `pending`
- `paid`
- `failed`
- `cancelled`
- `abandoned`
- `expired`
- `invalid_signature`

---

## 13) Invoice behavior plan

### What docs confirm
- `initial_invoice` exists and defaults to true in generateLink docs context if invoice capability is active.
- `sendEmailApproval` exists and defaults to true.

### What to implement
For the checkout request:
- set `initial_invoice = true`
- set `sendEmailApproval = true`

### To make sure the client gets the invoice
- include customer email in the request (exact field to verify in docs UI)
- keep `sendEmailApproval = true`
- after success callback, fetch document by `transaction_uid` and log whether a document exists

### To make sure you also get a merchant/admin copy
This is **not fully documented in the accessible snippets** as a dedicated request field.

Recommended fallback-safe approach:
1. After successful verified payment, call `Invoice/GetDocuments` with `transaction_uid`.
2. Save returned document metadata/URL.
3. Send your own application email to `PAYPLUS_MERCHANT_NOTIFICATION_EMAIL` with:
   - subscription name
   - customer details
   - transaction uid
   - document link / document metadata
4. If PayPlus has account-level CC/notification settings in dashboard, configure them there too.

This approach avoids depending on an undocumented inline merchant-email field.

---

## 14) Env vars to add

```env
# PayPlus API
PAYPLUS_ENABLED=true
PAYPLUS_BASE_URL=https://restapi.payplus.co.il/api/v1.0
PAYPLUS_API_KEY=
PAYPLUS_SECRET_KEY=

# Payment pages / plan mapping
PAYPLUS_DEFAULT_CURRENCY=ILS
PAYPLUS_PLAN_BASIC_PAYMENT_PAGE_UID=
PAYPLUS_PLAN_PRO_PAYMENT_PAGE_UID=
PAYPLUS_DEFAULT_CHARGE_METHOD=1
PAYPLUS_LINK_EXPIRY_MINUTES=30
PAYPLUS_INITIAL_INVOICE=true
PAYPLUS_SEND_EMAIL_APPROVAL=true
PAYPLUS_SEND_EMAIL_FAILURE=false

# Callback / redirects
PAYPLUS_REF_URL_SUCCESS=https://your-domain.com/payments/payplus/success
PAYPLUS_REF_URL_FAILURE=https://your-domain.com/payments/payplus/failure
PAYPLUS_IPN_PUBLIC_URL=https://your-domain.com/api/payplus/webhooks/ipn
PAYPLUS_IPN_FULL_PUBLIC_URL=https://your-domain.com/api/payplus/webhooks/ipn-full

# Merchant notifications
PAYPLUS_MERCHANT_NOTIFICATION_EMAIL=your-email@domain.com

# Security
PAYPLUS_ALLOWED_CLOCK_SKEW_SECONDS=300
PAYPLUS_WEBHOOK_TIMEOUT_SECONDS=10
PAYPLUS_IDEMPOTENCY_TTL_HOURS=48
```

### Env notes
- Never prefix these with public/client-exposed prefixes.
- Validate at app boot.
- Secrets must not appear in logs or API responses.

---

## 15) Suggested backend endpoints in your own app

### Public/app-facing endpoints
- `GET /api/subscriptions`
  - returns plans
  - includes checkout metadata for paid plans

- `POST /api/subscriptions/:id/checkout-link`
  - generates or reuses PayPlus link
  - returns `{ provider, paymentUrl, expiresAt, providerRequestUid }`

### PayPlus callback endpoints
- `GET /payments/payplus/success`
  - browser redirect target
  - UX only

- `GET /payments/payplus/failure`
  - browser redirect target
  - UX only

- `POST /api/payplus/webhooks/ipn`
  - provider server-to-server notification

- `POST /api/payplus/webhooks/ipn-full`
  - provider full notification

### Recommendation
Make redirect endpoints separate from webhook endpoints.
Do not reuse a generic `StoreController` callback blindly.

---

## 16) What to check in the existing `StoreController` callback

I could **not** inspect your actual `StoreController` file because it was not available in the conversation or file library.

When reviewing it, verify these exact points:

### It is correct only if it does all of the following
- separates browser redirect handling from provider webhook handling
- verifies PayPlus callback authenticity (`hash` / docs validation algorithm)
- uses idempotency lock / duplicate protection
- does not trust client-provided status alone
- matches incoming payment attempt to internal purchase/subscription row
- compares expected amount/currency/plan against DB
- records raw payload safely for audit
- updates payment state transactionally
- only activates subscription after verified success
- handles repeated webhook delivery safely
- handles failure path without activating subscription
- handles abandoned/cancel-like path without activating subscription

### Red flags
If your existing callback:
- trusts query params directly
- activates subscription on redirect success only
- does not verify provider authenticity
- does not compare amount/currency/plan
- has no idempotency handling
- mixes payment providers in one fragile generic callback without provider-specific validation

then it is **not sufficient** for this integration.

---

## 17) DB records to add for observability and audits

### `payment_attempts` (recommended)
- `id`
- `provider`
- `subscription_id`
- `user_id`
- `status`
- `expected_amount`
- `expected_currency`
- `provider_payment_page_uid`
- `provider_page_request_uid`
- `provider_transaction_uid`
- `provider_charge_method`
- `provider_customer_email`
- `provider_document_uid`
- `failure_code`
- `failure_message`
- `redirect_success_received_at`
- `redirect_failure_received_at`
- `ipn_received_at`
- `ipn_full_received_at`
- `verified_at`
- `paid_at`
- `raw_generate_request_json`
- `raw_generate_response_json`
- `raw_last_webhook_json`
- `raw_headers_json`
- timestamps

### `payment_webhook_events`
- `id`
- `provider`
- `event_type` (`ipn`, `ipn_full`, `redirect_success`, `redirect_failure`)
- `dedupe_key`
- `signature_valid`
- `processed`
- `payload_json`
- `headers_json`
- `error_message`
- timestamps

---

## 18) Security requirements (must implement)

### Secrets and config
- Store `api-key` and `secret-key` only in env/secret manager.
- Never expose secrets to frontend.
- Never log secrets.

### Webhook authenticity
- Implement the exact PayPlus signature/hash verification from docs.
- Reject unsigned or invalid requests.
- Log invalid attempts.

### Idempotency
- Deduplicate webhook events using stable key(s):
  - provider event id if available
  - else transaction uid + event type + status
  - else page request uid + event type

### State verification
Before activating subscription, compare against internal state:
- subscription id / plan mapping
- expected amount
- expected currency
- non-expired / valid attempt
- not already paid by another successful transaction unless explicitly allowed

### Redirect hardening
- Redirect endpoints must be UX-only.
- They may set a UI state / session state, but not finalize access.

### Request hardening
- Strict schema validation for inbound payloads.
- Reject extra malformed fields where practical.
- Use request size limits.
- Use timeouts.

### Logging / privacy
- Redact PII where not needed.
- Keep raw provider payloads in secure storage only if justified.
- Avoid logging full card-related values or sensitive invoice/customer data.

---

## 19) Testing checklist

### Unit tests
- config validation fails if env missing
- generateLink request mapping is correct
- webhook signature verification passes/fails correctly
- idempotency prevents duplicate processing
- success callback activates subscription only once
- failure callback never activates subscription
- abandoned/cancel-like flow does not activate subscription
- invoice fetch service handles empty/non-empty results

### Integration tests
- create link for paid plan
- reuse existing link if still active
- regenerate link when expired
- process valid success IPN
- reject invalid signature IPN
- process failure IPN
- process duplicate IPN without double activation
- fetch invoice documents after success

### Manual QA
- click `רכוש תוכנית`
- iframe opens
- successful payment updates backend state
- user sees success page
- subscription becomes active only after backend verification
- failed payment shows failure UI
- invoice is generated and retrievable
- merchant notification email is sent

---

## 20) Suggested implementation order for Cursor

### Phase 1 — foundation
1. Add env vars and config validation.
2. Build `PayplusHttpClient`.
3. Build typed DTOs/interfaces from docs-visible fields.
4. Add DB tables/migrations.

### Phase 2 — outbound API
5. Implement `listPaymentPages()`.
6. Implement `listChargeMethods()`.
7. Implement `generatePaymentLink()`.
8. Implement `disablePaymentLink()`.

### Phase 3 — subscription integration
9. Implement `generateOrReuseSubscriptionCheckoutLink(subscriptionId)`.
10. Expose link via your subscriptions/order API.
11. Return provider metadata for client rendering.

### Phase 4 — frontend
12. Replace `coming soon` with `buy` CTA.
13. Load checkout link on click.
14. Render iframe.
15. Add status polling after redirect/iframe completion.

### Phase 5 — webhooks / redirects
16. Add success redirect endpoint.
17. Add failure redirect endpoint.
18. Add IPN endpoint.
19. Add IPN FULL endpoint.
20. Add signature validation + idempotency + transactional updates.

### Phase 6 — invoices / notifications
21. Fetch documents by transaction UID on success.
22. Save document metadata.
23. Send merchant/admin notification email.

### Phase 7 — hardening
24. Add tests.
25. Add observability dashboards/logging.
26. Review all security rules before release.

---

## 21) Cursor agent instructions

Use these rules while implementing:

1. Do not invent undocumented provider field names if docs UI can verify them.
2. Prefer small provider-specific modules over a giant generic payment service.
3. Keep browser redirect flows separate from authoritative server-to-server flows.
4. Never activate subscription from frontend-only success signal.
5. All PayPlus provider payloads must be validated and logged safely.
6. All state changes must be idempotent and transactional.
7. Add tests around signature verification and duplicate delivery.
8. Default to explicit enum/status mapping, not loose string comparisons.
9. Keep provider raw payloads for audit, but redact secrets.
10. If docs ambiguity exists, leave a `VERIFY_WITH_PAYPLUS_DOCS_UI` comment in code instead of guessing.

---

## 22) Recommended open questions to verify inside docs UI before final merge

1. Exact request field name for payment page UID in `generateLink`.
2. Exact response field names for generated payment URL and page request UID.
3. Full `charge_method` enum list (value `5` description was truncated in snippet).
4. Exact `customer` object schema.
5. Exact type/format of `expiry_datetime`.
6. Whether there is a dedicated cancel redirect/callback field beyond failure redirect.
7. Exact payload schemas for `ipn` and `ipn-full`.
8. Exact callback verification algorithm for `hash` + `secret-key`.
9. Whether PayPlus supports merchant/admin CC email directly in generateLink payload.
10. Exact response schema of `Invoice/GetDocuments`.

---

## 23) Recommended final architecture decision

### Recommended production approach
- Use Payment Pages + generateLink for checkout.
- Use backend-generated links only.
- Store generated links and provider request UIDs in DB.
- Use iframe in frontend for UX.
- Use IPN/IPN FULL as source of truth.
- Use redirect success/failure only for UX.
- Set `initial_invoice = true`.
- Set `sendEmailApproval = true`.
- Fetch invoice docs after successful verified payment.
- Send your own merchant/admin notification email from backend.
- Block activation unless signature and business validations both pass.

This is the safest implementation model for your use case.

