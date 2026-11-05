<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

class mlabgiftcardaccountModuleFrontController extends ModuleFrontController
{
    public $ssl = true;
    public $auth = true;
    public $guestAllowed = false;

    public function initContent()
    {
        parent::initContent();
        header('Cache-Control: no-store, private');
        $runtime = $this->module->runtime();
        $customerId = (int) $this->context->customer->id;
        $runtime['platform']->customer($customerId);
        $message = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
                if (!hash_equals(Tools::getToken(false), (string) Tools::getValue('gc_token'))) {
                    throw new \Mlab\GiftCard\GiftCardException('invalid_token', 'Token non valido.', 403);
                }
                $runtime['api']->rateLimit('claim:' . $customerId, 10, 900);
                $code = \Mlab\GiftCard\Service::text(Tools::getValue('gc_code'), 'code', 64);
                $runtime['service']->execute('assign', ['code' => $code, 'customer_id' => $customerId],
                    (string) Tools::getValue('gc_request_key'), 'customer:' . $customerId);
                $message = 'Gift card associata al tuo account.';
            } catch (\Mlab\GiftCard\GiftCardException $error) {
                $this->errors[] = $error->getMessage();
            } catch (Throwable $error) {
                $this->errors[] = 'Operazione temporaneamente non disponibile.';
            }
        }
        $page = \Mlab\GiftCard\Service::integer(Tools::getValue('gc_page', 1), 'page', 1, 100000);
        $cards = $runtime['service']->cards($customerId, $page);
        foreach ($cards as &$card) {
            $row = $runtime['db']->row('SELECT code_ciphertext FROM ' . $runtime['db']->table('cards')
                . ' WHERE id_shop=' . (int) $this->context->shop->id . ' AND customer_id=' . $customerId
                . ' AND id=' . $runtime['db']->quote($card['id']));
            $card['code'] = $row ? $runtime['crypto']->decrypt($row['code_ciphertext']) : '';
            foreach (['balance_cents', 'reserved_cents', 'available_cents'] as $field) {
                $card[$field . '_formatted'] = number_format($card[$field] / 100, 2, ',', '.');
            }
        }
        unset($card);
        $this->context->smarty->assign(['mlabgc_cards' => $cards, 'mlabgc_message' => $message,
            'mlabgc_previous_url' => $page > 1 ? $this->context->link->getModuleLink('mlabgiftcard', 'account', ['gc_page' => $page - 1], true) : null,
            'mlabgc_next_url' => count($cards) === 50 ? $this->context->link->getModuleLink('mlabgiftcard', 'account', ['gc_page' => $page + 1], true) : null,
            'mlabgc_token' => Tools::getToken(false), 'mlabgc_request_key' => 'claim:' . bin2hex(random_bytes(16)),
            'mlabgc_account_url' => $this->context->link->getModuleLink('mlabgiftcard', 'account', [], true)]);
        $this->setTemplate('module:mlabgiftcard/views/templates/front/account.tpl');
    }
}
