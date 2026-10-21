<?php

use App\Models\Driver;

beforeEach(function () {
    $this->seas = Driver::factory()->create(['brand' => 'FilterBrandSEAS', 'model' => 'SEAS-MODEL-ONE']);
    $this->peerless = Driver::factory()->create(['brand' => 'FilterBrandPeerless', 'model' => 'PEERLESS-MODEL-TWO']);
});

it('filters the drivers index by manufacturer, the parameter the filter form submits', function () {
    $this->get('/drivers?manufacturer=FilterBrandSEAS')
        ->assertOk()
        ->assertSee('SEAS-MODEL-ONE')
        ->assertDontSee('PEERLESS-MODEL-TWO');
});

it('still understands the old ?brand= links', function () {
    $this->get('/drivers?brand=FilterBrandPeerless')
        ->assertOk()
        ->assertSee('PEERLESS-MODEL-TWO')
        ->assertDontSee('SEAS-MODEL-ONE');
});

it('shows every driver when no manufacturer is chosen (empty option)', function () {
    $this->get('/drivers?manufacturer=')
        ->assertOk()
        ->assertSee('SEAS-MODEL-ONE')
        ->assertSee('PEERLESS-MODEL-TWO');
});

it('marks the chosen manufacturer as selected in the filter form', function () {
    $html = $this->get('/drivers?manufacturer=FilterBrandSEAS')->assertOk()->getContent();

    expect($html)->toMatch('/<option value="FilterBrandSEAS"\s+selected/');
});
