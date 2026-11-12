<?php
if (!defined('_PS_VERSION_')) {
    exit;
}
require_once __DIR__ . '/bootstrap.php';

use Mlab\GiftCard\Admin;
use Mlab\GiftCard\Api;
use Mlab\GiftCard\CorePlatform;
use Mlab\GiftCard\Crypto;
use Mlab\GiftCard\Delivery;
use Mlab\GiftCard\GiftCardException;
use Mlab\GiftCard\Schema;
use Mlab\GiftCard\Service;
use Mlab\GiftCard\Storage;

class mlabgiftcard extends Module
{
    public const MASTER_KEY = 'MLABGC_MASTER_KEY';

    public function __construct()
    {
        $this->name = 'mlabgiftcard';
        $this->tab = 'payments_gateways';
        $this->version = '1.0.3';
        $this->author = 'MlabFactory';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->controllers = ['api', 'account'];
        $this->tabs = [[
            'class_name' => 'AdminMlabGiftCard',
            'name' => 'GiftCard',
            'parent_class_name' => 'SELL',
            'icon' => 'card_giftcard',
            'visible' => true,
        ]];
        $this->ps_versions_compliancy = ['min' => '9.0.0', 'max' => '9.2.99'];
        parent::__construct();
        $this->displayName = $this->l('MLab Gift Card');
        $this->description = $this->l('Gift card balances, customer assignment, immutable movements and secure integration APIs.');
        $this->confirmUninstall = $this->l('Gift card balances, history and encryption keys will be retained for reinstallation.');
    }

    public function install()
    {
        if (version_compare(PHP_VERSION, '8.1.0', '<') || !extension_loaded('openssl') || !extension_loaded('mbstring')) {
            $this->_errors[] = 'PHP 8.1+, OpenSSL and mbstring are required.';
            return false;
        }
        if (!parent::install()) {
            return false;
        }
        try {
            $master = Configuration::getGlobalValue(self::MASTER_KEY);
            if (!$master) {
                $storage = new Storage(Db::getInstance(), _DB_PREFIX_);
                if ($storage->row('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='
                    . $storage->quote(_DB_PREFIX_ . 'mlabgc_cards')) !== null) {
                    throw new RuntimeException('Restore the original MLABGC_MASTER_KEY before reinstalling existing gift card data.');
                }
                // A reinstall must never rotate the key and make stored codes unreadable.
                if (!Configuration::updateGlobalValue(self::MASTER_KEY, bin2hex(random_bytes(32)))) {
                    throw new RuntimeException('Cannot persist the encryption key.');
                }
            }
            Schema::install(new Storage(Db::getInstance(), _DB_PREFIX_));
            if (!$this->installAdminTab()
                || !$this->registerHook(['actionValidateOrderAfter', 'actionOrderStatusPostUpdate', 'displayCustomerAccount'])) {
                throw new RuntimeException('Cannot register the gift card menu or hooks.');
            }
            return true;
        } catch (Throwable $error) {
            parent::uninstall();
            $this->_errors[] = 'Gift card installation failed. Check the database permissions and server logs.';
            PrestaShopLogger::addLog('mlabgiftcard: installation failed (' . get_class($error) . ').', 3);
            return false;
        }
    }

    /** Also called by the upgrade script: repair an existing hidden tab without replacing its permissions. */
    public function installAdminTab(): bool
    {
        $parentId = (int) Tab::getIdFromClassName('SELL');
        if (!$parentId) {
            return false;
        }
        $id = (int) Tab::getIdFromClassName('AdminMlabGiftCard');
        $tab = new Tab($id ?: null);
        if ($id && $tab->module !== $this->name) {
            return false;
        }
        $tab->class_name = 'AdminMlabGiftCard';
        $tab->module = $this->name;
        $tab->id_parent = $parentId;
        $tab->active = true;
        $tab->enabled = (bool) $this->active;
        $tab->icon = 'card_giftcard';
        foreach (Language::getLanguages(false) as $language) {
            $tab->name[(int) $language['id_lang']] = 'GiftCard';
        }
        return (bool) $tab->save();
    }

    public function uninstall()
    {
        // Money and audit history must survive an accidental uninstall/reset.
        try {
            $db = new Storage(Db::getInstance(), _DB_PREFIX_);
            $db->exec('UPDATE ' . $db->table('api_keys') . ' SET active=0');
        } catch (Throwable $error) {
            $this->_errors[] = 'Unable to revoke API keys; gift card data was retained.';
            return false;
        }
        return parent::uninstall();
    }

    public function runtime(?int $shopId = null): array
    {
        $shopId = $shopId ?? (int) $this->context->shop->id;
        $db = new Storage(Db::getInstance(), _DB_PREFIX_);
        $crypto = new Crypto((string) Configuration::getGlobalValue(self::MASTER_KEY));
        $service = new Service($db, $crypto, $shopId);
        $platform = new CorePlatform($db, $shopId);
        return ['db' => $db, 'crypto' => $crypto, 'service' => $service, 'platform' => $platform,
            'api' => new Api($db, $crypto, $shopId, $service, $platform), 'delivery' => new Delivery($db, $crypto, $shopId)];
    }

