<?php
namespace Mlab\GiftCard;

final class Api
{
    public const SCOPES = ['read', 'issue', 'spend', 'manage', 'maintenance'];
    public const ACTIONS = [
        'cards' => ['GET', 'read'], 'card' => ['GET', 'read'], 'movements' => ['GET', 'read'],
        'reservation' => ['GET', 'read'], 'lookup' => ['POST', 'read'],
        'create' => ['POST', 'issue'], 'activate' => ['POST', 'issue'], 'reserve' => ['POST', 'spend'],
        'capture' => ['POST', 'spend'], 'redeem' => ['POST', 'spend'], 'release' => ['POST', 'spend'],
        'refund' => ['POST', 'manage'], 'assign' => ['POST', 'manage'], 'status' => ['POST', 'manage'],
        'validity' => ['POST', 'manage'], 'adjust' => ['POST', 'manage'], 'resend' => ['POST', 'manage'],
        'maintenance' => ['POST', 'maintenance'],
    ];

    public function __construct(private readonly Storage $db, private readonly Crypto $crypto, private readonly int $shopId,
        private readonly Service $service, private readonly Platform $platform) {}

    public function dispatch(string $action, string $method, array $data, string $token, string $requestKey): array
    {
        $account = $this->authenticate($token);
        if (!isset(self::ACTIONS[$action])) {
            throw new GiftCardException('unknown_action', 'Unknown API action.', 404);
        }
        [$expectedMethod, $scope] = self::ACTIONS[$action];
        if ($method !== $expectedMethod) {
            throw new GiftCardException('invalid_method', 'Use ' . $expectedMethod . ' for this action.', 405);
        }
        if (!in_array($scope, explode(',', $account['scopes']), true)) {
            throw new GiftCardException('forbidden', 'API key does not grant the required scope.', 403);
        }
        foreach (['card_id', 'reservation_id', 'code', 'currency'] as $field) {
            if (isset($data[$field]) && !is_string($data[$field])) {
                throw new GiftCardException('invalid_input', $field . ' must be a string.');
            }
        }
        $customer = isset($data['customer_id']) ? Service::integer($data['customer_id'], 'customer_id') : null;
        if ($customer !== null) {
            $this->platform->customer($customer);
        }
        if ($action === 'lookup') {
            return ['card' => $this->service->lookup(Service::text($data['code'] ?? null, 'code', 64), $customer)];
        }
        if ($action === 'cards') {
            return ['cards' => $this->service->cards($customer, Service::integer($data['page'] ?? 1, 'page', 1, 100000)), 'page_size' => 50];
        }
        if ($action === 'card') {
            return ['card' => $this->service->card((string) ($data['card_id'] ?? ''))];
        }
        if ($action === 'movements') {
            return ['movements' => $this->service->movements((string) ($data['card_id'] ?? ''),
                Service::integer($data['page'] ?? 1, 'page', 1, 100000)), 'page_size' => 100];
        }
        if ($action === 'reservation') {
            return ['reservation' => $this->service->reservation((string) ($data['reservation_id'] ?? ''))];
        }
        if ($action === 'create' || $action === 'reserve' || $action === 'redeem') {
            $this->platform->currency(Service::currency($data['currency'] ?? null));
        }
        if ($action === 'reserve' || $action === 'redeem') {
            $this->platform->cart(Service::integer($data['cart_id'] ?? null, 'cart_id'), $customer,
                $data['currency'], Service::integer($data['amount_cents'] ?? null, 'amount_cents'));
        }
        if ($action === 'capture' || $action === 'redeem') {
            $reservation = $action === 'capture' ? $this->service->reservation((string) ($data['reservation_id'] ?? ''))
                : ['cart_id' => $data['cart_id'], 'customer_id' => $customer, 'amount_cents' => $data['amount_cents']];
            $currency = $action === 'capture' ? $this->service->card($reservation['card_id'])['currency'] : $data['currency'];
            $this->platform->order(Service::integer($data['order_id'] ?? null, 'order_id'), $reservation, $currency);
        }
        if ($action === 'maintenance') {
            return ['expired_reservations' => $this->service->expireReservations()];
        }
        return $this->service->execute($action, $data, $requestKey, 'api:' . $account['id']);
    }

