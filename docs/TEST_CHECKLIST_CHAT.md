
# TEST_CHECKLIST_CHAT.md
Test checklist pre PWA Chat (onboarding, admin queue, badges, threads/rooms, tasks+Hotovo, delete+archive, voice, sound)

## Zasady
- Testovat po fazach. Kazda faza ma "must-pass" scenare.
- Ked zlyha must-pass, stopnut dalsie rozsirovanie a opravit.
- Vsetky testy su pouzitelne manualne; vybrane mozu byt automatizovane (Playwright/Cypress + REST tests).

---

# FAZA 1 — Onboarding + Admin approval queue + Personal identity

## 1.1 Onboarding UI (PWA)
- [ ] **T1** Neaktivne zariadenie vidi onboarding
  - Endpoint: `GET /me`
  - Expected HTTP: `401/403`
  - UI vysledok: zobrazi sa pole `nickname` + tlacidlo "Zadaj svoj nickname a Pripoj sa"

- [ ] **T2** Nickname validacia
  - Endpoint: `POST /auth/request-access`
  - Expected HTTP: `400`
  - UI vysledok: jasne hlasenie o neplatnom nickname

- [ ] **T3** Request access vytvori pending request
  - Endpoint: `POST /auth/request-access`
  - Expected HTTP: `200`
  - UI vysledok: "Caka na schvalenie" + zobrazi nickname + approval_code

- [ ] **T4** Pending expiracia (cas/cron)
  - Endpoint: `GET /me`
  - Expected HTTP: `401/403`
  - UI vysledok: "expirovane" + moznost poslat novu ziadost

## 1.2 Admin queue (WP admin)
- [ ] **T5** Admin vidi zoznam ziadosti
  - Endpoint: `GET /admin/access-requests`
  - Expected HTTP: `200`
  - UI vysledok: tabulka s nickname, created_at, device_id (skratene), status

- [ ] **T6** Approve jednym klikom
  - Endpoint: `POST /admin/access-requests/{id}/approve`
  - Expected HTTP: `200`
  - UI vysledok: device.status=active, user priradeny, audit zaznam, ziadost approved

- [ ] **T7** Deny a Wait
  - Endpoint: `POST /admin/access-requests/{id}/deny` a `POST /admin/access-requests/{id}/wait`
  - Expected HTTP: `200`
  - UI vysledok: denied -> PWA nerefrešuje, wait -> ziadost ostava oznacena waiting

- [ ] **T8** Disconnect / Remove from chat
  - Endpoint: `POST /admin/users/{user_id}/disconnect` a `POST /admin/users/{user_id}/remove-from-chat`
  - Expected HTTP: `200`
  - UI vysledok: sessions revoked, device->revoked, log v audite

## 1.3 Post-approve (PWA)
- [ ] **T9** Po approve sa PWA prihlasi bez rucneho zasahu
  - Endpoint: `POST /auth/refresh`
  - Expected HTTP: `200`
  - UI vysledok: PWA prejde do chatu, v hlavicke je nickname

---

# FAZA 2 — Rooms/Threads + BadgeBar + “for_me” (cervene)

## 2.1 Thread vs Room
- [ ] **T10** Direct thread ID je deterministicky
  - Endpoint: `GET /threads/{thread_id}`
  - Expected HTTP: `200`
  - UI vysledok: A aj B otvoria to iste vlakno (bez duplicity)

- [ ] **T11** Inbox je osobitny
  - Endpoint: `GET /threads/badges`
  - Expected HTTP: `200`
  - UI vysledok: inbox:{me} je zdroj for_me udalosti alebo target_user_id

## 2.2 Badges
- [ ] **T12** BadgeBar nacita /threads/badges
  - Endpoint: `GET /threads/badges`
  - Expected HTTP: `200`
  - UI vysledok: badges sa vykreslia po starte appky

- [ ] **T13** Priorita badge_state
  - Endpoint: `GET /threads/badges`
  - Expected HTTP: `200`
  - UI vysledok: zobrazeny najvyssi stav podla priority

- [ ] **T14** for_me je cervene
  - Endpoint: `GET /threads/badges`
  - Expected HTTP: `200`
  - UI vysledok: cerveny badge pre inbox/target_user_id

- [ ] **T15** Klik badge otvori spravny thread/room detail
  - Endpoint: `GET /threads/{id}/items`
  - Expected HTTP: `200`
  - UI vysledok: items nacitane, mark-read az po zobrazeni

- [ ] **T16** mark-read
  - Endpoint: `POST /threads/{id}/mark-read`
  - Expected HTTP: `200`
  - UI vysledok: unread_count klesne, badge sa aktualizuje

