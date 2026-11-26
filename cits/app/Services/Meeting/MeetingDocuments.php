<?php

namespace App\Services\Meeting;

use App\Models\Attachment;
use App\Models\Meeting;
use App\Models\User;
use App\Repositories\AttachmentRepository;
use App\Services\AttachmentService;
use Dom\HTMLDocument;
use Illuminate\Support\Collection;

class MeetingDocuments
{
    public function __construct(private MeetingService $meetings, private AttachmentRepository $attachments, private AttachmentService $storage) {}

    public function upload(User $user, Meeting $meeting, array $files, bool $internal): void
    {
        $ids = [];
        foreach ($files as $file) {
            $ids[] = $this->storage->storeMeetingMarkdown($user, $file, Attachment::MEETING_DOCUMENTS)->id;
        }
        // A failed file leaves the batch temporary for the existing cleanup job.
        $this->attachments->bindMeetingDocuments($ids, $user->id, $meeting->id, $internal ? 'meeting_internal' : 'meeting');
    }

    public function visible(User $user, Meeting $meeting): Collection
    {
        $documents = collect();
        if (! $this->meetings->visibleTo($user, $meeting) || ! $this->meetings->contentVisible($user, $meeting)) {
            return $documents;
        }
        $sections = ['meeting' => $meeting->notes_html];
        if ($user->seesInternal()) {
            $sections['meeting_internal'] = $meeting->internal_html;
        }
        foreach ($sections as $owner => $html) {
            if (! $html || ! str_contains($html, '/attachments/')) {
                continue;
            }
            $ids = [];
            $dom = HTMLDocument::createFromString($html, LIBXML_NOERROR, 'UTF-8');
            foreach ($dom->querySelectorAll('a[href]') as $link) {
                $path = parse_url($link->getAttribute('href'), PHP_URL_PATH);
                if (preg_match('~/attachments/(\d+)$~D', (string) $path, $match)) {
                    $ids[(int) $match[1]] = true;
                }
            }
            if ($ids === []) {
                continue;
            }
            $files = $this->attachments->boundMarkdown($meeting->id, $owner, array_keys($ids));
            foreach ($ids as $id => $_) {
                if ($files->has($id)) {
                    $documents->push($files->get($id));
                }
            }
        }

        return $documents->concat($this->attachments->meetingDocuments($meeting->id, array_keys($sections)))->unique('id')->values();
    }
}
