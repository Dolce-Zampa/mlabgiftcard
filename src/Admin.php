<?php
namespace Mlab\GiftCard;

final class Admin
{
    private int $shopId;
    private string $url;

    public function __construct(private readonly object $module, private readonly object $context, private readonly array $runtime)
    {
        $this->shopId = (int) $context->shop->id;
        $this->url = $context->link->getAdminLink('AdminModules') . '&configure=mlabgiftcard';
    }

    public function render(): string
    {
        $html = '';
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && \Tools::getValue('gc_action')) {
            try {
                if (!hash_equals(\Tools::getAdminTokenLite('AdminModules'), (string) \Tools::getValue('gc_token'))) {
                    throw new GiftCardException('invalid_token', 'Token di sicurezza non valido.', 403);
                }
                $result = $this->submit((string) \Tools::getValue('gc_action'));
                $html .= $this->module->displayConfirmation('Operazione completata.');
                if (isset($result['code'])) {
                    $html .= '<div class="alert alert-info">Codice gift card: <code>' . $this->e($result['code']) . '</code></div>';
                }
                if (isset($result['token'])) {
                    $html .= '<div class="alert alert-info">Copia ora la chiave API; è mostrata solo alla creazione: <code>'
                        . $this->e($result['token']) . '</code></div>';
                }
            } catch (GiftCardException $error) {
                $html .= $this->module->displayError($this->e($error->getMessage()));
            }
        }
        $html .= '<div class="panel"><h2>MLab Gift Card</h2><p>Importi in centesimi, date in UTC. Il saldo disponibile è il saldo residuo meno le prenotazioni aperte.</p>'
            . '<p><a href="#gc-create">Crea gift card</a> · <a href="#gc-products">Prodotti</a> · <a href="#gc-api">API</a> · <a href="#gc-jobs">Manutenzione</a></p></div>';
        $page = Service::integer(\Tools::getValue('gc_page', 1), 'pagina', 1, 100000);
        $html .= '<div class="panel"><h3>Gift card — pagina ' . $page . '</h3><div class="table-responsive"><table class="table"><thead><tr>'
            . '<th>Codice</th><th>Cliente</th><th>Saldo</th><th>Prenotato</th><th>Disponibile</th><th>Stato</th><th>Scadenza</th></tr></thead><tbody>';
        foreach ($this->runtime['service']->cards(null, $page) as $card) {
            $html .= '<tr><td><a href="' . $this->e($this->url . '&gc_card=' . $card['id']) . '">•••• '
                . $this->e($card['code_suffix']) . '</a></td><td>' . $this->e($card['customer_id'] ?? 'Al portatore') . '</td>'
                . '<td>' . $this->money($card['balance_cents'], $card['currency']) . '</td><td>' . $this->money($card['reserved_cents'], $card['currency'])
                . '</td><td>' . $this->money($card['available_cents'], $card['currency']) . '</td><td>'
                . $this->e($card['expired'] ? 'Scaduta' : $card['status']) . '</td><td>' . $this->e($card['expires_at'] ?? 'Senza scadenza') . '</td></tr>';
        }
        $html .= '</tbody></table></div><a href="' . $this->e($this->url . '&gc_page=' . max(1, $page - 1)) . '">Precedente</a> · <a href="'
            . $this->e($this->url . '&gc_page=' . ($page + 1)) . '">Successiva</a></div>';
        $selected = (string) \Tools::getValue('gc_card', \Tools::getValue('card_id', ''));
        if ($selected !== '') {
            $html .= $this->details($selected);
        }
        $html .= '<div id="gc-create" class="panel"><h3>Crea gift card</h3>' . $this->form('create',
            $this->input('amount_cents', 'Valore in centesimi (es. 5000 = 50,00)', 'number', '', true)
            . $this->input('currency', 'Valuta', 'text', 'EUR', true)
            . $this->input('customer_id', 'ID cliente (vuoto = codice al portatore)', 'number')
            . $this->input('recipient_email', 'Email destinatario', 'email')
            . $this->input('recipient_name', 'Nome destinatario')
            . $this->input('expires_at', 'Scadenza UTC (es. 2027-12-31T23:59:59Z, vuoto = nessuna)')
            . '<label>Stato iniziale</label><select class="form-control" name="status"><option value="active">Attiva — emissione manuale</option>'
            . '<option value="pending">In attesa del pagamento</option></select>', 'Crea gift card') . '</div>';
        return $html . $this->products() . $this->apiSection() . $this->jobs();
    }

    private function submit(string $action): array
    {
        $api = $this->runtime['api'];
        $platform = $this->runtime['platform'];
        $db = $this->runtime['db'];
        if ($action === 'create_key') {
            $scopes = \Tools::getValue('scopes', []);
            return $api->createKey((string) \Tools::getValue('key_name'), is_array($scopes) ? $scopes : [],
                (string) \Tools::getValue('gc_request_key'));
        }
        if ($action === 'revoke_key') {
            $api->revoke((string) \Tools::getValue('key_id'));
            return [];
        }
        if ($action === 'save_product' || $action === 'delete_product') {
            $productId = Service::integer(\Tools::getValue('product_id'), 'product_id');
            $attribute = Service::integer(\Tools::getValue('attribute_id', 0), 'attribute_id', 0);
            if ($action === 'delete_product') {
                $db->exec('DELETE FROM ' . $db->table('products') . ' WHERE id_shop=' . $this->shopId
                    . ' AND product_id=' . $productId . ' AND attribute_id=' . $attribute);
                return [];
            }
            $product = new \Product($productId, false, null, $this->shopId);
            if (!\Validate::isLoadedObject($product) || !$product->isAssociatedToShop($this->shopId)) {
                throw new GiftCardException('product_unavailable', 'Prodotto non trovato in questo negozio.', 404);
            }
            if ($attribute > 0 && (int) (new \Combination($attribute))->id_product !== $productId) {
                throw new GiftCardException('invalid_combination', 'La combinazione non appartiene al prodotto.');
            }
            $currency = Service::currency(\Tools::getValue('currency'));
            $platform->currency($currency);
            $amount = Service::integer(\Tools::getValue('amount_cents'), 'amount_cents');
            $days = Service::integer(\Tools::getValue('validity_days', 0), 'validity_days', 0, 36500);
            $db->exec('INSERT INTO ' . $db->table('products') . ' (id_shop,product_id,attribute_id,amount_cents,currency,validity_days) VALUES ('
                . $this->shopId . ',' . $productId . ',' . $attribute . ',' . $amount . ',' . $db->quote($currency) . ',' . $days . ')'
                . ' ON DUPLICATE KEY UPDATE amount_cents=VALUES(amount_cents),currency=VALUES(currency),validity_days=VALUES(validity_days)');
            return [];
        }
        if ($action === 'maintenance') {
            $this->runtime['service']->expireReservations(500);
            $this->runtime['delivery']->sendBatch(50);
            $db->exec('DELETE FROM ' . $db->table('rate_limits') . ' WHERE expires_at<' . $db->quote(gmdate('Y-m-d H:i:s', time() - 3600)));
            return [];
        }
        if ($action === 'reconcile_order') {
            $orderId = Service::integer(\Tools::getValue('order_id'), 'order_id');
            if ((int) (new \Order($orderId))->id_shop !== $this->shopId) {
                throw new GiftCardException('order_unavailable', 'Ordine non trovato in questo negozio.', 404);
            }
            $this->module->processOrder($orderId);
            return [];
        }
        $data = [];
        foreach (['card_id', 'customer_id', 'amount_cents', 'currency', 'recipient_email', 'recipient_name', 'expires_at',
            'status', 'payment_reference', 'delta_cents', 'reason', 'reservation_id', 'refund_reference'] as $field) {
            $value = \Tools::getValue($field);
            if ($value !== false && $value !== '') {
                $data[$field] = $value;
            }
        }
        if (!in_array($action, ['create', 'activate', 'assign', 'status', 'adjust', 'validity', 'resend', 'release', 'refund'], true)) {
            throw new GiftCardException('unknown_action', 'Operazione non valida.');
        }
        if (isset($data['customer_id'])) {
            $platform->customer(Service::integer($data['customer_id'], 'customer_id'));
        }
        if ($action === 'create') {
            $platform->currency(Service::currency($data['currency'] ?? null));
            $data['source_reference'] = 'manual:' . hash('sha256', (string) \Tools::getValue('gc_request_key'));
        }
        return $this->runtime['service']->execute($action, $data, (string) \Tools::getValue('gc_request_key'),
            'employee:' . (int) $this->context->employee->id);
    }

    private function details(string $id): string
    {
        $service = $this->runtime['service'];
        $card = $service->card($id);
        $hidden = $this->hidden('card_id', $id);
        $html = '<div class="panel"><h3>Gift card •••• ' . $this->e($card['code_suffix']) . '</h3><p>ID: <code>' . $this->e($id)
            . '</code> — Origine: ' . $this->e($card['source_reference']) . '</p><p>Destinatario: '
            . $this->e($card['recipient_email'] ?? 'Non specificato') . '</p>';
        if ($card['status'] === 'pending') {
            $html .= $this->form('activate', $hidden . $this->input('payment_reference', 'Riferimento pagamento verificato', 'text', '', true), 'Attiva dopo il pagamento');
        }
        $html .= $this->form('status', $hidden . $this->hidden('status', $card['status'] === 'blocked' ? 'unblocked' : 'blocked')
            . $this->input('reason', 'Motivazione', 'text', '', true), $card['status'] === 'blocked' ? 'Sblocca' : 'Blocca');
        if ($card['customer_id'] === null) {
            $html .= $this->form('assign', $hidden . $this->input('customer_id', 'ID cliente', 'number', '', true), 'Associa al cliente');
        }
        $html .= $this->form('adjust', $hidden . $this->input('delta_cents', 'Rettifica in centesimi (+ / −)', 'number', '', true)
            . $this->input('reason', 'Motivazione rettifica', 'text', '', true), 'Registra rettifica')
            . $this->form('validity', $hidden . $this->input('expires_at', 'Nuova scadenza UTC (vuoto = nessuna)', 'text', $card['expires_at'] ?? '')
            . $this->input('reason', 'Motivazione modifica validità', 'text', '', true), 'Aggiorna validità')
            . $this->form('resend', $hidden, 'Rimetti in coda email');
        $html .= '<h4>Prenotazioni e riscatti</h4>';
        $db = $this->runtime['db'];
        foreach ($db->rows('SELECT * FROM ' . $db->table('reservations') . ' WHERE id_shop=' . $this->shopId
            . ' AND card_id=' . $db->quote($id) . ' ORDER BY created_at DESC LIMIT 100') as $reservation) {
            $html .= '<p><code>' . $this->e($reservation['id']) . '</code> — ' . $this->e($reservation['status']) . ' — '
                . $this->money((int) $reservation['amount_cents'], $card['currency']) . ' — carrello ' . (int) $reservation['cart_id']
                . ' — ordine ' . (int) $reservation['order_id'] . '</p>';
            $fields = $this->hidden('reservation_id', $reservation['id']) . $hidden;
            if ($reservation['status'] === 'held') {
                $html .= $this->form('release', $fields . $this->input('reason', 'Motivazione rilascio', 'text', '', true), 'Rilascia prenotazione');
            } elseif ($reservation['status'] === 'captured' && (int) $reservation['refunded_cents'] < (int) $reservation['amount_cents']) {
                $html .= $this->form('refund', $fields . $this->input('amount_cents', 'Riaccredito in centesimi', 'number', '', true)
                    . $this->input('refund_reference', 'Riferimento univoco rimborso', 'text', '', true)
                    . $this->input('reason', 'Motivazione rimborso', 'text', '', true), 'Riaccredita sulla gift card');
            }
        }
        $html .= '<h4>Ultimi 100 movimenti</h4><table class="table"><thead><tr><th>Data UTC</th><th>Tipo</th><th>Variazione</th><th>Prenotato Δ</th><th>Saldo dopo</th><th>Autore / motivo</th></tr></thead><tbody>';
        foreach ($service->movements($id) as $movement) {
            $html .= '<tr><td>' . $this->e($movement['created_at']) . '</td><td>' . $this->e($movement['type']) . '</td><td>'
                . $this->money((int) $movement['delta_cents'], $card['currency']) . '</td><td>'
                . $this->money((int) $movement['delta_reserved_cents'], $card['currency']) . '</td><td>'
                . $this->money((int) $movement['balance_after_cents'], $card['currency']) . '</td><td>'
                . $this->e($movement['actor'] . ': ' . $movement['reason']) . '</td></tr>';
        }
        return $html . '</tbody></table></div>';
    }

    private function products(): string
    {
        $db = $this->runtime['db'];
        $html = '<div id="gc-products" class="panel"><h3>Prodotti che emettono gift card</h3><p>Ogni unità acquistata genera un codice dopo il pagamento. Il codice viene inviato al compratore e può essere associato da chi lo riceve. Configura prodotti virtuali e prezzi coerenti con il taglio.</p>';
        foreach ($db->rows('SELECT * FROM ' . $db->table('products') . ' WHERE id_shop=' . $this->shopId) as $mapping) {
            $html .= '<p>Prodotto ' . (int) $mapping['product_id'] . ', combinazione ' . (int) $mapping['attribute_id'] . ' — '
                . $this->money((int) $mapping['amount_cents'], $mapping['currency']) . '</p>'
                . $this->form('delete_product', $this->hidden('product_id', $mapping['product_id'])
                    . $this->hidden('attribute_id', $mapping['attribute_id']), 'Rimuovi associazione prodotto');
        }
        return $html . $this->form('save_product', $this->input('product_id', 'ID prodotto', 'number', '', true)
            . $this->input('attribute_id', 'ID combinazione (0 = tutte / prodotto semplice)', 'number', '0', true)
            . $this->input('amount_cents', 'Valore gift card in centesimi', 'number', '', true)
            . $this->input('currency', 'Valuta', 'text', 'EUR', true)
            . $this->input('validity_days', 'Validità in giorni dall’emissione (0 = nessuna scadenza)', 'number', '0', true), 'Salva prodotto') . '</div>';
    }

    private function apiSection(): string
    {
        $endpoint = $this->context->link->getModuleLink('mlabgiftcard', 'api', [], true);
        $html = '<div id="gc-api" class="panel"><h3>API per integrazione FE / BE</h3><p>Endpoint HTTPS: <code>' . $this->e($endpoint)
            . '</code></p><p>Aggiungi <code>&amp;action=reserve</code> (o un’altra operazione). Usa <code>Authorization: Bearer CHIAVE</code>, '
            . '<code>Content-Type: application/json</code> e <code>Idempotency-Key</code> per le scritture. Le chiavi sono credenziali server: il FE headless chiama il proprio BE.</p>'
            . '<table class="table"><thead><tr><th>Operazione</th><th>Metodo</th><th>Permesso</th></tr></thead><tbody>';
        foreach (Api::ACTIONS as $action => [$method, $scope]) {
            $html .= '<tr><td><code>' . $this->e($action) . '</code></td><td>' . $method . '</td><td>' . $scope . '</td></tr>';
        }
        $html .= '</tbody></table><h4>Chiavi API del negozio</h4>';
        foreach ($this->runtime['api']->keys() as $key) {
            $html .= '<p>' . $this->e($key['name']) . ' — ' . $this->e($key['scopes']) . ' — '
                . ($key['active'] ? 'Attiva' : 'Revocata') . ' — ultimo utilizzo: ' . $this->e($key['last_used_at'] ?? 'Mai') . '</p>';
            if ($key['active']) {
                $html .= $this->form('revoke_key', $this->hidden('key_id', $key['id']), 'Revoca chiave');
            }
        }
        $scopes = '';
        foreach (Api::SCOPES as $scope) {
            $scopes .= '<label class="checkbox"><input type="checkbox" name="scopes[]" value="' . $scope . '">' . $scope . '</label>';
        }
        return $html . $this->form('create_key', $this->input('key_name', 'Nome chiave', 'text', '', true) . $scopes, 'Genera chiave API') . '</div>';
    }

    private function jobs(): string
    {
        return '<div id="gc-jobs" class="panel"><h3>Consegna e riconciliazione</h3><p>Programma ogni minuto una richiesta POST all’API <code>action=maintenance</code> con una chiave che abbia il permesso maintenance. Elabora le email e rilascia le prenotazioni scadute.</p>'
            . $this->form('maintenance', '', 'Elabora email e prenotazioni scadute')
            . '<p>Ripeti l’emissione di un ordine pagato se il relativo hook è fallito. I codici già emessi restano gli stessi.</p>'
            . $this->form('reconcile_order', $this->input('order_id', 'ID ordine', 'number', '', true), 'Riconcilia ordine') . '</div>';
    }

    private function form(string $action, string $fields, string $button): string
    {
        return '<form method="post" action="' . $this->e($this->url) . '" style="margin-bottom:1.5rem">'
            . $this->hidden('gc_action', $action) . $this->hidden('gc_token', \Tools::getAdminTokenLite('AdminModules'))
            . $this->hidden('gc_request_key', 'admin:' . bin2hex(random_bytes(16))) . $fields
            . '<br><button class="btn btn-default" type="submit">' . $this->e($button) . '</button></form>';
    }

    private function input(string $name, string $label, string $type = 'text', string $value = '', bool $required = false): string
    {
        return '<label>' . $this->e($label) . '<input class="form-control" name="' . $this->e($name) . '" type="' . $type
            . '" value="' . $this->e($value) . '"' . ($required ? ' required' : '') . '></label> ';
    }

    private function hidden(string $name, mixed $value): string
    {
        return '<input type="hidden" name="' . $this->e($name) . '" value="' . $this->e($value) . '">';
    }
    private function e(mixed $text): string { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
    private function money(int $amount, string $currency): string { return number_format($amount / 100, 2, ',', '.') . ' ' . $this->e($currency); }
}
