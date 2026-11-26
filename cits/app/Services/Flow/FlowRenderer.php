<?php

namespace App\Services\Flow;

use App\Services\SanitizerService;
use League\CommonMark\GithubFlavoredMarkdownConverter;

/**
 * Markdown -> safe HTML. Mermaid fences become <div class="cits-flow"><pre class="mermaid">escaped source</pre></div>,
 * rendered in the browser (securityLevel strict). Raw HTML in the Markdown is stripped, the result is sanitized.
 */
class FlowRenderer
{
    public const FENCE = '/^(```|~~~)[ \t]*mermaid[ \t]*\n(.*?)^\1[ \t]*$/ms';

    public function __construct(private SanitizerService $sanitizer) {}

    /** @return list<string> mermaid sources in document order */
    public function blocks(string $md): array
    {
        preg_match_all(self::FENCE, $md, $m);

        return $m[2];
    }

    public function render(string $md): string
    {
        $nonce = bin2hex(random_bytes(4));
        $blocks = [];
        $md = preg_replace_callback(self::FENCE, function ($m) use (&$blocks, $nonce) {
            $blocks[] = $m[2];

            return "\n\nCITSFLOW{$nonce}N".(count($blocks) - 1)."END\n\n";
        }, $md);

        $html = (new GithubFlavoredMarkdownConverter(['html_input' => 'strip', 'allow_unsafe_links' => false]))->convert($md)->getContent();
        $html = $this->sanitizer->cleanMarkdown($html);

        return preg_replace_callback("~<p>CITSFLOW{$nonce}N(\\d+)END</p>~", fn ($m) => '<div class="cits-flow" data-index="'.$m[1].'"><pre class="mermaid">'
            .htmlspecialchars($blocks[(int) $m[1]], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</pre></div>', $html);
    }
}
