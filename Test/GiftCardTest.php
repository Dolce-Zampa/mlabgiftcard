<?php
use Mlab\GiftCard\Api;
use Mlab\GiftCard\Crypto;
use Mlab\GiftCard\GiftCardException;
use Mlab\GiftCard\Platform;
use Mlab\GiftCard\Schema;
use Mlab\GiftCard\Service;
use Mlab\GiftCard\Storage;
use PHPUnit\Framework\TestCase;

final class GiftCardTestPlatform implements Platform
{
    public bool $rejectOrder = false;
    public function customer(?int $id): void {}
    public function currency(string $iso): void {}
    public function cart(int $id, ?int $customerId, string $currency, int $amount): void {}
    public function order(int $id, array $reservation, string $currency): void
    {
        if ($this->rejectOrder) {
            throw new GiftCardException('order_mismatch', 'Mismatched order', 409);
        }
    }
}

final class GiftCardTest extends TestCase
{
    private GiftCardTestDb $adapter;
    private Storage $db;
    private Service $service;
    private Crypto $crypto;
    private string $prefix;
    private int $now;

    protected function setUp(): void
    {
        if (!getenv('MLABGC_TEST_DSN')) {
            $this->markTestSkipped('Set MLABGC_TEST_DSN for the isolated MySQL integration database.');
        }
        $this->prefix = 'gc_test_' . bin2hex(random_bytes(6)) . '_';
        $this->adapter = new GiftCardTestDb(giftCardTestConnection());
        $this->db = new Storage($this->adapter, $this->prefix);
        Schema::install($this->db);
        $this->crypto = new Crypto(str_repeat('a', 64));
        $this->now = time();
        $this->service = new Service($this->db, $this->crypto, 1, fn() => $this->now);
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        foreach (array_keys(Schema::definitions()) as $table) {
            $this->db->exec('DROP TABLE ' . $this->db->table($table));
        }
    }

    public function testPendingCardActivatesOnlyOnceAndReplaysSameIssuance(): void
    {
        $payload = ['amount_cents' => 5000, 'currency' => 'EUR', 'source_reference' => 'purchase:pending'];
        $card = $this->operate('create', $payload, 'purchase-key');
        $this->assertSame(0, $card['card']['balance_cents']);
        $this->assertFalse($card['card']['redeemable']);
        $this->assertSame($card, $this->operate('create', $payload, 'purchase-key'));
        $this->fails('card_inactive', fn() => $this->operate('reserve', $this->hold($card, 1000)));
        $activated = $this->operate('activate', ['card_id' => $card['card']['id'], 'payment_reference' => 'pi_verified'], 'activation-key');
        $this->assertSame(5000, $activated['card']['balance_cents']);
        $this->fails('invalid_state', fn() => $this->operate('activate', ['card_id' => $card['card']['id'], 'payment_reference' => 'pi_verified']));
        $this->assertCount(2, $this->service->movements($card['card']['id']));
    }

    public function testReservationsCapturePartialBalanceAndRepeatedCaptureCannotDoubleDebit(): void
    {
        $card = $this->card();
        $held = $this->operate('reserve', $this->hold($card, 4000));
        $this->assertSame(10000, $held['card']['balance_cents']);
        $this->assertSame(6000, $held['card']['available_cents']);
        $capture = ['reservation_id' => $held['reservation']['id'], 'order_id' => 21];
        $paid = $this->operate('capture', $capture);
        $this->assertSame(6000, $paid['card']['balance_cents']);
        $this->assertSame(0, $paid['card']['reserved_cents']);
        $again = $this->operate('capture', $capture);
        $this->assertSame($paid['reservation']['id'], $again['reservation']['id']);
        $this->assertSame(6000, $again['card']['balance_cents']);
        $this->assertLedger($card['card']['id']);
    }

    public function testTwoPhaseReleaseAndExpirationNeverSpendMoney(): void
    {
        $card = $this->card();
        $held = $this->operate('reserve', $this->hold($card, 4000));
        $this->operate('release', ['reservation_id' => $held['reservation']['id'], 'reason' => 'Stripe session expired']);
        $this->assertSame(10000, $this->service->card($card['card']['id'])['available_cents']);
        $held = $this->operate('reserve', $this->hold($card, 4000) + ['ttl_seconds' => 60]);
        $this->now += 61;
        $this->assertSame(1, $this->service->expireReservations());
        $this->fails('reservation_conflict', fn() => $this->operate('capture', ['reservation_id' => $held['reservation']['id'], 'order_id' => 21]));
        $this->assertSame(10000, $this->service->card($card['card']['id'])['balance_cents']);
        $this->assertLedger($card['card']['id']);
    }

