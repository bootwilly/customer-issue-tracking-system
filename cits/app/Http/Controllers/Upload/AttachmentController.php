<?php

namespace App\Http\Controllers\Upload;

use App\Http\Controllers\Controller;
use App\Services\AttachmentService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

class AttachmentController extends Controller
{
    public function __construct(private AttachmentService $service) {}

    public function show(Request $request, int $id): BinaryFileResponse
    {
        $a = $this->service->find($id);
        abort_unless($a, 404);
        abort_unless($this->service->canView($request->user(), $a), 403);
        $path = $this->service->absolutePath($a);
        abort_unless($path, 404);

        $inline = preg_match('#^(image/(png|jpe?g|gif|webp)|video/|application/pdf)#', $a->mime) === 1;
        $response = response()->file($path, [
            'Content-Type' => $a->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
        ]);
        $response->setContentDisposition(
            $inline ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $a->original_name,
            'attachment-'.$a->id.'.'.pathinfo($a->disk_path, PATHINFO_EXTENSION),
        );

        return $response;
    }
}
