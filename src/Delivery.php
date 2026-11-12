<?php
namespace Mlab\GiftCard;

/** At-least-once email delivery: a retry may resend the same code, never issue another card. */
final class Delivery
{
    public function __construct(private readonly Storage $db, private readonly Crypto $crypto, private readonly int $shopId) {}

    public function sendBatch(int $limit = 20): int
    {
        $sent = 0;
        for ($i = 0; $i < $limit; ++$i) {
            $job = $this->claim();
            if ($job === null) {
                break;
            }
            $card = $this->db->row('SELECT * FROM ' . $this->db->table('cards') . ' WHERE id_shop=' . $this->shopId
                . ' AND id=' . $this->db->quote($job['card_id']));
            $ok = false;
            $eligible = $card !== null && $card['status'] === 'active' && $card['recipient_email'] !== null
                && ($card['expires_at'] === null || $card['expires_at'] > gmdate('Y-m-d H:i:s'));
            try {
                if ($eligible) {
                    $language = (int) \Configuration::get('PS_LANG_DEFAULT', null, null, $this->shopId);
                    $code = $this->crypto->decrypt($card['code_ciphertext']);
                    $attachment = (new GiftCardPdf(dirname(__DIR__), $this->shopId))->attachment($card, $code);
                    $vars = [
                        '{recipient_name}' => htmlspecialchars($card['recipient_name'] ?? '', ENT_QUOTES, 'UTF-8'),
                        '{gift_card_code}' => $code,
                        '{gift_card_amount}' => number_format((int) $card['initial_cents'] / 100, 2, ',', '.') . ' ' . $card['currency'],
                        '{gift_card_expiry}' => $card['expires_at'] === null ? 'Senza scadenza / No expiry' : $card['expires_at'] . ' UTC',
                    ];
                    $ok = (bool) \Mail::Send($language, 'giftcard', 'Gift Card', $vars,
                        $card['recipient_email'], $card['recipient_name'], null, null, $attachment, null,
                        dirname(__DIR__) . '/mails/', false, $this->shopId);
                }
            } catch (\Throwable $error) {
                // Never put the bearer code or the mail transport exception in a log.
                \PrestaShopLogger::addLog('mlabgiftcard: gift card delivery failed; retry queued.', 3);
            }
            $status = !$eligible ? 'skipped' : ($ok ? 'sent' : 'pending');
            $this->db->update('deliveries', ['status' => $status, 'lease_token' => null, 'lease_until' => null,
                'sent_at' => $ok ? gmdate('Y-m-d H:i:s') : null,
                'next_attempt_at' => gmdate('Y-m-d H:i:s', time() + min(86400, 60 * (2 ** min(10, (int) $job['attempts']))))],
                'id_shop=' . $this->shopId . ' AND card_id=' . $this->db->quote($job['card_id'])
                . ' AND lease_token=' . $this->db->quote($job['lease_token']));
            $sent += (int) $ok;
        }
        return $sent;
    }

    private function claim(): ?array
    {
        return $this->db->transaction(function () {
            $now = $this->db->quote(gmdate('Y-m-d H:i:s'));
            $job = $this->db->row('SELECT * FROM ' . $this->db->table('deliveries') . ' WHERE id_shop=' . $this->shopId
                . " AND ((status='pending' AND next_attempt_at<=" . $now . ") OR (status='sending' AND lease_until<="
                . $now . ')) ORDER BY next_attempt_at LIMIT 1 FOR UPDATE');
            if ($job === null) {
                return null;
            }
            $job['lease_token'] = bin2hex(random_bytes(16));
            $job['attempts'] = (int) $job['attempts'] + 1;
            $this->db->update('deliveries', ['status' => 'sending', 'lease_token' => $job['lease_token'],
                'lease_until' => gmdate('Y-m-d H:i:s', time() + 600), 'attempts' => $job['attempts']],
                'id_shop=' . $this->shopId . ' AND card_id=' . $this->db->quote($job['card_id']));
            return $job;
        });
    }
}