---

# FAZA 3 — Items stream + Tasks/Requests + Hotovo consensus

## 3.1 Vytvorenie ulohy
- [ ] **T17** Vytvor task item v direct thread alebo room
  - Endpoint: `POST /threads/{id}/items`
  - Expected HTTP: `200`
  - UI vysledok: item v zozname, badge=pending_task/request

## 3.2 Hotovo workflow (task/request)
- [ ] **T18** Assignee da Hotovo
  - Endpoint: `POST /items/{id}/done`
  - Expected HTTP: `200`
  - UI vysledok: "Hotovo od: assignee", este nie splnene

- [ ] **T19** Vsetci assignees Hotovo, creator este nie
  - Endpoint: `GET /threads/{id}/items`
  - Expected HTTP: `200`
  - UI vysledok: stav "caka na potvrdenie zadavatelom"

- [ ] **T20** Creator potvrdi (confirm)
  - Endpoint: `POST /items/{id}/confirm`
  - Expected HTTP: `200`
  - UI vysledok: item preskrtnuty, presun do Done filtra

- [ ] **T21** Notifikacia zmeny
  - Endpoint: `GET /threads/badges`
  - Expected HTTP: `200`
  - UI vysledok: novy system item/event, badge aktualizovany

## 3.3 1:1 komunikacia “Hotovo” (closure)
- [ ] **T22** Close thread od jedneho ucastnika
  - Endpoint: `POST /threads/{id}/close`
  - Expected HTTP: `200`
  - UI vysledok: thread status "closed_by_me", nie uplne closed

- [ ] **T23** Close thread od druheho ucastnika
  - Endpoint: `GET /threads/{id}`
  - Expected HTTP: `200`
  - UI vysledok: thread "closed", presun do Closed filtra, badge zmizne

---

# FAZA 4 — Soft delete / Hard delete / Archivacia

## 4.1 Soft delete item
- [ ] **T24** Soft delete schova item iba mne
  - Endpoint: `POST /items/{id}/soft-delete`
  - Expected HTTP: `200`
  - UI vysledok: item zmizne pre mna, druhy ucastnik ho vidi

## 4.2 Hard delete item (admin)
- [ ] **T25** Hard delete ako admin
  - Endpoint: `DELETE /items/{id}`
  - Expected HTTP: `200`
  - UI vysledok: item zmizne pre vsetkych + audit

- [ ] **T26** Hard delete bez prav
  - Endpoint: `DELETE /items/{id}`
  - Expected HTTP: `403`
  - UI vysledok: fallback na soft delete alebo skryte tlacidlo

## 4.3 Archivacia room
- [ ] **T27** Archive room
  - Endpoint: `POST /rooms/{id}/archive`
  - Expected HTTP: `200`
  - UI vysledok: room sa neponuka v PWA, admin vidi historiu

- [ ] **T28** Restore room
  - Endpoint: `POST /rooms/{id}/restore`
  - Expected HTTP: `200`
  - UI vysledok: room spat v PWA

---

# FAZA 5 — Voice dictation (sk-SK)

- [ ] **T29** SpeechRecognition dostupne
  - Endpoint: N/A
  - Expected HTTP: N/A
  - UI vysledok: mic sa zobrazuje, po kliknuti nahrava

- [ ] **T30** Diktovanie v slovencine
  - Endpoint: N/A
  - Expected HTTP: N/A
  - UI vysledok: text sa vklada do inputu

- [ ] **T31** Fallback
  - Endpoint: N/A
  - Expected HTTP: N/A
  - UI vysledok: mic skryty alebo tooltip "nepodporovane", appka nespadne

---

# FAZA 6 — Zvukove notifikacie (MVP)

- [ ] **T32** "Arming" zvuku po user geste
  - Endpoint: N/A
  - Expected HTTP: N/A
  - UI vysledok: AudioContext aktivny po prvom geste

- [ ] **T33** Beep pre for_me event
  - Endpoint: N/A
  - Expected HTTP: N/A
  - UI vysledok: piskne pri for_me udalosti (ak app aktivna)

- [ ] **T34** Admin beep pre pending request
  - Endpoint: N/A
  - Expected HTTP: N/A
  - UI vysledok: beep len pri aktivnom admin rozhrani

---

# Must-pass summary (aby sme sa nezasekli)
Faza 1: T1, T3, T5, T6, T9 musia prejst.
Faza 2: T12, T14, T15, T16 musia prejst.
Faza 3: T18, T20, T22, T23 musia prejst.
Faza 4: T24, T25, T27 musia prejst.
Faza 5: T29, T31 musia prejst.
Faza 6: T32, T33 musia prejst.
