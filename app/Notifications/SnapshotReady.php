<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Database notification: a design/driver snapshot finished building (or failed).
 */
class SnapshotReady extends Notification
{
    use Queueable;

    /**
     * @param  string  $kind  "design" or "driver"
     * @param  string|null  $error  set when the build failed
     */
    public function __construct(
        public string $kind,
        public string $name,
        public string $version,
        public ?string $error = null,
    ) {
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> same shape as the other notifications shown on /notifications */
    public function toArray(object $notifiable): array
    {
        return [
            'body' => $this->error === null
                ? "Snapshot {$this->version} of {$this->name} is ready to download."
                : "Snapshot {$this->version} of {$this->name} could not be built: {$this->error}",
            'link' => url($this->kind === 'driver' ? '/dashboard/drivers' : '/dashboard/designs'),
            'icon' => '/storage/demo/default.png',
            'user' => ['name' => config('app.name')],
            'status' => $this->error === null ? 'ready' : 'failed',
            'kind' => $this->kind,
            'version' => $this->version,
        ];
    }
}
