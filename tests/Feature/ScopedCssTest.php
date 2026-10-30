<?php

use App\Support\ScopedCss;

/*
| A designer's own CSS is cleaned and limited to their text blocks before it is printed in a <style> element.
*/

it('prefixes every selector with the scope', function () {
    $css = ScopedCss::sanitize("h2 { color: #7c2d12; }\np:first-child, ul li { font-size: 1.15em; }", '.sd-rich-12');

    expect($css)
        ->toContain('.sd-rich-12 h2 {')
        ->toContain('color:#7c2d12')
        ->toContain('.sd-rich-12 p:first-child, .sd-rich-12 ul li {');
});

it('returns an empty string for empty or unusable CSS', function () {
    expect(ScopedCss::sanitize('', '.sd-rich-1'))->toBe('')
        ->and(ScopedCss::sanitize("   \n", '.sd-rich-1'))->toBe('')
        ->and(ScopedCss::sanitize('this is not css', '.sd-rich-1'))->toBe('');
});

it('drops every @-rule', function () {
    $css = ScopedCss::sanitize(
        "@import url(https://example.com/x.css);\n@font-face { font-family: X; src: local(x); }\n@media (min-width: 10px) { p { color: red } }\n@keyframes k { from { top: 0 } }\nh3 { color: blue; }",
        '.sd-rich-1',
    );

    expect($css)->not->toContain('@')->not->toContain('import')->not->toContain('font-face')->not->toContain('keyframes')
        ->and($css)->toContain('.sd-rich-1 h3 {')
        ->and(substr_count($css, '{'))->toBe(1);
});

it('drops declarations with url(), expression(), javascript:, behavior and binding', function (string $declaration) {
    $css = ScopedCss::sanitize("p { color: red; {$declaration} }", '.sd-rich-1');

    expect($css)->toContain('color:red')->not->toContain(trim(explode(':', $declaration)[0]).':');
})->with([
    'url' => 'background: url(http://example.com/x.png)',
    'escaped url' => 'background: u\72l(http://example.com/x.png)',
    'expression' => 'width: expression(alert(1))',
    'javascript' => 'background: javascript:alert(1)',
    'behavior' => 'behavior: url(x.htc)',
    'binding' => '-moz-binding: url(x.xml#y)',
    'fixed' => 'position: fixed',
    'sticky' => 'position: sticky',
    'important fixed' => 'position: fixed !important',
]);

it('keeps other positioning', function () {
    expect(ScopedCss::sanitize('p { position: relative; top: 2px }', '.sd-rich-1'))->toContain('position:relative');
});

it('drops rules for html, body, :root and *', function () {
    $css = ScopedCss::sanitize(
        "html { background: red }\nbody { display: none }\n:root { --x: 1 }\n* { margin: 0 }\nhtml p { color: red }\nbody > div { color: red }\n.keep { color: green }",
        '.sd-rich-1',
    );

    expect($css)->toBe('.sd-rich-1 .keep { color:green; }');
});

it('keeps class names that merely contain body or html', function () {
    expect(ScopedCss::sanitize('.body-text { color: green } #html5 { color: green }', '.sd-rich-1'))
        ->toContain('.sd-rich-1 .body-text')->toContain('.sd-rich-1 #html5');
});

it('never lets </style through', function () {
    $css = ScopedCss::sanitize("p { content: \"</style><script>alert(1)</script>\"; color: red }\n</style><script>alert(2)</script>\nq { color: blue }", '.sd-rich-1');

    expect($css)->not->toContain('<')->not->toContain('</style')
        ->and(stripos($css, '</style'))->toBeFalse();
});

it('cuts the input at 20 000 characters', function () {
    $long = str_repeat('p { color: red } ', 3000); // ~51 000 characters

    expect(strlen(ScopedCss::sanitize($long, '.sd-rich-1')))->toBeLessThan(20000 * 2);
    expect(substr_count(ScopedCss::sanitize($long, '.sd-rich-1'), '{'))->toBeLessThan(1200);
});

it('drops selectors that start with a sibling combinator, which would reach the rest of the page', function () {
    $out = ScopedCss::sanitize("~ footer { display: none } + div { color: red } h2 { color: red }", '.sd-rich-1');

    expect($out)->toBe('.sd-rich-1 h2 { color:red; }');
});
