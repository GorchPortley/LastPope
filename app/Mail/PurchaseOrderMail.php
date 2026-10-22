<?php

namespace App\Mail;

use App\Models\SupplierOrder;
use App\Services\Marketplace\PurchaseOrderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The purchase order e-mail to a supplier (docs/PLAN.md 8.3): PO number, lines (SKU, quantity, unit cost), the ship-to
 * address and - for blind dropship - the instructions not to put branding or prices in the parcel. Replies go to the
 * store's address (marketplace.store_email). The buyer's e-mail address never appears in it; the phone number only
 * inside the ship-to block. The same lines come as CSV attachment. Queued.
 */
class PurchaseOrderMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(public SupplierOrder $purchaseOrder)
    {
    }

    /** The address suppliers reply to: marketplace.store_email, or the mail from address. */
    public static function replyToAddress(): string
    {
        return (string) (config('marketplace.store_email') ?: config('mail.from.address'));
    }

    public function envelope(): Envelope
    {
        $storeName = (string) config('marketplace.store_name', 'SDLabs');

        return new Envelope(
            replyTo: [new Address(static::replyToAddress(), $storeName)],
            subject: "Purchase order {$this->purchaseOrder->number} from {$storeName}",
        );
    }

    public function content(): Content
    {
        $po = $this->purchaseOrder->loadMissing(['supplier', 'activeItems']);

        return new Content(
            markdown: 'mail.purchase-order',
            with: [
                'po' => $po,
                'supplier' => $po->supplier,
                'lines' => $po->activeItems,
                'shipTo' => $po->shipToLines(),
                'storeName' => (string) config('marketplace.store_name', 'SDLabs'),
                'replyTo' => static::replyToAddress(),
                'itemsTotal' => money($po->activeItems->sum(fn ($line) => $line->lineCostCents())),
            ],
        );
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        $po = $this->purchaseOrder;

        return [
            Attachment::fromData(fn () => app(PurchaseOrderService::class)->toCsv($po), $po->number.'.csv')->withMime('text/csv'),
        ];
    }
}
