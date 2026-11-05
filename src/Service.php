<?php
namespace Mlab\GiftCard;

/** Monetary state transitions are serialized on the card row, never on a cache lock. */
final class Service
{
    public const MAX_CENTS = 2000000000;
    private \Closure $clock;

    public function __construct(private readonly Storage $db, private readonly Crypto $crypto,
        private readonly int $shopId, ?callable $clock = null)
    {
        if ($shopId < 1) {
            throw new \InvalidArgumentException('A single shop context is required.');
        }
        $this->clock = $clock === null ? static fn() => time() : \Closure::fromCallable($clock);
    }

    public static function integer(mixed $value, string $name, int $min = 1, int $max = self::MAX_CENTS): int
    {
        if ((!is_int($value) && !(is_string($value) && preg_match('/^-?[0-9]{1,10}$/D', $value)))
            || (int) $value < $min || (int) $value > $max) {
            throw new GiftCardException('invalid_input', $name . ' must be an integer in the allowed range.');
        }
        return (int) $value;
    }

    public static function currency(mixed $currency): string
    {
        if (!is_string($currency) || !preg_match('/^[A-Z]{3}$/D', $currency)) {
            throw new GiftCardException('invalid_currency', 'Use a three-letter uppercase currency.');
        }
        return $currency;
    }

    public static function text(mixed $value, string $name, int $max = 255): string
    {
        if (!is_string($value) || trim($value) === '' || mb_strlen($value) > $max || preg_match('/[\x00-\x1f]/', $value)) {
            throw new GiftCardException('invalid_input', 'Invalid ' . $name . '.');
        }
        return trim($value);
    }

    public function execute(string $action, array $data, string $key, string $actor): array
    {
        foreach (['card_id', 'reservation_id', 'code', 'currency', 'source_reference', 'payment_reference',
            'refund_reference', 'recipient_email', 'recipient_name', 'reason', 'status', 'expires_at'] as $field) {
            if (isset($data[$field]) && !is_string($data[$field])) {
                throw new GiftCardException('invalid_input', $field . ' must be a string.');
            }
        }
        if (!preg_match('/^[A-Za-z0-9_.:\/-]{8,128}$/D', $key)) {
            throw new GiftCardException('invalid_idempotency_key', 'Idempotency-Key must contain 8–128 ASCII letters, digits, or ._:/-.');
        }
        $actor = self::text($actor, 'actor', 96);
        $hash = hash('sha256', json_encode([$action, $actor, $this->canonical($data)], JSON_THROW_ON_ERROR));
        return $this->db->transaction(function () use ($action, $data, $key, $actor, $hash) {
            $where = 'id_shop=' . $this->shopId . ' AND request_key=' . $this->db->quote($key);
            // Concurrent requests with the same key wait on this unique row.
            $this->db->exec('INSERT INTO ' . $this->db->table('requests')
                . ' (id_shop,request_key,payload_hash,created_at) VALUES (' . $this->shopId . ','
                . $this->db->quote($key) . ',' . $this->db->quote($hash) . ',' . $this->db->quote($this->now())
                . ') ON DUPLICATE KEY UPDATE request_key=request_key');
            $request = $this->db->row('SELECT * FROM ' . $this->db->table('requests') . ' WHERE ' . $where . ' FOR UPDATE');
            if (!hash_equals($request['payload_hash'], $hash)) {
                throw new GiftCardException('idempotency_conflict', 'This idempotency key was used for another request.', 409);
            }
            if ($request['response_ciphertext'] !== null) {
                return json_decode($this->crypto->decrypt($request['response_ciphertext']), true, 512, JSON_THROW_ON_ERROR);
            }
            $result = match ($action) {
                'create' => $this->create($data, $actor),
                'activate' => $this->activate($data, $actor),
                'assign' => $this->assign($data, $actor),
                'reserve' => $this->reserve($data, $actor),
                'redeem' => $this->redeem($data, $actor),
                'capture' => $this->capture($data, $actor),
                'release' => $this->release($data, $actor),
                'refund' => $this->refund($data, $actor),
                'status' => $this->status($data, $actor),
                'adjust' => $this->adjust($data, $actor),
                'validity' => $this->validity($data, $actor),
                'resend' => $this->resend($data),
                'expire' => $this->expire($data, $actor),
                default => throw new GiftCardException('unknown_action', 'Unknown gift card action.', 404),
            };
            $this->db->update('requests', ['response_ciphertext' => $this->crypto->encrypt(json_encode($result, JSON_THROW_ON_ERROR))], $where);
            return $result;
        });
    }

