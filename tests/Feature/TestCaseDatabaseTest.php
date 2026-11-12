<?php

use Illuminate\Support\Facades\Schema;

it('creates the settings table for every test', function () {
    expect(Schema::hasTable('settings'))->toBeTrue();
});

it('creates the settings table again in a fresh in-memory database', function () {
    expect(Schema::hasTable('settings'))->toBeTrue();
});
