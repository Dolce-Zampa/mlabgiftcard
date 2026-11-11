<?php
namespace Mlab\GiftCard;

final class Schema
{
    public static function install(Storage $db): void
    {
        foreach (self::definitions() as $table => $definition) {
            $db->exec('CREATE TABLE IF NOT EXISTS ' . $db->table($table) . ' (' . $definition
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin');
        }
    }

    public static function definitions(): array
    {
        return [
            'cards' => "
                id CHAR(32) NOT NULL PRIMARY KEY, id_shop INT UNSIGNED NOT NULL,
                code_hash CHAR(64) NOT NULL, code_ciphertext TEXT NOT NULL, code_suffix CHAR(4) NOT NULL,
                initial_cents BIGINT NOT NULL, balance_cents BIGINT NOT NULL DEFAULT 0, reserved_cents BIGINT NOT NULL DEFAULT 0,
                currency CHAR(3) NOT NULL, customer_id INT UNSIGNED NULL, status VARCHAR(12) NOT NULL,
                source_reference VARCHAR(160) NOT NULL, recipient_email VARCHAR(254) NULL, recipient_name VARCHAR(128) NULL,
                expires_at DATETIME NULL, activated_at DATETIME NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
                UNIQUE KEY code_per_shop (id_shop, code_hash), UNIQUE KEY issuance_source (id_shop, source_reference),
                KEY customer_cards (id_shop, customer_id, created_at)",
            'movements' => "
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, id_shop INT UNSIGNED NOT NULL,
                card_id CHAR(32) NOT NULL, reservation_id CHAR(32) NULL, type VARCHAR(24) NOT NULL,
                delta_cents BIGINT NOT NULL, delta_reserved_cents BIGINT NOT NULL,
                balance_after_cents BIGINT NOT NULL, reserved_after_cents BIGINT NOT NULL,
                actor VARCHAR(96) NOT NULL, reason VARCHAR(255) NOT NULL, reference VARCHAR(160) NULL,
                created_at DATETIME NOT NULL, KEY card_history (id_shop, card_id, id),
                UNIQUE KEY movement_source (id_shop,reservation_id,type,reference)",
            'reservations' => "
                id CHAR(32) NOT NULL PRIMARY KEY, id_shop INT UNSIGNED NOT NULL, card_id CHAR(32) NOT NULL,
                cart_id INT UNSIGNED NOT NULL, active_cart_id INT UNSIGNED NULL, customer_id INT UNSIGNED NULL, amount_cents BIGINT NOT NULL, requested_cents BIGINT NULL,
                refunded_cents BIGINT NOT NULL DEFAULT 0, status VARCHAR(12) NOT NULL, payment_confirmed_at DATETIME NULL,
                order_id INT UNSIGNED NULL, expires_at DATETIME NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
                KEY card_holds (id_shop, card_id, status), KEY due_holds (id_shop, status, expires_at),
                UNIQUE KEY order_redemptions (id_shop, order_id), UNIQUE KEY active_cart (id_shop,active_cart_id)",
            'requests' => "
                id_shop INT UNSIGNED NOT NULL, request_key VARCHAR(128) NOT NULL, payload_hash CHAR(64) NOT NULL,
                response_ciphertext MEDIUMTEXT NULL, created_at DATETIME NOT NULL,
                PRIMARY KEY (id_shop, request_key)",
            'api_keys' => "
                id CHAR(32) NOT NULL PRIMARY KEY, id_shop INT UNSIGNED NOT NULL,
                name VARCHAR(128) NOT NULL, key_hash CHAR(64) NOT NULL, scopes VARCHAR(128) NOT NULL,
                active TINYINT NOT NULL DEFAULT 1, created_at DATETIME NOT NULL, last_used_at DATETIME NULL,
                UNIQUE KEY api_token (key_hash), KEY shop_keys (id_shop, active)",
            'products' => "
                id_shop INT UNSIGNED NOT NULL, product_id INT UNSIGNED NOT NULL, attribute_id INT UNSIGNED NOT NULL DEFAULT 0,
                amount_cents BIGINT NOT NULL, currency CHAR(3) NOT NULL, validity_days INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (id_shop, product_id, attribute_id)",
            'deliveries' => "
                card_id CHAR(32) NOT NULL PRIMARY KEY, id_shop INT UNSIGNED NOT NULL,
                status VARCHAR(12) NOT NULL, attempts INT UNSIGNED NOT NULL DEFAULT 0,
                lease_token CHAR(32) NULL, lease_until DATETIME NULL, next_attempt_at DATETIME NOT NULL,
                sent_at DATETIME NULL, KEY due_deliveries (id_shop, status, next_attempt_at)",
            'rate_limits' => "
                bucket CHAR(64) NOT NULL PRIMARY KEY, hits INT UNSIGNED NOT NULL DEFAULT 1,
                expires_at DATETIME NOT NULL, KEY expired_buckets (expires_at)",
        ];
    }
}
