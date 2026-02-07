# PWA Rebuild TODO (Phase 1)

## Scaffold
- [ ] Vytvorit novy repo (napr. `bc-inventura-pwa`).
- [ ] Inicializovat Vite + React + TypeScript.
- [ ] Nastavit lint/format (minimalne ESLint + Prettier).
- [ ] Pridat `.env.example` s `VITE_API_BASE_URL`.

## App skeleton
- [ ] Zakladny routing (onboarding, chat placeholder).
- [ ] API klient s base URL + `X-Device-Id` header.
- [ ] Minimalne UI komponenty (Button, Input).
- [ ] Zakladne globalne styly (base.css).

## Onboarding flow
- [ ] Input `nickname` (2-32 znakov, len pismena/cisla/medzery/.-_).
- [ ] `POST /auth/request-access`.
- [ ] Zobrazit `approval_code` + `expires_at` pri `pending`.
- [ ] Stav `expired` + akcia "Poslat novu ziadost".
- [ ] Polling `POST /auth/refresh` alebo `GET /me` (5-10s).
- [ ] Pri `active` prechod do chatu.

## Chat placeholder
- [ ] Minimalny placeholder screen (bez realneho chatu).

## Docs
- [ ] README s install/run/build krokmi.
- [ ] Popis API kontraktov a Phase 1 scope.

## Out of scope
- [ ] badges / tasks / Hotovo
- [ ] zvuky / notifikacie / voice
