<?php

namespace App\Invoicing;

use App\Models\TaxInvoice;
use App\Support\Branding;
use App\Support\Locales;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * An invoice as a PDF (resources/views/invoices/pdf.blade.php), in its client's language: what the client downloads
 * and gets with the Invoice Issued email, and what the admin downloads. Made from the invoice's own copies, so the
 * same invoice always gives the same document (only the logo is today's).
 */
class InvoicePdf
{
    public static function content(TaxInvoice $invoice): string
    {
        $invoice->loadMissing('items', 'notice', 'client');

        return Locales::using($invoice->client?->preferredLocale(), fn () => Pdf::loadView('invoices.pdf', [
            'invoice' => $invoice,
            'logo' => self::logo(),
        ])->setPaper('a4')->output());
    }

    /** "INV-2026-0001.pdf", with any character a file name can't hold replaced. */
    public static function filename(TaxInvoice $invoice): string
    {
        return preg_replace('/[^A-Za-z0-9._-]+/', '-', $invoice->number).'.pdf';
    }

    /** The company logo as a data URI (dompdf reads no URLs), the PNG copy of an SVG one; null without one. */
    private static function logo(): ?string
    {
        $path = Branding::emailLogoPath();

        if ($path === null || ! is_file($path)) {
            return null;
        }

        return 'data:'.(mime_content_type($path) ?: 'image/png').';base64,'.base64_encode((string) file_get_contents($path));
    }
}
