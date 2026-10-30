<?php

namespace App\Support;

use Sabberworm\CSS\OutputFormat;
use Sabberworm\CSS\Parser;
use Sabberworm\CSS\RuleSet\DeclarationBlock;
use Sabberworm\CSS\Settings;
use Throwable;

/**
 * Makes a designer's own CSS (designs.description_css) safe to print inside a <style> element on the design page.
 *
 * The designer writes plain rules ("h2 { color: #7c2d12 }"); they come out limited to the text blocks they own
 * (".sd-rich-12 h2 { color: #7c2d12 }"). The raw text is stored as typed and cleaned each time the page is drawn
 * (Design::descriptionStyles()), so a better filter later also covers CSS saved earlier.
 *
 * What is removed:
 *  - everything over MAX_LENGTH characters, and every "<" (so nothing can close the <style> element);
 *  - all @-rules (@import, @font-face, @media, @keyframes, ...): only plain rules are kept;
 *  - a declaration that contains url(, src(, image-set(, expression(, javascript:, vbscript:, behavior or -moz-binding,
 *    or sets position to fixed / sticky (CSS escapes and comments are decoded before the check);
 *  - a selector that mentions html, body, :root or :host, or starts with "*", "~" or "+" (siblings after the block).
 *
 * The result is a plain string; invalid CSS gives an empty string.
 */
final class ScopedCss
{
    public const MAX_LENGTH = 20000;

    public static function sanitize(string $css, string $scope): string
    {
        // the scope is ours (".sd-rich-12"); keep it to the characters a class or id selector can hold anyway
        $scope = trim((string) preg_replace('/[^A-Za-z0-9_.# -]/', '', $scope));
        $css = trim(str_replace(["\0", '<'], '', mb_substr($css, 0, self::MAX_LENGTH)));

        if ($css === '' || $scope === '') {
            return '';
        }

        try {
            $document = (new Parser($css, Settings::create()->withLenientParsing(true)))->parse();
        } catch (Throwable) {
            return '';
        }

        $format = OutputFormat::createCompact();
        $rules = [];

        // top-level declaration blocks only: every @-rule (and everything nested in it) is dropped here
        foreach ($document->getContents() as $item) {
            if (! $item instanceof DeclarationBlock) {
                continue;
            }

            $selectors = [];
            foreach ($item->getSelectors() as $selector) {
                $text = trim($selector->getSelector());

                if ($text !== '' && ! self::targetsThePage($text)) {
                    $selectors[] = $scope.' '.$text;
                }
            }

            $declarations = [];
            foreach (self::declarationsOf($item) as $declaration) {
                if (! self::isForbidden($declaration->getRule(), $declaration->render($format))) {
                    $declarations[] = rtrim($declaration->render($format), ';').';';
                }
            }

            if ($selectors !== [] && $declarations !== []) {
                $rules[] = implode(', ', $selectors).' { '.implode(' ', $declarations).' }';
            }
        }

        return str_replace('<', '', implode("\n", $rules));
    }

    /** @return array<int, object> */
    private static function declarationsOf(DeclarationBlock $block): array
    {
        // sabberworm/php-css-parser 9 calls them declarations, 8 rules
        return method_exists($block, 'getDeclarations') ? $block->getDeclarations() : $block->getRules();
    }

    /** Rules for html, body, :root and the like would restyle the whole page; "*" would restyle all of it. */
    private static function targetsThePage(string $selector): bool
    {
        $selector = self::decode($selector);

        return str_starts_with($selector, '*')
            || str_contains($selector, ':root')
            || str_contains($selector, ':host')
            || preg_match('/(?<![\w\-.#\[="\'])(html|body)(?![\w\-])/i', $selector) === 1;
    }

    private static function isForbidden(string $property, string $rendered): bool
    {
        $property = strtolower(self::decode($property));
        $text = strtolower(self::decode($rendered));
        $compact = preg_replace('/\s+/', '', $text);

        if (str_contains($property, 'behavior') || str_contains($property, 'binding')) {
            return true;
        }

        if ($property === 'position' && preg_match('/fixed|sticky/', $compact) === 1) {
            return true;
        }

        foreach (['url(', 'src(', 'image-set(', 'expression(', 'javascript:', 'vbscript:', 'behavior', '-moz-binding'] as $needle) {
            if (str_contains($text, $needle) || str_contains($compact, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** Comments and CSS escapes (\75 rl, \u) removed, so "u\72l(" is seen as "url(". */
    private static function decode(string $css): string
    {
        $css = (string) preg_replace('~/\*.*?\*/~s', '', $css);
        $css = (string) preg_replace_callback(
            '/\\\\([0-9a-f]{1,6})[ \t\r\n\f]?/i',
            fn (array $m) => ($code = hexdec($m[1])) > 0 && $code <= 0x10FFFF ? mb_chr($code, 'UTF-8') : '',
            $css,
        );

        return (string) preg_replace('/\\\\(.)/su', '$1', $css);
    }
}
