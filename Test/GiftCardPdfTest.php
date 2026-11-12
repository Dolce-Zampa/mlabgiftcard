<?php
require_once __DIR__ . '/CoreDoubles.php';

use Mlab\GiftCard\GiftCardPdf;
use PHPUnit\Framework\TestCase;

final class GiftCardPdfTest extends TestCase
{
    private GiftCardPdf $pdf;
    private array $card;

    protected function setUp(): void
    {
        $this->pdf = new GiftCardPdf(dirname(__DIR__), 1);
        $this->card = ['source_reference' => 'order:21:detail:1:unit:1', 'initial_cents' => 2000, 'currency' => 'EUR'];
        Order::$records = [21 => ['id' => 21, 'id_shop' => 1, 'products' => [
            ['id_order_detail' => 1, 'product_attribute_id' => 2943, 'product_reference' => 'SKU25GC29431'],
        ]]];
    }

    public function testManualIssuanceStillUsesNormalEmail(): void
    {
        $this->assertNull($this->pdf->attachment(['source_reference' => 'manual:abc'], 'GC-8DUE-28DF'));
    }

    public function testOrderPdfContainsCodeAndUsesPurchasedVariant(): void
    {
        if (!defined('_PS_ROOT_DIR_') && !class_exists('TCPDF')) {
            $this->markTestSkipped('Set MLABGC_TEST_PS_ROOT to a PrestaShop installation for PDF generation.');
        }
        $attachment = $this->pdf->attachment($this->card, 'GC-8DUE-28DF');
        $this->assertSame('application/pdf', $attachment['mime']);
        $this->assertStringStartsWith('%PDF-', $attachment['content']);
        $this->assertSame('gift-card-dolce-zampa.pdf', $attachment['name']);
    }

    public function testRejectsDifferentGiftCardAmount(): void
    {
        $this->card['initial_cents'] = 5000;
        $this->expectException(RuntimeException::class);
        $this->pdf->attachment($this->card, 'GC-8DUE-28DF');
    }

    public function testRejectsSkuPathsAndMissingCombination(): void
    {
        Order::$records[21]['products'][0]['product_reference'] = '../outside';
        $this->expectException(RuntimeException::class);
        $this->pdf->attachment($this->card, 'GC-8DUE-28DF');
    }

    public function testRejectsAnotherShopsOrder(): void
    {
        Order::$records[21]['id_shop'] = 2;
        $this->expectException(RuntimeException::class);
        $this->pdf->attachment($this->card, 'GC-8DUE-28DF');
    }

    public function testRejectsMissingOrUnknownSku(): void
    {
        Order::$records[21]['products'][0]['product_reference'] = 'UNKNOWN';
        $this->expectException(RuntimeException::class);
        $this->pdf->attachment($this->card, 'GC-8DUE-28DF');
    }
}
