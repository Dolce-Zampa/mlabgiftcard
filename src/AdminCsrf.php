<?php
namespace Mlab\GiftCard;

use PrestaShop\PrestaShop\Adapter\SymfonyContainer;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/** Validates the form token against the employee's current back-office session. */
final class AdminCsrf
{
    public function __construct(private readonly ?object $manager, private readonly int $employeeId, private readonly int $shopId) {}

    public static function forContext(object $context): self
    {
        $container = class_exists(SymfonyContainer::class) ? SymfonyContainer::getInstance() : null;
        $manager = $container === null ? null : $container->get(CsrfTokenManagerInterface::class);
        return new self($manager, (int) ($context->employee->id ?? 0), (int) ($context->shop->id ?? 0));
    }

    public function token(): string
    {
        if ($this->manager === null) {
            return (string) \Tools::getAdminTokenLite('AdminModules');
        }
        return (string) $this->manager->getToken($this->tokenId())->getValue();
    }

    public function isValid(mixed $value): bool
    {
        if (!is_string($value) || $value === '' || $this->employeeId < 1 || $this->shopId < 1) {
            return false;
        }
        if ($this->manager === null) {
            $expected = \Tools::getAdminTokenLite('AdminModules');
            return is_string($expected) && $expected !== '' && hash_equals($expected, $value);
        }
        return $this->manager->isTokenValid(new CsrfToken($this->tokenId(), $value));
    }

    private function tokenId(): string
    {
        return 'mlabgiftcard_admin:' . $this->employeeId . ':' . $this->shopId;
    }
}
