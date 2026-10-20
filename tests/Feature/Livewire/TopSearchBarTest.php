<?php

use App\Http\Livewire\TopSearchBar;
use Livewire\Livewire;

it('renders the top search bar', function () {
    Livewire::test(TopSearchBar::class)
        ->assertStatus(200);
});
