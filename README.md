# MLab Gift Card

Modulo autonomo per PrestaShop **9.0.x, 9.1.x e 9.2.x**, PHP 8.1+, OpenSSL, mbstring e MySQL/MariaDB con InnoDB. Installazione senza Composer, override del core o dipendenza da Stripe. La compatibilità usa le API pubbliche dei moduli, `ModuleFrontController`, `Mail` e gli hook ordini di PrestaShop 9.

## Funzioni

- Creazione manuale o via API, attivazione dopo pagamento e codici casuali da 128 bit.
- Saldo in centesimi, uso parziale su più ordini e associazione facoltativa a un cliente. Le card al portatore diventano personali quando vengono associate; il trasferimento fra clienti è escluso.
- Prenotazione atomica, consumo, rilascio, riaccredito parziale, blocco, scadenza e rettifiche motivate. Una gift card per ordine e una prenotazione aperta per carrello.
- Ledger append-only con autore, motivo e saldi successivi. Ogni transizione e la relativa risposta idempotente sono salvate nella stessa transazione SQL.
- Accesso diretto dal menu laterale **Vendite → GiftCard** (icona regalo) oppure da **Moduli → MLab Gift Card → Configura**: elenco, dettaglio, movimenti, operazioni, prodotti/tagli, chiavi/API e manutenzione.
- Emissione dai prodotti configurati quando l’ordine entra in uno stato pagato; un codice per unità. L’email viene inviata al compratore, che può regalarla. Le card così emesse sono inizialmente al portatore.
- I codici sono nel formato `GC-8DUE-28DF`; non usano `0`, `O`, `1` e `I`. Gli altri formati non vengono accettati.
- Area cliente per vedere le proprie card e associarne una con il codice. Email HTML/testo IT/EN con coda, retry e lease per impedire invii simultanei.
- Per gli acquisti da prodotto con combinazione, l'email include un PDF stampabile con il codice, il valore e campi compilabili per destinatario e dedica.
- API HTTPS con chiavi separate per negozio, permessi, revoca, limiti di frequenza e idempotenza.

## Installazione

1. Copiare la cartella `mlabgiftcard` in `modules/` del negozio oppure caricare uno ZIP con questa cartella alla radice da Gestione moduli.
2. Installare **MLab Gift Card**, aprire **Vendite → GiftCard** e selezionare un singolo negozio nel contesto multistore.
3. Nella configurazione creare una card manuale oppure associare un prodotto virtuale e un eventuale ID combinazione al taglio desiderato. Il valore è quello configurato, anche se il prezzo di vendita del prodotto è diverso: definire consapevolmente prezzi, promozioni e trattamento fiscale.
4. Generare una chiave per il BE con i permessi necessari. Copiarla subito in un secret server. Per la manutenzione usare una chiave distinta con il solo permesso `maintenance`.
5. Programmare ogni minuto il job descritto in [docs/API.md](docs/API.md). Il pulsante back office permette anche l’esecuzione manuale.

Una corrispondenza con combinazione `0` copre il prodotto semplice o tutte le sue combinazioni; una corrispondenza specifica ha precedenza. La valuta deve coincidere con quella dell’ordine. Sono supportate valute attive a due decimali, senza conversione; il progetto usa EUR. Il negozio proprietario della card è sempre verificato.

### Aggiornamento da 1.0.0 a 1.0.1

Caricare i file aggiornati e premere **Aggiorna** in Gestione moduli: lo script di upgrade aggiunge la voce **GiftCard** senza modificare saldi, configurazione o chiavi API. Ricaricare il back office per aggiornare il menu. Non occorre disinstallare o reimpostare il modulo. La voce segue l’abilitazione del modulo; la disinstallazione la rimuove tramite il ciclo di vita standard di PrestaShop. Per profili dipendente personalizzati, concedere la lettura della pagina **GiftCard** in **Parametri avanzati → Team → Permessi** e l’autorizzazione a configurare **MLab Gift Card**. Il collegamento conserva il contesto multistore corrente e usa il token amministrativo generato dal core.

Le card acquistate vengono emesse una sola volta da `actionValidateOrderAfter` / `actionOrderStatusPostUpdate`. Un hook fallito produce un log privo del codice; usare **Riconcilia ordine** per ripetere l’emissione. Un ordine di emissione annullato, rimborsato o in errore blocca le sue card; un eventuale credito già utilizzato richiede riconciliazione. Il riaccredito di un ordine riscattato passa invece dall’operazione `refund`, con importo e riferimento espliciti. Il ritorno dell’ordine allo stato pagato non sblocca automaticamente le card.

### Allegati PDF delle gift card acquistate

Il codice viene inserito in memoria nel PDF identificato dallo SKU della combinazione salvato nella riga d'ordine. I modelli A4 si trovano in `pdf-templates/<SKU>.pdf`; `pdf-templates/catalog.json` associa ciascuno SKU a importo iniziale in centesimi e valuta. Il valore stampato nel PDF, il catalogo e l'importo configurato per la combinazione devono coincidere. Gli SKU previsti coprono le 15 varianti Dolce & Zampa da 20, 50, 70, 100 e 150 EUR. Per aggiungere una variante, fornire un modello compatibile con la stessa impaginazione A4 e aggiornare il catalogo.

Il modulo usa TCPDF fornito da PrestaShop e include FPDI con relativa licenza, senza dipendere da programmi esterni o salvare il PDF compilato su disco. Se ordine, SKU, modello o importo non sono validi, l'invio rimane in coda per un nuovo tentativo: correggere la configurazione e rieseguire la manutenzione. Le card create manualmente o via API senza una riga d'ordine continuano a ricevere la normale email senza allegato. La coda è almeno una volta: un reinvio può inviare di nuovo lo stesso codice.

