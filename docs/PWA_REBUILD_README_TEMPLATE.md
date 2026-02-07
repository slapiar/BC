# PWA Rebuild (Phase 1)

## Scope
Minimalny PWA scaffold (Vite + React + TypeScript) so zamerom na Phase 1:
- Onboarding s nickname.
- Request-access flow (pending/expired/active).
- Prechod do chatu po approve.

Mimo scope: badges, tasks, Hotovo, zvuky, notifikacie.

## Requirements
- Node.js 18+ (odporucane 20+).
- npm alebo pnpm.

## Setup
1. `npm install`
2. `cp .env.example .env`
3. `npm run dev`

## Env
- `VITE_API_BASE_URL` - URL na WP REST API (napr. `https://example.com/wp-json/bc-inventura/v1`).
- `VITE_DEVICE_ID` (volitelne) - fixny device id pre lokalne testy.

## Scripts
- `npm run dev` - lokalny dev server.
- `npm run build` - produkcny build.
- `npm run preview` - preview build.

## API (Phase 1)
- `POST /auth/request-access`
  - Header: `X-Device-Id`
  - Body: `{ "nickname": "..." }`
  - Response: `status=pending|active` + `approval_code`, `expires_at`.
- `POST /auth/refresh` alebo `GET /me`
  - Ak `status=active` -> prechod do chatu.
  - Ak `status=pending|waiting|denied|expired` -> zobrazit stav.

## UX
- Onboarding input: 2-32 znakov, len pismena/cisla/medzery/.-_.
- Pri `pending`: zobrazit `approval_code` a `expires_at`.
- Pri `expired`: zobrazit akciu "Poslat novu ziadost".
- Pri `active`: prechod do chatu (placeholder).

## Struktura (navrh)
- `src/`
  - `main.tsx`
  - `App.tsx`
  - `api/`
    - `client.ts`
    - `auth.ts`
  - `features/onboarding/`
    - `OnboardingScreen.tsx`
    - `validators.ts`
  - `features/chat/`
    - `ChatPlaceholder.tsx`
  - `ui/`
    - `Button.tsx`
    - `Input.tsx`
  - `styles/`
    - `base.css`

## Poznamky
- Build-only ZIP z /import/ je iba docasne nasadenie, nie vyvojovy zaklad.
- Phase 1 neobsahuje realny chat ani dalsie feature.
