<?php

namespace App\Services;

use App\Repositories\OutboxRepository;
use Illuminate\Support\Facades\Mail;
use Throwable;

/** Sends queued notification mails using SMTP settings stored in system_settings (mail.*). */
class OutboxService
{
    public function __construct(private OutboxRepository $repo, private WorkflowService $wf) {}

    public function sendBatch(int $limit = 20): array
    {
        if ($this->wf->setting('mail.enabled', '0') !== '1') {
            return ['sent' => 0, 'failed' => 0, 'skipped' => 'mail disabled'];
        }
        $this->applySmtpConfig();
        $max = (int) $this->wf->setting('mail.max_attempts', '5');
        $sent = $failed = 0;
        foreach ($this->repo->pending($limit, $max) as $m) {
            try {
                Mail::mailer('smtp')->html($m->body_html, function ($msg) use ($m) {
                    $msg->to($m->to_email)->subject($m->subject);
                });
                $this->repo->markSent($m);
                $sent++;
            } catch (Throwable $e) {
                $this->repo->markFailed($m, $e->getMessage(), $max);
                $failed++;
            }
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    public function applySmtpConfig(): void
    {
        $s = fn (string $k, ?string $d = null) => $this->wf->setting("mail.$k") ?: $d;
        $enc = $s('encryption', 'tls');
        config([
            'mail.mailers.smtp.transport' => config('mail.mailers.smtp.transport', 'smtp'),
            'mail.mailers.smtp.host' => $s('host'),
            'mail.mailers.smtp.port' => (int) $s('port', '587'),
            // tls = plain connect + STARTTLS (auto); ssl = implicit TLS; none = no encryption at all.
            'mail.mailers.smtp.scheme' => $enc === 'ssl' ? 'smtps' : 'smtp',
            'mail.mailers.smtp.auto_tls' => $enc !== 'none',
            // "tls" must not silently fall back to plain text when the server (or a man in the middle) drops STARTTLS
            'mail.mailers.smtp.require_tls' => $enc === 'tls',
            'mail.mailers.smtp.username' => $s('username'),
            'mail.mailers.smtp.password' => $s('password'),
            'mail.from.address' => $s('from_address', $s('username')),
            'mail.from.name' => $s('from_name', '客戶問題追蹤系統'),
        ]);
        app('mail.manager')->purge('smtp');
    }
}
