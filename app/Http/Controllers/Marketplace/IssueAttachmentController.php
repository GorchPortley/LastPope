<?php

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Models\OrderIssue;
use App\Models\OrderIssueMessage;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET /dashboard/issues/{orderIssue}/attachments/{message}/{index}
 *
 * Photos attached to a problem report live on the private disk; this streams one of them to the buyer, the seller or an
 * admin (OrderIssuePolicy::view). Admin-only notes are admin-only here too. Anybody else gets 403, a missing file 404.
 */
class IssueAttachmentController extends Controller
{
    /** Types a browser may show inline; anything else is downloaded. */
    private const INLINE = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public function show(OrderIssue $orderIssue, OrderIssueMessage $message, int $index): StreamedResponse
    {
        Gate::authorize('view', $orderIssue);

        abort_unless($message->order_issue_id === $orderIssue->getKey(), 404);

        // The buyer and the seller never learn that an internal note exists.
        abort_if($message->internal && ! auth()->user()->isPlatformSeller(), 404);

        $file = $message->files()[$index] ?? null;
        abort_if($file === null, 404);

        $disk = Storage::disk(config('marketplace.private_disk', 'private'));
        abort_unless($disk->exists($file['path']), 404);

        $mime = in_array($file['mime'] ?? '', self::INLINE, true) ? $file['mime'] : 'application/octet-stream';

        return $disk->response($file['path'], $file['name'] ?? basename($file['path']), [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
        ], in_array($mime, self::INLINE, true) ? 'inline' : 'attachment');
    }
}
