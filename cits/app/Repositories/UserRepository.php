<?php

namespace App\Repositories;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class UserRepository
{
    /** table => columns that reference users.id and therefore count as history. */
    private const HISTORY_COLUMNS = [
        'client_tickets' => ['created_by', 'accepted_by', 'edit_lock_user_id'],
        'ticket_revisions' => ['created_by', 'pm_ack_by'],
        'ticket_reopens' => ['created_by'],
        'ticket_comments' => ['created_by'],
        'issue_comments' => ['created_by'],
        'issues' => ['created_by', 'assignee_dev_id', 'assignee_test_id'],
        'issue_addenda' => ['created_by'],
        'ticket_issue_links' => ['linked_by'],
        'due_change_requests' => ['requested_by', 'reviewed_by'],
        'packages' => ['created_by'],
        'events' => ['actor_id'],
        'attachments' => ['uploaded_by'],
        'comment_reads' => ['user_id'],
        'user_notifications' => ['user_id'],
        'notification_outbox' => ['user_id'],
    ];

    public function find(int $id): ?User
    {
        return User::find($id);
    }

    /** @param int[] $ids */
    public function notificationUsers(array $ids): Collection
    {
        return User::findMany($ids, ['id', 'user_type', 'is_admin']);
    }

    public function findByLogin(string $login): ?User
    {
        $login = mb_strtolower(trim($login));

        return User::whereRaw('lower(account) = ?', [$login])
            ->orWhereRaw('lower(email) = ?', [$login])
            ->first();
    }

    public function all(): Collection
    {
        return User::orderBy('account')->get();
    }

    public function create(array $data): User
    {
        return User::create($data);
    }

    public function update(User $user, array $data): User
    {
        $user->fill($data)->save();

        return $user;
    }

    public function touchLogin(User $user): void
    {
        $user->forceFill(['last_login_at' => now()])->save();
    }

    public function hasHistory(int $userId): bool
    {
        foreach (self::HISTORY_COLUMNS as $table => $columns) {
            $q = DB::table($table)->where(function ($w) use ($columns, $userId) {
                foreach ($columns as $col) {
                    $w->orWhere($col, $userId);
                }
            });
            if ($q->exists()) {
                return true;
            }
        }

        return false;
    }

    /** Remove a user together with transient/linking rows (memberships, role assignments, drafts). */
    public function delete(User $user): void
    {
        DB::table('model_has_roles')->where('model_id', $user->id)->where('model_type', $user->getMorphClass())->delete();
        DB::table('model_has_permissions')->where('model_id', $user->id)->where('model_type', $user->getMorphClass())->delete();
        DB::table('project_members')->where('user_id', $user->id)->delete();
        DB::table('editor_drafts')->where('user_id', $user->id)->delete();
        DB::table('upload_sessions')->where('user_id', $user->id)->delete();
        $user->delete();
    }
}
