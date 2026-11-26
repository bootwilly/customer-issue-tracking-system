<?php

namespace App\Http\Controllers\Upload;

use App\Http\Controllers\Controller;
use App\Services\AttachmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

class AttachmentController extends Controller
{
    public function __construct(private AttachmentService $service) {}

    public function show(Request $request, int $id): BinaryFileResponse|RedirectResponse
    {
        $a = $this->service->find($id);
        abort_unless($a, 404);
        abort_unless($this->service->canView($request->user(), $a), 403);
        if ($a->state === 'bound' && in_array($a->owner_type, ['meeting', 'meeting_internal'], true)
            && str_ends_with($a->disk_path, '.md')) {
            return redirect()->route('meetings.documents.show', ['id' => $a->owner_id, 'document' => $id, 'download' => 1])
                ->header('Cache-Control', 'private, no-store');
        }
        $path = $this->service->absolutePath($a);
        abort_unless($path, 404);

        $inline = preg_match('#^(image/(png|jpe?g|gif|webp)|video/|application/pdf)#', $a->mime) === 1;
        $response = response()->file($path, [
            'Content-Type' => $a->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => str_ends_with($a->disk_path, '.md') ? 'private, no-store' : 'private, max-age=3600',
        ]);
        // Uploaded content never runs as a page of this site. PDF is left out: Chrome's viewer refuses a sandboxed PDF.
        if (! str_starts_with($a->mime, 'application/pdf')) {
            $response->headers->set('Content-Security-Policy', "default-src 'none'; img-src 'self'; media-src 'self'; style-src 'unsafe-inline'; sandbox");
        }
        $response->setContentDisposition(
            $inline ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $a->original_name,
            'attachment-'.$a->id.'.'.pathinfo($a->disk_path, PATHINFO_EXTENSION),
        );

        return $response;
    }
}
