# PWA Chat Panel – Badges/Avatars pre žiadosti + Profil vlákna + Filter + Soft/Hard delete

## Cieľ
Rozšíriť PWA chat panel tak, aby:
1) v paneli zobrazoval **“badge” kruhy** (avatar/icon v kruhu) pre každú osobu, ktorá má voči mne:
   - novú žiadosť / úlohu / požiadavku / pozvánku,
   - alebo prebiehajúce spojenie,
   - alebo neprečítané správy,
2) farba pozadia badge (kruh) signalizovala **typ/urgentnosť stavu**,
3) klik na badge otvoril **detail osoby** (profil/kontext), kde vidím:
   - všetky komunikácie (správy),
   - úlohy,
   - požiadavky,
   - systémové udalosti (napr. “request connect”),
4) detail má filter: “všetko” alebo len konkrétny typ,
5) v detaile aj v zoznamoch musí byť možnosť:
   - soft delete (skryť pre mňa),
   - hard delete (trvalé zmazanie, iba ak mám právo; inak fallback soft),
6) UI je MVP, no dátový model musí byť pripravený na rozšírenie.

---

## Pojmy a dátové objekty (MVP)
### Entity: Participant (osoba)
- `user_id` (int)
- `display_name`
- `avatar_url` (optional)
- `role` (optional)

### Entity: Thread (kontext s osobou)
Jedno “vlákno” pre dvojicu (ja + osoba). Nemiešať s miestnosťou.
- `thread_id` (string/int)
- `peer_user_id` (int) – osoba na druhej strane
- `last_activity_at` (datetime)
- `unread_count` (int)
- `badge_state` (enum) – vypočítaný stav pre farbu badge

### Entity: Item (jednotná položka v detaile)
MVP zaviesť jednotný event stream s typom:
- `item_id`
- `type` enum: `message | request | task | note | system`
- `subtype` string (optional): napr. `connect_request`, `order_issue`, `call_me`
- `title` / `text`
- `status` enum: `open | in_progress | done | archived` (pre task/request), pre message implicit
- `priority` enum: `low | normal | high | urgent`
- `created_at`, `updated_at`
- `created_by` (user_id)
- `thread_id`
- `deleted_by_me_at` (nullable datetime)  // soft delete pre mňa
- `deleted_hard_at` (nullable datetime)   // hard delete (admin/owner only)

Poznámka: pre MVP môže byť `message` existujúca tabuľka/endpoint, ale v PWA sa to má prezentovať jednotne (zjednotená DTO odpoveď).

---

## Badge signalizácia – stav a farby (konfigurácia)
Zaviesť mapu “badge state” -> farba/ikonka.

### Badge state (priorita vyhodnocovania)
1. `urgent_request` – urgent požiadavka/úloha (priority=urgent, status=open)
2. `pending_request` – nová žiadosť/úloha (open) bez urgent
3. `unread_messages` – neprečítané správy
4. `active_connection` – prebiehajúce spojenie / “online session” (voliteľné, ak máte)
5. `idle` – bez stavu (badge sa nezobrazuje)

### UI pravidlá
- Badge je kruh (avatar / fallback ikonka).
- Ak je `idle`, badge sa v paneli nezobrazuje (alebo je v sekcii “ostatní” – voliteľné).
- V kruhu zobraziť:
  - avatar, alebo iniciály, alebo ikonku typu (napr. task)
- Malý “dot” indikátor (optional) v rohu môže zobrazovať `unread_count > 0`.

### Farby (navrhnúť CSS tokeny, nie hard-coded)
- urgent_request -> `--badge-urgent`
- pending_request -> `--badge-pending`
- unread_messages -> `--badge-unread`
- active_connection -> `--badge-active`

---

## UI – Chat Panel (ľavý panel / horný panel podľa vašej PWA)
### Komponenty
1) `BadgeBar` (horizontálny pás alebo grid kruhov)
   - zoznam badge pre všetky threads s `badge_state != idle`
   - klik na badge -> navigate na `ThreadDetail(peer_user_id|thread_id)`
2) existujúci chat panel ostáva (rooms/messages), ale doplniť hore `BadgeBar`

### Onboarding + admin approval queue (MVP)
- Pri prvom spustení PWA musí používateľ zadať `nickname` (povinné).
- PWA odošle `nickname` spolu s registráciou zariadenia do approval queue.
- Admin v plugine vidí **frontu schválení** s nickname + device_id a vie spraviť:
  - `approve` (aktivuje chat),
  - `deny` (zamietne),
  - `wait` (ponechá v pending),
  - `disconnect` (odpojí),
  - `remove from chat` (odstráni z chatu, ak treba).
