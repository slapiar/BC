# TEST_CHECKLIST_CHAT.md
Test checklist pre PWA Chat (onboarding, admin queue, badges, threads/rooms, tasks+Hotovo, delete+archive, voice, sound)

## Zasady
- Testovat po fazach. Kazda faza ma "must-pass" scenare.
- Ked zlyha must-pass, stopnut dalsie rozsirovanie a opravit.
- Vsetky testy su pouzitelne manualne; vybrane mozu byt automatizovane (Playwright/Cypress + REST tests).

---

# FAZA 1 — Onboarding + Admin approval queue + Personal identity

## 1.1 Onboarding UI (PWA)
**T1** Neaktivne zariadenie vidi onboarding
- Predpoklad: device.status != active
- Ocakavanie: zobrazene pole `nickname` + tlacidlo "Zadaj svoj nickname a Pripoj sa"

**T2** Nickname validacia
- Vstupy: "", "A", 33+ znakov, specialne znaky
- Ocakavanie: client-side validacia; server-side validacia; jasne hlasenie

**T3** Request access vytvori pending request
- Akcia: klik "Pripoj sa"
- Ocakavanie:
  - POST `/auth/request-access` odoslany (Network)
  - Response: status=pending, approval_code, expires_at
  - UI: "Caka na schvalenie" + zobrazit nickname + approval_code

**T4** Pending expiracia (cas/cron)
- Predpoklad: pending request po expires_at
- Ocakavanie: PWA zobrazuje "expirovane" + umozni poslat novu ziadost

## 1.2 Admin queue (WP admin)
**T5** Admin vidi zoznam ziadosti
- Ocakavanie: tabulka s nickname, created_at, device_id (skratene), status

**T6** Approve jednym klikom
- Akcia: approve pending
- Ocakavanie:
  - device.status -> active
  - user vytvoreny/priradeny
  - audit zaznam existuje
  - ziadost zmizne z pending alebo sa oznaci approved

**T7** Deny a Wait
- Deny: status->denied/revoked; PWA uz nemoze refreshovat
- Wait: status->waiting; zostava v zozname s jasnym oznacenim

**T8** Disconnect / Remove from chat
- Disconnect: sessions revoked, device->revoked
- Remove from chat: user strati chat opravnenie (podla ACL), log v audite

## 1.3 Post-approve (PWA)
**T9** Po approve sa PWA prihlasi bez rucneho zasahu
- Ocakavanie:
  - POST `/auth/refresh` -> 200
  - UI prejde do chatu
  - v hlavicke sa zobrazuje nickname namiesto "Prihlaseny"

---

# FAZA 2 — Rooms/Threads + BadgeBar + “for_me” (cervene)

## 2.1 Thread vs Room
**T10** Direct thread ID je deterministicky
- A<->B -> dm:min:max
- Ocakavanie: obaja ucastnici otvoria to iste vlakno (nie duplicity)

**T11** Inbox je osobitny
- Ocakavanie: existuje inbox:{me} a udalosti "pre mna" idu sem alebo maju target_user_id

## 2.2 Badges
**T12** BadgeBar nacita /threads/badges
- Ocakavanie: request bezi po starte appky, badges renderovane

**T13** Priorita badge_state
- Priprav data: unread + pending task + urgent task
- Ocakavanie: zobrazeny najvyssi stav podla priority

**T14** for_me je cervene
- Predpoklad: item v inbox:{me} alebo target_user_id=me
- Ocakavanie: badge ma cervene pozadie a ma prednost pred inymi

**T15** Klik badge otvori spravny thread/room detail
- Ocakavanie: route prejde, items sa nacitaju, mark-read az po zobrazeni

**T16** mark-read
- Ocakavanie:
  - POST `/threads/{id}/mark-read` znizi unread_count
  - badge sa aktualizuje (poll alebo okamzite)

---

# FAZA 3 — Items stream + Tasks/Requests + Hotovo consensus

## 3.1 Vytvorenie ulohy
**T17** Vytvor task item v direct thread alebo room
- Akcia: POST `/threads/{id}/items` (type=task)
- Ocakavanie: item sa objavi v zozname, badge = pending_task/request

## 3.2 Hotovo workflow (task/request)
**T18** Assignee da Hotovo
- Akcia: POST `/items/{id}/done`
- Ocakavanie: item zobrazuje "Hotovo od: assignee", ale este NIE je splneny

**T19** Vsetci assignees Hotovo, creator este nie
- Ocakavanie: item stav "caka na potvrdenie zadavatelom"

**T20** Creator potvrdi (confirm)
- Akcia: POST `/items/{id}/confirm` (alebo creator done podla spec)
- Ocakavanie: item je preskrtnuty + presunuty do Done filtra

**T21** Notifikacia zmeny
- Pri done/confirm sa vytvori system item (alebo event) a badge sa aktualizuje

## 3.3 1:1 komunikacia “Hotovo” (closure)
**T22** Close thread od jedneho ucastnika
- POST `/threads/{id}/close`
- Ocakavanie: thread status "closed_by_me", ale nie uplne closed

**T23** Close thread od druheho ucastnika
- Ocakavanie: thread je "closed", presun do Closed filtra, badge zmizne (ak nic ine)

---

# FAZA 4 — Soft delete / Hard delete / Archivacia

## 4.1 Soft delete item
**T24** Soft delete schova item iba mne
- Akcia: POST `/items/{id}/soft-delete`
- Ocakavanie:
  - item zmizne z listu pri include_deleted=0
  - druhy ucastnik ho stale vidi

## 4.2 Hard delete item (admin)
**T25** Hard delete ako admin
- Akcia: DELETE `/items/{id}`
- Ocakavanie: item zmizne pre vsetkych + audit

**T26** Hard delete bez prav
- Ocakavanie: 403 + UI fallback na soft delete alebo skryt tlacidlo

## 4.3 Archivacia room
**T27** Archive room
- POST `/rooms/{id}/archive`
- Ocakavanie: room sa neponuka v PWA zozname, ale admin vidi historiu

**T28** Restore room
- POST `/rooms/{id}/restore`
- Ocakavanie: room spat v PWA

---

# FAZA 5 — Voice dictation (sk-SK)

**T29** SpeechRecognition dostupne
- Chrome Android: mic sa zobrazuje
- Po kliknuti: zacina nahravanie, indikator aktivny

**T30** Diktovanie v slovencine
- Ocakavanie: text sa vklada do inputu, user moze upravit a odoslat

**T31** Fallback
- Browser bez SpeechRecognition: mic nie je, alebo tooltip "nepodporovane"
- Appka nespadne

---

# FAZA 6 — Zvukove notifikacie (MVP)

**T32** "Arming" zvuku po user geste
- Po prvom kliknuti v appke sa AudioContext aktivuje
- Bez toho ziadny zvuk (ocakavane)

**T33** Beep pre for_me event
- Ked pride event pre mna (inbox/target_user_id), app pipne (ak je aktivna)

**T34** Admin beep pre pending request
- Iba ak admin je aktivny na WP admin stranke a zvuk je armed

---

# Must-pass summary (aby sme sa nezasekli)
Faza 1: T1, T3, T5, T6, T9 musia prejst.
Faza 2: T12, T14, T15, T16 musia prejst.
Faza 3: T18, T20, T22, T23 musia prejst.
Faza 4: T24, T25, T27 musia prejst.
Faza 5: T29, T31 musia prejst.
Faza 6: T32, T33 musia prejst.
