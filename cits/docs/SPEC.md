# CITS SPEC (single source for all agents)

Read this file + `database/migrations/2026_11_24_000100_create_cits_tables.php` + `database/seeders/data/workflow.php`.
Do NOT read chat history. Do NOT read other modules' files unless listed in your task.

## Stack
- PHP 8.5, Laravel 13, MariaDB 11.4 (prod) / SQLite (local + tests). Blade + Alpine.js + Tailwind 4 (Vite). NO Livewire.
- spatie/laravel-permission with teams: team id = project_id. Roles are global; assignment is per project.
- symfony/html-sanitizer for ALL user rich text (server side, before save).
- Tests: Pest 4 (`php artisan test`), SQLite in-memory. Format: `vendor/bin/pint`.
- Shell setup for bash: `. /tmp/env.sh` (adds php + composer to PATH, cd to app root).

## Layering (hard rules)
- Controllers (app/Http/Controllers/<Module>): validate input, call Services, return views/JSON. No queries.
- Services = BLL (app/Services): business rules only. NEVER use DB facade, Eloquent query builder, `Model::where`, `->save()`.
  Call Repositories only. Transactions via `App\Repositories\UnitOfWork::run(fn)`.
- Repositories = DAL (app/Repositories): ALL Eloquent / DB access lives here.
- Models (app/Models): relations + casts only. Already generated; add relations if needed, no business logic.
- No hardcoded business values in code: statuses, transitions, permissions, lookup lists, limits come from DB
  tables (workflow_statuses, workflow_transitions, field_edit_rules, lookup_items, system_settings, project_settings).
  Reading a status flag (is_terminal / is_excluded / is_released / client_tab / stage) is fine; comparing to literal
  status codes in business logic is NOT (except inside seeders/data).
- Nothing business is ever deleted. Tickets/issues/comments: no delete routes. Cancel = status change.
- All timestamps stored UTC; display with `->timezone('Asia/Taipei')`. Use helper `tw($dt)` (format Y-m-d H:i).
- UI text: Traditional Chinese literal characters (no \u escapes). Code identifiers English.

## Shared infrastructure (owner: Agent AUTH; others only USE these)
- Middleware aliases: `https` (force HTTPS in production, HSTS, secure cookie), `active` (logout disabled users),
  `project` (require selected project the user belongs to; admins may pick any project), `admin` (is_admin only).
- Current project: `App\Support\CurrentProject` singleton: `id(): ?int`, `get(): ?Project`, `set(int)`.
  Stored in session key `project_id`. On each request with a project it calls
  `app(PermissionRegistrar::class)->setPermissionsTeamId($id)` so `$user->can('ticket.create')` works per project.
- Admin bypass: Gate::before -> is_admin true => allowed (view everything).
- Layout: `resources/views/layouts/app.blade.php` used as `@extends('layouts.app')` with sections
  `title`, `content`. Header shows account, email, current project name (switcher dropdown), logout button,
  nav links filtered by permission: 客戶問題 (ticket.view, client users), 問題管理 (issue.view + internal PM),
  開發管理, 測試管理, 時序總覽, MES 頁面 (mes.manage), 系統管理 (admin).
- Flash messages: session('ok') / session('err') rendered by layout. Validation errors rendered by layout.
- Blade components available after AUTH: `<x-badge :status="$code" scope="ticket|issue"/>` (label from
  workflow_statuses), `<x-empty>text</x-empty>`.

## Auth rules
- Login with account OR email + password. Disabled users cannot log in.
- Password min length from system_settings `password.min_length` (6). No complexity rule.
- Throttle: key = lower(account)|ip, max `login.max_attempts` per `login.decay_minutes`. Show remaining wait.
- HTTPS: in production (APP_ENV=production) any http request is 301 -> https; login form/POST refused over http.
  Session cookie secure + httponly + samesite=lax. Add HSTS header (max-age 31536000).
- Admin account `admin` seeded from env ADMIN_INITIAL_PASSWORD. Users change own password at /account/password.

## Domain quick reference
- Ticket (client_tickets) statuses: submitted, in_progress, pending_close, closed, cancelled (client tabs via client_tab).
- Issue statuses/stages: see seeders/data/workflow.php. Issue origin: client | pm | dev | test.
- Chain lock: ticket editable/revisable/cancellable by client only if status allows AND dispatched_at IS NULL AND
  no other user's valid edit lock. Dispatch of any linked issue sets dispatched_at on all linked tickets (never cleared).
- Settlement: on any issue status change recompute linked tickets: active = linked issues whose status is_excluded=0.
  active non-empty and all is_released -> ticket pending_close; active empty -> needs_pm_action=1.
- Client View role: read only. Client PM: create/edit/cancel/close/reopen/comment.
- Ticket comments = client channel (ticket_comments); issue comments = internal channel (issue_comments).
- Test-created issue severity editable only by the creating Test user (field_edit_rules actor_rule=creator).

## Core services (owner: MAIN; UI agents call them, never re-implement)
- `App\Services\WorkflowService`: `statusMap(scope)`, `label(scope, code)`, `allowedTargets(scope, from, User)`,
  `assertTransition(scope, from, to, User, array $data)` (throws `App\Exceptions\BusinessException`).
- `App\Services\TicketService`: create, startEdit, heartbeat, saveEdit, releaseEdit, accept, cancel, revise,
  addComment, close, reopen, settle, timeline.
- `App\Services\IssueService`: createFromTickets, createInternal, updateFields, dispatch, link, transition,
  requestDueChange, reviewDueChange, addComment, addAddendum, quoteComment.
- `App\Services\SettlementService`: recompute(issueId).
- `App\Exceptions\BusinessException`: message is user-facing Chinese; controllers catch -> back()->with('err').

## Directory ownership (agents may only create/modify files in their own area)
- AUTH: app/Http/Middleware, app/Http/Controllers/{Auth,Admin,Account}, app/Support, app/Providers,
  app/Repositories/{UserRepository,ProjectRepository,SettingRepository}.php, app/Services/{Auth,Admin}*,
  resources/views/{layouts,auth,admin,account,components}, routes/web.php (owns file; others add route files
  routes/<module>.php and AUTH requires them), bootstrap/app.php, config/session.php, tests/Feature/Auth*, tests/Feature/Admin*.
- EDITOR: resources/js/editor/*, resources/views/components/editor.blade.php, app/Http/Controllers/Upload,
  app/Services/{Attachment,Draft,Sanitizer}*, app/Repositories/{Attachment,Draft,UploadSession}Repository.php,
  routes/upload.php, tests/Feature/Upload*.
- MAIN: app/Services/{Workflow,Ticket,Issue,Settlement,Numbering}*, app/Repositories/{Ticket,Issue,Workflow,Event,
  Counter,Comment,Lookup}Repository.php, app/Repositories/UnitOfWork.php, app/Exceptions, tests/Feature/Core*.
- CLIENT, PM, DEV, TEST, MES agents: app/Http/Controllers/<Module>, resources/views/<module>, routes/<module>.php,
  tests/Feature/<Module>*.

## Agent report format (max 15 lines)
Files created/changed (paths only) / test result line / open issues. No code in the report.
