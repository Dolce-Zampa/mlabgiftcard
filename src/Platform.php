<?php
namespace Mlab\GiftCard;

interface Platform
{
    public function customer(?int $id): void;
    public function currency(string $iso): void;
    public function cart(int $id, ?int $customerId, string $currency, int $amount): void;
    public function order(int $id, array $reservation, string $currency, ?int $pendingStateId = null): void;
}
