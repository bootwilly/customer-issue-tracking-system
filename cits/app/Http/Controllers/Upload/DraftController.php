<?php

namespace App\Http\Controllers\Upload;

use App\Http\Controllers\Controller;
use App\Services\DraftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DraftController extends Controller
{
    public function __construct(private DraftService $service) {}

    public function show(Request $request): JsonResponse
    {
        $data = $request->validate(['context' => ['required', 'string', 'max:100']]);

        return response()->json($this->service->get($request->user()->id, $data['context']));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['context' => ['required', 'string', 'max:100'], 'html' => ['nullable', 'string', 'max:'.DraftService::MAX_HTML]]);
        $this->service->save($request->user()->id, $data['context'], $data['html'] ?? '');

        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate(['context' => ['required', 'string', 'max:100']]);
        $this->service->clear($request->user()->id, $data['context']);

        return response()->json(['ok' => true]);
    }
}
