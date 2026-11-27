<?php

namespace App\Http\Controllers\Pm;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Pm\Concerns\GuardsInternal;
use App\Models\User;
use App\Services\Import\IssueImportService;
use App\Services\Import\IssueImportTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PmIssueImportController extends Controller
{
    use GuardsInternal;

    private function allowed(Request $request): User
    {
        $this->guard($request, 'issue.create');

        return $this->guard($request, 'issue.edit_pm');
    }

    public function index(Request $request, IssueImportService $import)
    {
        $this->allowed($request);

        return view('pm.issue-import', [
            'sources' => $import->sources($this->pid()),
            'fileContext' => IssueImportService::FILE_CONTEXT,
        ]);
    }

    public function template(Request $request, IssueImportTemplateService $template): BinaryFileResponse
    {
        $this->allowed($request);
        $path = tempnam(sys_get_temp_dir(), 'cits_tpl_');
        $template->write($this->pid(), $path);

        return response()->download($path, $template->filename(), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    public function preview(Request $request, IssueImportService $import): JsonResponse
    {
        $u = $this->allowed($request);
        $d = $request->validate(['file_id' => 'required|integer', 'source' => 'required|string|max:50']);

        return $this->json(fn () => $import->preview($u, $this->pid(), (int) $d['file_id'], $d['source']));
    }

    /** Picture inside the user's uploaded workbook (?p=xl/media/image1.png), for composing in the browser. */
    public function media(Request $request, IssueImportService $import, int $file): Response
    {
        $u = $this->allowed($request);
        try {
            [$bytes, $mime] = $import->media($u, $this->pid(), $file, (string) $request->query('p', ''));
        } catch (BusinessException) {
            abort(404);
        }

        return response($bytes, 200, [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'private, max-age=600',
        ]);
    }

    public function apply(Request $request, IssueImportService $import): JsonResponse
    {
        $u = $this->allowed($request);
        $d = $request->validate([
            'file_id' => 'required|integer',
            'source' => 'required|string|max:50',
            'rows' => 'required|array|min:1|max:1000',
            'rows.*.no' => 'required|string|max:30',
            'rows.*.action' => 'required|in:create,addendum',
            'rows.*.images' => 'present|array',
            'rows.*.images.*' => 'integer',
            'rows.*.process_type' => 'nullable|string|max:30',
            'rows.*.mes_page_id' => 'nullable|integer',
        ]);

        return $this->json(function () use ($import, $u, $d) {
            $done = $import->apply($u, $this->pid(), (int) $d['file_id'], $d['source'], $d['rows']);

            return ['done' => array_map(fn ($r) => $r + ['url' => route('issues.show', $r['issue_id'])], $done)];
        });
    }

    private function json(callable $fn): JsonResponse
    {
        try {
            return response()->json($fn());
        } catch (BusinessException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
