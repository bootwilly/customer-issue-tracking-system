<?php

namespace App\Http\Controllers\Manual;

use App\Http\Controllers\Controller;
use App\Services\ManualService;
use App\Support\CurrentProject;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ManualController extends Controller
{
    public function __construct(private ManualService $svc, private CurrentProject $current) {}

    /** No project is required to read the manual; apply the selected one (if any) so the menu renders. */
    public function show(Request $r, ?string $chapter = null): View
    {
        $this->current->apply();
        $chapter ??= ManualService::HOME;
        $html = $this->svc->render($r->user(), $chapter);
        abort_if($html === null, 404);

        return view('manual.show', ['html' => $html, 'chapter' => $chapter, 'chapters' => $this->svc->chapters($r->user())]);
    }

    public function image(Request $r, string $file): BinaryFileResponse
    {
        $path = $this->svc->image($r->user(), $file);
        abort_if($path === null, 404);

        return response()->file($path, ['Cache-Control' => 'private, max-age=3600']);
    }
}
