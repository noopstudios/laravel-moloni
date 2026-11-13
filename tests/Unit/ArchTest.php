<?php

use Symfony\Component\Finder\Finder;

it('uses Filament only in the Filament plugin, so the package works without it', function () {
    $offending = [];

    foreach (Finder::create()->files()->name('*.php')->in(__DIR__.'/../../src')->exclude('Filament') as $file) {
        if (preg_match('/\b(Filament|Livewire|BladeUI)\\\\/', $file->getContents()) === 1) {
            $offending[] = $file->getRelativePathname();
        }
    }

    sort($offending);

    expect($offending)->toBe([]);
});