- Po schválení sa `nickname` zobrazuje v PWA namiesto generického “Prihlásený”.
- Pri novej žiadosti admin dostane vizuálny + zvukový signal (viac v sekcii Signalizácia).

### Interakcie
- Klik badge:
  - nastav “active peer”
  - otvor detail (route/view)
- Long-press / context menu (optional):
  - “Mute/Hide” (soft delete thread pre mňa) – voliteľné

---

## UI – Thread Detail (profil/kontext osoby)
### Route
- `/thread/:thread_id` alebo `/peer/:peer_user_id` (preferované: thread_id)

### Layout
- Header:
  - avatar + meno + stav (online optional)
  - quick actions: `Filter`, `Soft delete`, `Hard delete` (podľa práv)
- Body:
  - Tabs alebo filter pill:
    - `All | Messages | Requests | Tasks | System`
  - List položiek (ItemList) s grouped day headers (optional)
- Footer:
  - Compose box pre message (existing)
  - Quick create: `+ Task`, `+ Request` (optional MVP)

### Filter
- client-side filter podľa `type`
- server-side parametre (preferované) kvôli výkonu:
  - `type=message|request|task|system`
  - `status=open|done|...`
  - `include_deleted=false` default

### 1:1 workflow “Hotovo” (pre komunikáciu aj úlohy)
- 1:1 komunikácia zostáva “otvorená”, kým **obaja účastníci** nedajú `Hotovo`.
- Pre úlohy/požiadavky:
  - riešiteľ nastaví `done` (Hotovo),
  - zadávateľ musí potvrdiť “Potvrdené” (finálne uzavretie),
  - až potom sa item považuje za uzavretý pre obe strany.
- Badge “pre mňa” má prednosť pred ostatnými (napr. červený stav).

---

## Delete – požiadavky (soft/hard)
### Soft delete (pre mňa)
- skryje item/thread pre aktuálneho usera
- nesmie mazať druhému userovi
- implementovať ako `deleted_by_me_at` alebo tabuľka mapovania `user_item_hidden`

### Hard delete
- trvalé zmazanie itemu (alebo anonymizácia) – iba pre admin/owner
- ak user nemá právo:
  - tlačidlo sa nezobrazuje, alebo server vráti 403 a UI fallback na soft

### Thread-level delete
- Soft delete thread: skryje celé vlákno v zoznamoch, ale dá sa obnoviť (optional)
- Hard delete thread: iba admin (optional)

---

## Backend API (WP REST) – nové/rozšírené endpointy
Pozn.: názvy prispôsobiť existujúcemu namespace `bc-inventura/v1`.

### 1) GET `/threads/badges`
Vracia zoznam threads s badge informáciou pre `BadgeBar`.
Response item:
- `thread_id`
- `peer_user_id`
- `peer_display_name`
- `peer_avatar_url`
- `badge_state`
- `unread_count`
- `last_activity_at`
- `top_reason` (optional: krátky text “urgent task”, “new request”, ...)

### 2) GET `/threads/{thread_id}`
Vracia meta o vlákne + peer.

### 3) GET `/threads/{thread_id}/items`
Query params:
- `type=all|message|request|task|system`
- `status=` (optional)
- `limit`, `cursor`
- `include_deleted=0|1` (default 0)
Response:
- `items[]` v jednotnej štruktúre (Item DTO)

### 4) POST `/threads/{thread_id}/items`
Vytvoriť nový item:
- pre message: `type=message`, `text`
- pre task/request: `title`, `text`, `priority`, `status=open`
Server nastaví `created_by`, `created_at`

### 5) POST `/items/{item_id}/soft-delete`
- nastaví `deleted_by_me_at=now()` pre aktuálneho usera (alebo zapíše do map tabuľky)

### 6) DELETE `/items/{item_id}`
- hard delete
- require capability (admin)
- inak 403

### 7) POST `/threads/{thread_id}/mark-read`
- nastaví všetky messages (alebo relevant items) ako prečítané
- update unread_count

### 8) POST `/threads/{thread_id}/resolve`
- nastaví stav “Hotovo” pre konkrétneho účastníka
- pri 1:1 sa thread považuje za uzavretý až po potvrdení oboma stranami

---

## Výpočet badge_state (server-side)
Implementovať deterministicky na serveri pri `/threads/badges`:
- Získať open items pre thread (requests/tasks) + unread messages.
- Pravidlá:
  - ak existuje open item s priority=urgent => urgent_request
  - else ak existuje open request/task => pending_request
  - else ak unread_count > 0 => unread_messages
  - else ak online/active flag => active_connection
  - else idle (thread sa nevracia v badge liste)