    public function createKey(string $name, array $scopes, ?string $formNonce = null): array
    {
        $name = Service::text($name, 'key name', 128);
        if ($scopes === [] || count(array_filter($scopes, 'is_string')) !== count($scopes) || array_diff($scopes, self::SCOPES)) {
            throw new GiftCardException('invalid_scope', 'Select valid API scopes.');
        }
        $scopes = array_unique($scopes);
        sort($scopes);
        if ($formNonce !== null && !preg_match('/^admin:[a-f0-9]{32}$/D', $formNonce)) {
            throw new GiftCardException('invalid_token', 'Invalid API key creation request.');
        }
        // Repeating a back-office form must not silently create another credential.
        $token = 'gc_api_' . ($formNonce === null ? bin2hex(random_bytes(32))
            : $this->crypto->hash('api-key:' . $this->shopId . ':' . $formNonce));
        $id = substr(hash('sha256', 'key-id:' . $token), 0, 32);
        $existing = $this->db->row('SELECT * FROM ' . $this->db->table('api_keys') . ' WHERE id=' . $this->db->quote($id));
        if ($existing !== null) {
            if (!$existing['active'] || $existing['name'] !== $name || $existing['scopes'] !== implode(',', $scopes)) {
                throw new GiftCardException('key_conflict', 'This key creation request was already used.', 409);
            }
            return ['id' => $id, 'token' => $token];
        }
        $this->db->insert('api_keys', ['id' => $id, 'id_shop' => $this->shopId, 'name' => $name,
            'key_hash' => hash('sha256', $token), 'scopes' => implode(',', $scopes), 'active' => 1,
            'created_at' => gmdate('Y-m-d H:i:s'), 'last_used_at' => null]);
        return ['id' => $id, 'token' => $token];
    }

    public function keys(): array
    {
        return $this->db->rows('SELECT id,name,scopes,active,created_at,last_used_at FROM ' . $this->db->table('api_keys')
            . ' WHERE id_shop=' . $this->shopId . ' ORDER BY created_at DESC');
    }

    public function revoke(string $id): void
    {
        if (!preg_match('/^[a-f0-9]{32}$/D', $id)) {
            throw new GiftCardException('invalid_id', 'Invalid API key identifier.');
        }
        $this->db->update('api_keys', ['active' => 0], 'id_shop=' . $this->shopId . ' AND id=' . $this->db->quote($id));
    }

    public function rateLimit(string $identity, int $limit = 120, int $seconds = 60): void
    {
        $window = intdiv(time(), $seconds) * $seconds;
        $bucket = $this->crypto->hash($this->shopId . ':' . $identity . ':' . $window);
        $this->db->exec('INSERT INTO ' . $this->db->table('rate_limits') . ' (bucket,hits,expires_at) VALUES ('
            . $this->db->quote($bucket) . ',1,' . $this->db->quote(gmdate('Y-m-d H:i:s', $window + $seconds))
            . ') ON DUPLICATE KEY UPDATE hits=hits+1');
        $row = $this->db->row('SELECT hits FROM ' . $this->db->table('rate_limits') . ' WHERE bucket=' . $this->db->quote($bucket));
        if ((int) $row['hits'] > $limit) {
            throw new GiftCardException('rate_limited', 'Too many requests. Retry later.', 429);
        }
    }

    private function authenticate(string $token): array
    {
        if (!preg_match('/^gc_api_[a-f0-9]{64}$/D', $token)) {
            throw new GiftCardException('unauthorized', 'Invalid API credentials.', 401);
        }
        $row = $this->db->row('SELECT * FROM ' . $this->db->table('api_keys') . ' WHERE id_shop=' . $this->shopId
            . ' AND active=1 AND key_hash=' . $this->db->quote(hash('sha256', $token)));
        if ($row === null) {
            throw new GiftCardException('unauthorized', 'Invalid API credentials.', 401);
        }
        $this->db->update('api_keys', ['last_used_at' => gmdate('Y-m-d H:i:s')], 'id=' . $this->db->quote($row['id']));
        return $row;
    }
}
