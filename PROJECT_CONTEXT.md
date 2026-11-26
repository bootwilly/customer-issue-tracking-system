# PROJECT_CONTEXT

Updated: 2026-11-24
Status: P1 deployed to production (https://<domain>/mescits/). P2 features in progress 2026-11-25. See HANDOVER.md (Chinese) for the full guide.

## Project Positioning

- Name: Customer Issue Tracking System (CITS). Client ticket reporting + internal PM/DEV/Test issue workflow for an ASP.NET MES product.

## Solution Structure

- PHP 8.5, Laravel 13 app in ./cits. Blade + Alpine.js + Tailwind 4 (Vite). No Livewire. TipTap editor (resources/js/editor).
- DB: MariaDB 11.4 (prod), SQLite (local + tests). spatie/laravel-permission with teams (team = project_id). laravel/mcp for the read-only MCP endpoint.
- Layers: app/Http/Controllers (validate, call services) -> app/Services (BLL, no DB) -> app/Repositories (DAL, all Eloquent/DB) ; app/Models (relations/casts only).
- Data-driven workflow: workflow_statuses, workflow_transitions, field_edit_rules, lookup_items, system_settings. Seed source: cits/database/seeders/data/workflow.php.
- Routes: routes/web.php (auth/admin) + routes/modules/*.php (glob-loaded) + routes/ai.php (MCP).
- Spec for agents: cits/docs/SPEC.md. PHP files UTF-8 without BOM, LF.

## Module Responsibilities

- Auth/Admin, Client tickets (/tickets), PM (/pm), Issue detail (/issues), DEV (/dev), Test (/test), Timeline, MES registry (/mes),
  Upload/Editor, Notify (mail settings, bell, password reset, personal settings), Reports (/reports), MCP (/mcp + /account/tokens), Revision diff.

- Online manual (/manual, login required): ManualService reads docs/manual (server: resources/manual); manual.json = audience per chapter; client accounts get no <!-- internal --> blocks. Update the manual when UI/workflow changes.

## Architecture Boundaries

- BLL must never touch the database directly. Data access goes through Repositories.
- Do not hardcode business values (status codes, tabs, stages, origins, kinds): read lookup_items / workflow_statuses via WorkflowService
  (lookupCodes, lookupMap, lookupValue, codesForTab, codesForStage). Stage names with behaviour: lookup stage_role.
- Sub-path deploy (/mescits): never use string-path redirects (Route::redirect, redirectGuestsTo('/')); use route()/closures. JS uses meta app-base.
- Vite production build MUST run with ASSET_URL=/mescits (deploy/build.py does it); otherwise dynamic import chunks (mermaid on /flows) load from /build and 404.
- Flow documents (/flows): raw Markdown stored, rendered server-side via commonmark (html_input strip) + SanitizerService::cleanMarkdown; mermaid rendered in browser with securityLevel strict.
- Flows have format drawio|mermaid (lookup flow_format; handlers in FlowService::HANDLERS). drawio = editable SVG (xmlsvg) stored in source_md, shown only via <img> + /flows/{id}/image (CSP sandbox), validated by DrawioParser. One page per flow.
- draw.io is self-hosted at cits/public/drawio, pinned v32.7.4 (CITS_VERSION.txt). js/PreConfig.js is locally patched (base URL, stealth, lockdown): re-apply after any upgrade, then re-test embed load/save/export and single-page check. Deploy it separately: python deploy/build.py --drawio.
- Flow v2: saving never creates a version (one draft per flow: draft_* columns, draft_rev, snapshots); publish creates the version (note required). Flow status = workflow scope 'flow'. Draft editing = flow.manage or project_flow_editors member.
- Meetings (/meetings, docs/meeting-system.md): clients see only customer_visible meetings; content of shared internal meetings only after publish (is_released status); internal_html / internal comments never to clients. Item editing is JSON autosave.
- System admin (users.is_admin) = full access: permissions pass via Gate::before; for side checks use User::seesInternal() (internal side) / User::actsAsClient() (client-only actions), never bare isClient(). isClient() is only for data semantics (who created it, read receipts, badges).
- Role project_admin = project-scoped admin (project.members, project.trash, flow.delete). Roles in lookup role_admin_only are granted only by system admins (ProjectAdminService enforces).
- Prod sql_mode is '' (silent varchar truncation) and SQLite tests ignore lengths: keep seed values within column sizes (SeedDataLengthTest checks workflow.php against MariaDB DDL). workflow scope max 30 chars.
- AI is read-only on any database: SELECT only. Prod schema/data changes = SQL file executed by the user.

## Known Entry Points

- Web: cits/public/index.php (prod copy patched to ../../app/mescits + usePublicPath). CLI: cits/artisan (cron schedule:run).
- Commands: cits:send-outbox, cits:cleanup-temp, queue:work (scheduled in routes/console.php).

## Build Tools

- Composer, artisan, npm (Vite). Tests: Pest (`php artisan test`). Format: vendor/bin/pint.
- Deploy tools: deploy/build.py (full package), deploy/make_install_sql.php (fresh DB), deploy/make_upgrade_sql.php (incremental SQL), deploy/mcp_call.py.

## Known Actions

- Single-file hotfix: upload via MCP upload_items to webdav-app (app core) or webdav-demo (public). Vendor change => full build.py redeploy.

## Dry-run Rules

- MES batch import and Web.sitemap import always preview first (no write); apply re-plans inside one transaction; never deactivate missing pages.

## Known Issues

- PROJECT_ARCHITECTURE.md title mismatch ("WillyStock").
- Tests never run on MariaDB yet (only SQLite).
- ticket_revisions stores only title/content (type/category/page not versioned).

## Suggested Workflow

1. Read PROJECT_CONTEXT.md, SESSION_LOG.md, HANDOVER.md.
2. Keep layering + no-hardcode rules; add lookups in workflow.php and ship them via make_upgrade_sql.php.
3. Run full test suite before deploy; update SESSION_LOG.md.

## Business Rules (confirmed by user 2026-11-24)

- client_tickets and issues are separate; linked N:M (ticket_issue_links) to merge duplicate problems.
- Status "released" (prod updated) is set by Test. A ticket moves to "pending close" only when ALL linked issues are released.
- Test "new found" issues go directly to DEV and also appear in PM list.
- Client View role: read-only, no other function (no comment, no reopen, no close).
- Client revision after PM accepted: takes effect immediately on submit; PM is notified only (no approval).
- SLA: default 2 days for PM to accept a submitted ticket; must be configurable (project_settings), not hardcoded.
- Nothing created can be deleted. Created tickets/issues can only change status to "cancelled".
- NO SLA. Replaced by date-sorted timeline view (age / overdue computed at query time).
- Cancelled / wont-fix issues (workflow_statuses.is_excluded=1) are excluded from ticket settlement.
  If all linked issues are excluded -> ticket.needs_pm_action=1; PM must choose (to pending-close / cancel ticket / new issue) with client-visible note.
- Client reopen -> PM creates NEW issue linked to ticket; released issues are untouched. Reopen text stored in ticket_reopens.
- Chain lock: once ANY linked issue is dispatched to DEV/TEST, ticket.dispatched_at is set (never cleared);
  client can no longer edit, revise or cancel. Linking a ticket to an already-dispatched issue also sets it.
  Dispatch locks tickets (ordered by id) then issue; rejects if any ticket has an active client edit lock.
- Client accounts in the same project can see each other's tickets.
- Hosting: iFastNet PAID Linux shared hosting (cPanel), unlimited disk, cPanel cron AVAILABLE, no resident queue worker, likely no SSH.
  Use database queue + cron "schedule:run" every minute; scheduler runs queue:work --stop-when-empty, daily temp/draft cleanup.
  Laravel app outside public_html; subdomain document root -> app/public.
  Do not depend on MariaDB SEQUENCE / system versioning (host version unverified). PHP version / upload limits unverified.
- SMTP: untested on host. Admin can toggle email on/off and edit SMTP settings (system_settings, password encrypted with Crypt), with test-send button.
  When off: in-app notifications only, outbox rows marked skipped, no backfill. No AD/LDAP.
- Client PM supplement comments: allowed in submitted / in progress (even after dispatch) / pending close. No edit, no delete.
  Comment channels by structure: ticket_comments = client channel, issue_comments = internal channel. Client comments shown read-only on all linked issues, with read tracking (comment_reads). PM can quote a comment into issue_addenda.
- Client may cancel after PM accept but before dispatch; PM notified.
- Test-created issue severity: editable ONLY by the creating Test user (field_edit_rules.actor_rule=creator).
- mes_pages maintained by ALL internal users. MES is ASP.NET WebForms (.aspx). Keep Web.sitemap import with dry-run preview; never auto-deactivate.
- MES pages: in-app registry (mes_pages) mapping to ASP.NET page files (page_path like ~/Folder/Page.aspx).
- Attachments: video/log supported, 100MB max, chunked upload (5MB chunks).

## Production DB Facts (verified via MCP SELECT, 2026-11-24)

- MariaDB 11.4.10-MariaDB-cll-lve (CloudLinux). DB name: <cpanel_user>_mescits. Remote access from dev machine works.
- Server defaults: character_set latin1 / latin1_swedish_ci (DB default changed to utf8mb4 / utf8mb4_uca1400_ai_ci on 2026-11-24), sql_mode '' (empty), time_zone SYSTEM (EST),
  wait_timeout 60s, max_user_connections 38, max_allowed_packet 512MB, lower_case_table_names 0, event_scheduler ON.
- Rules: DB default charset must be utf8mb4 + collation utf8mb4_uca1400_ai_ci (user runs ALTER DATABASE); Laravel connection strict=true,
  charset utf8mb4, collation utf8mb4_uca1400_ai_ci (DB_COLLATION), timezone '+00:00'; store UTC (DATETIME), display Asia/Taipei. Table names lowercase snake_case.
- Long work (chunk assembly, mail) must not hold an idle DB connection > 60s.
- Current DB user has full DDL/DML. Plan: deploy/migration user (full), app runtime user (SELECT/INSERT/UPDATE/DELETE;
  DELETE needed for Laravel jobs/sessions/cache tables only), MCP user SELECT only.
- Local MCP: iFastNET_MCPSERVER at http://127.0.0.1:8765/mcp, profile "shop" (MariaDB), "webdav-demo" (WebDAV, not yet working).
- Collation test (2026-11-24): utf8mb4_unicode_ci and general_ci treat different CJK Ext-B chars as EQUAL (rare-char collision);
  uca1400_ai_ci distinguishes them. Never use unicode_ci / general_ci. utf8mb3 turns rare chars into '?'.
- Chinese ORDER BY sorts by code point (not stroke / zhuyin). No Mroonga / ngram plugin -> Chinese search uses LIKE.
- MCP execute_maria_db_sql shows some CJK result strings garbled (MCP-side decoding); HEX confirms stored bytes are correct UTF-8.

## Hosting Path Facts (verified 2026-11-24)

- Web Disk root "/" = public web folder https://www.<domain>/mescits/ (inside public_html). Publicly served, Apache directory listing ON.
- NEVER deploy the Laravel app root (.env, storage, vendor, config) into this Web Disk root.
  Plan: app core outside public_html (second Web Disk account or changed Web Disk dir); only Laravel public/ contents in public_html/mescits.
- Customer attachments must be stored outside public_html.
- WebDAV profiles: webdav-demo = public_html/mescits (public, only Laravel public/ contents); webdav-app = app core dir (not web-reachable, verified by 404 probes 2026-11-24).

## Host PHP Facts (verified by self-deleting probe script, 2026-11-24)

- PHP 8.5.6, SAPI litespeed (LiteSpeed, Apache-compatible .htaccess), date.timezone UTC.
- upload_max_filesize 512M, post_max_size 800M, max_execution_time 60 (web), max_input_time 300, memory_limit 512M.
- open_basedir empty, disable_functions empty (proc_open/exec/symlink/putenv OK). fastcgi_finish_request absent (LiteSpeed has litespeed_finish_request).
- Extensions: all Laravel needs present; only sodium missing (Laravel default bcrypt/openssl -> OK; do not use sodium-based features).
- Outbound SMTP OK (587/465 to common providers). Disk free ~774 GB.
- Paths: public = /home/<user>/public_html/mescits (webdav-demo); app core = /home/<user>/app/mescits (webdav-app, outside public_html, PHP read/write OK).
  public/index.php must require __DIR__.'/../../app/mescits/vendor/autoload.php' and .../bootstrap/app.php.
- PDO on host (CloudLinux alt-php85): MUST use extension nd_pdo_mysql (mysqlnd). The default pdo_mysql.so links libmariadb 10.5.5 and is ABI-broken with PHP 8.5
  (corrupted rows, all values strings, wrong column meta). Switched in cPanel 'Select PHP Version' > Extensions on 2026-11-24. Verified native prepares OK, ints typed.
  If prod shows empty fields / 'not found' / wrong password again, first check PDO::ATTR_CLIENT_VERSION starts with 'mysqlnd'.
- CLI /usr/local/bin/php follows the PHP Selector: 8.5.6 with mysqlnd (cron OK).

## Build / Deploy (2026-11-24)

- Dev machine: PHP 8.5.13 NTS (winget), Composer at %LOCALAPPDATA%/cits-tools. Bash helper: `. /tmp/env.sh` (recreate if missing).
- Tests: `php artisan test` (Pest, SQLite memory). Local demo data: `php artisan db:seed --class=DemoSeeder` (password 123456).
- Deploy: deploy/README.md. Prod schema changes = SQL file executed by the user (AI never alters prod DB).
- Auth rules: password min length from system_settings password.min_length (6); login throttle; HTTPS forced in production.

## Collaboration rules (2026-11-26)
- Needinfo: flag only (no ticket status change); answered by client comment or saveEdit; reminder cits:needinfo-remind daily 01:30 (needinfo.remind_days).
- Watchers: every ticket/issue notification goes through WatchService (internal link /pm/tickets/{id}, client link /tickets/{id}); never the actor, never non-members; clients cannot watch issues.
- Client progress: lookup client_progress; slowest non-excluded linked issue decides; never expose internal issue numbers/status names to clients.
- PM ticket list scope param tt = client_tab code or all; only_action applies to the default scope only. Client close notifies acceptor + ticket.settle + watchers.
- Packages: many issues per package; batch add only DEV/TEST open issues; released package frozen; released issue cannot leave.
- Dependencies: blocks kind gates PM->DEV dispatch until upstream is released/excluded; no cycles; test-origin issues cannot be blocked.

## Production cron (2026-11-26)
- cPanel cron every 5 minutes: */5 * * * * /usr/local/bin/php /home/cpuser/app/mescits/artisan schedule:run >> /dev/null 2>&1
- Consequence: everyMinute tasks (send-outbox, queue:work) run every 5 min (mail delay <= 5 min). Any daily/at schedule MUST use a minute divisible by 5, otherwise it never fires.

## Public repo placeholders (2026-11-26)
- Tracked files use placeholders: example.com (site/mail domain), cpuser (cPanel user), cpuser_mescits (DB name/user), C:\Users\<you> (local paths).
- Real values: deploy/private.local.md and deploy/deploy.local.json (both git-ignored; build.py reads the json or CITS_* env vars). Never commit real domain/user/paths again.
