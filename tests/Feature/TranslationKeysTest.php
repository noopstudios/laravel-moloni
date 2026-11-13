<?php

use Symfony\Component\Finder\Finder;

/**
 * The literal keys passed to __() or Message::get() in a PHP source, unescaped.
 *
 * @return list<string>
 */
function translationKeysIn(string $source): array
{
    $pattern = '/(?:\b__|Message::get)\(\s*(?:\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)")/';
    preg_match_all($pattern, $source, $matches, PREG_SET_ORDER);

    return array_map(fn (array $match) => ($match[2] ?? '') !== ''
        ? stripcslashes($match[2])
        : str_replace(['\\\'', '\\\\'], ['\'', '\\'], $match[1] ?? ''), $matches);
}

it('has a pt translation for every key used in the package', function () {
    $translations = json_decode((string) file_get_contents(__DIR__.'/../../resources/lang/pt.json'), true, flags: JSON_THROW_ON_ERROR);
    $missing = [];

    foreach (Finder::create()->files()->in(__DIR__.'/../../src')->name('*.php') as $file) {
        foreach (translationKeysIn($file->getContents()) as $key) {
            // Keys of other packages' PHP translation files (e.g. Filament's) are theirs to translate.
            if (! str_contains($key, '::') && ! array_key_exists($key, $translations)) {
                $missing[] = $key.' ('.$file->getRelativePathname().')';
            }
        }
    }

    expect($missing)->toBe([])
        ->and(translationKeysIn('__(\'It\\\'s "here"\') . Message::get("A \\"quoted\\" key") . __($dynamic)'))
        ->toBe(['It\'s "here"', 'A "quoted" key']);
});

/**
 * Where a MoloniException is built with a bare string literal as its message, which no
 * translation reaches, as "file:line".
 *
 * @return list<string>
 */
function untranslatedMoloniExceptionsIn(string $source, string $file): array
{
    preg_match_all('/new\s+MoloniException\(\s*[\'"]/', $source, $matches, PREG_OFFSET_CAPTURE);

    return array_map(fn (array $match) => $file.':'.(substr_count(substr($source, 0, $match[1]), "\n") + 1), $matches[0]);
}

it('translates the message of every MoloniException, which operators read', function () {
    $untranslated = [];

    foreach (Finder::create()->files()->in(__DIR__.'/../../src')->name('*.php') as $file) {
        array_push($untranslated, ...untranslatedMoloniExceptionsIn($file->getContents(), $file->getRelativePathname()));
    }

    expect($untranslated)->toBe([])
        ->and(untranslatedMoloniExceptionsIn(<<<'PHP'
            throw new MoloniException('Bare.', FailureType::Validation);
            throw new MoloniException(
                "Bare {$endpoint}.",
                FailureType::Validation,
            );
            throw new MoloniException(__('Translated.'), FailureType::Validation);
            throw new MoloniException(Message::get('Translated :x.', ['x' => 1]), FailureType::Validation);
            throw new MoloniException($message, FailureType::Validation);
            PHP, 'sample.php'))
        ->toBe(['sample.php:1', 'sample.php:2']);
});
