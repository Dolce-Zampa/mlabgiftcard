<?php

use Mlab\GiftCard\AdminCsrf;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Csrf\CsrfToken;

if (!class_exists(CsrfToken::class)) {
    require_once __DIR__ . '/CsrfTokenDouble.php';
}
if (!class_exists('Tools')) {
    class Tools { public static function getAdminTokenLite($controller) { return 'core-admin-token'; } }
}

/** Simulates Symfony's randomized tokens for a single session. */
final class GiftCardCsrfManagerDouble
{
    public function __construct(private string $sessionSecret) {}
    public function getToken(string $id): CsrfToken
    {
        $nonce = bin2hex(random_bytes(16));
        return new CsrfToken($id, $nonce . '.' . hash_hmac('sha256', $id . ':' . $nonce, $this->sessionSecret));
    }
    public function isTokenValid(CsrfToken $token): bool
    {
        $parts = explode('.', $token->getValue(), 2);
        return count($parts) === 2 && hash_equals(hash_hmac('sha256', $token->getId() . ':' . $parts[0], $this->sessionSecret), $parts[1]);
    }
}

final class AdminCsrfTest extends TestCase
{
    public function testRandomizedTokenRemainsValidForTheSameEmployeeShopAndSession(): void
    {
        $manager = new GiftCardCsrfManagerDouble('session-one');
        $firstRequest = new AdminCsrf($manager, 7, 1);
        $secondRequest = new AdminCsrf($manager, 7, 1);
        $submitted = $firstRequest->token();

        self::assertNotSame($submitted, $secondRequest->token());
        self::assertTrue($secondRequest->isValid($submitted));
        self::assertFalse((new AdminCsrf($manager, 8, 1))->isValid($submitted));
        self::assertFalse((new AdminCsrf($manager, 7, 2))->isValid($submitted));
        self::assertFalse((new AdminCsrf(new GiftCardCsrfManagerDouble('session-two'), 7, 1))->isValid($submitted));
        self::assertFalse($secondRequest->isValid(''));
        self::assertFalse($secondRequest->isValid(['unexpected']));
    }

    public function testLegacyFallbackStillRequiresTheCorrectTokenAndEmployee(): void
    {
        $csrf = new AdminCsrf(null, 7, 1);
        self::assertTrue($csrf->isValid('core-admin-token'));
        self::assertFalse($csrf->isValid('wrong-token'));
        self::assertFalse((new AdminCsrf(null, 0, 1))->isValid('core-admin-token'));
    }
}
