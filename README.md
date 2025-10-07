



# PK Supplier Hub (PrestaShop 8)

**Multi-dostawca:** pobieranie feedów (CSV/JSON/XML), przeliczenia walut (ECB), marże/końcówki (w Twoich usługach), podgląd **Preview**, **Dry Diff**, **Real Run**, **logi**, **snapshoty** (rollback-ready), **cron** z tokenem, **guardy** bezpieczeństwa i **batching** dużych runów.

* **PrestaShop:** 8.x
* **PHP:** 8.1+
* **Moduł:** `pk_supplier_hub`

---

## 1) Wymagania

* PHP rozszerzenia: `curl`, `simplexml`, `json`, `mbstring`.
* MySQL/MariaDB z funkcjami **`GET_LOCK` / `RELEASE_LOCK`** (standard).
* Uprawnienia bazy do `CREATE TABLE` podczas instalacji.
* Włączony SSL w sklepie (endpoint cron wymusza HTTPS, o ile sklep używa SSL).

---

## 2) Instalacja

1. Zbuduj paczkę zip z katalogiem modułu:

   ```
   pk_supplier_hub/
     ├── pk_supplier_hub.php
     ├── classes/...
     ├── controllers/admin/...
     ├── controllers/front/cron.php
     ├── sql/install.sql
     ├── sql/uninstall.sql
     └── (index.php w każdym katalogu)
   ```
2. W BO: **Modules → Upload a module** → wybierz ZIP.
3. Po instalacji pojawią się zakładki:

   * **PK Supplier Hub → Sources**
   * **PK Supplier Hub → Runs & Logs**
4. Wejdź w **Modules → PK Supplier Hub → Configure** i sprawdź/wygenereuj **CRON token** (sekcja z przykładowymi URL-ami jest na dole).

> Deinstalacja usuwa zakładki i 4 tabele modułu (patrz `sql/uninstall.sql`).

---

## 3) Konfiguracja źródła (BO → Sources)

| Pole                         | Opis                                                      |
| ---------------------------- | --------------------------------------------------------- |
| **Name**                     | Nazwa źródła                                              |
| **Active**                   | Włącza/wyłącza źródło                                     |
| **Type**                     | `csv` / `json` / `xml` (fallback dla Content-Type)        |
| **URL**                      | Endpoint feedu (HTTP(S))                                  |
| **Auth type**                | `none` / `basic` / `bearer`                               |
| **Auth user / pass / token** | Maskowane w formularzu; puste/`********` nie nadpisuje DB |
| **Headers (JSON)**           | Dodatkowe nagłówki HTTP, np. `{"X-Api-Key":"abc"}`        |
| **Price update mode**        | `impact` (cena bazowa) lub `specific_price`               |
| **Tax rule group ID**        | (opcjonalnie) ID grupy podatkowej                         |
| **Zero qty policy**          | `none` / `disable` / `backorder`                          |
| **Stock buffer**             | Odjęcie bufora od feedowego stanu                         |
| **Max delta %**              | Twardy guard na zmianę ceny                               |
| **Shop ID**                  | `id_shop` w kontekście multistore                         |

**ACL/ROLE:** Akcje Run/Save wymagają prawa **edit** do zakładki.

---

## 4) Użycie (workflow)

1. **Preview** (w źródłach → przycisk „Preview”):
   pobiera `URL` przez **HttpClient**, parsuje (ParserFactory lub fallback CSV/JSON/XML), pokazuje pierwsze N rekordów + metryki.

2. **Dry Diff**:
   liczy różnice bez zapisu: **price/quantity/active** z guardem **Max delta %**.

3. **Real Run**:
   zapis zmian w batchach (domyślnie 300), **snapshot** stanu przed zmianą, logi per produkt. Guardy twarde (EAN, ujemne ceny/ilości, anomalie) + max delta.

4. **Runs & Logs**:
   lista runów, statystyki, podgląd logów. (Rollback – jeśli masz w repo `RollbackService.php` i UI, snapshoty już są gotowe.)

---

## 5) CRON / CLI

* Panel modułu pokazuje **Token** i przykładowe URL-e (można regenerować).
* Endpoint:

  ```
  /module/pk_supplier_hub/cron?token=TOKEN&id_source=ID&dry=1|0
  ```
