# TEST_CHECKLIST_CHAT.md
Phase 1 Must-pass tests (T1–T9)

## Scope
Backend + minimal PWA onboarding flow:
- request-access queue
- admin approve/deny/wait
- refresh/me status changes

## Must-pass tests (T1–T9)
T1. Request access (new device)
- POST /auth/request-access with valid `X-Device-Id` and nickname.
- Expect status `pending`, `approval_code`, `expires_at`.

T2. Request access (existing pending)
- Repeat POST /auth/request-access with same device + nickname.
- Expect status `pending` and same or refreshed `approval_code`.

T3. Request access (invalid nickname/device)
- Invalid nickname or device id should return 400 with error.

T4. Admin queue list (WP admin)
- Open Inventura -> Chat – Ziadosti o pristup.
- Pending request is visible with code + expiry.

T5. Admin approve
- Approve request in admin UI (or REST approve endpoint).
- Expect status `active` and user created/linked.

T6. Admin deny
- Deny request in admin UI (or REST deny endpoint).
- Expect status `denied`.

T7. Admin wait
- Set request to waiting in admin UI (or REST wait endpoint).
- Expect status `waiting`.

T8. Refresh/me (pending -> expired)
- After `expires_at`, refresh/me returns `status=expired`.
- Admin list should show `expired` for that request.

T9. Refresh/me (active)
- After approval, refresh/me returns `status=active` + user.

## Backend coverage
Covered by backend:
- REST endpoints: request-access, refresh, me, admin access-requests + approve/deny/wait.
- Admin queue page (approve/deny/wait).
- Audit log entries for access actions.

## Pending on PWA
- Onboarding UI and validation.
- Polling refresh/me, handling pending/expired/active.
- Display approval code + expiry, retry flow on expired.
