<?php

namespace App\Providers;

use App\Support\SeasonalTheme;
use Filament\Tables\Actions\Action as TableAction;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schema;
use Wave\Setting;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        // One per request (and per Octane request): it memoises the active season.
        $this->app->scoped(SeasonalTheme::class);

        // Chatify's routes name the package's controllers; the container hands out ours, which refuses to sign
        // another person's real-time channel (see App\Support\Chatify\ChannelAuthorizer).
        $this->app->bind(\Chatify\Http\Controllers\MessagesController::class, \App\Http\Controllers\Chatify\MessagesController::class);
        $this->app->bind(\Chatify\Http\Controllers\Api\MessagesController::class, \App\Http\Controllers\Chatify\Api\MessagesController::class);
        // Chatify's avatars come from App\Support\Avatars like everywhere else (its own avatar folder is unused here).
        $this->app->bind('ChatifyMessenger', fn () => new \App\Support\Chatify\Messenger);
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        if ($this->app->environment() == 'production') {
            $this->app['request']->server->set('HTTPS', true);
        }

        $this->setSchemaDefaultLength();

        // Dashboard form modals that keep a draft while open (see AutosavesFormDrafts). They also stop closing on a
        // stray click outside or on Escape; the close button and Cancel still work.
        TableAction::macro('autosavesDraft', function (): TableAction {
            /** @var TableAction $this */
            return $this
                ->closeModalByClickingAway(false)
                ->closeModalByEscaping(false)
                ->extraModalWindowAttributes(['wire:poll.10s' => 'saveFormDraft'], merge: true)
                ->afterFormFilled(fn (HasTable $livewire) => $livewire->restoreFormDraft())
                ->afterFormValidated(fn (HasTable $livewire) => $livewire->forgetFormDraft());
        });

        // Wave forgets its cached settings when one is saved or deleted; drop the memoised season with it.
        $forgetSeason = fn () => $this->app->make(SeasonalTheme::class)->flush();
        Setting::saved($forgetSeason);
        Setting::deleted($forgetSeason);

        Validator::extend('base64image', function ($attribute, $value, $parameters, $validator) {
            $explode = explode(',', $value);
            $allow = ['png', 'jpg', 'svg', 'jpeg'];
            $format = str_replace(
                [
                    'data:image/',
                    ';',
                    'base64',
                ],
                [
                    '', '', '',
                ],
                $explode[0]
            );

            // check file format
            if (!in_array($format, $allow)) {
                return false;
            }

            // check base64 format
            if (!preg_match('%^[a-zA-Z0-9/+]*={0,2}$%', $explode[1])) {
                return false;
            }

            return true;
        });
    }

    private function setSchemaDefaultLength(): void
    {
        try {
            Schema::defaultStringLength(191);
        }
        catch (\Exception $exception){}
    }
}