* Przykłady (curl):

  ```bash
  # Dry run
  curl -sS "https://shop.tld/module/pk_supplier_hub/cron?token=ABC123&id_source=5&dry=1"

  # Real run
  curl -sS "https://shop.tld/module/pk_supplier_hub/cron?token=ABC123&id_source=5&dry=0"
  ```

**Odpowiedzi:**

* `200`: `{ ok: true, id_run, dry, stats }`
* `401`: `{ ok: false, error: "unauthorized" }` (zły token)
* `409`: `{ ok: false, error: "locked" }` (równoległy run zablokowany)
* `500`: `{ ok: false, error: "exception" | "fatal", message }`

---

## 6) Model danych (tabele)

| Tabela          | Rola (kolumny kluczowe)                                                                                        |       |      |       |          |         |        |                                                                    |
| --------------- | -------------------------------------------------------------------------------------------------------------- | ----- | ---- | ----- | -------- | ------- | ------ | ------------------------------------------------------------------ |
| `pksh_source`   | Konfiguracja źródła (auth, headers, polityki, id_shop)                                                         |       |      |       |          |         |        |                                                                    |
| `pksh_run`      | Metadane uruchomienia: status, total/updated/skipped/errors, `dry_run`, `checksum`, `locked`, `message`, czasy |       |      |       |          |         |        |                                                                    |
| `pksh_log`      | Logi per rekord: `action` (`price                                                                              | stock | skip | error | nochange | disable | enable | rollback`), stare/nowe wartości, `reason`, `details`, `created_at` |
| `pksh_snapshot` | Snapshot stanu **przed** zmianą: `price`, `quantity`, `active` (+ opcjonalne `extra` JSON)                     |       |      |       |          |         |        |                                                                    |

> SQL (`install.sql` / `uninstall.sql`) uwzględnia prefix `_DB_PREFIX_` i engine `_MYSQL_ENGINE_`.

---

## 7) Jak to działa — technikalia

* **HttpClient**: hard-timeouty, retry (exponential backoff), rate-limit per host, whitelist **Content-Type**.
* **PreviewService / DiffService**: fallback parsery CSV/JSON/XML, integracja z `ParserFactory`, `FeedNormalizer`, `FeedValidator` gdy dostępne.
* **EcbProvider**: kursy EUR z ECB + cache w `Configuration` (24h) + fallback 1.0.
* **RunService**: batching (domyślnie 300), heartbeat co 50, lock na `GET_LOCK`, snapshoty, logi, twarde **guardy** (`GuardService`), multistore-aware (`product_shop` + fallback stock `id_shop=0`).
* **ACL**: wymagane `edit` do akcji (Runs/Sources), błyskawiczne sprawdzenie w kontrolerach.
* **Cron**: FrontController z tokenem i 409 dla locka.

---

## 8) Smoke-test (dla QA / DevOps)

1. **Instalacja modułu** → brak błędów.
2. **Configure** → widoczny token, działa „Regenerate”.
3. **Dodaj Source** (JSON/CSV testowy, bez auth; `id_shop` poprawny).
4. **Preview** → widzisz rekordy + metryki.
5. **Run Dry** → w **Runs & Logs**: statystyki (total/affected/skipped/errors).
6. **Run Real** → logi `price`/`stock`, `skip` dla guardów; statystyki rosną (heartbeat).
7. **Cron Dry** → `200 ok` z `id_run`.
8. **Lock test**: odpal drugi cron szybko – dostaniesz `409 locked`.
9. (Opc.) **Rollback** → jeśli dorzucony `RollbackService`, przywróć snapshot bieżącego runu.
10. **Uninstall** → czyści zakładki i 4 tabele.

---

## 9) Przykładowe feedy (mini)

**JSON**

```json
{
  "items": [
    { "ean": "5900000000011", "price": 99.90, "currency": "PLN", "quantity": 12, "active": 1 },
    { "reference": "SKU-123", "price": 19.99, "currency": "EUR", "quantity": 0, "active": 0 }
  ]
}
```

**CSV** (`;` lub `,`)

```csv
ean;price;currency;quantity;active
5900000000011;99.90;PLN;12;1
;19.99;EUR;0;0
```

**XML**

```xml
<feed>
  <product>
    <ean>5900000000011</ean>
    <price>99.90</price>
    <currency>PLN</currency>
    <quantity>12</quantity>
    <active>1</active>
  </product>
</feed>
```

---

## 10) Typowe błędy i rozwiązania

