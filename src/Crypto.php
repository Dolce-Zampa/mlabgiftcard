<?php
namespace Mlab\GiftCard;

final class Crypto
{
    private string $key;

    public function __construct(string $masterKey)
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $masterKey)) {
            throw new \RuntimeException('Gift card encryption key is unavailable.');
        }
        $this->key = hex2bin($masterKey);
    }

    public function encrypt(string $plain): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag, 'mlabgiftcard:v1');
        if ($cipher === false) {
            throw new \RuntimeException('Cannot encrypt gift card data.');
        }
        return base64_encode($iv . $tag . $cipher);
    }

    public function decrypt(string $encrypted): string
    {
        $data = base64_decode($encrypted, true);
        if ($data === false || strlen($data) < 28) {
            throw new \RuntimeException('Invalid encrypted gift card data.');
        }
        $plain = openssl_decrypt(substr($data, 28), 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA,
            substr($data, 0, 12), substr($data, 12, 16), 'mlabgiftcard:v1');
        if ($plain === false) {
            throw new \RuntimeException('Cannot decrypt gift card data.');
        }
        return $plain;
    }

    public function hash(string $value): string
    {
        return hash_hmac('sha256', $value, $this->key);
    }

    public function codeHash(string $code): string
    {
        $code = strtoupper(preg_replace('/[\s-]+/', '', trim($code)));
        if (str_starts_with($code, 'GC')) {
            $code = substr($code, 2);
        }
        if (!preg_match('/^[A-F0-9]{32}$/D', $code)) {
            throw new GiftCardException('card_unavailable', 'Gift card unavailable.', 404);
        }
        return $this->hash('code:' . $code);
    }

    public function newCode(): string
    {
        return 'GC-' . implode('-', str_split(strtoupper(bin2hex(random_bytes(16))), 8));
    }
}