    public function card(string $id): array
    {
        return $this->view($this->findCard($id));
    }

    public function lookup(string $code, ?int $customerId): array
    {
        return $this->view($this->byCode($code, $customerId));
    }

    public function cards(?int $customerId = null, int $page = 1): array
    {
        $where = 'id_shop=' . $this->shopId;
        if ($customerId !== null) {
            $where .= ' AND customer_id=' . self::integer($customerId, 'customer_id');
        }
        $page = self::integer($page, 'page', 1, 100000);
        $rows = $this->db->rows('SELECT * FROM ' . $this->db->table('cards') . ' WHERE ' . $where
            . ' ORDER BY created_at DESC,id DESC LIMIT 50 OFFSET ' . (($page - 1) * 50));
        return array_map([$this, 'view'], $rows);
    }

    public function movements(string $id, int $page = 1): array
    {
        $this->findCard($id);
        $page = self::integer($page, 'page', 1, 100000);
        $rows = $this->db->rows('SELECT * FROM ' . $this->db->table('movements') . ' WHERE id_shop=' . $this->shopId
            . ' AND card_id=' . $this->db->quote($id) . ' ORDER BY id DESC LIMIT 100 OFFSET ' . (($page - 1) * 100));
        foreach ($rows as &$row) {
            foreach (['id', 'id_shop', 'delta_cents', 'delta_reserved_cents', 'balance_after_cents', 'reserved_after_cents'] as $field) {
                $row[$field] = (int) $row[$field];
            }
        }
        unset($row);
        return $rows;
    }

    public function reservation(string $id): array
    {
        $row = $this->db->row('SELECT * FROM ' . $this->db->table('reservations') . ' WHERE ' . $this->whereId($id));
        if ($row === null) {
            throw new GiftCardException('reservation_unavailable', 'Reservation unavailable.', 404);
        }
        return $this->reservationView($row);
    }

    /** A bounded worker; money held by expired reservations remains unavailable until released. */
    public function expireReservations(int $limit = 100): int
    {
        $rows = $this->db->rows('SELECT id,card_id FROM ' . $this->db->table('reservations') . ' WHERE id_shop='
            . $this->shopId . " AND status='held' AND expires_at<=" . $this->db->quote($this->now())
            . ' ORDER BY expires_at LIMIT ' . self::integer($limit, 'limit', 1, 500));
        foreach ($rows as $row) {
            $this->execute('expire', ['card_id' => $row['card_id']], 'expiry:' . $row['id'], 'maintenance');
        }
        return count($rows);
    }

