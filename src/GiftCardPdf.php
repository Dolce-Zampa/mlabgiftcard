<?php
namespace Mlab\GiftCard;

/** Creates a printable attachment in memory from the purchased combination's template. */
final class GiftCardPdf
{
    public function __construct(private readonly string $moduleDir, private readonly int $shopId) {}

    /** Non-order cards have no purchased combination and retain the normal email delivery. */
    public function attachment(array $card, string $code): ?array
    {
        $source = (string) ($card['source_reference'] ?? '');
        if (!str_starts_with($source, 'order:')) {
            return null;
        }
        if (!preg_match('/^order:([1-9][0-9]*):detail:([1-9][0-9]*):unit:([1-9][0-9]*)$/D', $source, $matches)) {
            throw new \RuntimeException('Gift card order reference is invalid.');
        }
        if (!preg_match('/^GC-[A-Z0-9]{4}-[A-Z0-9]{4}$/D', $code)) {
            throw new \RuntimeException('Gift card code has an unsupported format.');
        }
        $order = new \Order((int) $matches[1]);
        if (!\Validate::isLoadedObject($order) || (int) $order->id_shop !== $this->shopId) {
            throw new \RuntimeException('Gift card order is unavailable.');
        }
        $sku = null;
        foreach ($order->getProducts() as $line) {
            if ((int) ($line['id_order_detail'] ?? 0) === (int) $matches[2]) {
                if ((int) ($line['product_attribute_id'] ?? 0) <= 0) {
                    throw new \RuntimeException('Gift card combination is unavailable.');
                }
                $sku = (string) ($line['product_reference'] ?? '');
                break;
            }
        }
        if (!is_string($sku) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $sku)) {
            throw new \RuntimeException('Gift card combination reference is unavailable.');
        }
        $catalog = json_decode((string) @file_get_contents($this->moduleDir . '/pdf-templates/catalog.json'), true);
        $entry = is_array($catalog) ? ($catalog[$sku] ?? null) : null;
        if (!is_array($entry) || ($entry['amount_cents'] ?? null) !== (int) ($card['initial_cents'] ?? 0)
            || ($entry['currency'] ?? null) !== ($card['currency'] ?? null)) {
            throw new \RuntimeException('Gift card PDF template does not match its balance.');
        }
        $template = $this->moduleDir . '/pdf-templates/' . $sku . '.pdf';
        if (!is_file($template) || !is_readable($template)) {
            throw new \RuntimeException('Gift card PDF template is unavailable.');
        }
        if (!class_exists('TCPDF')) {
            if (!defined('_PS_ROOT_DIR_')) {
                throw new \RuntimeException('Gift card PDF renderer is unavailable.');
            }
            $tcpdf = _PS_ROOT_DIR_ . '/vendor/tecnickcom/tcpdf/tcpdf.php';
            if (!is_file($tcpdf)) {
                throw new \RuntimeException('Gift card PDF renderer is unavailable.');
            }
            require_once $tcpdf;
        }
        require_once $this->moduleDir . '/lib/fpdi/src/autoload.php';
        $pdf = new \setasign\Fpdi\Tcpdf\Fpdi('P', 'pt', 'A4', true, 'UTF-8', false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetAutoPageBreak(false);
        if ($pdf->setSourceFile($template) !== 1) {
            throw new \RuntimeException('Gift card PDF template must contain one page.');
        }
        $page = $pdf->importPage(1);
        $size = $pdf->getTemplateSize($page);
        // The approved templates share this A4 layout; reject different pages rather than misplace a code.
        if (abs($size['width'] - 595.28) > 1 || abs($size['height'] - 841.89) > 1) {
            throw new \RuntimeException('Gift card PDF template has an unexpected size.');
        }
        $pdf->AddPage('P', [$size['width'], $size['height']]);
        $pdf->useTemplate($page);
        $pdf->SetFont('helvetica', '', 16);
        $pdf->SetTextColor(38, 75, 58);
        $pdf->SetXY(53, 520);
        $pdf->Cell(285, 25, $code, 0, 0, 'L');
        // FPDI imports the artwork, not its form widgets. Recreate the two gift-message fields.
        $pdf->SetFont('helvetica', '', 11);
        $pdf->TextField('destinatario', 479, 22, ['borderWidth' => 0], [], 73, 610.62);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->TextField('dedica', 515, 29, ['borderWidth' => 0, 'multiline' => true], [], 40, 670.62);
        $content = $pdf->Output('', 'S');
        if (!is_string($content) || !str_starts_with($content, '%PDF-')) {
            throw new \RuntimeException('Gift card PDF could not be created.');
        }
        return ['content' => $content, 'name' => 'gift-card-dolce-zampa.pdf', 'mime' => 'application/pdf'];
    }
}
