<?php

namespace App\Services\Flow;

use App\Exceptions\BusinessException;
use DOMDocument;
use DOMXPath;

/**
 * Validates a draw.io "editable SVG" (export format xmlsvg): the SVG is the picture, its root
 * `content` attribute holds the mxfile used to re-open it in the editor.
 * Displayed only through <img> + CSP sandbox, but downloads can be opened directly, so active content is rejected.
 */
class DrawioParser
{
    private const ACTIVE = [
        '/<\s*(script|iframe|object|embed|meta|link|base|form|handler|listener)\b/i' => '不可包含程式碼或嵌入元素',
        '/<[^>]*\son[a-z]+\s*=/i' => '不可包含事件屬性（on…）',
        '/(java|vb)script\s*:/i' => '不可包含 javascript: 連結',
        '/<!\s*(DOCTYPE|ENTITY)/i' => '不可包含 DOCTYPE／ENTITY 宣告',
    ];

    /**
     * Second pass on the parsed tree: the regexes above see raw text and can be dodged with namespace prefixes,
     * a '>' inside an earlier attribute value or character references. Compared by local name, values decoded.
     */
    private const ACTIVE_ELEMENTS = ['script', 'iframe', 'object', 'embed', 'meta', 'link', 'base', 'form', 'handler', 'listener',
        'set', 'animate', 'animatemotion', 'animatetransform', 'animatecolor', 'discard'];

    /** Attributes holding a URL, in any namespace (href, xlink:href ...), plus SMIL value attributes. */
    private const URL_ATTRIBUTES = ['href', 'src', 'action', 'formaction', 'from', 'to', 'values', 'by'];

    /** @return array{svg:string, labels:string[], pages:int} */
    public function parse(string $raw, int $maxBytes): array
    {
        $svg = $this->decode($raw);
        if (! mb_check_encoding($svg, 'UTF-8')) {
            throw new BusinessException('流程圖必須是 UTF-8 編碼。');
        }
        $svg = trim(preg_replace('/^\xEF\xBB\xBF/', '', $svg));
        if (strlen($svg) > $maxBytes) {
            throw new BusinessException('流程圖過大（上限 '.number_format($maxBytes).' bytes），請移除貼上的大圖片。');
        }
        foreach (self::ACTIVE as $re => $msg) {
            if (preg_match($re, $svg)) {
                throw new BusinessException("流程圖內容不安全：{$msg}。");
            }
        }

        $doc = $this->load($svg);
        if (! $doc || $doc->documentElement->localName !== 'svg') {
            throw new BusinessException('不是有效的 SVG 檔。');
        }
        $this->assertInert($doc);
        $content = $doc->documentElement->getAttribute('content');
        $file = $content !== '' ? $this->load($content) : null;
        if (! $file) {
            throw new BusinessException('SVG 裡沒有 draw.io 原始資料，無法再編輯（請用 draw.io 匯出「可編輯的 SVG」）。');
        }

        $models = $this->models($file, $maxBytes);
        if (count($models) === 0) {
            throw new BusinessException('draw.io 資料裡沒有圖。');
        }
        if (count($models) > 1) {
            throw new BusinessException('一份流程圖只能有一頁，請把其他頁拆成另一份流程圖。');
        }

        return ['svg' => $this->lightOnly($svg), 'labels' => $this->labels($models[0]), 'pages' => 1];
    }

    /**
     * draw.io exports `color-scheme: light dark` + light-dark() colours: on a dark-mode OS the <img> would
     * switch to white text on our white page. Pin the root to light (root start tag only, content untouched).
     */
    private function lightOnly(string $svg): string
    {
        return preg_replace_callback('/<svg\b[^>]*>/', fn ($m) => preg_replace('/color-scheme:\s*light\s+dark/i', 'color-scheme: light', $m[0]), $svg, 1);
    }

