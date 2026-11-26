<?php

// Merge the chapters into dist/manual.html (self-contained). PDF: node build-pdf.mjs
require __DIR__.'/../../cits/vendor/autoload.php';

use League\CommonMark\GithubFlavoredMarkdownConverter;

$md = new GithubFlavoredMarkdownConverter(['html_input' => 'strip', 'allow_unsafe_links' => false]);
$files = array_merge([__DIR__.'/README.md'], glob(__DIR__.'/[0-9][0-9]-*.md'));
$body = '';
$toc = '';

foreach ($files as $file) {
    $id = basename($file, '.md');
    $html = (string) $md->convert(preg_replace('/^<!-- \/?internal -->[ \t]*(\R|\z)/m', '', file_get_contents($file)));
    // chapter links -> in-page anchors
    $html = preg_replace('/href="(README|\d\d-[a-z-]+)\.md"/', 'href="#$1"', $html);
    $html = preg_replace_callback('/<img src="(img\/[a-z0-9-]+\.png)"/', fn ($m) => '<img loading="lazy" src="data:image/png;base64,'.base64_encode(file_get_contents(__DIR__.'/'.$m[1])).'"', $html);
    $html = preg_replace('/<pre><code class="language-mermaid">(.*?)<\/code><\/pre>/s', '<pre class="mermaid">$1</pre>', $html);
    $body .= "<section id=\"$id\">$html</section>\n";
    preg_match('/^# (.+)$/m', file_get_contents($file), $t);
    $toc .= '<a href="#'.$id.'">'.htmlspecialchars($id === 'README' ? '首頁' : $t[1])."</a>\n";
}

$mermaid = file_get_contents(__DIR__.'/../../cits/node_modules/mermaid/dist/mermaid.min.js');
$css = <<<'CSS'
*{box-sizing:border-box}
body{margin:0;font:15px/1.75 "Segoe UI","Microsoft JhengHei",sans-serif;color:#1e293b;background:#fff}
nav{position:fixed;top:0;bottom:0;left:0;width:250px;overflow:auto;padding:20px 12px;background:#f8fafc;border-right:1px solid #e2e8f0}
nav a{display:block;padding:6px 10px;border-radius:6px;color:#334155;text-decoration:none}
nav a:hover{background:#eef2ff;color:#4338ca}
main{margin-left:250px;padding:24px 40px;max-width:1000px}
h1{font-size:26px;color:#4338ca;border-bottom:2px solid #c7d2fe;padding-bottom:6px}
h2{font-size:20px;margin-top:32px}
h3{font-size:16px}
table{border-collapse:collapse;width:100%;margin:12px 0}
th,td{border:1px solid #cbd5e1;padding:6px 10px;text-align:left;vertical-align:top}
th{background:#f1f5f9}
img{display:block;max-width:100%;max-height:1100px;width:auto;margin:12px auto;border:1px solid #cbd5e1;border-radius:6px}
code{background:#f1f5f9;padding:1px 5px;border-radius:4px;overflow-wrap:anywhere}
blockquote{margin:12px 0;padding:4px 14px;border-left:4px solid #f59e0b;background:#fffbeb}
pre.mermaid{text-align:center;background:none}
a{color:#4338ca}
@media (max-width:800px){nav{position:static;width:auto}main{margin:0;padding:16px}}
@page{size:A4;margin:14mm 12mm}
@media print{
 nav{display:none}main{margin:0;padding:0;max-width:none}body{font-size:10.5pt}
 section{break-before:page}section:first-of-type{break-before:auto}
 h2,h3{break-after:avoid}img,tr,pre.mermaid,blockquote{break-inside:avoid}
 img{max-height:225mm}pre.mermaid svg{max-height:210mm}
}
CSS;

@mkdir(__DIR__.'/dist');
file_put_contents(__DIR__.'/dist/manual.html', <<<HTML
<!doctype html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>客戶問題追蹤系統 操作手冊</title>
<style>$css</style>
</head>
<body>
<nav>$toc</nav>
<main>$body</main>
<script>
HTML.$mermaid.<<<'HTML'
</script>
<script>
document.querySelectorAll('img').forEach((i) => (i.loading = 'eager'))
mermaid.initialize({ startOnLoad: false, securityLevel: 'strict', theme: 'neutral' })
mermaid.run().then(() => (document.body.dataset.ready = '1'), (e) => (document.body.dataset.ready = 'error: ' + e.message))
</script>
</body>
</html>
HTML);
echo 'dist/manual.html '.round(filesize(__DIR__.'/dist/manual.html') / 1048576, 1)." MB\n";
