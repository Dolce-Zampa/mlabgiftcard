<?php
require_once __DIR__ . '/CoreDoubles.php';
require_once dirname(__DIR__) . '/mlabgiftcard.php';

use Mlab\GiftCard\CorePlatform;
use Mlab\GiftCard\Crypto;
use Mlab\GiftCard\GiftCardException;
use Mlab\GiftCard\Schema;
use Mlab\GiftCard\Storage;
use PHPUnit\Framework\TestCase;

final class CoreIntegrationTest extends TestCase
{
    private Storage $db;
    private mlabgiftcard $module;

    protected function setUp(): void
    {
        if (!getenv('MLABGC_TEST_DSN')) {
            $this->markTestSkipped('Set MLABGC_TEST_DSN for isolated MySQL integration tests.');
        }
        Db::$adapter = new GiftCardTestDb(giftCardTestConnection());
        $this->db = new Storage(Db::$adapter, _DB_PREFIX_);
        foreach (array_keys(Schema::definitions()) as $table) { $this->db->exec('DROP TABLE IF EXISTS ' . $this->db->table($table)); }
        Schema::install($this->db);
        Configuration::$values = ['MLABGC_MASTER_KEY' => str_repeat('a', 64), 'PS_LANG_DEFAULT' => 1,
            'PS_OS_CANCELED' => 6, 'PS_OS_REFUND' => 7, 'PS_OS_ERROR' => 8];
        Currency::$records = [1 => ['id' => 1, 'iso_code' => 'EUR'], 2 => ['id' => 2, 'iso_code' => 'USD'], 3 => ['id' => 3, 'iso_code' => 'JPY', 'precision' => 0]];
        Customer::$records = [42 => ['id' => 42, 'id_shop' => 1], 43 => ['id' => 43, 'id_shop' => 2]];
        Cart::$records = [11 => ['id' => 11, 'id_shop' => 1, 'id_customer' => 42, 'id_currency' => 1]];
        Order::$records = [21 => ['id' => 21, 'id_shop' => 1, 'id_cart' => 11, 'id_customer' => 42,
            'id_currency' => 1, 'current_state' => 2, 'products' => [['id_order_detail' => 1, 'product_id' => 100,
                'product_attribute_id' => 2, 'product_reference' => 'SKU25GC29441', 'product_quantity' => 2]]]];
        Mail::$messages = []; Mail::$fail = false;
        PrestaShopLogger::$messages = [];
        $this->module = new mlabgiftcard();
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            foreach (array_keys(Schema::definitions()) as $table) { $this->db->exec('DROP TABLE ' . $this->db->table($table)); }
        }
    }

    public function testPaidOrderIssuesOneCardPerUnitAndReconciliationKeepsCodesAndValue(): void
    {
        $this->mapping();
        $this->assertSame(2, $this->module->processOrder(21));
        $before = $this->db->rows('SELECT id,code_ciphertext,initial_cents FROM ' . $this->db->table('cards') . ' ORDER BY id');
        Customer::$records[42]['email'] = 'changed@example.test';
        $this->db->exec('UPDATE ' . $this->db->table('products') . ' SET amount_cents=9000');
        $this->assertSame(2, $this->module->processOrder(21));
        $this->assertSame($before, $this->db->rows('SELECT id,code_ciphertext,initial_cents FROM ' . $this->db->table('cards') . ' ORDER BY id'));
        $this->assertCount(2, $this->db->rows('SELECT * FROM ' . $this->db->table('deliveries')));
    }

    public function testUnpaidOrderDoesNotIssueAndCancelledIssuanceIsBlocked(): void
    {
        $this->mapping();
        Order::$records[21]['current_state'] = 10;
        $this->assertSame(0, $this->module->processOrder(21));
        $this->assertSame([], $this->db->rows('SELECT * FROM ' . $this->db->table('cards')));
        Order::$records[21]['current_state'] = 2;
        $this->module->processOrder(21);
        $this->module->hookActionOrderStatusPostUpdate(['id_order' => 21, 'newOrderStatus' => new OrderState(6)]);
        foreach ($this->db->rows('SELECT * FROM ' . $this->db->table('cards')) as $card) {
            $this->assertSame('blocked', $card['status']);
        }
        $this->assertSame(0, $this->module->runtime()['delivery']->sendBatch());
        $this->assertSame([], Mail::$messages);
    }

    public function testMailFailureRetriesSameCodesWithoutIssuingCreditAgain(): void
    {
        if (!defined('_PS_ROOT_DIR_') && !class_exists('TCPDF')) {
            $this->markTestSkipped('Set MLABGC_TEST_PS_ROOT to a PrestaShop installation to test PDF email attachments.');
        }
        $this->mapping();
        $this->module->processOrder(21);
        Mail::$fail = true;
        $this->assertSame(0, $this->module->runtime()['delivery']->sendBatch());
        $failedCode = Mail::$messages[0][3]['{gift_card_code}'];
        $this->assertSame('application/pdf', Mail::$messages[0][8]['mime']);
        $this->assertStringStartsWith('%PDF-', Mail::$messages[0][8]['content']);
        $this->db->exec('UPDATE ' . $this->db->table('deliveries') . " SET next_attempt_at='2000-01-01 00:00:00'");
        Mail::$fail = false;
        $this->assertSame(2, $this->module->runtime()['delivery']->sendBatch());
        $this->assertContains($failedCode, array_map(fn($message) => $message[3]['{gift_card_code}'], array_slice(Mail::$messages, 2)));
        $this->assertSame(0, $this->module->runtime()['delivery']->sendBatch());
        $this->assertCount(2, $this->db->rows('SELECT * FROM ' . $this->db->table('cards')));
    }

    public function testMissingPdfKeepsOrderGiftCardsQueuedWithoutSendingAnIncompleteEmail(): void
    {
        $this->mapping();
        Order::$records[21]['products'][0]['product_reference'] = 'UNKNOWN';
        $this->module->processOrder(21);
        $this->assertSame(0, $this->module->runtime()['delivery']->sendBatch());
        $this->assertSame([], Mail::$messages);
        $jobs = $this->db->rows('SELECT status, attempts FROM ' . $this->db->table('deliveries'));
        $this->assertCount(2, $jobs);
        foreach ($jobs as $job) {
            $this->assertSame('pending', $job['status']);
            $this->assertSame(1, (int) $job['attempts']);
        }
    }

    public function testCoreGuardRejectsForeignCustomersCurrenciesCartsAndGiftCardPurchases(): void
    {
        $platform = new CorePlatform($this->db, 1);
        $this->rejected('customer_unavailable', fn() => $platform->customer(43));
        $this->rejected('invalid_currency', fn() => $platform->currency('JPY'));
        $this->rejected('cart_unavailable', fn() => $platform->cart(11, 43, 'EUR', 1000));
        $this->rejected('cart_mismatch', fn() => $platform->cart(11, 42, 'USD', 1000));
        $this->rejected('cart_mismatch', fn() => $platform->cart(11, 42, 'EUR', 10001));
        $platform->cart(11, 42, 'EUR', 10000);
        $this->mapping();
        Cart::$records[11]['products'] = [['id_product' => 100, 'id_product_attribute' => 2]];
        $this->rejected('gift_card_purchase', fn() => $platform->cart(11, 42, 'EUR', 1000));
    }

    public function testCaptureRequiresTheExactShopCartCurrencyAndAPaidOrder(): void
    {
        $platform = new CorePlatform($this->db, 1);
        $reservation = ['cart_id' => 11, 'customer_id' => 42, 'amount_cents' => 5000];
        $platform->order(21, $reservation, 'EUR');
        $this->rejected('order_mismatch', fn() => $platform->order(21, $reservation, 'USD'));
        $reservation['cart_id'] = 12;
        $this->rejected('order_mismatch', fn() => $platform->order(21, $reservation, 'EUR'));
        $reservation['cart_id'] = 11;
        Order::$records[21]['current_state'] = 10;
        $this->rejected('order_unpaid', fn() => $platform->order(21, $reservation, 'EUR'));
        Order::$records[21]['current_state'] = 2;
        $this->mapping();
        $this->rejected('gift_card_purchase', fn() => $platform->order(21, $reservation, 'EUR'));
    }

    public function testOnlyProtectedReservationCanBeCapturedForTheExpectedPendingState(): void
    {
        $platform = new CorePlatform($this->db, 1);
        $reservation = ['cart_id' => 11, 'customer_id' => 42, 'amount_cents' => 5000, 'payment_confirmed_at' => null];
        Order::$records[21]['current_state'] = 21;
        $this->rejected('order_unpaid', fn() => $platform->order(21, $reservation, 'EUR', 21));
        $reservation['payment_confirmed_at'] = '2026-01-01T00:00:00Z';
        $this->rejected('order_unpaid', fn() => $platform->order(21, $reservation, 'EUR', 22));
        $platform->order(21, $reservation, 'EUR', 21);
        Configuration::$values['PS_OS_OUTOFSTOCK_UNPAID'] = 9;
        Order::$records[21]['current_state'] = 9;
        $platform->order(21, $reservation, 'EUR', 21);
    }

    public function testUninstallAndReinstallKeepFinancialDataAndRevokeOldApiKeys(): void
    {
        $runtime = $this->module->runtime();
        $key = $runtime['api']->createKey('Checkout', ['read']);
        $card = $runtime['service']->execute('create', ['amount_cents' => 5000, 'currency' => 'EUR',
            'source_reference' => 'test:preserved', 'status' => 'active'], 'preserved-card', 'test');
        $this->assertTrue($this->module->uninstall());
        $this->assertTrue($this->module->install());
        $this->assertSame(5000, $runtime['service']->card($card['card']['id'])['balance_cents']);
        $row = $this->db->row('SELECT code_ciphertext FROM ' . $this->db->table('cards'));
        $this->assertSame($card['code'], (new Crypto(Configuration::$values['MLABGC_MASTER_KEY']))->decrypt($row['code_ciphertext']));
        $this->rejected('unauthorized', fn() => $runtime['api']->dispatch('cards', 'GET', [], $key['token'], ''));
        $this->assertContains('actionValidateOrderAfter', $this->module->hooks);
    }

    public function testMissingMasterKeyCannotSilentlyReplaceExistingDataEncryptionKey(): void
    {
        unset(Configuration::$values['MLABGC_MASTER_KEY']);
        $this->assertFalse($this->module->install());
        $this->assertArrayNotHasKey('MLABGC_MASTER_KEY', Configuration::$values);
    }

    public function testHookDoesNotSwallowAnEngineAbortOfTheOuterOrderTransaction(): void
    {
        $this->mapping();
        Db::$adapter->pdo->beginTransaction();
        Db::$adapter->failOn = 'INSERT INTO ' . $this->db->table('cards');
        Db::$adapter->abortOuterOnFailure = true;
        try {
            $this->module->hookActionOrderStatusPostUpdate(['id_order' => 21, 'newOrderStatus' => new OrderState(2)]);
            $this->fail('An aborted outer transaction must propagate to the order creator.');
        } catch (RuntimeException $error) {
            $this->assertSame('Injected database failure', $error->getMessage());
        }
        $this->assertFalse(Db::$adapter->pdo->inTransaction());
        $this->assertSame([], $this->db->rows('SELECT * FROM ' . $this->db->table('cards')));
    }

    private function mapping(): void
    {
        $this->db->insert('products', ['id_shop' => 1, 'product_id' => 100, 'attribute_id' => 0,
            'amount_cents' => 5000, 'currency' => 'EUR', 'validity_days' => 365]);
    }
    private function rejected(string $code, callable $operation): void
    {
        try { $operation(); $this->fail('Expected ' . $code); }
        catch (GiftCardException $error) { $this->assertSame($code, $error->error); }
    }
}
