<?php

namespace App\Services;

/**
 * Thin bridge to the EDITOR module (sanitize / bind attachments / clear drafts),
 * so core services do not depend on its internals.
 */
class ContentService
{
    public function clean(string $html): string
    {
        if (class_exists(SanitizerService::class)) {
            return app(SanitizerService::class)->clean($html);
        }

        return strip_tags($html, '<p><br><strong><em><ul><ol><li>');
    }

    public function bind(string $html, string $ownerType, int $ownerId, ?string $context): void
    {
        if (class_exists(AttachmentService::class)) {
            app(AttachmentService::class)->bindFromHtml($html, $ownerType, $ownerId, (string) $context);
        }
    }

    public function clearDraft(int $userId, ?string $context): void
    {
        if ($context && class_exists(DraftService::class)) {
            app(DraftService::class)->clear($userId, $context);
        }
    }

    public function isBlank(string $html): bool
    {
        return trim(html_entity_decode(strip_tags($html, '<img>'))) === '';
    }
}
