# Verifiche di installazione e integrazione

## Matrice negozi

Eseguire su installazioni isolate PrestaShop 9.0.x, 9.1.x e 9.2.x con versioni PHP supportate dal rispettivo core. Servono HTTPS, un trasporto email di prova e MySQL/MariaDB InnoDB.

1. Installare lo ZIP, aprire Configura e generare una card da 5000 EUR. Verificare dettaglio, saldo, codice email e ledger.
2. Creare una card pending: saldo zero, impossibilità di riscatto, attivazione dal back office con riferimento pagamento, una sola emissione di credito.
3. Associare il codice dal proprio account cliente. Verificare che altro cliente e guest non possano usarlo; provare token CSRF errato e dieci tentativi di codice non valido.
4. Usare una chiave read per leggere e tentare una scrittura: deve ricevere 403. Revocare la chiave: deve ricevere 401. Verificare che le chiavi di shop A non funzionino su shop B.
5. Configurare un prodotto virtuale/combinazione da 50 euro, pagare due unità: vengono emesse due card una volta sola. Ripetere l’hook/riconciliazione. Annullare l’ordine: i codici sono bloccati. Controllare le email HTML e testo, e il fallback inglese.
6. Spegnere temporaneamente il trasporto email, emettere una card, riavviarlo ed eseguire il job: stesso codice, credito invariato. Verificare un secondo worker concorrente.
7. Disinstallare/reinstallare: saldi e codice restano recuperabili, vecchie API key revocate. Verificare backup e ripristino della configurazione `MLABGC_MASTER_KEY` insieme alle tabelle.
8. Verificare **Vendite → GiftCard** dopo una nuova installazione e dopo l’aggiornamento 1.0.0 → 1.0.1: una sola voce con icona regalo, accesso alla configurazione e contesto negozio conservato. Ripetere l’upgrade: nessun duplicato, saldi e chiavi API invariati. Disabilitare/riabilitare il modulo e verificarne la scomparsa/ricomparsa; disinstallare e verificare la rimozione della voce. Con un dipendente senza lettura della pagina o senza permesso di configurare il modulo, anche l’URL diretto deve essere negato.

## Checkout PsWebService

- Totale dopo coupon 7500, gift card 5000: riserva 5000, Checkout Stripe 2500, snapshot con importi distinti, ordine pagato 7500 con due pagamenti, capture una volta sola.
- Totale 3000, gift card 5000: ordine pagato via Gift Card, nessuna sessione Stripe, saldo finale 2000.
- Cart modificato dopo snapshot: il BE rifiuta la conferma e gestisce pagamento/rilascio o rimborso. La sola risposta FE non conferma il pagamento.
- Retry dopo errore di rete: stesso risultato con stessa chiave; errore 409 cambiando payload.
- Sessione scaduta: release ripetibile. Tentativo Stripe fallito ma sessione ancora aperta: prenotazione conservata.
- Pagamento differito: TTL adeguata. Webhook pagato dopo rilascio: 409 e riconciliazione, senza credito negativo.
- Rimborso 1500 su riscattato 3000: riaccredito una volta sola; successivo rimborso oltre i 1500 rimanenti rifiutato. Verificare codice di rimborso duplicato con diversa chiave.
- Chargeback acquisto card: blocco via API; impedire ulteriori consumi; revisionare ordini già riscattati.

## Evidenza automatizzata locale

La suite `Test/phpunit.xml` esercita le tabelle SQL reali, l’idempotenza, i limiti dei rimborsi, il rollback dopo un errore fra aggiornamento saldo e ledger, i permessi API e due processi concorrenti sullo stesso saldo. Usa un adapter delle sole primitive DB pubbliche di PrestaShop; le prove di shop elencate sopra richiedono il core PrestaShop installato.
