### 2.3 Matching Engine (Double Opt-In Architecture)
The backend uses a standard asynchronous non-blocking double opt-in mechanism.
* **Action Types:** `LIKE` or `PASS`.
* **State Resolution:** When User A `LIKE`s User B, the backend checks for an existing `LIKE` from User B to User A.
  * If exists: Status changes to `MATCHED`. Trigger Match Notification webhook.
  * If none exists: Store the interaction row with state `PENDING`.

---

## 3. Database Schema & Data Models (Relational/PostgreSQL)

To ensure high performance, ACID compliance for financial/transactional states of matching, and precise indexing, a relational structure is recommended.

```sql
-- 1. EXTENSION FOR UUID GENERATION
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";

-- 2. ENUMS
CREATE TYPE icebreaker_status AS ENUM ('DISABLED', 'UPCOMING', 'ACTIVE', 'COOLDOWN', 'ARCHIVED');
CREATE TYPE interaction_type AS ENUM ('LIKE', 'PASS');
CREATE TYPE match_intent AS ENUM ('ROMANCE', 'SOCIAL', 'CARPOOL', 'NETWORKING');

-- 3. EVENT CONFIGURATION EXTENSION
-- Extends the existing core events table
CREATE TABLE event_icebreaker_configs (
    event_id UUID PRIMARY KEY, -- FK to core_events.id
    status icebreaker_status NOT NULL DEFAULT 'DISABLED',
    allowed_intents match_intent[] DEFAULT '{ROMANCE, SOCIAL, CARPOOL}',
    started_at TIMESTAMP WITH TIME ZONE,
    ended_at TIMESTAMP WITH TIME ZONE,
    purged_at TIMESTAMP WITH TIME ZONE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- 4. ICEBREAKER USER PROFILES
CREATE TABLE icebreaker_profiles (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    event_id UUID NOT NULL REFERENCES event_icebreaker_configs(event_id) ON DELETE CASCADE,
    user_session_id VARCHAR(255) NOT NULL, -- Tracks anonymous or authenticated user session
    display_name VARCHAR(50) NOT NULL,
    avatar_url TEXT NOT NULL, -- S3 URL of the profile image
    gender VARCHAR(20),
    target_genders VARCHAR(20)[], -- Array of preferences for matching filters
    primary_intent match_intent NOT NULL,
    bio VARCHAR(150),
    instagram_handle VARCHAR(50),
    whatsapp_number VARCHAR(20),
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT unique_event_user_session UNIQUE (event_id, user_session_id)
);

-- Indexing for rapid queries during feed generation
CREATE INDEX idx_profiles_event_intent ON icebreaker_profiles(event_id, primary_intent) WHERE is_active = TRUE;

-- 5. INTERACTIONS TABLE (SWIPES)
CREATE TABLE icebreaker_interactions (
    id BIGSERIAL PRIMARY KEY,
    event_id UUID NOT NULL REFERENCES event_icebreaker_configs(event_id) ON DELETE CASCADE,
    actor_profile_id UUID NOT NULL REFERENCES icebreaker_profiles(id) ON DELETE CASCADE,
    target_profile_id UUID NOT NULL REFERENCES icebreaker_profiles(id) ON DELETE CASCADE,
    action interaction_type NOT NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT unique_actor_target_interaction UNIQUE (actor_profile_id, target_profile_id)
);

CREATE INDEX idx_interactions_lookup ON icebreaker_interactions(target_profile_id, actor_profile_id);

-- 6. MATCHES TABLE
CREATE TABLE icebreaker_matches (
    id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    event_id UUID NOT NULL REFERENCES event_icebreaker_configs(event_id) ON DELETE CASCADE,
    profile_one_id UUID NOT NULL REFERENCES icebreaker_profiles(id) ON DELETE CASCADE,
    profile_two_id UUID NOT NULL REFERENCES icebreaker_profiles(id) ON DELETE CASCADE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT unique_match_pair UNIQUE (profile_one_id, profile_two_id),
    CONSTRAINT check_id_order CHECK (profile_one_id < profile_two_id) -- Prevents duplicate combinations (A,B) and (B,A)
);
4. API Endpoint Specifications
All endpoints require a validated header context identifying the X-Event-ID and the client session X-User-Session-Token.

4.1 GET /api/v1/events/{event_id}/icebreaker/config
Retrieves whether the feature is available and what intents are allowed.

Response (200 OK):

JSON
{
  "event_id": "8f4b23b6-1194-4b5c-ac19-0937a28848a2",
  "status": "ACTIVE",
  "allowed_intents": ["ROMANCE", "SOCIAL", "CARPOOL"],
  "stats": {
    "total_active_profiles": 142
  }
}
4.2 POST /api/v1/events/{event_id}/icebreaker/profiles
Creates or reactivates an attendee profile.

Request Body:

JSON
{
  "display_name": "Danielle",
  "avatar_url": "[https://cdn.snapshare-live.com/buckets/events/selfies/user_xyz.jpg](https://cdn.snapshare-live.com/buckets/events/selfies/user_xyz.jpg)",
  "gender": "FEMALE",
  "target_genders": ["MALE"],
  "primary_intent": "ROMANCE",
  "bio": "Team Bride! Looking for someone to dance with 💃",
  "instagram_handle": "danielle_bride_party",
  "whatsapp_number": "+972541234567"
}
Response (201 Created):

JSON
{
  "profile_id": "b0a68d80-ca09-436f-bfe1-95bd2de8cf90",
  "status": "ACTIVE"
}
4.3 GET /api/v1/events/{event_id}/icebreaker/discover
Fetches a paginated list of target profiles matching the current user's filters, excluding profiles already swiped (LIKE or PASS).

Query Parameters:

limit (default: 20)

intent (optional filter)

Response (200 OK):

JSON
{
  "profiles": [
    {
      "profile_id": "c1b58d91-da10-547f-cfe2-06bd2de8cf91",
      "display_name": "Guy",
      "avatar_url": "[https://cdn.snapshare-live.com/buckets/events/selfies/guy_photo.jpg](https://cdn.snapshare-live.com/buckets/events/selfies/guy_photo.jpg)",
      "primary_intent": "ROMANCE",
      "bio": "Friend of the Groom, down for tequila shots."
    }
  ],
  "has_more": false
}
Note: Sensitive details like whatsapp_number and instagram_handle are strictly hidden in discovery view.

4.4 POST /api/v1/events/{event_id}/icebreaker/interact
Submits a preference decision (Like or Pass).

Request Body:

JSON
{
  "target_profile_id": "c1b58d91-da10-547f-cfe2-06bd2de8cf91",
  "action": "LIKE"
}
Response (200 OK - No Match):

JSON
{
  "match_found": false
}
Response (200 OK - Match Triggered):

JSON
{
  "match_found": true,
  "match_details": {
    "match_id": "e3c88e92-fa11-4444-8888-999999999999",
    "display_name": "Guy",
    "instagram_handle": "guy_insta_handle",
    "whatsapp_number": "+972528888888"
  }
}
4.5 DELETE /api/v1/events/{event_id}/icebreaker/profiles/me
Opt-out mechanism. Soft-deletes user metadata immediately.

Response (204 No Content)

5. System Logic & Algorithmic Discovery
To minimize heavy SQL computations on discovery feeds during high-concurrency event peaks, the Discovery Query must be optimized.

5.1 Discovery Filtering SQL Logic
When generating a feed for User_A, the database must return profiles that:

Belong to the same event_id.

Have is_active = TRUE.

Match structural constraints (e.g., if intent is ROMANCE, verify gender compatibility via target_genders arrays).

Excluding: Profiles User_A has already interacted with.

Excluding: User_A themselves.

SQL
SELECT p.id, p.display_name, p.avatar_url, p.bio, p.primary_intent
FROM icebreaker_profiles p
WHERE p.event_id = :current_event_id
  AND p.id != :my_profile_id
  AND p.is_active = TRUE
  AND p.primary_intent = :my_intent
  -- Gender filtering logic for romance intent
  AND (:my_intent != 'ROMANCE' OR (p.gender = ANY(:my_target_genders) AND :my_gender = ANY(p.target_genders)))
  -- Exclusion logic
  AND NOT EXISTS (
      SELECT 1 
      FROM icebreaker_interactions i 
      WHERE i.actor_profile_id = :my_profile_id 
        AND i.target_profile_id = p.id
  )
LIMIT :limit;
6. Security, Privacy & Data Retention Regulations
Given that users are submitting personal intent information (e.g., dating preferences) dynamically at an event, security controls must be paramount.

6.1 Content Moderation (Anti-Abuse)
Image Moderation: Because the application integrates into the active gallery uploaded by photographers or user selfies, all image URLs used for avatars must pass through an automated AWS Rekognition / Google Vision API check to ensure no NSFW (Not Safe For Work) or explicit content is set as a profile image.

Text Moderation: Fields like display_name and bio must run through a basic regex profanity filter based on localized culture strings (Hebrew/English).

6.2 Data Lifespan & GDPR Compliance (The "Purge" Routine)
Data collected for the matchmaking engine must be treated as highly transient.

Automated Cron Job: A backend worker runs every hour checking for events whose ended_at timestamp is older than 48 hours and whose configuration status is COOLDOWN.

The Purge Protocol:

Cascade delete rows in icebreaker_interactions.

Cascade delete rows in icebreaker_matches.

Hard delete or anonymize rows in icebreaker_profiles (truncate phone numbers, names, and bios; delete or replace image URLs in the storage bucket).

Update event_icebreaker_configs.status to 'ARCHIVED'.

7. Scalability & Edge-Case Considerations
7.1 Real-Time Notifications
While a full WebSocket architecture can be complex to maintain, a mutual match requires real-time feedback if both users are currently browsing their gallery.

MVP Approach: Short-polling or SSE (Server-Sent Events) on the Match List endpoint while the user is actively on the Discovery page.

Alternative: Push notifications via WhatsApp (if utilizing WhatsApp API business gateways, trigger a text alert: "You have a new match at the wedding! Click here to see who...").

7.2 The "Empty State" Problem
At the beginning of an event, few people have signed up.

Backend Mitigation: If discovery results equal zero, the backend can return a structural metadata flag "suggest_onboarding_broadcast": true, triggering the frontend to render an illustrative instructional tutorial or gamified animation reminding the user to tell their table-mates to scan the QR code.
"""