Pozn.: Pre MVP stačí `pending_request` + `unread_messages` + `urgent_request`.

---

## PWA State management (MVP)
- Store:
  - `badges[]` (výsledok /threads/badges)
  - `activeThreadId`
  - `threadItems[thread_id]` cache (paged)
  - `filters[thread_id]` (selected type)
- Refresh policy:
  - pri otvorení appky načítať `badges`
  - potom poll každých N sekúnd (napr. 10–20) alebo pri keepalive (ak máte)
  - po kliknutí na thread detail: načítať items + mark-read (ak vhodné)

---

## UX pravidlá
- BadgeBar má byť “tiché” a čitateľné:
  - max 8 badge zobraziť, zvyšok do `+N` (optional)
- Pri kliknutí na badge:
  - automaticky spustiť `mark-read` pre messages po zobrazení (nie hneď pri kliknutí, aby sa nestratili pri back)
- Filter musí byť “sticky” pre daný thread (pamätať v store/localStorage)
- Soft delete:
  - potvrdenie “Skryť túto položku?” (optional)
- Hard delete:
  - vždy potvrdenie + require role

---

## Osobné miestnosti (inbox) + shared rooms
- Každý člen má **osobné vlákno** (inbox) pre priame 1:1 komunikácie.
- Popri tom existujú **shared rooms** (prevádzky, roly, projekty).
- Badges sa primárne odvíjajú z osobných vlákien (inbox), shared rooms sú sekundárne.

---

## Bezpečnosť a ACL
- Všetky endpointy musia vyžadovať:
  - platnú PWA session (device active + refresh ok)
  - a následne ACL:
    - user môže vidieť len svoje threads/items
    - admin môže hard delete
- Soft delete je vždy povolený (pre vlastný pohľad), hard delete len admin.

---

## Akceptačné kritériá (DoD)
1) BadgeBar zobrazí aspoň 1 kruh, ak existuje pending request alebo unread messages.
2) Farba badge korektne zodpovedá priorite (urgent > pending > unread).
3) Klik na badge otvorí ThreadDetail a načíta items.
4) Filter v ThreadDetail prepína typy bez reloadu (alebo s parametrom).
5) Soft delete skryje item (už sa nezobrazuje pri include_deleted=0).
6) Hard delete funguje len admin, inak 403 a UI nepovie “deleted”.
7) `threads/badges` je stabilný endpoint, nevytvára duplikáty, rešpektuje soft delete.

---

## Poznámky k implementácii (aby Copilot neblúdil)
- Neimplementovať “fingerprinting”.
- Avatar/icon riešiť fallbackom (iniciály).
- Neriešiť realtime websockets v MVP; polling stačí.
- Držať jednotný DTO formát Item, aj keď dáta pochádzajú z viacerých tabuliek.

## Hlasové zadávanie (sk-SK)
- PWA má mať hlasové diktovanie textu v slovenčine (`sk-SK`).
- Vstup musí byť prepínateľný (mikrofón on/off) a jasne signalizovať stav.

## Poznámky k signalizácii. 
- Žiadalo by sa zvukom upozorniť na prichádzajúcu komunikáciu, nejaké pekné zapípanie, komu je určená. 

- Keď nejaké zariadenie žiada o schválenie, musí byť admin v plugine o tom upvedomený zvukovým signálom, a stránka so žiadosťou sa musí automaticky objaviť. Tam už musí byť adminovi zjavné kto to je. Teda nicname sa musí zadať už v PWA. Buď nejaký popup alebo sa rovno nechá políčko, zadaj nic name a toto nickname sa musí zobrazovať aj v PWA namiesto slova Prihlásený. Inak môže prísť viac žiadostí naraz a admin nevie komu volať o potvrdenie
- Zoznam chat-ov musí byť pre Admina dostupný tak, že kliknutím na riadok zoznamu sa otvorí záznam o celej komunikácii, aby sa dalo dosledovať, sporné požiadavky, keď jeden tvrdí, že poslal, tak musí byť o tom dohľadateľný záznam. Rovnako, je potrebné mazať tie chaty, ktoré sú nežiadúce alebo už nepotrebné.
- Mazanie chatov prebieha v dvoch krokoch: najprv **soft delete** (chat sa skryje z bežného prehľadu aj z PWA, ale ostáva v databáze a v admin archíve), následne môže admin spraviť **hard delete** (definitívne vymazanie z DB podľa potreby, aby sa neplnilo úložisko).
- Admin má v plugine k dispozícii aj **archív miestností chatu** – miestnosť sa dá archivovať (prestane sa ponúkať v PWA, ale história správ zostáva dostupná v admin prehľade) a kedykoľvek znovu obnoviť.