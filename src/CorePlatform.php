<?php
namespace Mlab\GiftCard;

final class CorePlatform implements Platform
{
    public function __construct(private readonly Storage $db, private readonly int $shopId) {}

    public function customer(?int $id): void
    {
        if ($id === null) {
            return;
        }
        $customer = new \Customer($id);
        if (!\Validate::isLoadedObject($customer) || (int) $customer->id_shop !== $this->shopId || $customer->deleted) {
            throw new GiftCardException('customer_unavailable', 'Customer unavailable in this shop.', 404);
        }
    }

    public function currency(string $iso): void
    {
        $id = (int) \Currency::getIdByIsoCode($iso, $this->shopId);
        $currency = new \Currency($id);
        if (!\Validate::isLoadedObject($currency) || !$currency->active || $currency->deleted || (int) $currency->precision !== 2) {
            throw new GiftCardException('invalid_currency', 'An active shop currency with two decimal places is required.');
        }
    }

    public function cart(int $id, ?int $customerId, string $currency, int $amount): void
    {
        $cart = new \Cart($id);
        if (!\Validate::isLoadedObject($cart) || (int) $cart->id_shop !== $this->shopId
            || ($customerId !== null && (int) $cart->id_customer !== $customerId)
            || ($customerId === null && (int) $cart->id_customer > 0)) {
            throw new GiftCardException('cart_unavailable', 'Cart unavailable for this customer.', 404);
        }
        if ((new \Currency((int) $cart->id_currency))->iso_code !== $currency
            || $amount > (int) round((float) $cart->getOrderTotal(true, \Cart::BOTH) * 100)) {
            throw new GiftCardException('cart_mismatch', 'Amount or currency does not match the cart.', 409);
        }
        foreach ($cart->getProducts() as $line) {
            if ($this->productMapping((int) $line['id_product'], (int) ($line['id_product_attribute'] ?? 0)) !== null) {
                throw new GiftCardException('gift_card_purchase', 'Gift cards cannot pay for carts containing gift card products.', 409);
            }
        }
    }

    public function order(int $id, array $reservation, string $currency): void
    {
        $order = new \Order($id);
        if (!\Validate::isLoadedObject($order) || (int) $order->id_shop !== $this->shopId
            || (int) $order->id_cart !== (int) $reservation['cart_id']
            || ($reservation['customer_id'] !== null && (int) $order->id_customer !== (int) $reservation['customer_id'])
            || (new \Currency((int) $order->id_currency))->iso_code !== $currency
            || (int) round((float) $order->total_paid_tax_incl * 100) < (int) $reservation['amount_cents']) {
            throw new GiftCardException('order_mismatch', 'Order does not match the reservation.', 409);
        }
        $state = new \OrderState((int) $order->current_state);
        if (!\Validate::isLoadedObject($state) || !$state->paid) {
            throw new GiftCardException('order_unpaid', 'The order must be confirmed as paid before capture.', 409);
        }
        foreach ($order->getProducts() as $line) {
            if ($this->productMapping((int) $line['product_id'], (int) $line['product_attribute_id']) !== null) {
                throw new GiftCardException('gift_card_purchase', 'Gift cards cannot pay for gift card products.', 409);
            }
        }
    }

    public function productMapping(int $product, int $attribute): ?array
    {
        return $this->db->row('SELECT * FROM ' . $this->db->table('products') . ' WHERE id_shop=' . $this->shopId
            . ' AND product_id=' . $product . ' AND attribute_id IN (0,' . $attribute . ') ORDER BY attribute_id DESC LIMIT 1');
    }
}