    public function testRefundIsBoundedAndProviderReferenceIsIdempotentAcrossKeys(): void
    {
        $card = $this->card();
        $held = $this->operate('reserve', $this->hold($card, 4000));
        $this->operate('capture', ['reservation_id' => $held['reservation']['id'], 'order_id' => 21]);
        $refund = ['reservation_id' => $held['reservation']['id'], 'amount_cents' => 1500,
            'refund_reference' => 'refund:partial', 'reason' => 'Item returned'];
        $this->operate('refund', $refund);
        $this->operate('refund', $refund);
        $this->assertSame(7500, $this->service->card($card['card']['id'])['balance_cents']);
        $refund['amount_cents'] = 2600;
        $refund['refund_reference'] = 'refund:excess';
        $this->fails('refund_conflict', fn() => $this->operate('refund', $refund));
        $this->assertLedger($card['card']['id']);
    }

    public function testCustomerCurrencyShopAndInvalidAmountsAreRejected(): void
    {
        $card = $this->card(['customer_id' => 42]);
        $payload = $this->hold($card, 4000);
        $payload['customer_id'] = 43;
        $this->fails('card_unavailable', fn() => $this->operate('reserve', $payload));
        $payload['customer_id'] = 42;
        $payload['currency'] = 'USD';
        $this->fails('currency_mismatch', fn() => $this->operate('reserve', $payload));
        $payload['currency'] = 'EUR';
        $payload['amount_cents'] = 0.5;
        $this->fails('invalid_input', fn() => $this->operate('reserve', $payload));
        $otherShop = new Service($this->db, $this->crypto, 2);
        $this->fails('card_unavailable', fn() => $otherShop->card($card['card']['id']));
        $this->assertSame(10000, $this->service->card($card['card']['id'])['balance_cents']);
    }

    public function testFailureAfterBalanceUpdateRollsBackCardReservationLedgerAndIdempotency(): void
    {
        $card = $this->card();
        $this->adapter->failOn = 'INSERT INTO ' . $this->db->table('movements');
        try {
            $this->operate('reserve', $this->hold($card, 4000), 'retry-after-failure');
            $this->fail('Injected failure should abort the transaction.');
        } catch (RuntimeException $error) {
            $this->assertSame('Injected database failure', $error->getMessage());
        }
        $this->assertSame(10000, $this->service->card($card['card']['id'])['available_cents']);
        $this->assertSame([], $this->db->rows('SELECT * FROM ' . $this->db->table('reservations')));
        $this->operate('reserve', $this->hold($card, 4000), 'retry-after-failure');
        $this->assertSame(6000, $this->service->card($card['card']['id'])['available_cents']);
        $this->assertLedger($card['card']['id']);
    }

    public function testNestedHookTransactionsPreserveTheOuterCommitAndRollbackBoundary(): void
    {
        $this->adapter->pdo->beginTransaction();
        $this->card();
        $this->assertTrue($this->adapter->pdo->inTransaction());
        $this->adapter->pdo->rollBack();
        $this->assertSame([], $this->db->rows('SELECT * FROM ' . $this->db->table('cards')));
        $this->assertSame([], $this->db->rows('SELECT * FROM ' . $this->db->table('requests')));

        $card = $this->card();
        $this->adapter->pdo->beginTransaction();
        $this->db->insert('rate_limits', ['bucket' => str_repeat('f', 64), 'hits' => 1, 'expires_at' => '2030-01-01 00:00:00']);
        $this->adapter->failOn = 'INSERT INTO ' . $this->db->table('movements');
        try {
            $this->operate('reserve', $this->hold($card, 4000));
            $this->fail('Expected nested write failure');
        } catch (RuntimeException $error) {
            $this->assertSame('Injected database failure', $error->getMessage());
        }
        $this->assertTrue($this->adapter->pdo->inTransaction());
        $this->adapter->pdo->commit();
        $this->assertCount(1, $this->db->rows('SELECT * FROM ' . $this->db->table('rate_limits')));
        $this->assertSame(10000, $this->service->card($card['card']['id'])['available_cents']);
        $this->assertSame([], $this->db->rows('SELECT * FROM ' . $this->db->table('reservations')));
    }

    public function testChangedIdempotentPayloadCannotChangeCredit(): void
    {
        $card = $this->card();
        $this->operate('reserve', $this->hold($card, 4000), 'unchanged-request');
        $this->fails('idempotency_conflict', fn() => $this->operate('reserve', $this->hold($card, 5000), 'unchanged-request'));
        $this->assertSame(6000, $this->service->card($card['card']['id'])['available_cents']);
    }

