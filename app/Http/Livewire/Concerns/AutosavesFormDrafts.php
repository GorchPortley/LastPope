<?php

namespace App\Http\Livewire\Concerns;

use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\On;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Keeps an unsaved copy of a table action's modal form, so closing the modal by mistake does not lose the work.
 *
 * Use the trait in the page's Volt component and opt an action in with ->autosavesDraft() (a macro registered in
 * AppServiceProvider). While the modal is open it polls saveFormDraft(), which stores the form's raw state in the
 * cache per user, page, action and record. Opening the same form again restores it (with a "Start over" button),
 * and a successful submit forgets it. Uploads that were not saved yet are kept by their Livewire temporary file name
 * and come back while that file still exists (Livewire removes them after a day).
 */
trait AutosavesFormDrafts
{
    /** Kept out of drafts: a stale publish switch must not undo one flipped in the table since. */
    protected static array $draftIgnoredFields = ['active', 'published'];

    protected static int $draftTtlDays = 14;

    public function saveFormDraft(): void
    {
        if (($key = $this->formDraftKey()) === null) {
            return;
        }

        $data = $this->packDraftValue(Arr::except($this->mountedTableActionsData[array_key_last($this->mountedTableActionsData)] ?? [], static::$draftIgnoredFields));
        $hash = md5(serialize($data));

        if ((Cache::get($key)['hash'] ?? null) === $hash) {
            return;
        }

        Cache::put($key, ['hash' => $hash, 'data' => $data, 'saved_at' => now()->toIso8601String()], now()->addDays(static::$draftTtlDays));
    }

    /** Called after Filament filled the form (defaults or the record); lays the draft over it. */
    public function restoreFormDraft(): void
    {
        $key = $this->formDraftKey();
        $draft = $key ? Cache::get($key) : null;

        if (! is_array($draft['data'] ?? null)) {
            return;
        }

        $lost = 0;
        $data = $this->unpackDraftValue($draft['data'], $lost);
        $index = array_key_last($this->mountedTableActionsData);
        $this->mountedTableActionsData[$index] = array_replace($this->mountedTableActionsData[$index] ?? [], $data);

        Notification::make()
            ->info()
            ->title('Restored your unsaved changes')
            ->body(trim('From '.\Illuminate\Support\Carbon::parse($draft['saved_at'])->diffForHumans().'. '.($lost > 0 ? 'Some files you had just uploaded expired, so add them again.' : '')))
            ->actions([
                NotificationAction::make('discard')
                    ->label('Start over')
                    ->button()
                    ->color('gray')
                    ->dispatch('discard-form-draft')
                    ->close(),
            ])
            ->seconds(15)
            ->send();
    }

    public function forgetFormDraft(): void
    {
        if ($key = $this->formDraftKey()) {
            Cache::forget($key);
        }
    }

    /** "Start over": drop the draft and open the form again as it would be without one. */
    #[On('discard-form-draft')]
    public function discardFormDraft(): void
    {
        if (($key = $this->formDraftKey()) === null) {
            return;
        }

        Cache::forget($key);

        $name = Arr::last($this->mountedTableActions);
        $record = $this->mountedTableActionRecord;

        $this->unmountTableAction(shouldCloseModal: false);
        $this->mountTableAction($name, $record === null ? null : (string) $record);
    }

    /** Null when no action is open or the open one does not autosave. */
    protected function formDraftKey(): ?string
    {
        $action = $this->getMountedTableAction();

        if ($action === null || ! array_key_exists('wire:poll.10s', $action->getExtraModalWindowAttributes())) {
            return null;
        }

        return implode(':', ['form-draft', auth()->id(), $this->getName(), $action->getName(), $this->mountedTableActionRecord ?? 'new']);
    }

    private function packDraftValue(mixed $value): mixed
    {
        if ($value instanceof TemporaryUploadedFile) {
            return ['__draft_upload' => $value->getFilename()];
        }

        if (is_array($value)) {
            return array_map(fn ($item) => $this->packDraftValue($item), $value);
        }

        return is_object($value) ? null : $value;
    }

    private function unpackDraftValue(mixed $value, int &$lost): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_keys($value) === ['__draft_upload']) {
            $file = TemporaryUploadedFile::createFromLivewire($value['__draft_upload']);

            if ($file->exists()) {
                return $file;
            }

            $lost++;

            return null;
        }

        $out = [];
        foreach ($value as $key => $item) {
            $item = $this->unpackDraftValue($item, $lost);
            // An expired upload leaves its slot out, so the file list does not show an empty entry.
            if ($item === null && is_array($value[$key]) && array_keys($value[$key]) === ['__draft_upload']) {
                continue;
            }
            $out[$key] = $item;
        }

        return $out;
    }
}