    private function create(array $data, string $actor): array
    {
        $amount = self::integer($data['amount_cents'] ?? null, 'amount_cents');
        $currency = self::currency($data['currency'] ?? null);
        $customer = $this->customer($data);
        $reference = self::text($data['source_reference'] ?? null, 'source_reference', 160);
        if (!preg_match('/^[A-Za-z0-9_.:\/-]+$/D', $reference)) {
            throw new GiftCardException('invalid_input', 'Invalid source_reference.');
        }
        $email = $data['recipient_email'] ?? null;
        if ($email !== null && (!is_string($email) || strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL))) {
            throw new GiftCardException('invalid_input', 'Invalid recipient_email.');
        }
        $name = isset($data['recipient_name']) ? self::text($data['recipient_name'], 'recipient_name', 128) : null;
        if (isset($data['validity_days']) && isset($data['expires_at'])) {
            throw new GiftCardException('invalid_date', 'Use expires_at or validity_days, not both.');
        }
        $days = isset($data['validity_days']) ? self::integer($data['validity_days'], 'validity_days', 0, 36500) : 0;
        $expires = $days > 0 ? gmdate('Y-m-d H:i:s', ($this->clock)() + $days * 86400) : $this->date($data['expires_at'] ?? null);
        $state = $data['status'] ?? 'pending';
        if (!in_array($state, ['pending', 'active'], true)) {
            throw new GiftCardException('invalid_state', 'New cards must be pending or active.');
        }
        $existing = $this->db->row('SELECT * FROM ' . $this->db->table('cards') . ' WHERE id_shop=' . $this->shopId
            . ' AND source_reference=' . $this->db->quote($reference) . ' FOR UPDATE');
        if ($existing !== null) {
            if ((int) $existing['initial_cents'] !== $amount || $existing['currency'] !== $currency
                || $this->nullableInt($existing['customer_id']) !== $customer || $existing['recipient_email'] !== $email) {
                throw new GiftCardException('source_conflict', 'The issuance source belongs to another card definition.', 409);
            }
            return ['card' => $this->view($existing), 'code' => $this->crypto->decrypt($existing['code_ciphertext'])];
        }
        $code = $this->crypto->newCode();
        $card = [
            'id' => bin2hex(random_bytes(16)), 'id_shop' => $this->shopId,
            'code_hash' => $this->crypto->codeHash($code), 'code_ciphertext' => $this->crypto->encrypt($code),
            'code_suffix' => substr($code, -4), 'initial_cents' => $amount, 'balance_cents' => 0, 'reserved_cents' => 0,
            'currency' => $currency, 'customer_id' => $customer, 'status' => $state, 'source_reference' => $reference,
            'recipient_email' => $email, 'recipient_name' => $name, 'expires_at' => $expires,
            'activated_at' => $state === 'active' ? $this->now() : null,
            'created_at' => $this->now(), 'updated_at' => $this->now(),
        ];
        $this->db->insert('cards', $card);
        $card = $this->move($card, $state === 'active' ? 'issued' : 'created', $state === 'active' ? $amount : 0,
            0, $actor, 'Gift card issuance', $reference);
        if ($state === 'active') {
            $this->queueDelivery($card);
        }
        return ['card' => $this->view($card), 'code' => $code];
    }

    private function activate(array $data, string $actor): array
    {
        $card = $this->findCard((string) ($data['card_id'] ?? ''), true);
        $reference = self::text($data['payment_reference'] ?? null, 'payment_reference', 160);
        if ($card['status'] !== 'pending') {
            throw new GiftCardException('invalid_state', 'Only pending cards can be activated.', 409);
        }
        $this->usableDate($card);
        $card['status'] = 'active';
        $card['activated_at'] = $this->now();
        $card = $this->move($card, 'issued', (int) $card['initial_cents'], 0, $actor, 'Payment confirmed', $reference);
        $this->queueDelivery($card);
        return ['card' => $this->view($card)];
    }

    private function assign(array $data, string $actor): array
    {
        $customer = self::integer($data['customer_id'] ?? null, 'customer_id');
        $card = isset($data['code']) ? $this->byCode((string) $data['code'], $customer, true)
            : $this->findCard((string) ($data['card_id'] ?? ''), true);
        if (isset($data['code'])) {
            $this->usable($card);
        }
        if ($card['customer_id'] !== null && (int) $card['customer_id'] !== $customer) {
            throw new GiftCardException('already_assigned', 'The card is already assigned to another customer.', 409);
        }
        if ((int) $card['reserved_cents'] > 0) {
            throw new GiftCardException('card_reserved', 'Release the reservations before assigning the card.', 409);
        }
        $card['customer_id'] = $customer;
        return ['card' => $this->view($this->move($card, 'assigned', 0, 0, $actor, 'Assigned to customer', (string) $customer))];
    }

    private function reserve(array $data, string $actor): array
    {
        $customer = $this->customer($data);
        $card = $this->byCode((string) ($data['code'] ?? ''), $customer, true);
        $card = $this->releaseExpired($card, $actor);
        $this->usable($card);
        if (self::currency($data['currency'] ?? null) !== $card['currency']) {
            throw new GiftCardException('currency_mismatch', 'Gift card currency does not match the cart.');
        }
        $requested = self::integer($data['amount_cents'] ?? null, 'amount_cents');
        $available = (int) $card['balance_cents'] - (int) $card['reserved_cents'];
        $partial = $data['allow_partial'] ?? true;
        if (!is_bool($partial)) {
            throw new GiftCardException('invalid_input', 'allow_partial must be boolean.');
        }
        if ($available <= 0 || (!$partial && $available < $requested)) {
            throw new GiftCardException('insufficient_balance', 'Insufficient available gift card balance.', 409);
        }
        $amount = min($requested, $available);
        $cartId = self::integer($data['cart_id'] ?? null, 'cart_id');
        if ($this->db->row('SELECT id FROM ' . $this->db->table('reservations') . ' WHERE id_shop='
            . $this->shopId . ' AND active_cart_id=' . $cartId) !== null) {
            throw new GiftCardException('cart_reserved', 'This cart already has a reservation; reuse or release it.', 409);
        }
        $ttl = self::integer($data['ttl_seconds'] ?? 7200, 'ttl_seconds', 60, 604800);
        $expires = gmdate('Y-m-d H:i:s', ($this->clock)() + $ttl);
        if ($card['expires_at'] !== null && $expires > $card['expires_at']) {
            $expires = $card['expires_at'];
        }
        $reservation = [
            'id' => bin2hex(random_bytes(16)), 'id_shop' => $this->shopId, 'card_id' => $card['id'],
            'cart_id' => $cartId, 'active_cart_id' => $cartId, 'customer_id' => $customer,
            'amount_cents' => $amount, 'refunded_cents' => 0, 'status' => 'held', 'order_id' => null,
            'expires_at' => $expires, 'created_at' => $this->now(), 'updated_at' => $this->now(),
        ];
        $this->db->insert('reservations', $reservation);
        $card = $this->move($card, 'reserved', 0, $amount, $actor, 'Checkout reservation', null, $reservation['id']);
        return ['reservation' => $this->reservationView($reservation), 'card' => $this->view($card)];
    }

    private function capture(array $data, string $actor): array
    {
        [$card, $reservation] = $this->lockedReservation($data);
        $order = self::integer($data['order_id'] ?? null, 'order_id');
        if ($reservation['status'] === 'captured' && (int) $reservation['order_id'] === $order) {
            return ['reservation' => $this->reservationView($reservation), 'card' => $this->view($card)];
        }
        $this->usable($card);
        $existing = $this->db->row('SELECT id FROM ' . $this->db->table('reservations') . ' WHERE id_shop='
            . $this->shopId . ' AND order_id=' . $order);
        if ($existing !== null && $existing['id'] !== $reservation['id']) {
            throw new GiftCardException('order_already_redeemed', 'This order already has a gift card payment.', 409);
        }
        if ($reservation['status'] !== 'held' || $reservation['expires_at'] <= $this->now()) {
            throw new GiftCardException('reservation_conflict', 'Reservation is no longer available for capture.', 409);
        }
        $reservation['status'] = 'captured';
        $reservation['order_id'] = $order;
        $reservation['active_cart_id'] = null;
        $this->saveReservation($reservation);
        $amount = (int) $reservation['amount_cents'];
        $card = $this->move($card, 'redeemed', -$amount, -$amount, $actor, 'Order payment', (string) $order, $reservation['id']);
        return ['reservation' => $this->reservationView($reservation), 'card' => $this->view($card)];
    }

    private function release(array $data, string $actor): array
    {
        [$card, $reservation] = $this->lockedReservation($data);
        if ($reservation['status'] === 'captured') {
            throw new GiftCardException('reservation_conflict', 'Refund a captured reservation instead of releasing it.', 409);
        }
        if ($reservation['status'] === 'held') {
            $reason = self::text($data['reason'] ?? null, 'reason');
            $reservation['status'] = 'released';
            $reservation['active_cart_id'] = null;
            $this->saveReservation($reservation);
            $card = $this->move($card, 'released', 0, -(int) $reservation['amount_cents'], $actor, $reason, null, $reservation['id']);
        }
        return ['reservation' => $this->reservationView($reservation), 'card' => $this->view($card)];
    }

    private function refund(array $data, string $actor): array
    {
        [$card, $reservation] = $this->lockedReservation($data);
        $amount = self::integer($data['amount_cents'] ?? null, 'amount_cents');
        $reference = self::text($data['refund_reference'] ?? null, 'refund_reference', 160);
        $previous = $this->db->row('SELECT delta_cents FROM ' . $this->db->table('movements') . ' WHERE id_shop='
            . $this->shopId . ' AND reservation_id=' . $this->db->quote($reservation['id'])
            . " AND type='refunded' AND reference=" . $this->db->quote($reference));
        if ($previous !== null) {
            if ((int) $previous['delta_cents'] !== $amount) {
                throw new GiftCardException('refund_conflict', 'The refund reference was used for a different amount.', 409);
            }
            return ['reservation' => $this->reservationView($reservation), 'card' => $this->view($card)];
        }
        if ($reservation['status'] !== 'captured' || $amount > (int) $reservation['amount_cents'] - (int) $reservation['refunded_cents']) {
            throw new GiftCardException('refund_conflict', 'Refund exceeds the unrefunded captured amount.', 409);
        }
        $reservation['refunded_cents'] = (int) $reservation['refunded_cents'] + $amount;
        $this->saveReservation($reservation);
        $card = $this->move($card, 'refunded', $amount, 0, $actor, self::text($data['reason'] ?? null, 'reason'),
            $reference, $reservation['id']);
        return ['reservation' => $this->reservationView($reservation), 'card' => $this->view($card)];
    }

    private function status(array $data, string $actor): array
    {
        $card = $this->findCard((string) ($data['card_id'] ?? ''), true);
        $status = $data['status'] ?? null;
        if (!in_array($status, ['blocked', 'unblocked'], true)) {
            throw new GiftCardException('invalid_state', 'Use blocked or unblocked.');
        }
        $card['status'] = $status === 'blocked' ? 'blocked' : ($card['activated_at'] === null ? 'pending' : 'active');
        return ['card' => $this->view($this->move($card, $status, 0, 0, $actor, self::text($data['reason'] ?? null, 'reason')))];
    }

    private function redeem(array $data, string $actor): array
    {
        $data['allow_partial'] = false;
        $held = $this->reserve($data, $actor);
        return $this->capture(['reservation_id' => $held['reservation']['id'], 'order_id' => $data['order_id'] ?? null], $actor);
    }

    private function adjust(array $data, string $actor): array
    {
        $card = $this->findCard((string) ($data['card_id'] ?? ''), true);
        if ($card['activated_at'] === null) {
            throw new GiftCardException('invalid_state', 'Activate the card before adjusting its balance.', 409);
        }
        $amount = self::integer($data['delta_cents'] ?? null, 'delta_cents', -self::MAX_CENTS);
        if ($amount === 0) {
            throw new GiftCardException('invalid_input', 'delta_cents must be nonzero.');
        }
        return ['card' => $this->view($this->move($card, 'adjusted', $amount, 0, $actor,
            self::text($data['reason'] ?? null, 'reason')))];
    }

    private function validity(array $data, string $actor): array
    {
        $card = $this->findCard((string) ($data['card_id'] ?? ''), true);
        if ((int) $card['reserved_cents'] !== 0) {
            throw new GiftCardException('card_reserved', 'Release reservations before changing validity.', 409);
        }
        $card['expires_at'] = $this->date($data['expires_at'] ?? null);
        return ['card' => $this->view($this->move($card, 'validity_changed', 0, 0, $actor,
            self::text($data['reason'] ?? null, 'reason')))];
    }

    private function resend(array $data): array
    {
        $card = $this->findCard((string) ($data['card_id'] ?? ''), true);
        $this->usable($card);
        if ($card['recipient_email'] === null) {
            throw new GiftCardException('no_recipient', 'No recipient was specified for this gift card.');
        }
        $this->queueDelivery($card, true);
        return ['queued' => true, 'card_id' => $card['id']];
    }

    private function expire(array $data, string $actor): array
    {
        return ['card' => $this->view($this->releaseExpired($this->findCard((string) ($data['card_id'] ?? ''), true), $actor))];
    }

    private function releaseExpired(array $card, string $actor): array
    {
        $rows = $this->db->rows('SELECT * FROM ' . $this->db->table('reservations') . ' WHERE id_shop=' . $this->shopId
            . ' AND card_id=' . $this->db->quote($card['id']) . " AND status='held' AND expires_at<="
            . $this->db->quote($this->now()) . ' FOR UPDATE');
        foreach ($rows as $row) {
            $row['status'] = 'released';
            $row['active_cart_id'] = null;
            $this->saveReservation($row);
            $card = $this->move($card, 'reservation_expired', 0, -(int) $row['amount_cents'], $actor,
                'Reservation expired', null, $row['id']);
        }
        return $card;
    }

    private function lockedReservation(array $data): array
    {
        $id = (string) ($data['reservation_id'] ?? '');
        $preliminary = $this->reservation($id);
        $card = $this->findCard($preliminary['card_id'], true);
        $row = $this->db->row('SELECT * FROM ' . $this->db->table('reservations') . ' WHERE ' . $this->whereId($id) . ' FOR UPDATE');
        return [$card, $row];
    }

    private function saveReservation(array &$reservation): void
    {
        $reservation['updated_at'] = $this->now();
        $this->db->update('reservations', $reservation, $this->whereId($reservation['id']));
    }

    private function findCard(string $id, bool $lock = false): array
    {
        $row = $this->db->row('SELECT * FROM ' . $this->db->table('cards') . ' WHERE ' . $this->whereId($id)
            . ($lock ? ' FOR UPDATE' : ''));
        if ($row === null) {
            throw new GiftCardException('card_unavailable', 'Gift card unavailable.', 404);
        }
        return $row;
    }

    private function byCode(string $code, ?int $customer, bool $lock = false): array
    {
        $row = $this->db->row('SELECT * FROM ' . $this->db->table('cards') . ' WHERE id_shop=' . $this->shopId
            . ' AND code_hash=' . $this->db->quote($this->crypto->codeHash($code)) . ($lock ? ' FOR UPDATE' : ''));
        if ($row === null || ($row['customer_id'] !== null && (int) $row['customer_id'] !== $customer)) {
            throw new GiftCardException('card_unavailable', 'Gift card unavailable.', 404);
        }
        return $row;
    }

    private function usable(array $card): void
    {
        if ($card['status'] !== 'active') {
            throw new GiftCardException('card_inactive', 'Gift card is not active.', 409);
        }
        $this->usableDate($card);
    }

    private function usableDate(array $card): void
    {
        if ($card['expires_at'] !== null && $card['expires_at'] <= $this->now()) {
            throw new GiftCardException('card_expired', 'Gift card has expired.', 409);
        }
    }

    private function move(array $card, string $type, int $delta, int $reserved, string $actor,
        string $reason, ?string $reference = null, ?string $reservationId = null): array
    {
        $card['balance_cents'] = (int) $card['balance_cents'] + $delta;
        $card['reserved_cents'] = (int) $card['reserved_cents'] + $reserved;
        if ($card['balance_cents'] < 0 || $card['balance_cents'] > self::MAX_CENTS
            || $card['reserved_cents'] < 0 || $card['reserved_cents'] > $card['balance_cents']) {
            throw new GiftCardException('balance_conflict', 'The change would invalidate the available balance.', 409);
        }
        $card['updated_at'] = $this->now();
        $this->db->update('cards', $card, $this->whereId($card['id']));
        $this->db->insert('movements', [
            'id_shop' => $this->shopId, 'card_id' => $card['id'], 'reservation_id' => $reservationId,
            'type' => $type, 'delta_cents' => $delta, 'delta_reserved_cents' => $reserved,
            'balance_after_cents' => $card['balance_cents'], 'reserved_after_cents' => $card['reserved_cents'],
            'actor' => $actor, 'reason' => $reason, 'reference' => $reference, 'created_at' => $this->now(),
        ]);
        return $card;
    }

    private function queueDelivery(array $card, bool $resend = false): void
    {
        if ($card['recipient_email'] === null) {
            return;
        }
        $this->db->exec('INSERT INTO ' . $this->db->table('deliveries') . ' (card_id,id_shop,status,next_attempt_at) VALUES ('
            . $this->db->quote($card['id']) . ',' . $this->shopId . ",'pending'," . $this->db->quote($this->now()) . ')'
            . ($resend ? " ON DUPLICATE KEY UPDATE status=IF(status='sending',status,'pending'), next_attempt_at=VALUES(next_attempt_at), attempts=0"
                : ' ON DUPLICATE KEY UPDATE card_id=card_id'));
    }

    private function view(array $card): array
    {
        unset($card['code_hash'], $card['code_ciphertext']);
        foreach (['id_shop', 'initial_cents', 'balance_cents', 'reserved_cents'] as $field) {
            $card[$field] = (int) $card[$field];
        }
        $card['customer_id'] = $this->nullableInt($card['customer_id']);
        $card['available_cents'] = $card['balance_cents'] - $card['reserved_cents'];
        $card['expired'] = $card['expires_at'] !== null && $card['expires_at'] <= $this->now();
        $card['redeemable'] = $card['status'] === 'active' && !$card['expired'] && $card['available_cents'] > 0;
        foreach (['expires_at', 'activated_at', 'created_at', 'updated_at'] as $field) {
            $card[$field] = $this->iso($card[$field]);
        }
        return $card;
    }

    private function reservationView(array $row): array
    {
        unset($row['active_cart_id']);
        foreach (['id_shop', 'cart_id', 'amount_cents', 'refunded_cents'] as $field) {
            $row[$field] = (int) $row[$field];
        }
        $row['customer_id'] = $this->nullableInt($row['customer_id']);
        $row['order_id'] = $this->nullableInt($row['order_id']);
        foreach (['expires_at', 'created_at', 'updated_at'] as $field) {
            $row[$field] = $this->iso($row[$field]);
        }
        return $row;
    }

    private function date(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $date = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new \DateTimeZone('UTC')) : false;
        if ($date === false || $date->format('Y-m-d\TH:i:s\Z') !== $value || $date->getTimestamp() <= ($this->clock)()) {
            throw new GiftCardException('invalid_date', 'expires_at must be a future UTC timestamp, e.g. 2027-12-31T23:59:59Z.');
        }
        return $date->format('Y-m-d H:i:s');
    }

    private function customer(array $data): ?int
    {
        return isset($data['customer_id']) ? self::integer($data['customer_id'], 'customer_id') : null;
    }

    private function nullableInt(mixed $value): ?int { return $value === null ? null : (int) $value; }
    private function now(): string { return gmdate('Y-m-d H:i:s', ($this->clock)()); }
    private function iso(?string $date): ?string { return $date === null ? null : str_replace(' ', 'T', $date) . 'Z'; }

    private function whereId(string $id): string
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) {
            throw new GiftCardException('invalid_id', 'Invalid identifier.');
        }
        return 'id_shop=' . $this->shopId . ' AND id=' . $this->db->quote($id);
    }

    private function canonical(array $data): array
    {
        foreach ($data as &$value) {
            if (is_array($value)) {
                $value = $this->canonical($value);
            }
        }
        unset($value);
        ksort($data);
        return $data;
    }
}
