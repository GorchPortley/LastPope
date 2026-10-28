<?php

/*
| devdojo/auth reads its texts from config/devdojo/auth/language.php (our copy). A key it uses that our copy lacks is
| null, and the page crashes (`trans(null)` hands Blade a Translator: "htmlspecialchars(): Argument #1 must be of type
| string"). This happened with login.couldnt_find_your_account after a package update; keep every used key present.
*/

it('has every text devdojo/auth uses in our copy of its language config', function () {
    $used = collect(Illuminate\Support\Facades\File::allFiles(base_path('vendor/devdojo/auth')))
        ->filter(fn ($file) => str_ends_with($file->getFilename(), '.php'))
        ->flatMap(fn ($file) => preg_match_all('/devdojo\.auth\.language\.([a-z_]+)\.([a-z_]+)/', $file->getContents(), $m) ? $m[0] : [])
        ->unique()
        ->values();

    expect($used)->not->toBeEmpty();

    $missing = $used->reject(fn (string $key) => is_string(config($key)) && config($key) !== '');
    expect($missing->all())->toBe([]);
});