    public function getContent()
    {
        header('Cache-Control: no-store, private');
        if (!isset($this->context->employee) || !$this->context->employee->id
            || !$this->getPermission('configure', $this->context->employee)) {
            return $this->displayError('Non autorizzato.');
        }
        if (Shop::getContext() !== Shop::CONTEXT_SHOP) {
            return $this->displayError('Seleziona un singolo negozio prima di gestire le gift card.');
        }
        try {
            return (new Admin($this, $this->context, $this->runtime()))->render();
        } catch (Throwable $error) {
            PrestaShopLogger::addLog('mlabgiftcard: admin request failed (' . get_class($error) . ').', 3);
            return $this->displayError('Operazione non disponibile. Consulta i log del negozio.');
        }
    }

    public function hookActionValidateOrderAfter($params)
    {
        $orders = $params['orders'] ?? (isset($params['order']) ? [$params['order']] : []);
        foreach ($orders as $order) {
            if ($order instanceof Order) {
                $this->processOrderSafely((int) $order->id, $params['orderStatus'] ?? null);
            }
        }
    }

    public function hookActionOrderStatusPostUpdate($params)
    {
        $this->processOrderSafely((int) ($params['id_order'] ?? 0), $params['newOrderStatus'] ?? null);
    }

    private function processOrderSafely(int $orderId, $state): void
    {
        $storage = new Storage(Db::getInstance(), _DB_PREFIX_);
        $hadOuterTransaction = $storage->inTransaction();
        try {
            $this->processOrder($orderId, $state);
        } catch (Throwable $error) {
            if ($hadOuterTransaction && !$storage->inTransaction()) {
                // InnoDB deadlocks can abort the entire transaction, including the order.
                // The caller must retry instead of treating that order as committed.
                throw $error;
            }
            // Let PrestaShop finish saving the paid order. Admin can replay issuance.
            PrestaShopLogger::addLog('mlabgiftcard: order ' . $orderId . ' requires gift card reconciliation ('
                . get_class($error) . ').', 3);
        }
    }

    /** Safe to replay from back office if a hook failed after payment. */
    public function processOrder(int $orderId, $state = null): int
    {
        $order = new Order($orderId);
        if (!Validate::isLoadedObject($order)) {
            throw new GiftCardException('order_unavailable', 'Ordine non trovato.', 404);
        }
        $state = $state ?? new OrderState((int) $order->current_state);
        if (!$state instanceof OrderState || !Validate::isLoadedObject($state)) {
            throw new GiftCardException('invalid_state', 'Stato ordine non valido.');
        }
        $runtime = $this->runtime((int) $order->id_shop);
        $service = $runtime['service'];
        $db = $runtime['db'];
        $count = 0;
        if ($state instanceof OrderState && $state->paid) {
            $currency = (string) (new Currency((int) $order->id_currency))->iso_code;
            $runtime['platform']->currency($currency);
            $buyer = new Customer((int) $order->id_customer);
            foreach ($order->getProducts() as $line) {
                $mapping = $runtime['platform']->productMapping((int) $line['product_id'], (int) $line['product_attribute_id']);
                if ($mapping === null) {
                    continue;
                }
                if ($mapping['currency'] !== $currency) {
                    throw new GiftCardException('currency_mismatch', 'Gift card product currency does not match its order.');
                }
                $quantity = Service::integer((int) $line['product_quantity'], 'quantity', 1, 1000);
                for ($unit = 1; $unit <= $quantity; ++$unit) {
                    $source = 'order:' . $orderId . ':detail:' . (int) $line['id_order_detail'] . ':unit:' . $unit;
                    if ($db->row('SELECT id FROM ' . $db->table('cards') . ' WHERE id_shop=' . (int) $order->id_shop
                        . ' AND source_reference=' . $db->quote($source)) !== null) {
                        ++$count;
                        continue;
                    }
                    $days = (int) $mapping['validity_days'];
                    $service->execute('create', [
                        'amount_cents' => (int) $mapping['amount_cents'], 'currency' => $currency, 'status' => 'active',
                        'source_reference' => $source, 'recipient_email' => (string) $buyer->email,
                        'recipient_name' => trim($buyer->firstname . ' ' . $buyer->lastname),
                        'validity_days' => $days,
                    ], 'issue:' . hash('sha256', $source), 'order:' . $orderId);
                    ++$count;
                }
            }
        } elseif (in_array((int) $state->id, [(int) Configuration::get('PS_OS_CANCELED'),
            (int) Configuration::get('PS_OS_REFUND'), (int) Configuration::get('PS_OS_ERROR')], true)) {
            $cards = $db->rows('SELECT id FROM ' . $db->table('cards') . ' WHERE id_shop=' . (int) $order->id_shop
                . " AND status<>'blocked' AND source_reference LIKE " . $db->quote('order:' . $orderId . ':detail:%'));
            foreach ($cards as $card) {
                $service->execute('status', ['card_id' => $card['id'], 'status' => 'blocked',
                    'reason' => 'Issuance order cancelled or refunded; review any redeemed value'],
                    'order-block:' . bin2hex(random_bytes(16)), 'order:' . $orderId);
            }
        }
        return $count;
    }

    public function hookDisplayCustomerAccount($params)
    {
        $this->context->smarty->assign('mlabgc_account_url', $this->context->link->getModuleLink($this->name, 'account', [], true));
        return $this->display(__FILE__, 'views/templates/hook/account-link.tpl');
    }
}