    /** Accepts raw SVG or the editor's data URI (base64 or url-encoded). */
    private function decode(string $raw): string
    {
        $raw = trim($raw);
        if (! str_starts_with($raw, 'data:')) {
            return $raw;
        }
        [$head, $body] = array_pad(explode(',', $raw, 2), 2, '');
        if (! str_starts_with($head, 'data:image/svg+xml')) {
            throw new BusinessException('不是 SVG 資料。');
        }
        $out = str_contains($head, ';base64') ? base64_decode($body, true) : rawurldecode($body);
        if ($out === false) {
            throw new BusinessException('SVG 資料解碼失敗。');
        }

        return $out;
    }

    private function load(string $xml): ?DOMDocument
    {
        if (preg_match('/<!\s*(DOCTYPE|ENTITY)/i', $xml)) {
            return null;
        }
        $prev = libxml_use_internal_errors(true);
        $doc = new DOMDocument;
        $ok = $doc->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        return $ok && $doc->documentElement && $doc->doctype === null ? $doc : null;
    }

    private function assertInert(DOMDocument $doc): void
    {
        foreach ((new DOMXPath($doc))->query('//*') as $el) {
            if (in_array(strtolower($el->localName), self::ACTIVE_ELEMENTS, true)) {
                throw new BusinessException('流程圖內容不安全：不可包含程式碼、動畫或嵌入元素。');
            }
            foreach ($el->attributes as $attr) {
                $name = strtolower($attr->localName);
                if (str_starts_with($name, 'on')) {
                    throw new BusinessException('流程圖內容不安全：不可包含事件屬性（on…）。');
                }
                if (in_array($name, self::URL_ATTRIBUTES, true) && ! $this->safeUrl($attr->value)) {
                    throw new BusinessException('流程圖內容不安全：不可包含 javascript: 連結或非圖片的 data: 網址。');
                }
            }
        }
    }

    /** The value is already entity-decoded; browsers ignore whitespace / control characters inside a scheme. */
    private function safeUrl(string $value): bool
    {
        $v = strtolower((string) preg_replace('/[\x00-\x20]+/', '', $value));
        if (preg_match('/^(javascript|vbscript):/', $v)) {
            return false;
        }

        return ! str_starts_with($v, 'data:') || str_starts_with($v, 'data:image/');
    }

    /**
     * mxGraphModel of every page. A page is either inline XML or compressed text
     * (base64 of raw-deflated, URI-encoded XML) depending on the editor's compressXml setting.
     *
     * @return DOMDocument[]
     */
    private function models(DOMDocument $file, int $maxBytes): array
    {
        $root = $file->documentElement;
        if ($root->localName === 'mxGraphModel') {
            return [$file];
        }
        if ($root->localName !== 'mxfile') {
            return [];
        }
        $out = [];
        foreach ($root->getElementsByTagName('diagram') as $d) {
            $inline = null;
            foreach ($d->childNodes as $c) {
                if ($c->nodeType === XML_ELEMENT_NODE && $c->localName === 'mxGraphModel') {
                    $inline = $c;
                }
            }
            if ($inline) {
                $m = new DOMDocument;
                $m->appendChild($m->importNode($inline, true));
                $out[] = $m;

                continue;
            }
            $text = trim($d->textContent);
            $bin = $text === '' ? false : base64_decode($text, true);
            // cap the inflated size: a few MB of deflate data can expand to gigabytes
            $xml = $bin === false ? false : @gzinflate($bin, $maxBytes);
            $m = $xml === false ? null : $this->load(rawurldecode($xml));
            if (! $m || $m->documentElement->localName !== 'mxGraphModel') {
                throw new BusinessException('draw.io 資料損毀，無法解析。');
            }
            $out[] = $m;
        }

        return $out;
    }

    /** @return string[] distinct plain-text labels, document order */
    private function labels(DOMDocument $model): array
    {
        $xp = new DOMXPath($model);
        $out = [];
        foreach ($xp->query('//mxCell/@value | //UserObject/@label | //object/@label') as $attr) {
            $t = preg_replace('/<(br|\/div|\/p|\/li)\b[^>]*>/i', ' ', $attr->value);
            $t = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            if ($t !== '') {
                $out[$t] = true;
            }
        }

        return array_keys($out);
    }
}
