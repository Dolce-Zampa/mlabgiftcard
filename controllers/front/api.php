<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

class mlabgiftcardapiModuleFrontController extends ModuleFrontController
{
    public $ssl = true;
    public $ajax = true;
    public $display_header = false;
    public $display_footer = false;

    public function initContent() {}

    public function postProcess()
    {
        $status = 200;
        try {
            if (!$this->module->active) {
                throw new \Mlab\GiftCard\GiftCardException('module_disabled', 'Gift card API unavailable.', 503);
            }
            if (!Tools::usingSecureMode()) {
                throw new \Mlab\GiftCard\GiftCardException('https_required', 'HTTPS is required.', 426);
            }
            $runtime = $this->module->runtime();
            $runtime['api']->rateLimit('api:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
            $action = Tools::getValue('action', '');
            if (!is_string($action)) {
                throw new \Mlab\GiftCard\GiftCardException('invalid_action', 'Invalid API action.', 400);
            }
            $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
            if ($method === 'GET') {
                $data = array_intersect_key($_GET, array_flip(['card_id', 'reservation_id', 'customer_id', 'page']));
            } else {
                if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 65536) {
                    throw new \Mlab\GiftCard\GiftCardException('body_too_large', 'Request body too large.', 413);
                }
                if (!preg_match('/^application\/json(?:\s*;|$)/i', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))) {
                    throw new \Mlab\GiftCard\GiftCardException('invalid_content_type', 'Use application/json.', 415);
                }
                $raw = file_get_contents('php://input', false, null, 0, 65537);
                $data = strlen((string) $raw) <= 65536 ? json_decode((string) $raw, true) : null;
                if (!is_array($data) || !str_starts_with(ltrim((string) $raw), '{')) {
                    throw new \Mlab\GiftCard\GiftCardException('invalid_json', 'Request body must be a JSON object.', 400);
                }
            }
            $token = \Mlab\GiftCard\Api::extractCredential($_SERVER, $method, $data);
            $result = $runtime['api']->dispatch($action, $method, $data, $token, (string) ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''));
            if ($action === 'maintenance') {
                $result['emails_sent'] = $runtime['delivery']->sendBatch();
                $runtime['db']->exec('DELETE FROM ' . $runtime['db']->table('rate_limits') . ' WHERE expires_at<'
                    . $runtime['db']->quote(gmdate('Y-m-d H:i:s', time() - 3600)));
            }
            $body = ['success' => true, 'data' => $result];
        } catch (\Mlab\GiftCard\GiftCardException $error) {
            $status = $error->status;
            $body = ['success' => false, 'error' => ['code' => $error->error, 'message' => $error->getMessage()]];
        } catch (Throwable $error) {
            $status = 503;
            $body = ['success' => false, 'error' => ['code' => 'temporarily_unavailable', 'message' => 'Retry with the same idempotency key.']];
            PrestaShopLogger::addLog('mlabgiftcard: API operation failed (' . get_class($error) . ').', 3);
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');
        header('X-Content-Type-Options: nosniff');
        if ($status === 429) {
            header('Retry-After: 60');
        }
        http_response_code($status);
        exit(json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
