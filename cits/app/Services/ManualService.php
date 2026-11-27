<?php

namespace App\Services;

use App\Models\User;
use League\CommonMark\GithubFlavoredMarkdownConverter;

/**
 * Online user manual: Markdown files shipped with the app (no database).
 * manual.json: chapter file => audience (all | internal | admin). Blocks between
 * <!-- internal --> and <!-- /internal --> lines are removed for client accounts.
 */
class ManualService
{
    public const HOME = 'README';

    private const BLOCK = '/^<!-- internal -->[ \t]*\R.*?^<!-- \/internal -->[ \t]*(\R|\z)/ms';

    private const MARKER = '/^<!-- \/?internal -->[ \t]*(\R|\z)/m';

    /** Any opening marker still present after the complete blocks were removed, wherever it sits on the line. */
    private const LEFTOVER = '/<!--\s*internal\s*-->/i';

    /** resources/manual on the server (copied by deploy/build.py), docs/manual in the repository. */
    public function dir(): string
    {
        return is_dir(resource_path('manual')) ? resource_path('manual') : base_path('../docs/manual');
    }

    /** @return array<string,string> chapter => title, only those this user may read */
    public function chapters(User $u): array
    {
        $file = $this->dir().'/manual.json';
        $manifest = is_file($file) ? json_decode((string) file_get_contents($file), true) : [];
        $list = [];
        foreach ($manifest ?: [] as $chapter => $audience) {
            if (! $this->allows($u, $audience) || ($md = $this->markdown($u, $chapter)) === null) {
                continue;
            }
            $list[$chapter] = preg_match('/^# (.+?)\s*$/m', $md, $m) ? $m[1] : $chapter;
        }

        return $list;
    }

    /** Rendered HTML of one chapter, or null when it does not exist for this user. */
    public function render(User $u, string $chapter): ?string
    {
        $chapters = $this->chapters($u);
        if (! isset($chapters[$chapter])) {
            return null;
        }
        $html = (new GithubFlavoredMarkdownConverter(['html_input' => 'strip', 'allow_unsafe_links' => false]))
            ->convert($this->markdown($u, $chapter))->getContent();

        // chapter links -> routes; links to chapters this user cannot read become plain text
        $html = preg_replace_callback('~<a href="(README|\d\d-[a-z-]+)\.md">(.*?)</a>~s', fn ($m) => isset($chapters[$m[1]])
            ? '<a href="'.e(route('manual.show', $m[1] === self::HOME ? null : $m[1])).'">'.$m[2].'</a>'
            : $m[2], $html);
        $html = preg_replace_callback('~<img src="img/([a-z0-9-]+\.png)"~', fn ($m) => '<img loading="lazy" src="'.e(route('manual.image', $m[1])).'"', $html);

        // same markup as FlowRenderer, so resources/js/flow/index.js draws the diagrams
        return preg_replace('~<pre><code class="language-mermaid">(.*?)</code></pre>~s', '<div class="cits-flow"><pre class="mermaid">$1</pre></div>', $html);
    }

    /** Absolute path of a screenshot, only if a chapter visible to this user shows it. */
    public function image(User $u, string $file): ?string
    {
        $path = $this->dir().'/img/'.basename($file);
        if (! is_file($path)) {
            return null;
        }
        foreach (array_keys($this->chapters($u)) as $chapter) {
            if (str_contains($this->markdown($u, $chapter), '](img/'.basename($file).')')) {
                return $path;
            }
        }

        return null;
    }

    private function allows(User $u, string $audience): bool
    {
        return match ($audience) {
            'all' => true,
            'internal' => $u->seesInternal(),
            'admin' => (bool) $u->is_admin,
            default => false,
        };
    }

    private function markdown(User $u, string $chapter): ?string
    {
        $path = $this->dir().'/'.basename($chapter).'.md';
        if (! is_file($path)) {
            return null;
        }

        $md = (string) file_get_contents($path);
        if (! $u->seesInternal()) {
            $md = (string) preg_replace(self::BLOCK, '', $md);
            // fail closed: an opening marker left over (indented, misspelt closing line, never closed) hides the rest
            if (preg_match(self::LEFTOVER, $md, $m, PREG_OFFSET_CAPTURE)) {
                $md = substr($md, 0, $m[0][1]);
            }
        }

        // Marker lines are removed for everyone: a comment line inside a table would break the table.
        return (string) preg_replace(self::MARKER, '', $md);
    }
}
