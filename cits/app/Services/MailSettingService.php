<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\EventRepository;
use App\Repositories\ProjectRepository;
use App\Repositories\SettingRepository;
use App\Support\CurrentProject;
use Illuminate\Support\Facades\Mail;
use Throwable;

class MailSettingService
{
    /** Plain (non-secret) keys, stored as mail.<key>. */
    private const PLAIN = ['enabled', 'host', 'port', 'encryption', 'username', 'from_address', 'from_name'];

    public function __construct(
        private SettingRepository $settings,
        private EventRepository $events,
        private ProjectRepository $projects,
        private CurrentProject $current,
        private OutboxService $outbox,
    ) {}

    /** Values for the form; the password is never returned. */
    public function current(): array
    {
        $out = [];
        foreach (self::PLAIN as $k) {
            $out[$k] = $this->settings->get("mail.$k");
        }
        $out['enabled'] = $out['enabled'] === '1';
        $out['port'] = $out['port'] ?: '587';
        $out['encryption'] = $out['encryption'] ?: 'tls';
        $out['password_set'] = ($this->settings->get('mail.password') ?? '') !== '';

        return $out;
    }

    public function update(User $actor, array $data, ?string $newPassword, bool $clearPassword): void
    {
        $changed = [];
        foreach (self::PLAIN as $k) {
            $v = $k === 'enabled' ? (empty($data[$k]) ? '0' : '1') : (string) ($data[$k] ?? '');
            if ($this->settings->get("mail.$k") !== $v) {
                $changed[] = $k;
            }
            $this->settings->set("mail.$k", $v);
        }
        if ($clearPassword) {
            $this->settings->set('mail.password', null, true);
            $changed[] = 'password';
        } elseif ($newPassword !== null && $newPassword !== '') {
            $this->settings->set('mail.password', $newPassword, true);
            $changed[] = 'password';
        }

        $pid = $this->current->id() ?? $this->projects->active()->first()?->id;
        if ($pid) {
            $this->events->log($pid, 'system', 0, 'mail_settings_changed', null, null, $actor->id, ['changed' => $changed]);
        }
    }

    /** @return array{ok: bool, message: string} */
    public function sendTest(string $to): array
    {
        try {
            $this->outbox->applySmtpConfig();
            Mail::mailer('smtp')->html('<p>這是一封來自客戶問題追蹤系統的測試郵件。</p>', function ($m) use ($to) {
                $m->to($to)->subject('【CITS】SMTP 測試郵件');
            });

            return ['ok' => true, 'message' => "測試郵件已寄出至 {$to}。"];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => '寄送失敗：'.mb_substr($e->getMessage(), 0, 300)];
        }
    }
}
