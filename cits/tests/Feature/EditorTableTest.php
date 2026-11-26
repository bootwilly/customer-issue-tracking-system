<?php

use App\Services\Export\HtmlToText;
use App\Services\SanitizerService;

it('keeps editor tables and merged cells but drops styles and colgroups', function () {
    $html = '<table style="min-width: 75px"><colgroup><col style="min-width: 25px"></colgroup><tbody>'
        .'<tr><th colspan="2" style="width:9px" onclick="x()"><p>品項</p></th><th><p>數量</p></th></tr>'
        .'<tr><td rowspan="2"><p>螺絲</p></td><td><p><strong>M3</strong></p></td><td style="text-align: right"><p>10</p></td></tr>'
        .'</tbody></table>';
    $out = app(SanitizerService::class)->clean($html);

    expect($out)->toContain('<table>')->toContain('<tbody>')->toContain('<th colspan="2">')->toContain('<td rowspan="2">')
        ->toContain('<strong>M3</strong>')
        ->not->toContain('style=')->not->toContain('onclick')->not->toContain('colgroup')->not->toContain('<col');
});

it('no longer truncates long content at 20000 characters', function () {
    $html = '<p>'.str_repeat('長', 30000).'</p><p>結尾</p>';
    expect(app(SanitizerService::class)->clean($html))->toContain('結尾');
});

it('turns table rows into pipe separated lines for exports', function () {
    $html = '<p>說明</p><table><tbody><tr><th><p>品項</p></th><th><p>數量</p></th></tr><tr><td><p>螺絲<br>M3</p></td><td><p>10</p></td></tr></tbody></table><p>完</p>';
    expect(HtmlToText::lines($html))->toBe(['說明', '品項 | 數量', '螺絲 M3 | 10', '完']);
});

it('keeps the drawing marker on images so drawings can be re-opened', function () {
    $out = app(SanitizerService::class)->clean('<img src="/attachments/7" alt="繪圖" data-drawing="1" data-x="y" onerror="x()">');
    expect($out)->toContain('data-drawing="1"')->toContain('src="/attachments/7"')->not->toContain('data-x')->not->toContain('onerror');
});
