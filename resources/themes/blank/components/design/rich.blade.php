{{--
    Text a member wrote in the rich editor (design summary, description, driver notes) in the site's default look
    (.sd-rich), with the design's own class (sd-rich-{design id}) that the designer's CSS is scoped to.

    The content goes through the Tiptap converter, as it always did on this page: it takes the editor's JSON or HTML,
    keeps only markup the editor itself can produce (scripts, event handlers and unknown tags are dropped) and wraps
    plain text in paragraphs. (x-safe-html-renderer is not used here: it treats JSON content as plain text.)
--}}
@props(['content' => null, 'design'])

@php($html = trim((string) tiptap_converter()->asHtml((string) $content)))

@if (trim(strip_tags($html, '<img><hr><table>')) !== '')
    <div {{ $attributes->class(['sd-rich', 'sd-rich-'.$design->getKey()]) }}>{!! $html !!}</div>
@endif
