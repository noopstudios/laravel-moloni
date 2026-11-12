<?php

it('loads the package config from env', function () {
    expect(config('moloni.base_url'))->toBe('https://api.moloni.pt/v1/')
        ->and(config('moloni.queue_connection'))->toBe('database')
        ->and(config('moloni.client_secret'))->toBeNull();
});
