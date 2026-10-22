<?php

namespace App\Models;

use Database\Factories\OrderIssueMessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line of a problem report's thread: a message, a photo upload, a system event, or an admin's internal note. */
class OrderIssueMessage extends Model
{
    /** @use HasFactory<OrderIssueMessageFactory> */
    use HasFactory;

    protected $fillable = [
        'order_issue_id',
        'author_id',
        'event',
        'body',
        'attachments',
        'internal',
    ];

    protected $casts = [
        'attachments' => 'array',
        'internal' => 'boolean',
    ];

    public function issue(): BelongsTo
    {
        return $this->belongsTo(OrderIssue::class, 'order_issue_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function isSystem(): bool
    {
        return $this->author_id === null;
    }

    /** @return array<int, array{path: string, name: string, size: int, mime: string}> */
    public function files(): array
    {
        return array_values(array_filter((array) $this->attachments, 'is_array'));
    }
}