    public function testPartialReservationAndAdjustmentsPreserveHeldMoney(): void
    {
        $card = $this->card();
        $held = $this->operate('reserve', $this->hold($card, 15000));
        $this->assertSame(10000, $held['reservation']['amount_cents']);
        $this->fails('balance_conflict', fn() => $this->operate('adjust', ['card_id' => $card['card']['id'],
            'delta_cents' => -1, 'reason' => 'Cannot remove reserved credit']));
        $this->operate('adjust', ['card_id' => $card['card']['id'], 'delta_cents' => 1000, 'reason' => 'Manual correction']);
        $this->assertSame(1000, $this->service->card($card['card']['id'])['available_cents']);
        $this->assertLedger($card['card']['id']);
    }

    public function testStructuredValuesCannotBeCoercedIntoStringIdentifiers(): void
    {
        $this->fails('invalid_input', fn() => $this->operate('reserve', ['code' => ['invalid']]));
        $this->fails('invalid_input', fn() => $this->operate('activate', ['card_id' => ['invalid']]));
    }

    public function testClaimCannotTransferCustomerOwnershipOrInvalidateOpenHolds(): void
    {
        $card = $this->card();
        $held = $this->operate('reserve', $this->hold($card, 4000));
        $this->fails('card_reserved', fn() => $this->operate('assign', ['card_id' => $card['card']['id'], 'customer_id' => 42]));
        $this->operate('release', ['reservation_id' => $held['reservation']['id'], 'reason' => 'Cancelled checkout']);
        $this->operate('assign', ['code' => $card['code'], 'customer_id' => 42]);
        $this->fails('card_unavailable', fn() => $this->operate('assign', ['code' => $card['code'], 'customer_id' => 43]));
        $this->assertSame(42, $this->service->card($card['card']['id'])['customer_id']);
    }

    public function testBlockedAndExpiredCardsCannotBeSpentAndRefundDoesNotReactivateThem(): void
    {
        $card = $this->card(['validity_days' => 1]);
        $held = $this->operate('reserve', $this->hold($card, 4000));
        $this->operate('status', ['card_id' => $card['card']['id'], 'status' => 'blocked', 'reason' => 'Chargeback']);
        $this->fails('card_inactive', fn() => $this->operate('capture', ['reservation_id' => $held['reservation']['id'], 'order_id' => 21]));
        $this->operate('release', ['reservation_id' => $held['reservation']['id'], 'reason' => 'Blocked issuance']);
        $this->operate('status', ['card_id' => $card['card']['id'], 'status' => 'unblocked', 'reason' => 'Dispute resolved']);
        $this->now += 86401;
        $this->fails('card_expired', fn() => $this->operate('reserve', $this->hold($card, 4000)));
        $this->assertTrue($this->service->card($card['card']['id'])['expired']);
    }

    public function testExactRedeemAndOneGiftCardPaymentPerOrder(): void
    {
        $card = $this->card();
        $paid = $this->operate('redeem', $this->hold($card, 4000) + ['order_id' => 21]);
        $this->assertSame(6000, $paid['card']['balance_cents']);
        $another = $this->card();
        $this->fails('order_already_redeemed', fn() => $this->operate('redeem', $this->hold($another, 4000, 12) + ['order_id' => 21]));
        $this->assertSame(10000, $this->service->card($another['card']['id'])['available_cents']);
    }

    public function testApiScopesRevocationAndPlatformValidationProtectMutations(): void
    {
        $platform = new GiftCardTestPlatform();
        $api = new Api($this->db, $this->crypto, 1, $this->service, $platform);
        $read = $api->createKey('Read only', ['read']);
        $this->assertSame([], $api->dispatch('cards', 'GET', [], $read['token'], '')['cards']);
        $this->fails('forbidden', fn() => $api->dispatch('create', 'POST', [], $read['token'], 'forbidden-request'));
        $spend = $api->createKey('Checkout', ['spend']);
        $card = $this->card();
        $held = $api->dispatch('reserve', 'POST', $this->hold($card, 4000), $spend['token'], 'api-reserve-test');
        $platform->rejectOrder = true;
        $this->fails('order_mismatch', fn() => $api->dispatch('capture', 'POST',
            ['reservation_id' => $held['reservation']['id'], 'order_id' => 21], $spend['token'], 'api-capture-test'));
        $this->assertSame(10000, $this->service->card($card['card']['id'])['balance_cents']);
        $api->revoke($read['id']);
        $this->fails('unauthorized', fn() => $api->dispatch('cards', 'GET', [], $read['token'], ''));
        $other = new Api($this->db, $this->crypto, 2, new Service($this->db, $this->crypto, 2), $platform);
        $this->fails('unauthorized', fn() => $other->dispatch('cards', 'GET', [], $spend['token'], ''));
        foreach ($this->db->rows('SELECT * FROM ' . $this->db->table('api_keys')) as $row) {
            $this->assertNotContains($spend['token'], $row);
        }
    }