| Problem                               | Przyczyna                                          | Rozwiązanie                                                         |
| ------------------------------------- | -------------------------------------------------- | ------------------------------------------------------------------- |
| `401 unauthorized` (cron)             | zły/ brak tokena                                   | Wygeneruj token w Configure i użyj w URL                            |
| `409 locked`                          | trwa inny run                                      | Poczekaj na zakończenie / zabij proces blokujący                    |
| `Disallowed Content-Type`             | feed zwraca `text/html` (błąd po stronie dostawcy) | Zweryfikuj endpoint/URL lub nagłówki; HttpClient blokuje HTML       |
| Brak zmian w Real Run                 | Guard **Max delta %** lub walidacja                | Sprawdź logi `skip` i dostosuj `max_delta_pct`/dane                 |
| Puste quantity w multistore           | Stock w `id_shop=0`                                | Włączony fallback do `id_shop=0`; sprawdź konfigurację stocka       |
| Brak klas Parser/Normalizer/Validator | Działają fallbacki                                 | To OK; wpięcie własnych klas odbywa się automatycznie, gdy istnieją |

---

## 11) Bezpieczeństwo

* Równoległe runy blokowane przez **MySQL GET_LOCK**.
* Cron wyłącznie przez **token** (konfiguracja modułu).
* Maskowanie sekretów w formularzu źródeł (nie nadpisujemy przy pustkach/`********`).
* Guardy danych: brak EAN, ujemne ceny/ilości, anomalia qty, **max delta %**.

---

## 12) Wersjonowanie & Changelog

* SemVer: `1.0.0` → pierwsza stabilna; kolejne fixy `1.0.x`.
* Zmiany DB zawsze przez migracje lub `install.sql` bump + `upgrade` (jeśli dołożysz).

**CHANGELOG (szablon):**

```
## [1.0.1] - 2025-10-07
### Added
- Batching runów (300) + heartbeat co 50
- Secure cron endpoint z tokenem
- Hard guards + multistore-aware read (product_shop + stock fallback)
### Fixed
- Maskowanie `details` w logu (substr length)
### Security
- Lock na GET_LOCK, whitelist Content-Type
```

---

## 13) Struktura kodu (główne pliki)

| Ścieżka                                                     | Opis                                            |
| ----------------------------------------------------------- | ----------------------------------------------- |
| `pk_supplier_hub.php`                                       | instalacja, zakładki, config + cron token       |
| `classes/HttpClient.php`                                    | bezpieczny HTTP (timeout/retry/ratelimit/CT)    |
| `classes/EcbProvider.php`                                   | kursy EUR (cache 24h)                           |
| `classes/PreviewService.php`                                | pobierz→parsuj→metryki                          |
| `classes/DiffService.php`                                   | policz zmiany, guard max delta                  |
| `classes/GuardService.php`                                  | twarde walidacje (EAN/price/qty/active)         |
| `classes/RunService.php`                                    | lock, run (dry/real), logi, snapshoty, batching |
| `controllers/front/cron.php`                                | cron endpoint (token)                           |
| `controllers/admin/AdminPkSupplierHubSourcesController.php` | BO: lista+form+RunDry/RunReal (ACL, maski)      |
| `controllers/admin/AdminPkSupplierHubRunsController.php`    | BO: runy/logi/rollback (jeśli dodasz)           |
| `sql/install.sql` / `sql/uninstall.sql`                     | schema modułu                                   |

---

## 14) FAQ

**Q:** Czy muszę mieć ParserFactory/FeedNormalizer/FeedValidator?
**A:** Nie – są opcjonalne. Fallbacki CSV/JSON/XML działają out-of-the-box.

**Q:** Co jeśli dostawca zwraca HTML (błąd 500 z serwera)?
**A:** HttpClient to zatrzyma (Content-Type whitelist). Sprawdź URL/parametry, popraw feed lub dołóż nagłówki w `Headers (JSON)`.

**Q:** Jak działa multistore?
**A:** Czytamy z `product_shop` w `id_shop` źródła + fallback stocka z `id_shop=0`. Zapis też w kontekście `id_shop`.

**Q:** Jak zatrzymać równoległe uruchomienia?
**A:** Automatycznie – lock na `GET_LOCK`. Drugi run dostaje `409`.

---

## 15) Licencja / Autor

* Autor: **Mateusz Piasecki **
* Licencja: wg polityki Twojego projektu (uzupełnij tu, np. MIT / Proprietary).

---

