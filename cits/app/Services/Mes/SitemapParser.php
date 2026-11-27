<?php

namespace App\Services\Mes;

use App\Exceptions\BusinessException;
use DOMDocument;
use DOMElement;

/** Pure parser for ASP.NET Web.sitemap XML (no DB, XXE-safe). */
class SitemapParser
{
    public const MAX_NODES = 5000;

    /**
     * Flatten the sitemap into an ordered node list (parents always precede children).
     *
     * @return array<int, array{type:string,name:string,raw:string,path:?string,parent:?int,sort:int,depth:int}>
     *                                                                                                           `parent` = index of the nearest folder ancestor (null = top level).
     *
     * @throws BusinessException
     */
    public function parse(string $xml): array
    {
        $xml = preg_replace('/^\xEF\xBB\xBF/', '', $xml);
        if (trim($xml) === '') {
            throw new BusinessException('請提供 Web.sitemap 內容。');
        }
        // DTD / entity declarations are never legitimate in a sitemap: refuse (XXE, billion laughs).
        if (preg_match('/<!\s*(DOCTYPE|ENTITY)/i', $xml)) {
            throw new BusinessException('Web.sitemap 不可包含 DOCTYPE 或 ENTITY 宣告。');
        }

        $prev = libxml_use_internal_errors(true);
        try {
            $dom = new DOMDocument;
            $ok = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
        $root = $ok && $dom->doctype === null ? $dom->documentElement : null;
        if (! $root || strtolower($root->localName) !== 'sitemap') {
            throw new BusinessException('不是有效的 Web.sitemap（根節點必須是 siteMap）。');
        }

        $out = [];
        $this->walk($root, null, 0, $out);
        if (! $out) {
            throw new BusinessException('Web.sitemap 內沒有任何節點。');
        }

        return $out;
    }

    private function walk(DOMElement $el, ?int $folderParent, int $depth, array &$out): void
    {
        $pos = 0;
        foreach ($el->childNodes as $c) {
            if (! $c instanceof DOMElement || strtolower($c->localName) !== 'sitemapnode') {
                continue;
            }
            if (count($out) >= self::MAX_NODES) {
                throw new BusinessException('Web.sitemap 節點數過多。');
            }
            $pos++;
            $raw = trim($c->getAttribute('url'));
            $title = trim($c->getAttribute('title'));
            $isPage = $raw !== '';
            $path = $isPage ? $this->normalizeUrl($raw) : null;
            $name = $title !== '' ? $title : ($path ? pathinfo($path, PATHINFO_FILENAME) : '');
            $idx = count($out);
            $out[] = [
                'type' => $isPage ? 'page' : 'folder',
                'name' => mb_substr($name, 0, 100),
                'raw' => $raw,
                'path' => $path,
                'parent' => $folderParent,
                'sort' => $pos * 10,
                'depth' => $depth,
            ];
            // Children of a page hang under that page's nearest folder ancestor.
            $this->walk($c, $isPage ? $folderParent : $idx, $depth + 1, $out);
        }
    }

    /** "Production/WorkOrder.aspx?x=1" -> "~/Production/WorkOrder.aspx"; null when not an .aspx page. */
    public function normalizeUrl(string $url): ?string
    {
        $u = str_replace('\\', '/', trim($url));
        $u = preg_split('/[?#]/', $u, 2)[0];
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $u) || str_starts_with($u, '//')) {
            return null;
        }
        $u = ltrim(preg_replace('#^~/?#', '', $u), '/');
        $u = preg_replace('#/{2,}#', '/', $u);
        if ($u === '' || str_contains($u, '..') || ! str_ends_with(strtolower($u), '.aspx') || strlen($u) + 2 > 255) {
            return null;
        }

        return '~/'.$u;
    }
}
