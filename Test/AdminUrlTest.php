<?php

use Mlab\GiftCard\Admin;
use PHPUnit\Framework\TestCase;

if (!class_exists('Tools')) {
    class Tools
    {
        public static function getAdminTokenLite($controller) { return 'core-admin-token'; }
    }
}

final class AdminUrlTest extends TestCase
{
    public function testAdminFormsKeepTheCurrentPageAndItsHttpsOrigin(): void
    {
        $originalQuery = $_GET;
        try {
            $_GET = ['controller' => 'AdminModules', 'configure' => 'mlabgiftcard',
                'token' => 'core-admin-token', 'setShopContext' => 's-1', 'gc_page' => '4', 'gc_card' => 'old'];
            $admin = new Admin(new stdClass(), (object) ['shop' => (object) ['id' => 1]], []);
            $form = (new ReflectionMethod(Admin::class, 'form'))->invoke($admin, 'create', '', 'Create');
            $url = (new ReflectionProperty(Admin::class, 'url'))->getValue($admin);

            self::assertStringContainsString('method="post" action=""', $form);
            self::assertStringContainsString('name="gc_token" value="core-admin-token"', $form);
            self::assertSame('?controller=AdminModules&configure=mlabgiftcard&token=core-admin-token&setShopContext=s-1', $url);
        } finally {
            $_GET = $originalQuery;
        }
    }

    public function testMenuRedirectUsesTheCurrentOriginAndRetainsTheCoreToken(): void
    {
        self::assertSame('/admin/index.php?controller=AdminModules&token=abc&configure=mlabgiftcard',
            Admin::relativeAdminUrl('http://shop.example/admin/index.php?controller=AdminModules&token=abc&configure=mlabgiftcard'));
        self::assertSame('/admin/modules?token=abc', Admin::relativeAdminUrl('/admin/modules?token=abc'));
    }
}
