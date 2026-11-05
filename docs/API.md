# API v1

Base URL, generata nella configurazione del modulo:

```text
https://shop.example/index.php?fc=module&module=mlabgiftcard&controller=api
```

Ogni operazione seleziona `action` nella query string. Autenticazione: `Authorization: Bearer gc_api_...`; le scritture usano JSON e una chiave `Idempotency-Key` da 8–128 caratteri ASCII alfanumerici o `._:/-`. Riutilizzare **stessa chiave, stesso payload e stessa credenziale** per un retry. Salvare chiave e payload nel BE prima della chiamata. Le chiavi sono specifiche del negozio selezionato dal dominio/URL PrestaShop.

Le risposte hanno forma `{"success":true,"data":{...}}`; errori `{"success":false,"error":{"code":"...","message":"..."}}`. Importi sempre interi in centesimi, valuta ISO maiuscola e timestamp UTC `YYYY-MM-DDTHH:MM:SSZ`. Le date nel ledger grezzo sono SQL UTC. Nessuna conversione di valuta. Gli ID di card/prenotazione sono stringhe esadecimali da 32 caratteri.

## Operazioni

| action | Metodo | Scope | Parametri |
| --- | --- | --- | --- |
| cards | GET | read | `customer_id?`, `page?` (50 per pagina) |
| card | GET | read | `card_id` |
| movements | GET | read | `card_id`, `page?` (100 per pagina) |
| reservation | GET | read | `reservation_id` |
| lookup | POST | read | `code`, `customer_id?`; restituisce stato, saldo, prenotato, disponibile |
| create | POST | issue | `amount_cents`, `currency`, `source_reference`; opzionali: `status` (pending predefinito oppure active), `customer_id`, `recipient_email`, `recipient_name`, `expires_at` oppure `validity_days` |
| activate | POST | issue | `card_id`, `payment_reference` verificato dal BE |
| assign | POST | manage | `card_id` oppure `code`, `customer_id`; associazione permanente, senza prenotazioni aperte |
| reserve | POST | spend | `code`, `cart_id`, `customer_id?`, `currency`, `amount_cents`, `allow_partial?` (true), `ttl_seconds?` (7200, intervallo 60–604800) |
| capture | POST | spend | `reservation_id`, `order_id` di un ordine pagato che corrisponda alla prenotazione |
| redeem | POST | spend | `code`, `cart_id`, `customer_id?`, `currency`, `amount_cents`, `order_id`; riscatto esatto atomico per ordine già pagato |
| release | POST | spend | `reservation_id`, `reason` |
| refund | POST | manage | `reservation_id`, `amount_cents`, `refund_reference`, `reason` |
| status | POST | manage | `card_id`, `status` = blocked/unblocked, `reason` |
| adjust | POST | manage | `card_id`, `delta_cents` positivo o negativo, `reason` |
| validity | POST | manage | `card_id`, `expires_at` (null per togliere la scadenza), `reason`; senza prenotazioni aperte |
| resend | POST | manage | `card_id`; accoda lo stesso codice, senza emettere credito |
| maintenance | POST | maintenance | `{}`; rilascia fino a 100 prenotazioni scadute, tenta fino a 20 email, ripulisce i rate limit; nessuna Idempotency-Key richiesta |

`source_reference` identifica un’emissione (`purchase:123:line:1`) e deve essere univoco; ammette lettere, cifre e `._:/-`, massimo 160 caratteri. Il valore massimo per saldo/importo è 2.000.000.000 centesimi. `validity_days` (0–36500) conta dalla creazione; zero equivale a nessuna scadenza. Per una card pending scegliere una data esplicita oppure creare la card dopo il pagamento. `create` restituisce anche il codice completo: conservarlo solo quanto necessario e non inserirlo nei log. Le successive letture restituiscono soltanto il suffisso mascherabile.

Le API sono per chiamate fidate server-to-server. Il BE verifica l’identità del suo utente prima di usare `customer_id`: l’API key autorizza il servizio e non autentica il cliente finale. Un codice assegnato richiede sempre il relativo `customer_id` per lookup/riscatto; un guest può spendere soltanto una card al portatore. I clienti condivisi fra negozi richiedono l’ID del negozio proprietario del cliente: il modulo mantiene isolamento stretto per shop.

## Esempio checkout parziale

```sh
curl --request POST "$GIFT_CARD_API_URL&action=reserve" \
  --header "Authorization: Bearer $GIFT_CARD_API_KEY" \
  --header 'Content-Type: application/json' \
  --header 'Idempotency-Key: checkout:cart:123:attempt:1' \
  --data '{"code":"GC-00000000-00000000-00000000-00000000","cart_id":123,"customer_id":42,"amount_cents":7500,"currency":"EUR","ttl_seconds":7200}'
```

Con card da 50 euro e ordine da 75 euro, la prenotazione restituisce `reservation.amount_cents=5000`, `card.balance_cents=5000`, `card.reserved_cents=5000`, `card.available_cents=0`. Il BE incassa 2500 centesimi con Stripe. Dopo la creazione dell’ordine pagato:

```json
{"reservation_id":"ID_RICEVUTO","order_id":321}
```

Va inviato a `POST ...&action=capture` con una nuova chiave idempotente stabile. Lo snapshot distingue il totale ordine dal solo residuo incassato con Stripe. Per un ordine da 30 euro interamente coperto da una card da 50, il BE crea l’ordine con pagamento Gift Card, poi cattura 3000: la card conserva 2000 centesimi.

In caso di crash dopo la creazione dell’ordine, ripetere `capture`. Se la prenotazione è stata già catturata sullo stesso ordine, viene restituito il risultato senza un altro addebito. Se è stata rilasciata o scaduta, la cattura restituisce 409: il BE deve gestire il pagamento tardivo senza forzare un nuovo addebito automatico.

## Job

```sh
curl --fail --silent --show-error --request POST "$GIFT_CARD_API_URL&action=maintenance" \
  --header "Authorization: Bearer $GIFT_CARD_MAINTENANCE_KEY" \
  --header 'Content-Type: application/json' --data '{}'
```

Programmare ogni minuto. Gli invii sono almeno una volta: un arresto dopo l’invio email può causare il reinvio dello stesso codice. La prenotazione scade secondo la TTL scelta dal BE; il modulo non conosce lo stato di Stripe. La TTL va allineata al metodo di pagamento e al suo termine. La consegna usa la configurazione email di PrestaShop e il negozio destinatario.

## Errori principali

- 400/422: payload, identificatore, valuta o importo non validi.
- 401/403: chiave assente/revocata o scope insufficiente.
- 404: card/prenotazione/cliente non disponibile nel contesto richiesto.
- 409: saldo insufficiente, card bloccata/scaduta, prenotazione non catturabile, ordine non corrispondente, doppia associazione carrello/ordine o payload diverso con la stessa chiave.
- 413/415: corpo oltre 64 KiB o Content-Type errato.
- 426: HTTPS richiesto; 429: limite di frequenza (120 richieste/minuto/IP).
- 503: errore temporaneo del database; riprovare con stessa chiave e payload. Una transazione fallita non conserva modifiche parziali.

Una risposta a una richiesta idempotente è la fotografia di quella operazione. Per il saldo aggiornato chiamare `card`/`lookup`, senza assumere che il saldo contenuto in un vecchio replay sia quello corrente.
