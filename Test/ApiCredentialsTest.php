<?php

use Mlab\GiftCard\Api;
use PHPUnit\Framework\TestCase;

final class ApiCredentialsTest extends TestCase
{
    public function testPostBodyCredentialIsRemovedBeforeProcessing(): void
    {
        $token = 'gc_api_' . str_repeat('a', 64);
        $data = ['api_key' => $token, 'amount_cents' => 2000, 'currency' => 'EUR'];

        self::assertSame($token, Api::extractCredential([], 'POST', $data));
        self::assertSame(['amount_cents' => 2000, 'currency' => 'EUR'], $data);
    }

    public function testHeaderTakesPrecedenceAndBodyCredentialIsRemoved(): void
    {
        $headerToken = 'gc_api_' . str_repeat('a', 64);
        $data = ['api_key' => 'gc_api_' . str_repeat('b', 64)];

        self::assertSame($headerToken, Api::extractCredential(['HTTP_AUTHORIZATION' => 'Bearer ' . $headerToken], 'POST', $data));
        self::assertSame([], $data);
    }

    public function testBodyCredentialIsNotAcceptedForGetAndMalformedHeaderIsNotBypassed(): void
    {
        $token = 'gc_api_' . str_repeat('a', 64);
        $data = ['api_key' => $token];
        self::assertSame('', Api::extractCredential([], 'GET', $data));

        $data = ['api_key' => $token];
        self::assertSame('', Api::extractCredential(['HTTP_AUTHORIZATION' => 'invalid'], 'POST', $data));
        self::assertSame([], $data);
    }
}
