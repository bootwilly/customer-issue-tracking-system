<?php

namespace App\Services\Export;

/** Sanitized editor HTML -> plain-text lines. Images and links keep their URL so the reader can open them. */
final class HtmlToText
{
    /** @return list<string> */
    public static function lines(?string $html): array
    {
        $t = (string) $html;
        if ($t === '') {
            return [];
        }
        $abs = fn (string $u) => preg_match('~^https?://~i', $u) ? $u : url('/'.ltrim($u, '/'));
        $t = preg_replace_callback('~<img\b[^>]*\bsrc="([^"]*)"[^>]*>~i', fn ($m) => "\n[圖片] ".$abs(html_entity_decode($m[1]))."\n", $t);
        $t = preg_replace_callback('~<a\b[^>]*\bhref="([^"]*)"[^>]*>(.*?)</a>~is', function ($m) use ($abs) {
            $text = trim(strip_tags($m[2]));
            $href = $abs(html_entity_decode($m[1]));

            return $text !== '' && $text !== $href ? "{$text}（{$href}）" : $href;
        }, $t);
        // table rows -> one line, cells separated by " | "
        $t = preg_replace_callback('~<tr\b[^>]*>(.*?)</tr>~is', function ($m) {
            preg_match_all('~<t[hd]\b[^>]*>(.*?)</t[hd]>~is', $m[1], $c);
            $cells = array_map(fn ($x) => trim(preg_replace('~\s+~u', ' ', strip_tags(preg_replace('~<\s*(br|/p)\b[^>]*>~i', ' ', $x)))), $c[1]);

            return implode(' | ', $cells)."\n";
        }, $t);
        $t = preg_replace('~<\s*br\s*/?\s*>~i', "\n", $t);
        $t = preg_replace('~<\s*li\b[^>]*>~i', '• ', $t);
        $t = preg_replace('~</\s*(p|div|li|h[1-6]|blockquote|pre|tr|ul|ol)\s*>~i', "\n", $t);
        $t = strip_tags($t);
        $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = str_replace(["\r\n", "\r", "\u{00A0}"], ["\n", "\n", ' '], $t);
        $lines = array_map('rtrim', explode("\n", $t));
        $out = [];
        foreach ($lines as $l) {
            if ($l === '' && ($out === [] || end($out) === '')) {
                continue; // collapse blank runs
            }
            $out[] = $l;
        }
        while ($out && end($out) === '') {
            array_pop($out);
        }

        return $out;
    }
}