    public function testCodesAndIdempotencyResultsAreEncryptedAndNormalized(): void
    {
        $card = $this->card();
        $row = $this->db->row('SELECT * FROM ' . $this->db->table('cards'));
        $this->assertNotContains($card['code'], $row);
        $this->assertSame($card['code'], $this->crypto->decrypt($row['code_ciphertext']));
        $this->assertSame($this->crypto->codeHash($card['code']), $this->crypto->codeHash(strtolower(str_replace('-', ' ', $card['code']))));
        $this->assertSame($card['card']['id'], $this->service->lookup($card['code'], null)['id']);
        $this->expectException(RuntimeException::class);
        $this->crypto->decrypt(base64_encode(str_repeat('x', 64)));
    }

    public function testRefreshingKeyCreationDoesNotCreateOrReactivateCredentials(): void
    {
        $api = new Api($this->db, $this->crypto, 1, $this->service, new GiftCardTestPlatform());
        $nonce = 'admin:' . str_repeat('1', 32);
        $key = $api->createKey('Checkout', ['read', 'spend'], $nonce);
        $this->assertSame($key, $api->createKey('Checkout', ['spend', 'read'], $nonce));
        $this->assertCount(1, $api->keys());
        $api->revoke($key['id']);
        $this->fails('key_conflict', fn() => $api->createKey('Checkout', ['read', 'spend'], $nonce));
    }

    public function testParallelReservationsCannotOverspendTheSameCard(): void
    {
        $card = $this->card();
        $results = $this->parallel([
            [$this->hold($card, 8000, 11) + ['allow_partial' => false], 'parallel-first'],
            [$this->hold($card, 8000, 12) + ['allow_partial' => false], 'parallel-second'],
        ]);
        $this->assertCount(1, array_filter($results, fn($result) => $result['ok']));
        $this->assertSame(2000, $this->service->card($card['card']['id'])['available_cents']);
        $this->assertLedger($card['card']['id']);
    }

    public function testParallelRetriesWithSameKeyReturnExactlyOneReservation(): void
    {
        $card = $this->card();
        $request = [$this->hold($card, 8000), 'same-parallel-key'];
        $results = $this->parallel([$request, $request]);
        $this->assertTrue($results[0]['ok']);
        $this->assertTrue($results[1]['ok']);
        $this->assertSame($results[0]['reservation'], $results[1]['reservation']);
        $this->assertCount(1, $this->db->rows('SELECT * FROM ' . $this->db->table('reservations')));
        $this->assertSame(2000, $this->service->card($card['card']['id'])['available_cents']);
    }

    private function parallel(array $requests): array
    {
        $processes = [];
        foreach ($requests as [$payload, $key]) {
            $process = proc_open([PHP_BINARY, __DIR__ . '/worker.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $this->assertIsResource($process);
            fwrite($pipes[0], json_encode(['prefix' => $this->prefix, 'payload' => $payload, 'key' => $key], JSON_THROW_ON_ERROR));
            fclose($pipes[0]);
            $processes[] = [$process, $pipes];
        }
        $results = [];
        foreach ($processes as [$process, $pipes]) {
            $output = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), $errors);
            $results[] = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        }
        return $results;
    }

    private function card(array $extra = []): array
    {
        return $this->operate('create', $extra + ['amount_cents' => 10000, 'currency' => 'EUR', 'status' => 'active',
            'source_reference' => 'purchase:' . bin2hex(random_bytes(8))]);
    }
    private function hold(array $card, int $amount, int $cart = 11): array
    {
        return ['code' => $card['code'], 'amount_cents' => $amount, 'currency' => 'EUR', 'cart_id' => $cart,
            'customer_id' => $card['card']['customer_id']];
    }
    private function operate(string $action, array $payload, ?string $key = null): array
    {
        return $this->service->execute($action, $payload, $key ?? 'test:' . bin2hex(random_bytes(12)), 'test');
    }
    private function fails(string $code, callable $operation): void
    {
        try { $operation(); $this->fail('Expected error: ' . $code); }
        catch (GiftCardException $error) { $this->assertSame($code, $error->error); }
    }
    private function assertLedger(string $id): void
    {
        $card = $this->service->card($id);
        $movements = $this->service->movements($id);
        $this->assertSame($card['balance_cents'], array_sum(array_map(fn($row) => (int) $row['delta_cents'], $movements)));
        $this->assertSame($card['reserved_cents'], array_sum(array_map(fn($row) => (int) $row['delta_reserved_cents'], $movements)));
    }
}
