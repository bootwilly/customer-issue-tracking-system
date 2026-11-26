<?php

namespace App\Services;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

class SanitizerService
{
    private ?HtmlSanitizer $sanitizer = null;

    private ?HtmlSanitizer $markdown = null;

    private const BASE = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'ul', 'ol', 'li', 'h2', 'h3', 'blockquote', 'code', 'pre',
        'table', 'thead', 'tbody', 'tr'];

    /** Max HTML length kept by the sanitizer (symfony default is 20000 chars and silently truncates longer input). */
    private const MAX_INPUT = 2_000_000;

    /** Rendered Markdown (flow documents) additionally needs headings, rules and strikethrough. */
    private const MARKDOWN_EXTRA = ['h1', 'h4', 'h5', 'h6', 'hr', 'del'];

    public function clean(string $html): string
    {
        return ($this->sanitizer ??= $this->build(self::BASE, self::MAX_INPUT))->sanitize($html);
    }

    public function cleanMarkdown(string $html): string
    {
        return ($this->markdown ??= $this->build([...self::BASE, ...self::MARKDOWN_EXTRA], self::MAX_INPUT))->sanitize($html);
    }

    private function build(array $elements, ?int $maxInput = null): HtmlSanitizer
    {
        $config = new HtmlSanitizerConfig;
        if ($maxInput !== null) {
            $config = $config->withMaxInputLength($maxInput); // symfony default is 20000 chars
        }
        foreach ($elements as $el) {
            $config = $config->allowElement($el);
        }
        $config = $config
            ->allowElement('span', ['style'])
            // table cells: merged cells only; widths / alignment styles are dropped
            ->allowElement('th', ['colspan', 'rowspan'])
            ->allowElement('td', ['colspan', 'rowspan'])
            ->allowElement('a', ['href', 'data-attachment-id'])
            ->allowElement('img', ['src', 'alt', 'data-drawing'])
            ->forceAttribute('a', 'rel', 'noopener noreferrer')
            ->allowLinkSchemes(['http', 'https'])
            ->allowRelativeLinks(true)
            ->allowMediaSchemes(['http', 'https'])
            ->allowRelativeMedias(true)
            ->dropElement('script')
            ->dropElement('style')
            ->dropElement('iframe')
            ->dropElement('object')
            ->dropElement('embed')
            ->withAttributeSanitizer(new SpanStyleAttributeSanitizer)
            ->withAttributeSanitizer(new ImgSrcAttributeSanitizer((string) parse_url((string) config('app.url'), PHP_URL_HOST)));

        return new HtmlSanitizer($config);
    }
}

/** span style 僅保留 color / font-size */
class SpanStyleAttributeSanitizer implements AttributeSanitizerInterface
{
    public function getSupportedElements(): ?array
    {
        return ['span'];
    }

    public function getSupportedAttributes(): ?array
    {
        return ['style'];
    }

    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
    {
        $out = [];
        foreach (explode(';', $value) as $decl) {
            [$prop, $val] = array_pad(explode(':', $decl, 2), 2, '');
            $prop = strtolower(trim($prop));
            $val = trim($val);
            if ($prop === 'color' && preg_match('/^(#[0-9a-f]{3,8}|rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}\s*(,\s*[\d.]+\s*)?\)|[a-z]{3,20})$/i', $val)) {
                $out[] = "color: $val";
            } elseif ($prop === 'font-size' && preg_match('/^\d+(\.\d+)?(px|em|rem|%)$/', $val)) {
                $out[] = "font-size: $val";
            }
        }

        return $out === [] ? null : implode('; ', $out);
    }
}

/** img src 僅允許 /attachments/{id} 或同源 */
class ImgSrcAttributeSanitizer implements AttributeSanitizerInterface
{
    public function __construct(private string $host) {}

    public function getSupportedElements(): ?array
    {
        return ['img'];
    }

    public function getSupportedAttributes(): ?array
    {
        return ['src'];
    }

    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
    {
        if (preg_match('#^/attachments/\d+$#', $value)) {
            return $value;
        }
        $parts = parse_url($value);
        if ($parts && isset($parts['host']) && $this->host !== '' && strcasecmp($parts['host'], $this->host) === 0) {
            return $value;
        }

        return null;
    }
}
