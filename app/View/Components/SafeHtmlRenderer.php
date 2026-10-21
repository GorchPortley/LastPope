<?php

namespace App\View\Components;

use Illuminate\View\Component;

/**
 * Renders rich text written by users (listing descriptions...). The content goes through the Tiptap
 * schema first, so only the markup the editor itself can produce survives: scripts, event handlers,
 * iframes and unknown attributes are dropped. Plain text (seed data, imports) is turned into paragraphs.
 *
 *     <x-safe-html-renderer :content="$listing->description" />
 */
class SafeHtmlRenderer extends Component
{
    public function __construct(public string $content = '')
    {
    }

    public function sanitized(): string
    {
        $content = trim($this->content);

        if ($content === '') {
            return '';
        }

        if ($content === strip_tags($content)) {
            $content = collect(preg_split('/\R{2,}/', $content))
                ->map(fn (string $paragraph) => '<p>'.nl2br(e(trim($paragraph))).'</p>')
                ->implode('');
        }

        return trim((string) tiptap_converter()->asHtml($content));
    }

    public function render()
    {
        return view('components.safe-html-renderer', ['html' => $this->sanitized()]);
    }
}
