<?php

use App\Services\Forum\UsernameSanitizer;

it('keeps valid Flarum names unchanged', function () {
    expect((new UsernameSanitizer())->base('Speaker_Builder-42'))->toBe('Speaker_Builder-42');
});

it('replaces characters Flarum rejects', function () {
    $sanitizer = new UsernameSanitizer();

    expect($sanitizer->base('José Müller'))->toBe('Jose_Muller')
        ->and($sanitizer->base('john.doe@example.com'))->toBe('john_doe_example_com')
        ->and($sanitizer->base('  --a b--  '))->toBe('a_b');
});

it('pads, prefixes and truncates to 3-30 characters that are not all digits', function () {
    $sanitizer = new UsernameSanitizer();

    expect($sanitizer->base('ab'))->toBe('ab_')
        ->and($sanitizer->base('12345'))->toBe('u12345')
        ->and($sanitizer->base(str_repeat('x', 50)))->toHaveLength(30)
        ->and($sanitizer->base('???'))->toBe('member');
});

it('falls back to the display name when the username has nothing usable', function () {
    expect((new UsernameSanitizer())->base('***', 'Ada Lovelace'))->toBe('Ada_Lovelace');
});

it('offers numbered alternatives and never a reserved name', function () {
    $sanitizer = new UsernameSanitizer(['admin', 'forumadmin', 'admin_2']);

    expect($sanitizer->candidates('speaker', count: 3))->toBe(['speaker', 'speaker_2', 'speaker_3'])
        ->and($sanitizer->candidates('ForumAdmin', count: 2))->toBe(['ForumAdmin_2', 'ForumAdmin_3'])
        ->and($sanitizer->candidates('admin', count: 2))->toBe(['admin_3', 'admin_4']);
});

it('keeps suffixed names within 30 characters', function () {
    $names = (new UsernameSanitizer())->candidates(str_repeat('y', 40), count: 3);

    expect($names[0])->toHaveLength(30)
        ->and($names[1])->toHaveLength(30)->toEndWith('_2')
        ->and($names[2])->toEndWith('_3');
});
