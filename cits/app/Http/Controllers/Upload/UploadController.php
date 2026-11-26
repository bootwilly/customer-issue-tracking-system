<?php

namespace App\Http\Controllers\Upload;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Services\AttachmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UploadController extends Controller
{
    public function __construct(private AttachmentService $service) {}

    public function image(Request $request): JsonResponse
    {
        $data = $request->validate(['file' => ['required', 'file'], 'context' => ['nullable', 'string', 'max:100']]);

        return $this->run(fn () => $this->payload($this->service->storeImage($request->user(), $data['file'], $data['context'] ?? null)));
    }

    public function init(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'size' => ['required', 'integer', 'min:1'],
            'context' => ['nullable', 'string', 'max:100'],
        ]);

        return $this->run(fn () => $this->service->initUpload($request->user(), $data['name'], (int) $data['size'], $data['context'] ?? null));
    }

    public function chunk(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['index' => ['required', 'integer', 'min:0'], 'chunk' => ['required', 'file']]);

        return $this->run(fn () => ['received' => $this->service->receiveChunk($request->user(), $id, (int) $data['index'], $data['chunk'])]);
    }

    public function complete(Request $request, string $id): JsonResponse
    {
        return $this->run(fn () => $this->payload($this->service->completeUpload($request->user(), $id)));
    }

    private function payload(Attachment $a): array
    {
        return [
            'attachment_id' => $a->id,
            'name' => $a->original_name,
            'size' => $a->size,
            'mime' => $a->mime,
            'url' => route('attachments.show', $a->id),
        ];
    }

    private function run(callable $fn): JsonResponse
    {
        try {
            return response()->json($fn());
        } catch (BusinessException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
