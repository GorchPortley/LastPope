<?php

namespace App\Jobs;

use App\Models\Design;
use App\Models\User;
use App\Notifications\SnapshotReady;
use App\Services\Snapshots\DesignSnapshotBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Builds a design snapshot (ZIP + PDF) off the request and notifies the owner when it is ready.
 *
 *     BuildDesignSnapshot::dispatch($design, 'v1.0', $request->user());
 */
class BuildDesignSnapshot implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public Design $design,
        public string $version,
        public User $requestedBy,
    ) {
    }

    public function handle(DesignSnapshotBuilder $builder): void
    {
        $snapshot = $builder->build($this->design, $this->version);

        $this->notifyUsers(new SnapshotReady('design', (string) $this->design->name, $snapshot->version));
    }

    public function failed(Throwable $exception): void
    {
        report($exception);

        $this->notifyUsers(new SnapshotReady('design', (string) $this->design->name, $this->version, $exception->getMessage()));
    }

    /** The design's owner, and the requesting user when that is someone else (e.g. an admin). */
    private function notifyUsers(SnapshotReady $notification): void
    {
        $this->design->designer?->notify($notification);

        if ($this->design->user_id !== $this->requestedBy->getKey()) {
            $this->requestedBy->notify($notification);
        }
    }
}
