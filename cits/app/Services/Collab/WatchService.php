<?php

namespace App\Services\Collab;

use App\Exceptions\BusinessException;
use App\Models\ClientTicket;
use App\Models\Issue;
use App\Models\User;
use App\Repositories\EventRepository;
use App\Repositories\ProjectRepository;
use App\Repositories\SettingRepository;
use App\Repositories\WatchRepository;
use App\Services\WorkflowService;
use Illuminate\Support\Collection;

/**
 * Watch lists (CC): extra recipients of ticket / issue notifications.
 * Clients watch only client tickets; internal issues are never watched by (or notified to) client accounts.
 * Internal recipients get the PM ticket page link, clients the client ticket page.
 */
class WatchService
{
    public function __construct(
        private WatchRepository $repo,
        private EventRepository $events,
        private ProjectRepository $projects,
        private SettingRepository $settings,
        private WorkflowService $wf,
    ) {}

    public function canWatchTicket(User $u, ClientTicket $t): bool
    {
        return $this->wf->can($u, 'ticket.view', $t->project_id)
            || ($u->seesInternal() && $this->wf->can($u, 'issue.view', $t->project_id));
    }

    public function canWatchIssue(User $u, Issue $i): bool
    {
        return $u->seesInternal() && $this->wf->can($u, 'issue.view', $i->project_id);
    }

    public function watchingTicket(User $u, int $ticketId): bool
    {
        return $this->repo->has('ticket', $ticketId, $u->id);
    }

    public function watchingIssue(User $u, int $issueId): bool
    {
        return $this->repo->has('issue', $issueId, $u->id);
    }

    /** @return Collection<int, User> */
    public function ticketWatchers(ClientTicket $t): Collection
    {
        return $this->repo->watchers('ticket', $t->id);
    }

    /** @return Collection<int, User> internal watchers only */
    public function issueWatchers(Issue $i): Collection
    {
        return $this->repo->watchers('issue', $i->id)->filter(fn (User $w) => $w->seesInternal())->values();
    }

    public function toggleTicket(User $u, ClientTicket $t, bool $on): void
    {
        if (! $this->canWatchTicket($u, $t)) {
            throw new BusinessException('沒有權限關注這張單。');
        }
        $on ? $this->repo->add('ticket', $t->id, $u->id) : $this->repo->remove('ticket', $t->id, $u->id);
    }

    public function toggleIssue(User $u, Issue $i, bool $on): void
    {
        if (! $this->canWatchIssue($u, $i)) {
            throw new BusinessException('沒有權限關注這張單。');
        }
        $on ? $this->repo->add('issue', $i->id, $u->id) : $this->repo->remove('issue', $i->id, $u->id);
    }

    /** Auto-subscribe (creator, acceptor, assignees). Client accounts are never added to issues. */
    public function autoWatchTicket(int $ticketId, ?int $userId): void
    {
        if ($userId) {
            $this->repo->add('ticket', $ticketId, $userId);
        }
    }

    /** @param array<int|null> $userIds */
    public function autoWatchIssue(Issue $i, array $userIds): void
    {
        foreach (array_unique(array_filter($userIds)) as $id) {
            $this->repo->add('issue', $i->id, (int) $id);
        }
    }

    /**
     * Ticket notification: the given internal / client recipients plus watchers of the matching side, without the actor.
     *
     * @param  array<int|null>  $internalIds
     * @param  array<int|null>  $clientIds
     */
    public function notifyTicket(ClientTicket $t, string $title, ?int $actorId, array $internalIds, array $clientIds): void
    {
        $watchers = $this->capped($this->repo->watchers('ticket', $t->id), $t->project_id);
        $internal = array_merge($internalIds, $watchers->filter(fn (User $w) => $w->seesInternal())->pluck('id')->all());
        $client = array_merge($clientIds, $watchers->reject(fn (User $w) => $w->seesInternal())->pluck('id')->all());
        $drop = fn (array $ids) => array_values(array_diff(array_unique(array_map('intval', array_filter($ids))), [(int) $actorId]));
        $internal = $drop($internal);
        $client = array_values(array_diff($drop($client), $internal));
        $this->events->notify($internal, $t->project_id, $title, "/pm/tickets/{$t->id}", $this->mail());
        $this->events->notify($client, $t->project_id, $title, "/tickets/{$t->id}", $this->mail());
    }

    /** @param array<int|null> $ids issue notification: ids + internal watchers, without the actor */
    public function notifyIssue(Issue $i, string $title, ?int $actorId, array $ids): void
    {
        $watchers = $this->capped($this->issueWatchers($i), $i->project_id)->pluck('id')->all();
        $all = array_values(array_diff(array_unique(array_map('intval', array_filter(array_merge($ids, $watchers)))), [(int) $actorId]));
        $this->events->notify($all, $i->project_id, $title, "/issues/{$i->id}", $this->mail());
    }

    /** Watchers still in the project, capped by watch.max_recipients (oldest subscriptions first). */
    private function capped(Collection $watchers, int $projectId): Collection
    {
        $max = max(1, $this->settings->int('watch.max_recipients', 50));

        return $watchers->filter(fn (User $w) => $w->is_admin || $this->projects->isMember($projectId, $w->id))->take($max)->values();
    }

    private function mail(): bool
    {
        return $this->wf->setting('mail.enabled', '0') === '1';
    }
}
