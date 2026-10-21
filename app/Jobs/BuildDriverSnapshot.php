<?php

namespace App\Jobs;

use App\Models\Driver;
use App\Models\User;
use App\Notifications\SnapshotReady;
use App\Services\Snapshots\DriverSnapshotBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Builds a driver data-pack snapshot (ZIP) off the request and notifies the owner when it is ready.
 *
 *     BuildDriverSnapshot::dispatch($driver, 'v1.0', $request->user());
 */
class BuildDriverSnapshot implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public Driver $driver,
        public string $version,
        public User $requestedBy,
    ) {
    }

    public function handle(DriverSnapshotBuilder $builder): void
    {
        $snapshot = $builder->build($this->driver, $this->version);

        $this->notifyUsers(new SnapshotReady('driver', $this->driver->fullName(), $snapshot->version));
    }

    public function failed(Throwable $exception): void
    {
        report($exception);

        $this->notifyUsers(new SnapshotReady('driver', $this->driver->fullName(), $this->version, $exception->getMessage()));
    }

    /** The driver's owner, and the requesting user when that is someone else (e.g. an admin). */
    private function notifyUsers(SnapshotReady $notification): void
    {
        $this->driver->creator?->notify($notification);

        if ($this->driver->user_id !== $this->requestedBy->getKey()) {
            $this->requestedBy->notify($notification);
        }
    }
}