## Integrazione con PsWebService

Il modulo è la fonte autorevole per il saldo. PsWebService deve chiamare le sue API e conservare gli ID opachi di card/prenotazione nello snapshot checkout; il FE headless usa il BE come proxy. Le chiavi API non vanno distribuite al browser. Il controller `account` supporta anche la sessione cliente nativa PrestaShop, con token CSRF e controllo del proprietario.

Il collegamento di `/api/order`, Stripe Checkout, webhook e conferma `webserviceapi` segue questo contratto:

1. PsWebService autentica il cliente/guest, prepara il carrello e applica i coupon; passa a `reserve` il codice, l’ID carrello, il cliente e il totale finale in centesimi.
2. Memorizza la risposta con la stessa chiave d’idempotenza e salva nello snapshot **totale ordine, credito gift card, residuo Stripe, reservation_id**. La somma dei due pagamenti deve essere il totale ordine.
3. Con residuo positivo, crea Checkout solo per quel residuo. La prenotazione deve durare almeno quanto la sessione; metodi differiti richiedono una durata adeguata (massimo sette giorni) e gestione dei pagamenti tardivi.
4. Con residuo zero, crea un ordine pagato tramite il flusso server di conferma. Il solo possesso del codice nel browser non autorizza una conferma ordine.
5. Dopo l’accettazione dell’ordine PrestaShop chiama `capture` con `reservation_id` e `order_id`. Il modulo verifica negozio, carrello, cliente, valuta, totale e stato pagato. La chiamata può essere ritentata in sicurezza se il BE si interrompe dopo la creazione dell’ordine.
6. Registra in PrestaShop i pagamenti Gift Card e Stripe per i rispettivi importi. Il modulo gestisce il credito; la creazione dell’ordine e dei relativi `OrderPayment` appartiene all’adattatore di checkout. Il credito gift card deve lasciare integro il totale dei prodotti, compresi gli sconti commerciali già applicati.
7. Rilascia la prenotazione se la creazione Checkout fallisce o la sessione diventa terminale/scade. Un semplice tentativo carta fallito può lasciare la sessione aperta: il BE deve verificarlo prima del rilascio.

Il modulo impedisce di pagare con gift card carrelli/ordini che contengono prodotti configurati per emettere altre card. `redeem` offre anche un consumo immediato atomico per un ordine già pagato e validato. Prima del rilascio del checkout, va collegato il contratto sopra alle API esistenti del progetto.

## Garanzie e ciclo di vita

Le tabelle `<prefisso>mlabgc_*` usano InnoDB. Il lock `SELECT ... FOR UPDATE` sulla card serializza le scritture; la proiezione saldo/prenotato è aggiornata insieme al ledger. Se il core ha già una transazione aperta, il modulo usa savepoint e ne conserva il confine di commit/rollback. Vincoli univoci proteggono idempotenza, emissione da una stessa origine, singolo pagamento gift card per ordine e singola prenotazione per carrello. La chiave d’idempotenza è legata anche all’identità del chiamante; un payload diverso restituisce 409.

Il rimborso non può superare l’importo catturato meno i riaccrediti precedenti. Anche il riferimento del rimborso protegge da duplicati con chiavi diverse. Il rimborso conserva eventuale blocco/scadenza della card. Le rettifiche non possono spendere credito prenotato o rendere il saldo negativo. La scadenza impedisce nuovi riscatti senza cancellare lo storico o azzerare il credito contabile.

Una prenotazione scaduta viene rilasciata dalla manutenzione o prima di una nuova prenotazione sulla stessa card. Un pagamento arrivato dopo il rilascio restituisce 409 e richiede riconciliazione nel BE: il modulo non riutilizza credito che nel frattempo potrebbe essere stato speso altrove.

I codici sono cercati tramite HMAC e conservati cifrati AES-256-GCM per consegna/reinvio. Anche le risposte idempotenti che contengono il codice sono cifrate. La chiave globale `MLABGC_MASTER_KEY` va inclusa nei backup e mantenuta stabile; perderla rende i codici illeggibili. Le chiavi API sono conservate solo come hash.

La disinstallazione conserva saldi, ledger, prenotazioni e chiave di cifratura, revocando le chiavi API. Una reinstallazione recupera i dati. La cancellazione definitiva dei dati finanziari richiede un intervento esplicito dell’amministratore del database.

## Verifiche

La suite del modulo usa un **database MySQL/MariaDB reale isolato**, anche per due richieste concorrenti da processi separati, senza avviare il bootstrap del microservizio:

```sh
MLABGC_TEST_DSN='mysql:host=127.0.0.1;dbname=mlabgiftcard_test' \
MLABGC_TEST_USER='test_user' MLABGC_TEST_PASSWORD='test_password' \
php vendor/bin/phpunit -c resources/prestashop-modules/mlabgiftcard/Test/phpunit.xml
```

La suite crea e cancella esclusivamente tabelle con prefisso casuale nel database `mlabgiftcard_test`. Consultare [docs/QA.md](docs/QA.md) per le verifiche su negozi PrestaShop 9.0, 9.1 e 9.2, email e checkout reale. Le prove sul ledger non sostituiscono queste verifiche di installazione e integrazione.

Riferimenti di compatibilità: [front controller](https://devdocs.prestashop-project.org/9/modules/concepts/controllers/front-controllers/), [hook ordini](https://devdocs.prestashop-project.org/9/modules/concepts/hooks/list-of-hooks/actionorderstatuspostupdate/), [modifiche PrestaShop 9](https://devdocs.prestashop-project.org/9/modules/core-updates/9.0/).
