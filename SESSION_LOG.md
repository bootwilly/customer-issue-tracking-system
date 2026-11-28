# SESSION_LOG

## 2026-11-24

### Handled

- Analyzed project folder. Filled PROJECT_CONTEXT.md skeleton.

### Analysis

- Folder only has AGENTS.md, GEMINI.md, PROJECT_CONTEXT.md, PROJECT_ARCHITECTURE.md, SESSION_LOG.md.
- No source code, no .sln/.csproj, not a git repo.
- User confirmed this is a NEW project.

### Fixes

- PROJECT_CONTEXT.md filled from AGENTS.md rules (BLL/DAL boundary, no hardcoding, read-only DB). Tech stack inferred as C#/.NET, marked TBD.

### Verification

- None (no code).

### TODO

- Confirm tech stack, DB engine, UI type.
- Fix PROJECT_ARCHITECTURE.md title ("WillyStock").
- Create .editorconfig (UTF-8 BOM, CRLF for .cs).
- git init (needs user approval).

### Update (same day)

- User confirmed stack: PHP 8.5 + MariaDB 11.4.10. PROJECT_CONTEXT.md updated (C#/.NET inference removed).
- User confirmed Laravel + Blade. PROJECT_CONTEXT.md updated with Laravel layer mapping (Eloquent/Query Builder only in Repository).
- Still TBD: Laravel version, test framework, auth approach, .editorconfig.

### Notes

- Rule files must stay ASCII-only.

### Update: system planning (2026-11-24)

- Produced full plan (no code). Published as private artifact page "CITS Plan".
- Key decisions proposed (pending user confirm):
  - Split client_tickets (client-visible) and issues (internal), N:M via ticket_issue_links.
  - Workflow statuses/transitions as config tables, not hardcoded.
  - Client edit lock: SELECT FOR UPDATE + lock TTL + heartbeat + row_version.
  - Revisions in ticket_revisions (PM accepts). Optional MariaDB system-versioned tables for audit.
  - events table = single source for timeline; comments have visibility (client/internal).
  - spatie/laravel-permission teams = per-project roles; policies check permissions only.
  - Editor: TipTap (MIT); pasted images uploaded, not base64; drafts in localStorage + editor_drafts; server-side HTML sanitize.
  - MCP via laravel/mcp, read-only tools, Sanctum token, reuse Service layer.
  - Stack: Laravel 13 (verify PHP 8.5), Livewire 3 + Alpine, Fortify, Pest.
- Open questions: who sets "released"; Test new-found goes via PM or direct to DEV; client View rights; revision approval; N:M ok; SLA; unfinished sentence in req 4.7.
- Requirement conflicts found: 4.3 says 3 tabs but lists 4; (C) two "issue type" fields; no type for client "requirement".

### Update: user decisions (2026-11-24)

- Recorded confirmed business rules in PROJECT_CONTEXT.md (Business Rules section). Artifact page updated to v2.
- New open questions: SLA calendar vs working days / holidays / per project; SLA for other stages and auto-close;
  reopen -> new issue or reopen old; cancelled linked issues vs ticket status; client cancel cascade;
  DEV must ack revision?; severity of Test new-found; client accounts see each other's tickets?;
  hosting OS + cron + queue worker; SMTP / AD-LDAP; MES menu source; attachment types and size limit.

### Update: v3 decisions (2026-11-24)

- Designed: chain lock (dispatched_at), settlement rule with is_excluded + needs_pm_action, reopen via new issue,
  timeline view replacing SLA, no-cron strategies (outbox, draft-scoped temp image cleanup, probabilistic GC),
  mes_pages registry, chunked 100MB upload. Artifact page v3. PROJECT_CONTEXT Business Rules extended.
- Open: iFastNet plan + phpinfo limits; client PM comment after dispatch?; client cancel after accept but before dispatch?;
  severity edit = creator Test or any Test?; who maintains mes_pages; WebForms or MVC / Web.sitemap.

### Update: v4 (2026-11-24)

- User confirmed paid iFastNet with cPanel cron (screenshot). Switched design back to database queue + cron schedule:run.
- Added: client supplement comments design, SMTP admin toggle + encrypted settings + test mail, severity creator-only,
  mes_pages editable by all internal users, Web.sitemap import with dry-run. Artifact page v4.
- Open: phpinfo values (PHP ver, upload/post limits, exec time, memory, extensions, MariaDB ver, outbound SMTP);
  cron min interval; who replies to client (PM only?); client comments immutable ok?; per-user email opt-out?

### Update: iFastNET_MCPSERVER probe (2026-11-24)

- Local MCP at http://127.0.0.1:8765/mcp (Kestrel, C# SDK) responds; 14 tools listed.
- test_maria_db_connection / list_maria_db_tables / browse_remote(webdav) all fail; SDK hides exception message.
- Network OK: 3306, 2078 reachable; WebDAV TLS cert valid (*.domain, LE). PROPFIND without auth -> 401.
- Suspects: ini dbname has doubled first letter vs cPanel user prefix; cPanel Remote MySQL IP whitelist; DB user privileges; Web Disk creds.
- Note: execute_maria_db_sql allows DDL/DML. AI must still use SELECT only (AGENTS rule). Recommend read-only DB user for MCP.
- Retest after user fixed grants + ini dbname typo: MariaDB connection OK, 11.4.10, DB empty (0 tables). Facts recorded in PROJECT_CONTEXT. Artifact v5.
- WebDAV (webdav-demo) now works: browse_remote "/" OK, directory empty. Web Disk physical root unknown (ask user). Write not tested.
- DB default charset still latin1 (user has not run ALTER DATABASE yet).
- WebDAV write OK: uploaded /ping.txt (34 bytes) via upload_items. Not reachable at https://<domain>/ping.txt (404) -> Web Disk root is likely NOT the main public_html (still unconfirmed). /ping.txt left on server; user to delete or ask.
- Verified utf8mb4 CJK behavior on server; chose utf8mb4_uca1400_ai_ci (unicode_ci collides rare chars). Recorded in PROJECT_CONTEXT.
- FOUND: Web Disk root is public_html/mescits, served at https://www.<domain>/mescits/ with directory listing ON. ping.txt publicly readable.
  Deployment plan changed: split app core outside public_html; add Options -Indexes.
- Uploaded public_html/mescits/.htaccess (Options -Indexes) via WebDAV; listing now 403. ping.txt still present: MCP has no delete tool, user deletes manually.
- Verified DB default now utf8mb4 / utf8mb4_uca1400_ai_ci. Second Web Disk (mescits_app) creds: user adds [webdav-app] to connections.ini himself; never paste passwords in chat.
- webdav-app profile added by user. browse OK, upload probe.txt OK, isolated from webdav-demo, 6 candidate URLs all 404.
  Black-box test only (cannot prove physical path); user to confirm Web Disk dir in cPanel. probe.txt + ping.txt left for manual delete.
- Ran self-deleting probe scripts on host: PHP 8.5.6 LiteSpeed, limits OK, outbound SMTP OK, app dir = ~/app/mescits (outside public_html).
  Cleaned up ping.txt, probe.txt and probe scripts (all confirmed deleted / 404). Facts in PROJECT_CONTEXT.

### Update: dev plan (2026-11-24)

- Local toolchain: node 24, npm 11, git 2.55 present. NO php, composer, mariadb locally (blocker for artisan/tests).
- Proposed token-saving dev plan: compact spec docs as single source for subagents; interfaces/migrations first (main);
  core engine tests-first; parallel Sonnet subagents on non-overlapping module folders; Haiku for chores; short reports; phase-per-session.
- Pending user decisions: install PHP/Composer (Herd), git init, test DB, write-permission scope for subagents,
  .histories granularity, who runs migrations (AI is SELECT-only on DB).

### Update: build night (2026-11-24 ~03:00-)

- User authorized start + deploy + page review, then went to sleep. New rules: admin password random 8 digits
  (in ADMIN_PASSWORD.local.txt, git-ignored); user passwords min 6 chars no complexity; HTTPS mandatory for login.
- Done: W0 skeleton, W1 schema/seed, W2 core engine (52 tests green incl. AUTH 27, EDITOR 9, core 14).
- Frontend decision: Blade + Alpine + Tailwind (Livewire dropped).
- Prod DB schema is NOT touched by AI: deploy/out/install.sql for user to run in phpMyAdmin.
- Prod .env gets DB_PASSWORD placeholder; user fills it (AI does not handle passwords).
- See .histories/W0-W2-*.md for file list.
- All modules built by subagents (AUTH, EDITOR, CLIENT, PM, DEVTEST). Full suite 73/73 green.
- Local visual review done (client list/detail/create, PM index, issue detail, timeline, MES): fixed column wrapping + banner.
- DEPLOYED code to host. Login page live over HTTPS. Blocked on user: install.sql, DB_PASSWORD in .env, cron. See deploy/README.md.
- Known TODO (hardcoded values to move to lookups/flags): ClientQueryService ANOMALY_TYPES + tab labels,
  comment kind 'reply' exclusion (client/PM views), TestService BOUNCED_STATUSES, origin label map in PM views,
  stage literals 'DEV'/'TEST' in IssueService (rework + set_due). Timeline shows internal actor names to clients (decide).
- Not done yet: SMTP settings admin page (mail.* keys exist; OutboxService reads them), Web.sitemap import, MCP (laravel/mcp),
  revision diff view, reports. laravel/boost (from cits/CLAUDE.md skeleton) intentionally NOT installed.
- No git commits made (user rule).

## 2026-11-24 Login form moved to "/"
- Guests now get the login form at "/" (HomeController). GET /login only redirects to route('home'); POST /login unchanged.
- Gotcha: Route::redirect('/login','/') and redirectGuestsTo('/') with a string go to the DOMAIN root on the /mescits sub-path deploy. Always use route()/closures for redirects.
- All middleware/logout redirects use route('home'). Tests 73/73. Deployed 7 files to webdav-app, verified on production.

## 2026-11-24 Handover doc
- Wrote HANDOVER.md (root): status, stack, layout, hard rules, 41 tables, workflow graphs, settlement/chain lock/edit lock,
  roles, 79 routes, service API, settings, local dev, deploy (full + single-file + schema change), host pitfalls, debt list, tests.
- NEW suspected bug: web.php `Route::redirect('/', '/admin/users')` same sub-path problem as /login (unverified, not fixed).
- deploy/README.md says `python deploy/make_install_sql.php` -> should be `php`.
- PROJECT_CONTEXT.md top sections are stale (still say "no source code yet").

## 2026-11-24 Prod login failure = PDO native prepares broken on host
- Symptom: correct admin password rejected. DB data verified correct via MCP.
- Diag (temp log, shape only) showed every string column of the fetched User was empty.
- One-shot self-deleting probe: native prepares (EMULATE_PREPARES=false) return corrupted/shifted rows; emulated = correct.
  Host: PHP 8.5.6, PDO client 10.5.5, MariaDB 11.4.10-cll-lve.
- Fix: config/database.php mariadb options PDO::ATTR_EMULATE_PREPARES => env('DB_EMULATE_PREPARES', true). Diag removed. Deployed. 73/73.
- Earlier prod requests with bound params (settings reads) also returned garbage -> silently fell back to defaults. No writes affected (no prod writes yet).

## 2026-11-24 Sub-path fixes (/admin 404 + editor API)
- /admin used Route::redirect -> domain root 404. Fixed with route().
- Editor JS fetch('/uploads/...','/drafts') hit domain root: added meta app-base + base() prefix in api().
- Upload response url now route('attachments.show') (absolute; content stores absolute URLs -> domain change needs rewrite).
- Remaining relative paths OK: redirect('/x') and url('/x') resolve with base path; notification url stored relative and wrapped by url() in mail.

## 2026-11-24 Root cause: host pdo_mysql ABI-broken -> switched to nd_pdo_mysql
- Ticket "not found" after create: emulated prepares on libmariadb pdo_mysql return all strings; strict int compares fail. Column meta also garbage.
- User switched extension pdo_mysql -> nd_pdo_mysql in CloudLinux PHP Selector. Verified mysqlnd 8.5.6, typed ints, native prepares OK, CLI cron PHP 8.5.6 mysqlnd.
- Reverted EMULATE_PREPARES and TypedMariaDbConnection registration. Unused files kept pending user OK to delete:
  app/Database/TypedMariaDbConnection.php, tests/Feature/TypedConnectionTest.php. Tests 74/74. Deployed.

## 2026-11-24 Removed TypedMariaDbConnection files (user OK). Prod copy /app/Database/ remains (no MCP delete), unused.

## 2026-11-24 Internal users browse all internal pages
- View = internal + issue.view; actions keep own permissions. PM index/ticket show opened, MES index canView, nav unified. canAck added for ack button. Tests 74/74. Deployed.
- Not changed: client page /tickets stays client-only (internal see tickets via /pm/tickets/{id}). Admin stays admin-only.

## 2026-11-25 Admin can browse /tickets read-only
- Nav shows the client tickets link for admin; create limited to client accounts; admin view does not write comment_reads. Tests 75/75. Deployed.

## 2026-11-25 P2 batch (4 parallel subagents + main)
- Built: SMTP settings + test mail, bell/notifications, forgot/reset password, personal settings (email_notify), reports + CSV, MCP (/mcp, tokens),
  Web.sitemap import (dry-run), revision diff. Removed hardcoded values via new lookup categories (see .histories/p2-batch-*).
- New tool deploy/make_upgrade_sql.php -> deploy/out/upgrade-20261124-1635.sql (2 migrations + INSERT IGNORE lookups/settings). User must run it BEFORE deploying new code
  (new code needs stage_role/settle_tab/... lookups and users.email_notify).
- laravel/mcp added -> full redeploy via build.py required.
- Tests 112/112. git repo existed (empty, branch master); no commit made (needs user OK).
- Known: ticket_revisions does not version type/category/page (diff shows current values). MCP 429 path untested. Tests still SQLite only.

## 2026-11-25 Removed prod leftover app/Database/TypedMariaDbConnection.php (+ empty dir) via one-shot self-deleting script (user request). Verified gone, script 404.

## 2026-11-25 P2 deployed
- User ran upgrade-20261124-1635.sql; verified email_notify, api_tokens(+FK), 2 migration rows, 8 lookup categories (36 lookups).
- Full deploy: app.zip (7113 files, .env kept, zip+extractor self-deleted) + public/. Smoke: / 200 (forgot link), protected pages 302 to /mescits/, /password/forgot 200, POST /mcp 401 without token. No new errors in prod log.

## 2026-11-25 Excel export for client/PM/DEV/Test lists
- xlsx via openspout; list sheet + detail sheet (two-way links) + info sheet; same filter builder as list pages (repositories share filtered()).
- Columns from lookup export_<list> (whitelisted in code); data.export permission (all roles); export.max_rows adjustable at /admin/settings/export.
- Host probe: zip/xmlwriter OK, /tmp writable. Upgrade SQL deploy/out/upgrade-20261124-1722.sql (INSERT IGNORE only). After deploy: reset spatie permission cache (new permission).
- Debt: DEV/Test tab labels duplicated as constants in IssueExportService (copied from blade); sort label shown as code in PM info.
- Tests 134/134.

## 2026-11-25 Excel export deployed
- Verified upgrade data (export lookups 86, max_rows, data.export on 5 roles). Full deploy (7335 files, .env kept). Permission cache cleared via one-shot script. Export routes 302 for guests.

## 2026-11-25 Flowchart (MD import) feature spec
- Spec written to docs/flowchart-feature.md. Decided: view = all project members (new flow.view on 5 roles), edit = client_pm + internal_pm (flow.manage).
- Render client-side with mermaid (strict), server stores raw MD + revisions. Known pitfall: vite base must include /mescits (ASSET_URL) or mermaid dynamic chunks 404.
- Not implemented yet. npm install mermaid was blocked by the auto-mode permission check; needs user OK.

## 2026-11-25 Flowchart feature implemented (not deployed)
- /flows: list, MD upload -> preview (browser mermaid.parse, save disabled on error) -> store/update (new version + revision), ?v= old versions, download .md, sort, toggle.
- Permissions flow.view (5 roles), flow.manage (client_pm, internal_pm). Settings flow.max_bytes, flow.max_blocks.
- SanitizerService::cleanMarkdown added (symfony default max input 20000 chars; ticket clean() still has that limit - possible existing truncation of long tickets, not verified).
- build.py now builds with ASSET_URL=/mescits (needed for mermaid dynamic chunks). Local public/build is rebuilt without it after verification.
- Tests 143/143. Browser verified on temp sqlite. Upgrade SQL: deploy/out/upgrade-20261125-0531.sql. Suggestions A-E in docs/flowchart-feature.md not implemented.

## 2026-11-25 Flowchart feature deployed
- User ran upgrade-20261125-0531.sql; verified by SELECT: 2 tables, migration row, flow.max_bytes/max_blocks, flow.view on 5 roles, flow.manage on client_pm/internal_pm.
- mermaid pinned ~11.17.2 (package.json + lock). Full deploy: 7349 files, .env kept; public/ 101 files (upload_directory client timed out at 600s but server kept going; verified all 97 build assets 200 and manifest hash matches).
- Permission cache cleared (one-shot script, self-deleted, 404 after). Smoke: /flows 302 for guests, mermaid chunk 200 under /mescits/build. No new prod log file.
- Tip: public/ now ~100 files; upload takes >10 min. Verify by HEAD-checking build assets instead of trusting the client result.

## 2026-11-25 Flowchart online editor (draw.io) decided, schema applied
- Decided: self-hosted draw.io (jgraph/drawio v32.7.4, pinned, static webapp under public/drawio, stealth=1) for online create/edit; mermaid MD import kept, both formats coexist. Format fixed per flow.
- Storage: editable SVG (xmlsvg, compressXml=false) in source_md (column not renamed). Display via <img> + CSP sandbox + panzoom for both formats. Single page only (pages=0, server rejects >1 <diagram>). No in-diagram links for now.
- User ran SQL; verified by SELECT: project_flows.format/search_text, project_flow_revisions.format, migration 2026_11_27_000100_add_format_to_project_flows (batch 5), lookup flow_format (drawio, mermaid), setting flow.drawio_max_bytes=5000000. Prod has 0 flows.
- Not implemented yet. Local migration file must match this exact DDL.

## 2026-11-25 Flowchart online editor (draw.io) implemented (not deployed)
- public/drawio = draw.io v32.7.4 static (3075 files, 115MB; WEB-INF/META-INF/*.map removed), PreConfig.js patched (self base URL, stealth+lockdown). Version + sha256 in public/drawio/CITS_VERSION.txt.
- New: DrawioParser, FlowService formats()/parseDrawio()/prepare(), optimistic lock (base_version + lockForUpdate), routes flows.create/edit/image, editor.blade.php, js flow/editor.js, flow/view.js, flow/panzoom.js (own code, no dependency). Search ?q= on title + search_text.
- Pitfall found: draw.io SVG uses color-scheme light dark + light-dark(): dark-mode OS renders black boxes in <img>. Parser pins root to color-scheme: light.
- Pitfall: embed export still writes compressed pages despite compressXml config; parser decodes both.
- build.py: public/ excludes drawio; --drawio builds drawio.zip + xd_*.php extractor (public dir).
- Tests 157/157 (FlowTest 23). Browser verified on temp sqlite: create, re-open, autosave draft, v2, 422 conflict, zoom/pan, no external requests.

## 2026-11-25 Flowchart online editor (draw.io) deployed
- build.py --drawio: app.zip 8.7MB (7355 files written, .env kept), drawio.zip 44.6MB -> public/drawio (3076 files, v32.7.4), both extractors and zips self-deleted (404 after).
- public/build: only the 41 new assets + manifest uploaded (diff against remote listing, fast); all 98 manifest assets HEAD 200, manifest md5 matches.
- Smoke: /flows, /flows/create, /flows/{id}/edit, /flows/{id}/image 302 for guests; /drawio/index.html loads editor under /mescits/drawio, base URL correct, stealth+lockdown on, 12 resources all same-origin, no 404; /drawio/WEB-INF 404. No new prod log file.
- Tip: upload only new build assets (browse_remote /build/assets diff) instead of upload_directory of public/.

## 2026-11-25 Flow v2 + project admin planned
- Plan in docs/flow-v2-and-project-admin.md: draft vs publish (save overwrites one draft, publish = new version with change note), flow status draft/effective/obsolete (lookup flow_status + flow_status_viewable), per-flow editors table, soft delete + trash, new internal role project_admin (project.members, project.trash, flow.publish, flow.delete) with anti-escalation limits. Decisions D1-D7 pending.
- Nav: flowchart moved before client tickets (layouts/app.blade.php), not deployed yet.
- Prod has 1 flow (drawio, v2, active) -> will map to effective v2.

## 2026-11-25 Flow v2 + project admin implemented (not deployed)
- Decisions D1-D7 = recommendations. Draft/publish split: save overwrites one draft (draft_rev + snapshots, flow.draft_keep), publish = new version with required note. version 0 = never published.
- Status via workflow scope flow (draft/effective/obsolete; obsolete terminal = no editing). Viewer-visible statuses: lookup flow_status_viewable. Badge colour via client_tab (ticket-only usage elsewhere, checked).
- Per-flow editors (project_flow_editors, membership checked at query time). Soft delete + trash; purge needs exact title, event keeps title + version count.
- Role project_admin (flow.view/manage/delete, project.members, project.trash). Cannot grant/revoke lookup role_admin_only roles, cannot touch members holding them or itself. Pages /project-admin (members, trash, events with lookup event_label).
- Nav: flowchart first, project admin entry. make_upgrade_sql.php now emits workflow statuses/transitions and roles (NOT EXISTS, roles.project_id NULL).
- Tests 164/164. Browser verified: editor save -> draft (v1 unchanged), publish v2 with note, project admin pages.
- Upgrade SQL deploy/out/upgrade-20261125-1446.sql (user runs). Deploy: clear permission cache + cits.workflow_status_map cache.

## 2026-11-25 Flow v2 + project admin deployed
- Verified upgrade-20261125-1446.sql by SELECT: 9 project_flows cols, revisions.note, 2 new tables, migration row, role project_admin + 5 grants, flow statuses 3 / transitions 3, 18 lookups, flow.draft_keep; existing flow 1 = effective v2.
- app.zip 8.8MB (7365 files, .env kept), extractor self-deleted. Build: only 1 new asset (app css) + manifest; manifest md5 matches.
- One-shot script: permission cache + cits.workflow_status_map cleared, self-deleted (404 after).
- Smoke: /flows*, /project-admin/* 302 for guests; drawio 200; no new prod log file.
- Next for user: grant project_admin to someone in admin > project members.

## 2026-11-25 Flowchart link moved to header
- layouts/app.blade.php: flowchart link removed from nav row, now right after the project switcher in the top bar (shown when a project is selected and user has flow.view). FlowTest nav test updated. Tests 164/164. Not deployed.
- Deployed 2026-11-25: app.zip (7365 files, .env kept, extractor self-deleted); no new build assets (CSS hash unchanged), manifest md5 matches, 98 assets 200. No new prod log file.

## 2026-11-25 Rich-text editor tables (not deployed)
- @tiptap/extension-table ^3.35.0 (TableKit, resizable off). Toolbar select "table...": insert 3x3 with header, add/delete row/col, toggle header row, merge/split, delete table (in-table options disabled outside a table).
- SanitizerService::clean(): table/thead/tbody/tr + th/td (colspan, rowspan only; styles/colgroup dropped). Max input raised 20000 -> 2,000,000 for ticket/issue content too (fixes the silent truncation noted 2026-11-25).
- HtmlToText (Excel export): table row -> one line, cells joined by " | ". app.css: .prose table styles.
- Tests 167/167 (EditorTableTest). Browser verified insert table + add column on /pm/issues/create.

## 2026-11-26 Editor drawing area (not deployed)
- resources/js/editor/drawing.js: full-screen overlay with self-hosted draw.io (embed JSON). Start from selected images (stacked vertically, locked: style locked=1, data URI images) or re-open a drawing (load xmlpng). Annotation defaults via configure: red stroke, no fill, straight red arrows. Save = export xmlpng (PNG with tEXt mxfile) -> /uploads/image -> replaces the selected images at the first one's position (text in between kept; original screenshots stay as attachments).
- editor/index.js: CitsImage (attr drawing -> data-drawing="1"), toolbar "drawing" button (hidden on mobile), double-click a drawing to re-open. Do not use instanceof NodeSelection here (failed across bundles): pass {from,node} or duck-type sel.node.
- Sanitizer: img keeps data-drawing. Upload pipeline stores files byte-for-byte, so the embedded diagram survives.
- Tests 168/168. Browser verified: two screenshots -> circle on each + connector arrow -> save -> one composite image, double-click re-opens with both screenshots and shapes editable.
- Deployed 2026-11-26 (tables + 2M sanitizer limit + drawing area): app.zip 7366 files (.env kept, extractor self-deleted); new assets app css + editor index js, manifest md5 matches, 98 assets 200. No new prod log file.

## 2026-11-26 Drawing image picker (not deployed)
- The drawing button now opens a picker with thumbnails of every image in the content (doc order, images inside the current selection pre-ticked); ticked images go onto one canvas, "blank canvas" for none; ticking exactly one drawing re-opens it. Double-click on a drawing still re-opens directly.
- Browser verified: 3 images, tick 1+2 -> canvas with 2 images -> save replaces only those two; tick only the drawing -> re-edit with shapes.
- Deployed 2026-11-26 (drawing image picker): app.zip 7366 files, new editor assets, manifest md5 matches, all assets 200, no new prod log.

## 2026-11-26 Lookup admin (process_type) implemented (not deployed)
- System admin > lookup options page (/admin/lookups/{category}, admin only). Editable categories from lookup lookup_editable (only process_type now). Add (auto code c<base36 time><rand>), rename, up/down reorder (renumber 10,20..), enable/disable (keep >=1 enabled), delete only when unused (usage via LookupAdminRepository::USAGE table/column map).
- Fixes for disabled items: issue edit select keeps current disabled value with a "(disabled)" suffix; PmQueryService::editableInput rejects picking a disabled/unknown process_type (keeping current allowed); issue export uses WorkflowService::lookupMapAll.
- DatabaseSeeder: editable categories use firstOrCreate (no overwrite of UI edits); others still updateOrCreate.
- No audit trail: events.project_id is NOT NULL and lookups are global (deviation from plan).
- Prod needs 1 row: INSERT IGNORE lookup_items (lookup_editable, process_type, <process type label>, 10, 1).
- Tests 172/172 (LookupAdminTest 4). Browser verified add on /admin/lookups.
- Deployed 2026-11-26 (lookup admin): verified lookup_editable row by SELECT; app.zip 7371 files, extractor self-deleted, manifest md5 matches, no new prod log.

## 2026-11-26 User manual (docs/manual)
- Added role-based manual (10 chapters, Traditional Chinese), state diagrams, troubleshooting, screenshots; build.php -> dist/manual.html, build-pdf.mjs -> dist/CITS-manual.pdf. No app code changed. Ops/deploy manual deferred by user.
- Screenshots: local php -S + throwaway SQLite (DemoSeeder + extra states); production not accessed. Local public/build is built with ASSET_URL=/mescits (mermaid chunks 404 locally without a rewrite).
- Online doc destination not chosen yet.

### Known issues found while documenting (not fixed)
1. User with only role project_admin: login redirects to /pm -> 403 (reproduced locally).
2. dev_doing -> test_pending never sets assignee_test_id: Test "mine" filter misses those issues and testers get no notification.
3. DEV-created issue has no assignee_dev_id, so it is hidden by the default "mine" filter on /dev.
4. Dispatch does not reject when a client holds a valid edit lock (IssueService::chainLock just clears it); contradicts Business Rules in PROJECT_CONTEXT.
5. Ticket links in notifications / timeline send internal users to the read-only client view /tickets/{id}, not /pm/tickets/{id}.
6. Minor: dev/test list "source" column shows raw origin code (client); member pages show role codes instead of names.
7. HANDOVER.md workflow graph is stale (new/discussing -> test_pending requires assignee_test_id).

## 2026-11-26 Online manual /manual deployed
- Login required; source = docs/manual (Markdown), audience per chapter in manual.json; client accounts never get <!-- internal --> blocks or screenshots only those blocks reference.
- Tests 176/176. Local browser check OK (client 6 chapters, staff all but admin chapter).
- Deployed by WebDAV: 65 new files (resources/manual etc.) -> routes/modules/manual.php -> layouts/app.blade.php (prod copy differed only by the new link). upload_directory client timed out at 600s again, server completed; sizes verified. No front-end rebuild (reuses flow/index.js).
- Smoke: /manual* 302 for guests, / 200, no new prod log. Logged-in view on prod not verified.
- Manual text update: edit docs/manual/*.md, upload to app resources/manual/ (or full build.py).

## 2026-11-26 Meeting records implemented (not deployed)
- Plan: docs/meeting-system.md (user decisions D1-D5 + E1-E7 = recommendations). No video, no email yet.
- Visibility: internal users see all project meetings; clients only customer_visible ones. Internal-created: shared via meeting.share toggle (unshare logged, hides client comments with the meeting). Client-created: always shared, content never held back.
- Shared internal meeting: clients see header + item topics; discussion/conclusion/notes after a status with is_released (done) is reached (published_at). internal_html + internal comments never reach clients.
- Edit: meeting.manage, or meeting.create + (creator or attendee); clients only on client-created meetings. Cancel/re-open: creator or manager. Delete: meeting.delete (project_admin) -> project trash (restore / purge with exact title).
- Status scopes meeting (scheduled/recording/done/cancelled) and meeting_item (open/done); lookups meeting_type (editable in admin lookup page), meeting_initial, meeting_item_initial, event_label meeting_*.
- Items autosave via JSON (resources/js/meeting/items.js, vanilla: Alpine starts before page modules). Enter in topic = next/new row. Issue link by issue_no (internal only). Issue page: meeting items block + "add to meeting" form.
- Next meeting: copies type/title/place/attendees/duration, carries non-terminal items (carried_from_id). .ics download. Comments: plain text, one reply level, internal flag inherited by replies, own edit/soft delete; deleted text visible to meeting.delete holders. Unread count via meeting_reads.
- Attachments: owner types meeting (client allowed only when open to clients) and meeting_internal (never clients).
- Tests 185/185 (MeetingTest 9). Browser verified locally: create (start now) -> autosave items, Enter adds row, reorder persists, notes, done; client view hides internal notes/comments.
- Prod needs deploy/out/upgrade-20261126-0001.sql (5 tables + workflow/lookup/permission rows). After deploy: clear permission cache + cits.workflow_status_map cache.
- 2026-11-26 prod check after upgrade-20261126-0001.sql: tables, migration row, meeting statuses/transitions, lookups, grants OK, BUT workflow_statuses/transitions.scope is varchar(10) and prod sql_mode '' silently truncated 'meeting_item' to 'meeting_it' (SQLite tests never see this).
- Fix: migration 2026_11_29_000200_widen_workflow_scope (scope -> varchar(30) on both tables + UPDATE 'meeting_it' -> 'meeting_item'). SQL deploy/out/upgrade-20261126-0007.sql. New SeedDataLengthTest compiles all migrations as MariaDB DDL (pretend) and checks workflow.php values against real varchar sizes. Tests 186/186. Not deployed (waiting for the user to run the fix SQL).
- Deployed 2026-11-26 (meetings): verified by SELECT scope varchar(30) + 4 meeting_item rows fixed; migrations row 2026_11_29_000200 NOT present (user to insert; no runtime effect). app.zip 7454 files (.env kept, extractor self-deleted); new assets app css + meeting items js, manifest md5 matches, 99 assets 200. One-shot cleared permission + cits.workflow_status_map cache (meeting_item statuses = 2), self-deleted. Smoke: / 200, /meetings* 302 for guests; no new prod log file.

## 2026-11-26 Meeting: pick issues from the issue list (not deployed)
- Meeting page "add from issue list" dialog (internal editors with issue.view): search number/title, stage filter (workflow_statuses.stage values), open-only (non-terminal) default, multi-select kept across searches, max 100 rows. POST /meetings/{id}/items/issues adds items (topic = number + title, issue linked), skips issues already on the meeting.
- Issue page "add to meeting" now refuses an issue already on that meeting (was a duplicate item).
- No schema/SQL change. Tests 187/187. Browser verified: filter DEV, tick 2 across filters, add -> 2 linked items.
- 2026-11-26 PM board bulk "add to meeting" (not deployed): checkbox column on the internal issue list (current page, select-all), bar with open meetings the user can edit + "new meeting". POST /meetings/add-issues: existing meeting -> addIssues (skips linked); "new" -> /meetings/create?issue_ids[] prefill, store links them before typed topics (internal users with issue.view only). Row click still opens the issue. No SQL. Tests 188/188. Browser verified: tick 2 -> new meeting with 2 linked items; tick 2 (1 already there) -> existing meeting adds 1.

## 2026-11-26 Manual: meeting chapter (not deployed)
- Added docs/manual/11-meetings.md + 10 screenshots; updated README, 01, 08, 09, 10, manual.json; rebuilt dist. ManualTest 4/4; client render hides internal blocks/screenshots.
- Prod views of /meetings and /pm lack the issue picker and bulk "add to meeting" (checked by download); the chapter documents them, so deploy that feature together with the manual.
- Found, not fixed: meetings/_item.blade.php combines w-full with w-auto, w-full wins, so status select/arrows wrap onto separate rows.

## 2026-11-26 System admin = full access
- is_admin already passed every permission via Gate::before; blockers were hard-coded user_type checks.
- Added User::seesInternal() (admin or internal) and User::actsAsClient() (admin or client). Access gates use these; isClient() is kept only for data semantics (home redirect, read receipts, Meeting::isClientOrigin, client-created meeting forced shared, notify targets, badges).
- Admin can now open/edit/cancel/close/reopen client tickets and also accept/reply/settle; project-admin page lets admin grant role_admin_only roles.
- ClientPagesTest updated (old "admin read-only" expectation removed). 189/189 pass. Not deployed.
- Note: admin who is not a project member still does not appear in member pickers (assignee/attendee/owner).

## 2026-11-26 Bugzilla comparison + suggestion doc (not implemented)
- Compared CITS with Bugzilla features (chat only, no code change).
- Wrote docs/bugzilla-inspired-suggestions.md: 6 proposals (needinfo flag, CC/watch, duplicate detection, saved queries, issue dependencies, tags) with priority, affected modules, risks, and open decisions for mainline.
- Key constraint recorded: client similar-search must never query internal issues.
- No schema change, no deploy, no git commit.

## 2026-11-26 Meeting manual + issue picker deployed; production incident (manual session) - READ BEFORE NEXT DEPLOY
- Supersedes the "not deployed" notes in the two entries above for: meeting issue picker, PM board bulk "add to meeting", meeting manual chapter.
- Code change by this session: only cits/resources/views/meetings/_item.blade.php ($box no longer carries w-full; textareas add w-full; issue_no w-28 -> w-40). Front-end rebuilt. Tests 188/188 before the concurrent edits.
- Deployed by WebDAV single files (no SQL): assets app-B64ulgKF.css, items-CtvsJNGG.js, manifest.json; MeetingController, PmController, MeetingService, MeetingRepository; routes/modules/meeting.php; meetings form/show/_item, pm/index; resources/js/meeting/items.js; resources/manual (01, 08, 09, 10, 11, README, manual.json, 11 meeting screenshots).
- INCIDENT 10:45:03-10:49:25 (+08): HTTP 500, "User::seesInternal() undefined" (18 log entries). Cause: the upload script read files from the live working tree while the "System admin = full access" change was being written, so new MeetingService / MeetingController / meetings form.blade.php went up without the new User.php. Guest-only smoke test (302) did not catch it.
- Fix: re-uploaded the three files with the pre-change calls (isClient()); written on prod 10:49:41, 10:50:04, 10:50:24. No log errors after 10:49:25. Logged-in pages on prod NOT verified; local re-test of the prod snapshot not finished.
- Prod now differs from the local tree: "System admin = full access" is NOT on prod (local MeetingService, MeetingController, meetings/form differ from prod). Deploy that change as one set (User.php first, then every caller). deploy/out/app.zip is older than prod.
- Next deploys: copy files to a frozen snapshot, run tests on the snapshot, upload from it; check no other session is editing; smoke test must include logged-in pages or the prod log afterwards.
- Open: manual does not describe the admin full-access behaviour yet; HANDOVER.md not updated; deploy/build.py ignore pattern "services.php" also drops config/services.php; 5 bugs found while writing the manual still unfixed.

## 2026-11-26 Deployed: system admin full access + manual 9.0 (frozen snapshot)
- Manual: docs/manual/09-system-admin.md new 9.0 "what admins can use" (both client + internal sides, ticket opened by admin shows admin, no read receipts, non-member admin not in pickers); 01-getting-started menu table drops "(read-only)" for admin. dist/manual.html rebuilt; PDF not rebuilt.
- Snapshot: scratchpad snap-20261126 (cits without vendor/node_modules/storage/public build+drawio, docs/manual, deploy/build.py), md5 baseline 404 files; re-checked unchanged before upload. vendor must be a real copy, not a junction (Pest resolves the real path and test files fail to bind TestCase). npm build (ASSET_URL=/mescits) in snapshot, tests 189/189 on snapshot, build.py --no-npm from snapshot.
- Uploaded: 72 new /build/assets (hashes differ from prod build), app.zip (7470 entries; extractor wrote 7463, env kept, zip + extractor deleted), then manifest.json + fonts-manifest.json. Manifest md5 matches, 99 assets 200. Prod User.php / MeetingService / TicketService / meetings form / manual 09 md5 = snapshot.
- Logged-in smoke: one-shot token script zs_*.php (self-deletes, deletes its session files) boots Laravel per request with a forged file session, GET only, prints status codes. admin: all 200 (/tickets/create 200); internal: 200 except /tickets/create 403, /admin 403, manual 09 404; client: internal pages 403, meetings/tickets/flows/manual 200. Needs SCRIPT_NAME=<app.url path>/index.php or every route 404s.
- Smoke-script side effect: first run kept one DB connection per app instance -> 14 x "max_user_connections" (1203) in prod log at 03:25:46 UTC, client column all 500; added $app['db']->disconnect() per request, rerun clean, no newer log entries. Do the same in future one-shot scripts.
- Prod log timestamps are UTC. Prod == local tree now for the admin-access set. Still open: migrations row 2026_11_29_000200 missing; build.py "services.php" ignore drops config/services.php; HANDOVER.md not updated; PDF manual stale.

## 2026-11-26 Known issues fixed (not deployed)
- 1 Landing: AuthService::landingRoute(user, pid) picks the first page the side + permissions open (pm / tickets / meetings / flows / project-admin, else manual). project_admin-only users land on meetings instead of /pm 403.
- 2 DEV -> test_pending now requires assignee_test_id (workflow.php + migration 2026_11_30_000100 updates the existing prod row, upgrade SQL only INSERT IGNOREs). IssueService: required assignees the issue already has are dropped from abilities targets and reused in transition (rework round does not ask again). Assignees are checked against userIdsWithPermission(dev.manage / test.manage).
- 3 DEV-created issue: assignee_dev_id = creator. Test-created: assignee_test_id = creator.
- 4 chainLock throws when a not-yet-dispatched linked ticket has a valid client edit lock (transaction rolls back) - matches the Business Rule. CoreFlowTest updated (old test expected lock-clearing).
- 5 Ticket notifications to internal recipients use /pm/tickets/{id}; timeline ticket link -> pm.tickets.show; /tickets/{id} redirects users that are not actsAsClient to the PM page (old notification rows).
- 6 Origin labels from lookup issue_origin (dev table, issue page had a hardcoded map); new lookup role_label for role names on both member pages.
- 7 HANDOVER.md workflow graph + required fields; manual 04/05/06/08/10 updated for the new behaviour (removed "known issue" notes).
- deploy/build.py: ignore_build_files skips packages/services/config/routes cache only in bootstrap/cache (config/services.php was never deployed).
- Tests 194/194 (new BugfixTest 5).

## 2026-11-26 Collaboration features implemented (not deployed, browser check NOT done)
- From docs/bugzilla-inspired-suggestions.md, decisions = doc recommendations: needinfo in own table (tickets only), clients watch tickets only, client similar-search sees same-project tickets only, controlled tags (lookup issue_tag, OR filter, internal only), blocks-only enforcement on PM->DEV dispatch.
- Migration 2026_11_30_000200_create_collab_tables: ticket_needinfo, ticket_watchers, issue_watchers, saved_queries, issue_tags, issue_dependencies.
- Services app/Services/Collab/{Needinfo,Watch,Similar,SavedQuery,Tag,Dependency}Service + repositories; CollabController + routes/modules/collab.php; views collab/_watch, _saved_queries, _similar.
- All ticket/issue notifications now go through WatchService (watchers added, actor excluded, internal -> /pm/tickets, client -> /tickets, non-members dropped, cap watch.max_recipients). Settlement too.
- New permissions ticket.needinfo (internal_pm), issue.tag (internal_pm/dev/test); lookups event_label needinfo_*/tags_changed/dependency_*, dependency_kind, dependency_blocking, dependency_free_origin, similar_exclude_ticket, lookup_editable issue_tag; settings needinfo.remind_days, similar.min_score, watch.max_recipients, saved_query.max_per_list. Command cits:needinfo-remind daily 01:30 UTC.
- Tests 201/201 (CollabTest 7, BugfixTest 5).
- TODO: browser check (scratch DB collab.sqlite seeded, data created), manual chapters for the 6 features, upgrade SQL (php deploy/make_upgrade_sql.php --applied=... incl. 000200 widen scope), HANDOVER, deploy via frozen snapshot.

## 2026-11-26 Client progress / PM sees client close / multi-issue packages (not deployed)
- User asked: (1) client cannot see progress after acceptance, (2) PM board cannot see client-closed tickets, (3) a package must take many fixes.
- (1) ClientProgressService + lookup client_progress (codes = ticket status / issue stage / released status). Slowest non-excluded linked issue decides; shown on client show (step bar) and index (column). No internal issue numbers exposed. ETA = latest release_planned_at of unreleased linked issues.
- (2) TicketService::close notifies acceptor + ticket.settle holders + watchers. PM ticket list scope param `tt` (client_tab code or all; WorkflowService::ticketScope*). only_action applies to the default scope only. Issue rows show linked ticket status badge.
- (3) IssueService addToPackage/removeFromPackage (DEV/TEST open issues; released package frozen; released issue cannot leave). dev/index lists package contents + batch add. Handoff select preselects the issue's package. Events package_set/package_unset.
- Tests: ProgressPackageTest (4). Full suite 205/205. Verified locally (PM scope, batch add, client progress via curl).
- Collab browser check: needinfo request, watch toggle, dependency add verified in browser; tags/similar covered by tests only.
- No new migration; new lookup rows ride on upgrade SQL INSERT IGNORE.
- TODO (unchanged): manual chapters for collab + these 3, upgrade SQL (include 2026_11_29_000200 row + 2026_11_30_000100/000200), deploy via frozen snapshot after user runs SQL.
- Edit-script gotcha: when old_text is a prefix of new_text, a rerun "skip if new present" check fails and inserts twice; use fixdup approach (replace b+b[len(a):] with b).
- Upgrade SQL generated: deploy/out/upgrade-20261126-0431.sql (re-runs harmless 2026_11_29_000200 DDL to add its missing migrations row; 2026_11_30_000100 data UPDATE; 2026_11_30_000200 6 collab tables; INSERT IGNORE lookups/settings/permissions incl. role_label, client_progress, event_label package_*, issue.tag, ticket.needinfo). Waiting for the user to run it. Manual chapters are being written by someone else.

## 2026-11-26 Deployed: known-issue fixes + collab features + client progress/closed scope/multi-issue packages
- First SQL run hit the wrong DB (one-shot SELECT check showed nothing applied); user re-ran upgrade-20261126-0431.sql; verified by SELECT: 6 collab tables, migration rows 000200 (b8) / 30_000100 (b9) / 30_000200 (b10), dev_doing->test_pending requires ["package_id","assignee_test_id"], lookups client_progress 8 / role_label 6 / dependency_*, event labels, 4 settings, grants issue.tag (dev,internal_pm,test) + ticket.needinfo (internal_pm).
- Frozen snapshot snap-20261126b (md5 baseline 431 files, real vendor copy); build ASSET_URL=/mescits + 205/205 tests in snapshot; build.py --no-npm: app.zip 7494 entries (config/services.php included, no .env/sqlite).
- Uploaded: 1 new asset (app-ylwRWp_q.css), app.zip, extractor (7487 files written, env kept, zip + self deleted), manifest + fonts-manifest (md5 match, 99 assets 200). One-shot cleared permission + status-map cache (issue.tag/ticket.needinfo loaded, new routes present), self-deleted.
- Logged-in smoke (admin/internal/client): all expected codes (client 403 on /pm, /similar/internal; internal 403 on /tickets/create, /admin). No new prod log entries (latest still 03:25:46 UTC incident).
- extractor.txt format: line1 = file name, line2 = token (LF). URL = https://example.com/mescits/<line1>?t=<line2>.
- Open: manual chapters (someone else writing), PDF manual stale, HANDOVER collab section; needinfo reminder relies on cPanel cron schedule:run (01:30 daily).

## 2026-11-26 Package add = hand-off to test (not deployed)
- dev/index: "add to package" now hands each issue off via IssueService::transition to the target of the transition that requires package_id (WorkflowService::targetRequiring; today dev_doing -> test_pending), inside one transaction (all or nothing). A <dialog> asks for the tester; "keep each issue's tester" only when every picked issue already has one.
- Candidates = statuses with a package-requiring transition (not TEST stage any more); issues already in the package but still dev_doing are listed.
- Tests 205/205; dialog checked in browser (centered, blocks without tester). New CSS (m-auto) -> new asset on deploy. No SQL needed.
- Note: removing an issue from a package does not revert its status.
- Deployed 2026-11-26 (package add = hand-off to test): snapshot snap-20261126c (431 files baseline, 205/205 in snapshot), 1 new asset app-COeFy71q.css, app.zip (7487 written, env kept, zip + extractor self-deleted), manifests md5 match, 99 assets 200. Prod IssueService / WorkflowService / dev/index md5 == snapshot. Logged-in smoke all expected; no new prod log entries. No SQL, no cache clear needed.
- download_items args: {"profile","paths":[remote...],"localDirectory","overwrite"}.

## 2026-11-26 Manual: collab features / client progress / packages (not deployed)
- Manual-only change (docs/manual 01-07, 09, 10, img, dist HTML+PDF). No app code touched, no deploy. Details: .histories/manual-collab-20261126.md.
- Matches code as of 13:41 (= package hand-off deploy). ManualTest 4/4 in an isolated copy.
- NOT covered (being written by another session after 13:41): meeting list filters + Excel export (ch11, 7.4); "remove from package" now withdraws from test (5.8 and 10.10 must change when that ships).
- Found, not fixed: (1) packages.released_at is never written, so released packages are never frozen; (2) client watchers (ticket creator is auto-watcher) also receive internal-recipient notifications, e.g. "internal issues all cancelled, PM action needed"; (3) database/seeders/DemoSeeder.php fails since dev_doing -> test_pending requires assignee_test_id.

## 2026-11-26 Withdraw-from-test + meeting filters/export (not deployed)
- Withdraw: new transition issue test_pending -> dev_doing with permission 'system' (no button; used only by removeFromPackage after dev.manage check). Back to DEV, package + actual_done_at cleared, tester kept, rework_count untouched, event test_withdrawn, tester/dev/watchers notified, settle recompute. test_doing/test_scheduled/released refused (tester must fail it).
- Meetings: list filters type/status/from/to (Taipei dates -> UTC start_at bounds), tab links keep filters. Excel export: meetings.export (current tab/q/filters, all rows) and meetings.export-one; sheets for meetings (with details) and discussion items; columns lookup export_meetings / export_meeting_items; visibility identical to the page (MeetingService::exportRows). Permission data.export.
- Blade gotcha: mixing @php(...) inline with @php...@endphp blocks in one view breaks compilation -> use block form.
- Tests 207/207. Upgrade SQL deploy/out/upgrade-20261126-0559.sql (INSERT IGNORE only: transition + lookups). Waiting for the user to run it before deploy.

## 2026-11-26 Manual: withdraw-from-test + meeting filters/export (written, deploy with the feature)
- docs/manual 01, 02, 05, 06, 07, 10, 11 + 7 screenshots; dist rebuilt. Document behaviour that needs upgrade-20261126-0559.sql + feature deploy.
- The manual-only deploy running now uses an older frozen snapshot and does NOT contain this part.
- Deployed 2026-11-26 14:20 (withdraw-from-test + meeting filters/export): user ran upgrade-20261126-0559.sql; verified by SELECT (transition test_pending->dev_doing 'system', event_label test_withdrawn, export_meetings 13 / export_meeting_items 10). Snapshot snap-20261126d, 207/207 in snapshot. docs/manual was being rewritten by another person (files modified minutes before) -> snapshot manual replaced with the last deployed one (snap-20261126c); app.zip diff vs previous deploy = 13 code files only, manual identical. No new assets. Extractor 7488 written, env kept, self-deleted; manifest md5 match, 99 assets 200; prod MeetingExportService/IssueService/meetings index md5 == snapshot. Smoke (admin/internal/client): meetings filters + /meetings/export 200 for all three, /pm /dev 403 for client. No new log entries.
- Build gotcha: deploy/out/stage left over from a failed run makes build.py fail with WinError 145 -> remove stage and rebuild.
- Manual: deploy it separately once the writer says it is finished (build.py packs docs/manual into resources/manual).
- Deployed 2026-11-26 14:35 (finished manual): writer reported done; checked 70 images referenced exist, chapter links ok. Snapshot snap-20261126e, 207/207; app.zip diff vs previous deploy = 77 resources/manual files only (+ env.production template). Extractor 7497 written, env kept, self-deleted; prod 11-meetings.md / 05-dev.md / dev-package-dialog.png md5 == snapshot. Manual smoke: admin all 200; internal 09 404; client 04/05/06/08/09 + internal images 404 (role filtering as designed). No new log entries.
- Still open: docs/manual/dist (manual.html / PDF) not rebuilt; cPanel cron for schedule:run unconfirmed.

## 2026-11-26 Platform + development record written
- docs/PLATFORM_DEV_RECORD.md: platform facts (paths, DB, cron */5, SMTP), architecture rules, modules, workflows, data model, notifications, local tooling + gotchas, upgrade SQL procedure, frozen-snapshot deploy procedure, today's changelog, open items. Read it first when handing over to another agent.

## 2026-11-26 Manual on prod: overlap fixed (manual session)
- The manual-only upload (batch 1, 76 files, started about 14:15) overlapped the 14:20 / 14:35 full deploys. Its late .md uploads reverted 01, 02, 05, 06, 07, 10 to batch 1 after 14:31.
- Fixed about 14:50: re-uploaded the six files from snap-20261126e. All six md5 match; every manual file size on prod equals snap-20261126e, none extra. / 200, /manual 302 for guests.
- dist/manual.html and PDF WERE rebuilt after batch 2 (the "not rebuilt" note above is outdated).
- Rule for next time: only one session deploys at a time; announce in SESSION_LOG before uploading and re-check it right before each upload batch.

## 2026-11-26 Manual layout fix (local, not deployed)
- docs/manual/02-workflow.md: 2.2 and 2.4 diagrams split into LR charts (rendered at 54% / 47% before; now 78-100%). img/forgot.png retaken with mail enabled. dist rebuilt.
- Checked online rendering locally for client / PM / admin: no broken images, tables or diagrams.
- Production still has the previous version of these two files.

## 2026-11-26 15:05 Manual deploy IN PROGRESS (manual session)
- Uploading only resources/manual/02-workflow.md and img/forgot.png. Do not start another deploy until the done note below.

## 2026-11-26 15:06 Manual deploy DONE (manual session)
- Prod resources/manual/02-workflow.md and img/forgot.png updated (WebDAV), md5 verified after download; both matched snap-20261126e before upload. / 200, /manual 302 for guests. Prod manual == local docs/manual. Other sessions may deploy again.
- Plan written (not started): docs/project-channel-notify-plan.md - per-project notification channels (Discord / Telegram / LINE Messaging API group push / Teams Workflows; Teams home edition cannot use Workflows). Provider adapters, channel_outbox + cits:send-channels, event codes added to WatchService calls, client-audience filtering, LINE join webhook with signature check, SSRF host allowlists, encrypted credentials. Handed to another agent.

## 2026-11-26 Deployment guide + final install SQL
- docs/DEPLOYMENT_GUIDE.md: platform requirements (PHP 8.5 + nd_pdo_mysql, MariaDB 11.4, dir layout, cron */5, SMTP 465 SSL), system traits, final SQL files, fresh install, DB upgrade, frozen-snapshot code deploy (step by step), manual-only deploy, post-install settings, verification checklist, troubleshooting, today's deploy log, appendix templates (smoke / read-only check / cache clear, token placeholders).
- Final install SQL regenerated from a scratch SQLite (not the dev DB): deploy/out/install-20261126.sql (= install.sql; 14 migrations, 214 lookups, 40 transitions, 36 permissions, admin user hash from ADMIN_PASSWORD.local.txt). Old baseline kept as install-20261123.sql.
- IMPORTANT: install.sql is the make_upgrade_sql baseline; it now lists all 14 migrations (= prod state), so future upgrade SQL needs no --applied.

## 2026-11-26 License review
- Added cits/public/drawio/LICENSE (Apache-2.0, downloaded from jgraph/drawio tag v32.7.4 with user approval; no NOTICE in that tag).
- No strong copyleft: composer 133 pkgs (prod 82) all permissive except nette/schema + nette/utils dual BSD-3/GPL (pick BSD-3); npm 222 pkgs, no GPL/LGPL/AGPL; DOMPurify MPL-2.0 OR Apache-2.0 (pick Apache); lightningcss MPL-2.0 is build-time only.
- draw.io bundle contains libavoid-js (LGPL-2.1, separate unmodified JS file -> keep its LICENSE, allow replacement), templates CC BY 4.0 (attribution), icon sets: not for use in Atlassian products/marketplace.
- Pending user decision: project LICENSE (MIT/Apache-2.0/AGPL/none) + copyright holder; THIRD_PARTY_NOTICES.md generation; amend into the initial commit.
- Licensing done (not committed yet): LICENSE (Apache-2.0), NOTICE (holder placeholder "The CITS Authors"), composer.json license Apache-2.0, THIRD_PARTY_NOTICES.md (162 browser-bundled npm pkgs from package-lock closure of alpinejs/mermaid/@tiptap/* + tailwindcss; 82 prod composer pkgs), licenses/THIRD_PARTY_LICENSES_{JS,PHP}.txt (texts from local node_modules/vendor; MIT text added for alpinejs/fastdom/strictdom which ship none). AGENT_DEPLOY.md for public users (local / VPS / cPanel routes).
- Regenerate notices after dependency upgrades (THIRD_PARTY_NOTICES.md section 8).

## 2026-11-26 Published to GitHub
- Public repo https://github.com/bootwilly/customer-issue-tracking-system (branch master, single commit 4ba620c). Author set repo-locally to bootwilly <id+bootwilly@users.noreply.github.com>; NOTICE copyright "2026 bootwilly". Pre-push scan: no real domain / cPanel user / local paths / personal email in tracked files.
- Remote: origin (https). Future pushes only when the user says so.
- Portability fixes (not committed): build.py app_dir config (CITS_APP_DIR, default app/mescits), ASSET_URL derived from app_url path, cross-platform npm; make_upgrade_sql.php baseline optional + --applied-file / --no-baseline; docs no longer depend on ADMIN_PASSWORD.local.txt; root README.md (architecture, deployment routes, license notes).

## 2026-11-26 Ticket audience, package release and demo hand-off

- Internal settlement notifications filter direct recipients and watchers by account audience and project access. Public ticket updates keep customer watchers.
- The first issue confirmed released freezes package membership in the same transaction. Subsequent issues can finish in that package. Historical release evidence protects unstamped packages without a data migration. New package creation rolls back if hand-off fails.
- DemoSeeder supplies the existing project tester during hand-off. Existing partially seeded projects keep the existing skip behavior.
- Updated the online and offline manuals. Unit suite: 2 passed, 17 assertions. Additional mock service checks exercised first and subsequent releases, refused add and withdrawal. Package list and candidate queries ran on a read-only local SQLite connection. Changed Blade views compiled.
- Feature tests were added but not run: the suite uses RefreshDatabase and writes fixtures. DemoSeeder was checked against the workflow without executing it. No deployment or database changes.

## 2026-11-26 Production preflight for 6a7deb7 (deployment held)
- Verified rollback copies of all 12 affected production files. No production files were uploaded or changed.
- With the authorized isolated SQLite memory database and test-only settings, the exact commit passed 210/211 tests. ProgressPackageTest line 194 matched the frozen package name in the preceding flash error instead of checking package options.
- Verified the actual package selector excluded the frozen package and included the open package. A temporary assertion scoped to option labels passed all 7 ProgressPackageTest cases (125 assertions). Restored the snapshot test. Production release awaits a decision on the failing committed test.

## 2026-11-26 Deployment of 10349cb
- IN PROGRESS: 12 verified runtime/manual files. Isolated memory SQLite suite 211/211 (3764 assertions). Production rollback baseline rechecked.
- DONE at 2026-11-26 17:20:19 Asia/Taipei. All 12 production files matched release SHA-256 on download. The extractor removed itself and the private zip. Guest login page and manifest returned 200; /dev, /issues/1 and /manual redirected guests to login. All 99 manifest assets returned 200; manifest and log listing matched pre-deploy. No production DB writes or authenticated smoke tests. Rollback files and manifest retained under %LOCALAPPDATA%/CITS/deployment-backups/10349cb.

## 2026-11-26 Meeting Markdown attachments assessment
- Code review only. Meeting notes already support multi-file selection and separate shared/internal attachment binding. The default extension list omits md and the file endpoint downloads Markdown.
- Proposed: meeting-scoped Markdown admission and preview, reusing the existing renderer and attachment ownership. Enforce meeting visibility on both preview and original download, including for the uploader of a bound document. No new table needed for documents linked from notes.
- No feature code, database operations, deployment or runtime verification.

## 2026-11-26 Meeting Markdown documents (local feature branch)
- Based WillyNB/meeting-markdown-documents on master 0fe1489. Meeting editors can attach multiple UTF-8 .md files in shared or internal notes. Existing attachment storage and schema are reused.
- The document list follows saved link order and visibility. Preview and original download check current meeting access and references. Markdown preview reuses the sanitized flow renderer. Default limit: 200000 bytes, capped by the existing attachment limit.
- Updated the meeting manual and regenerated offline HTML/PDF. Unit suite: 7 passed, 50 assertions (repository doubles and private test storage). Verified route registration, Blade compilation, subpath Vite build, and a headless Chrome fixture exercising the actual multi-file editor and preview switching with mocked upload responses.
- No database writes, live-account test, commit, push or deployment.

## 2026-11-26 Production deployment of meeting Markdown documents
- IN PROGRESS: 1f2695e. Verified 7 existing files and 2 manifests for rollback, 3 new paths absent. Isolated SQLite full suite 216/216 (3797 assertions); separate multi-role document lifecycle passed (24 assertions).
- DONE 2026-11-26 18:27:38 Asia/Taipei. Pushed feature branch and deployed 1f2695e. Verified SHA-256 of 10 runtime/manual files, all 99 public assets and 2 manifests. Canonical URLs for both new assets and manifests matched. Guest meeting/list, document preview/download and manual returned 302 to login; login returned 200. Log listing unchanged. Retried a timed-out asset transfer before manifest switch; private parts and one-time installer cleanup verified. No production DB migrations or authenticated writes. Rollback copies and file manifest: %LOCALAPPDATA%/CITS/deployment-backups/1f2695e/run-20261126181452.

## 2026-11-26 Standalone meeting documents section
- Added a header shortcut and an always-visible Markdown card above meeting notes. Editors upload batches independently; the page refreshes only the document list. Staff uploads default to internal. Prior note links keep their visibility rules.
- New batches use existing attachment ownership with a dedicated context. A file validation failure leaves staged files temporary for scheduled cleanup. No schema change.
- Local unit suite passed (9 tests, 64 assertions), routes registered, Blade compiled and the /mescits Vite build passed. Offline HTML and PDF manuals regenerated. No deployment or commit.

## 2026-11-26 Standalone meeting Markdown production deployment
- IN PROGRESS: ffa3a3d. Remote feature branch verified; exact snapshot 218/218 tests, 3811 assertions on isolated memory SQLite. Additional multi-role batch workflow 31 assertions. Browser fixture passed. Seven production files and both manifests backed up, one new path checked.
- DONE 2026-11-26 18:55:14 Asia/Taipei. Deployed ffa3a3d from WillyNB/meeting-markdown-documents. Production SHA-256 verified for 8 application/manual files, 99 compiled assets and both manifests. Canonical manifests and new asset URLs matched. Batch endpoint GET returned 405 with Allow POST. Guests redirected to login for meetings, document preview/download and manual. Log listing unchanged. No production schema migration or real-user uploads. Rollback files and hashes retained under %LOCALAPPDATA%/CITS/deployment-backups/ffa3a3d.

## 2026-11-26 Idle auto logout
- LogoutWhenIdle middleware (web group, appended): session key idle.last_at, setting session.idle_minutes (default 60 in code, 0 = off).
- Header X-CITS-Background = not activity (edit-lock heartbeat). Remember-me disabled/refused while limit on.
- Layout JS timer shares last activity via localStorage across tabs; warns 2 min before; GET /session/ping keeps alive.
- No SQL needed on production (code default). Use now()->getTimestamp() not time() so tests can travel().
- Tests 221/221. Not yet committed/deployed.
- DEPLOYED 54aafc7 2026-11-27 ~00:05 Asia/Taipei, snapshot snap-20261126z: tests 221/221, app.zip 7509 files (7502 written, env kept), 1 new css asset, manifests md5 match, 99/99 assets 200, 4 remote files md5 match snapshot. Smoke: admin/internal/client codes as expected, layout timer present; idle probe (session idle.last_at = now-61min) redirected to login and session was invalidated. Logs unchanged. No SQL.

## 2026-11-27 Auth logs (login IP audit)
- Table auth_logs (migration 2026_12_01_000100), AuthLogService/Repository, events in lookup auth_log_event/auth_log_detail, export columns export_auth_logs.
- ip = Request::ip() (trustProxies *: rightmost X-Forwarded-For from the host proxy, Server header openresty); raw X-Forwarded-For also stored. Not yet verified on production what the proxy sends.
- Admin page /admin/auth-logs (filter, paginate, xlsx), last 10 rows on the user edit page. Purge cits:purge-auth-logs daily 19:25 UTC, setting auth_log.keep_days 180.
- Tests 226/226. Upgrade SQL deploy/out/upgrade-20261126-1633.sql must run BEFORE deploying the code. Not committed/deployed yet.

## 2026-11-27 Editor content handling review
- Reviewed rich-text save paths, draft cleaning, Markdown preview and attachment delivery. Normal save paths use SanitizerService. ContentService::clean still falls back to strip_tags if the sanitizer class is missing; allowed tags can retain unsafe attributes in that fallback. Recommend requiring the sanitizer or rejecting the save. Static review only; production records were not inspected.
- DEPLOYED ae2fa59 2026-11-27 ~01:00 Asia/Taipei, snapshot snap-20261127a: tests 226/226, 7511 files written, env kept, 1 new css, manifests md5 match, 99/99 assets 200. Prod DB verified read-only: 15 migrations = repo, auth_logs columns/indexes, lookups 9/6/9, settings. Baseline deploy/out/install.sql regenerated (install-20261127.sql, 15 migrations). Smoke: /admin/auth-logs 200 admin, 403 internal/client.
- FINDING: host proxy (openresty -> Apache) sets REMOTE_ADDR and X-Real-IP from the CLIENT-SUPPLIED X-Forwarded-For and drops the header; true peer IP is not available to PHP. Normal browsers log correctly, but IP is spoofable, and login/password-reset throttles keyed by IP can be bypassed by rotating X-Forwarded-For. forwarded_for column will stay empty. Needs host fix or per-account throttle.

## 2026-11-27 Security hardening (pending SQL + deploy)
- Per-account login throttle login-account|<login> (login.account_max_attempts 10 / account_decay_minutes 15) and per-account forgot-password throttle; detail per_account.
- Attachment types widened via migration 2026_12_02_000100 (only replaces the old default; prod value confirmed = old default) + seed; editor file picker accept list.
- @richtext Blade directive re-sanitizes stored HTML at render (13 places); SecurityHeaders middleware (CSP object-src none, base-uri self, frame-ancestors self, form-action self; nosniff; XFO SAMEORIGIN; Referrer-Policy); attachments (non-PDF) get CSP sandbox.
- Tests 230/230. Upgrade SQL deploy/out/upgrade-20261126-1700.sql must run first. Prod auth_logs already has rows (logging works).
- DEPLOYED d1add53 2026-11-27 Asia/Taipei, snapshot snap-20261127b: SQL upgrade-20261126-1700 verified read-only (16 migrations, no dup, allowed_ext/account throttle settings, per_account lookup). install.sql baseline regenerated (16). Tests 230/230, 7513 files, no new assets, manifests md5 match, 99/99 assets 200. Live headers: CSP/nosniff/XFO/Referrer-Policy. Smoke: ticket/issue/meeting pages 200 with body, attachment CSP sandbox, role codes as expected. Logs unchanged.

## 2026-11-27 Security audit fixes (docs/security-audit-20261127.md, by another agent)
- Verified all 19 findings against code; H-03 reproduced live (forged X-Forwarded-Host rewrote every link on prod home page -> reset-link poisoning).
- Fixed (uncommitted): H-03 trustProxies headers limited to X-Forwarded-For|Proto + reset cooldown (recentlyCreatedToken); H-01 quoteComment scoped to ticket+project+link (IssueRepository::linkedTicketComment); H-02 8 inline confirm() + mes x-data -> @js; H-04 itemJson masks issue for clients, export hides owner/due/status before notes published; H-05 img src only (/prefix)*/attachments/{id} (same host, no query) + ticket edit refuses Sec-Fetch-Dest not document/iframe; H-06 DrawioParser DOM pass (local names, on*, decoded URL schemes, SMIL) + gzinflate cap + doctype check; M-02 upload routes add 'active', bound attachments need config/attachments.php view permission + active project, uploader shortcut only for temp; M-08 issue edit binds pasted images + clears draft.
- Prod read-only survey before tightening img rule: all 3 stored <img> are /mescits/attachments/N (nothing dropped).
- Tests 243/243 (new tests/Feature/SecurityAuditTest.php). No upgrade SQL needed.
- Not done yet: M-01, M-03..M-07 (except headers), L-01..L-05.
- Batch 2 (uncommitted): M-01 project-admin candidates hide clients belonging to projects the actor is not in, system admins locked for non-admin actors; M-04 user_type switch drops other-type roles in all projects (+ revoke MCP tokens, issue watches when becoming client) in one transaction, EventRepository::notify only to project members or system admins, notifyIssue never to clients; L-01 LogoutAfterPasswordChange middleware (session keeps [user id, HMAC(password hash)], other sessions signed out after change/reset; Laravel authenticateSessions() rejected: it kicks on in-session user switch), MCP failed-token throttle 20/min/IP + ForceHttps on /mcp; M-03 issue mes_page_id must be an active page of the project (IssueService), createFromTickets checks count/project before linking with a generic message, ackRevisions for every ticket; M-02 rest: file names lose / \ and control chars, complete claims the session atomically (open->assembling, back to open on failure).
- Tests 250 + tests/Feature/ZzDebugTest.php (my debug file, pending user OK to delete). Still open: M-05, M-06, M-07 (.htaccess / extractor), L-02..L-05.
- DEPLOYED d3c2ac7 2026-11-27 ~09:10 Asia/Taipei, snapshot snap-20261127c (needs deploy/deploy.local.json copied into the snapshot). Tests 250/250, 7515 files, env kept, no new assets (manifest md5 same), app.zip diff = exactly the 34 changed/added app files. Live: forged X-Forwarded-Host no longer reflected (6 links on real host). Downloaded 3 files md5 match. Smoke (admin/internal/client) same codes as before; attachments 200 for internal and client; /tickets/1/edit with Sec-Fetch-Dest image = 403 (no lock taken). No new log file.

## 2026-11-27 draw.io device import HTTP 413 investigation
- Native diagram import starts in the browser. On save, the flow editor posts the entire editable SVG as a multipart text field (source_md). Its size can exceed the original file size.
- The flow parser size check returns HTTP 422. For a save-time 413, check upstream request limits, including non-file fields. Failing request path, size, and host log are needed to identify the rejecting layer. Static review only.
- User sample replayed locally with the bundled draw.io device importer and xmlsvg export: 313530 source bytes, one page, 948 mxCell elements, no embedded images. Export: 363004 SVG bytes; multipart with source_md alone: 363144 bytes. No production request made; rejecting layer unverified.
- Batch 3 (uncommitted): M-05 host change drops stored SMTP password, require_tls for tls, undecryptable settings -> default + warning; M-06 meeting Markdown preview strict CSP, drawio PreConfig plugins=0/pwa=0 and deletes p/mode/gapi-stealth (browser-verified locally: params gone, no external requests, embed configure/init/load OK), onedrive3/teams.html dropped; M-07 public .htaccess (Options -Indexes outside mod_negotiation - host has no mod_negotiation, LiteSpeed; dotfiles + zip/sql/sqlite/bak/log forbidden; no // trailing-slash redirect; Header always setifempty nosniff/XFO/Referrer) pre-tested in a temp prod subfolder (no 500, PHP headers not overridden, folder removed); extractors POST + X-Deploy-Token only, flock, shutdown self-delete, stale routes/ files removed (guarded by routes/web.php in zip), drawio.zip now goes to webdav-app; L-02 ContentService no strip_tags fallback, manual leftover internal marker fails closed (all 12 chapters unaffected), sitemap doctype; L-03 report CSV formula chars; L-04 draft size/count caps, localStorage keys per user, cleared on logout; L-05 unique ticket_prefix, user delete history/transient tables.
- Tests 256/256. Not done: LIKE escaping, SMTP private-IP block, extract-to-temp+rename (reasons in .histories).
- Anonymous production check for flow 2: GET edit returned 302. Two multipart POST requests to the save path with method override, dummy text, no cookie and no CSRF token returned 419 for both 1171 and 363151 body bytes. A size-only 413 at the standalone sample size was not reproduced. Existing diagram content and the actual failing request remain unknown.
- Flow 2 save failure reproduced anonymously on production with a dummy multipart text field and no session or diagram. Total body sizes 1048576 and 1048577 bytes reached CSRF (419); 1276582 bytes, matching the reported request length, returned a generic HTML 413. A size limit on this request path is confirmed; the enforcing component and exact configured limit are unverified.
- Flow editor HTTP 413 (uncommitted): prod host limits multipart TEXT fields to ~1 MB (1000 KB ok, 1100 KB 413; file parts fine to 10 MB; PHP upload_max 512M). flow/editor.js now sends the SVG as file part source_file; FlowController accepts source_file or source_md. Test added (1.1 MB SVG via file). Tests 257/257.
- DEPLOYED 33a1689 2026-11-27 ~10:30 Asia/Taipei, snapshot snap-20261127d (+ public/drawio, + deploy.local.json). Tests 257/257. 3 new assets, manifest md5 match, 99/99 assets 200. New POST extractor used for the first time: GET with query token = 404, POST + X-Deploy-Token: 7515 files, env kept, stale routes 0, self deleted. drawio redeployed (3075 files, onedrive3/teams removed -> 404, PreConfig plugins=0 live). public/.htaccess replaced (prod copy was identical to the old build, backup kept in scratch): home 200, dirs 403, .zip 403, dotfiles 404, static files get nosniff/XFO/Referrer, PHP pages keep a single XFO. Downloaded 3 files md5 match. Smoke: same codes as before, /flows/create loads new editor JS (source_file fix), cits-user meta present. No new log file.

## 2026-11-27 MES meeting workbook import assessment
- Reviewed the supplied workbook offline: 59 numbered issues, 36 linked detail sheets, 45 embedded picture placements including 2 on an unassigned sheet. PM and developer columns hold historical notes.
- Proposed a preview with source IDs, image and note reconciliation, status review, and matching against existing project issues before creation. No import or DB access.

## 2026-11-27 Excel import for PM internal issues (assessment only)
- Proposed: own ZipArchive+DOM xlsx reader (cells + xl/drawings anchors -> row), one row = one issue, upload via existing chunked upload, dry-run preview stages images as temp attachments (context issue:import:<token>), apply creates all rows through IssueService::createInternal in one transaction with <img src=/attachments/{id}>.
- Gotchas found: shapes/callouts (xdr:sp, grpSp, cxnSp) have no bitmap and cannot be rendered on the host (no LibreOffice); EMF/WMF not browser-viewable; Excel 365 in-cell pictures live in xl/richData, not drawings; host 60s execution limit and 1 MB text-field limit.
- Column mapping should come from a lookup (no hardcoded headers). No code written.
- 2026-11-27 re-analysis of the same workbook (zip structure): summary sheet A1:L60 (dimension to T647, rows 61+ empty). Date column is a General number (20260513) or text like "20260721(PM)", not a date serial. 36 hyperlinks in column F use location "<sheet>!A1"; 23 rows have no detail sheet. One unlinked sheet holds 2 pictures.
- 41 PNG media files, 45 placements, 4 reused across sheets, no EMF in content, no in-cell richData. 31 sp + 7 cxnSp + 1 grpSp on 9 sheets: red 6pt rectangles on screenshots, white text callouts, and one shapes-only flowchart. Every one of the 83 anchors has absolute a:xfrm off/ext (EMU), so overlay geometry is exact (px = EMU/9525). One picture has srcRect crop.
- Detail sheet A1 often repeats summary F; text cells and pictures interleave by row (conclusion lines between pictures). Statuses: 32 discussing-like, 15 spec-confirm, 6 closed, 4 wont-fix, 2 other.
- 2026-11-27 checked meeting items -> internal issue: no conversion exists. meeting_items only link an EXISTING issue (issue_no field in itemFields, or addIssues/prefillIssues issue -> item). Item fields are plain text (topic 300, discussion/conclusion cleanText 5000), no images; item status only open/done. Creating an issue from an item would need a new action (MeetingService -> IssueService::createInternal, then set issue_id).

## 2026-11-27 Meetings: cross-meeting threads, item pictures, item -> internal issue (uncommitted)
- Threads: issue page groups linked items by carried_from_id (MeetingService::issueThreads); meeting items show other meetings' discussion of the same issue (itemHistory, internal only); one item per issue per meeting; scheduleNext skip_closed (issue terminal); first publish logs issue event meeting_discussed + notifyIssue (once).
- Item pictures: attachments owner_type meeting_item (no schema change), config meeting.item_images_max=10, upload via /uploads/image then POST/PUT/DELETE /meetings/{id}/items/{item}/images; PUT swaps the file in place (same id, ?v=sha in URLs); canView for clients = meetingOpenToClients; carried by scheduleNext (row copy, same disk_path); deleted with item / purge. Annotate reuses editor/drawing.js openDrawing (xmlpng tEXt mxfile = re-edit).
- Item -> internal issue: GET/POST /meetings/{id}/items/{item}/issue; origins from lookup issue_create_perm the user holds; IssueService::createInternal + item lock + issue_id + event from_meeting + internal owner auto-watch; item pictures copied as temp attachments of draft context issue:meeting-item:{itemId}.
- New lookups event_label meeting_discussed / from_meeting: prod needs upgrade SQL (make_upgrade_sql.php). Tests 263/263. Browser not verified (local login password file read was denied); paste/drawio UI untested in a real browser.
- 2026-11-27 feasibility (severity 3, skip closed/wont-fix, rest new): 49 rows import, 10 skipped. All map to createInternal origin pm -> status new, no transitions. Titles "D: first line of F" max 94 chars, no duplicates. process_type: 33 feature, 8 ui_change, 8 null. 32 rows have detail sheets with 40 pictures; 9 rows have 39 shapes (one is a 20-shape flowchart). 21 detail A1 duplicate F. 4 skipped rows own detail sheets (ignored). Cross refs: rows 19->17, 36->29, 40->13 (row 16 "confirm question 1..3" is a false positive). Editor drawings are draw.io xmlpng (data-drawing=1, modelFromImages), so annotated screenshots could be imported as editable drawings. mes_page_id matching not checked against production. Preview CSV in session scratchpad only.
- 2026-11-27 decisions: strip title prefixes, composite PNG (not draw.io), dedupe table for re-import, prod read-only check allowed.
- Prod read-only (SELECT via MCP, HEX decoded): target project id 2 (ticket_prefix YC) has 0 issues; 43 active pages there. Exact function-name match 7/49 rows (rows 1,3,4,5,6,7,8); fuzzy suggestions mostly wrong, so only exact match auto-fills mes_page_id. Prod process_type has an admin-added 4th item (code ctp5drdtpin, "issue discussion") -> map category "discussion" to it, by lookup not by code.
- Title prefix strip (4 regex: question N, date+discussion, date+space, (hh:mm)) changes 23 titles, no empty or duplicate titles.
- DEPLOYED 1e8b299 2026-11-27 ~15:45 Asia/Taipei, snapshot snap-20261127e. Tests 263/263. app.zip diff vs snap-20261127d = exactly 4 new views + 16 changed files (+env.production). 4 new assets (app css, drawing chunk, editor index, items), manifest md5 match, 100/100 assets 200. Extractor: 7519 files, env kept, stale routes 0, self deleted, 2nd POST 404. Downloaded 3 files md5 match. Smoke (admin/internal/client): same codes as before; /meetings/3 shows image strip + new items JS for admin and internal. No new log file. Pending: user runs deploy/out/upgrade-20261127-0737.sql (0 migrations; INSERT IGNORE only, new event_label meeting_discussed / from_meeting); until then those timeline rows show the code. Browser E2E done earlier on a scratch SQLite copy (paste, drawio annotate + re-edit, convert, carry-over, history).
- 2026-11-27 user ran upgrade-20261127-0737.sql. Read-only check: event_label meeting_discussed (310) and from_meeting (320) present and active, lookup_items 242 rows no duplicates, migrations unchanged (16, batch 12). Script self-deleted (404).
- 2026-11-27 built the standard shared workbook (session scratchpad, not in repo: contains client data) with Excel COM from a copy; original untouched. List sheet: 17 columns (no, date as real date, process type = CITS lookup names, function, MES page = prod project 2 page names, title pre-stripped, content as values not formulas, detail hyperlink, status, severity 3, asker, PM, dev, test, close note, package, note); list validations to row 1000 from the hidden lists sheet via named ranges, unique numeric no, title <= 200. Help sheet = rules. Detail sheets sorted by number; the unlinked sheet renamed to "unassigned pictures". All 83 drawing anchors preserved. Original summary F2:F10 and L8 were formulas (=sheet!A1), which explains the duplicated detail A1.
- Importer can now target ONLY this standard format: no header aliases, no title regex, no category mapping, no fuzzy page matching.
- pwsh 7 COM gotchas: Range.Value2 refuses object[,] and int (write cell by cell with [string]/[double]); BuiltinDocumentProperties null; automation EXCEL.EXE may linger, stop only '/automation -Embedding' processes.

## 2026-11-27 PM issue Excel import (standard template only)
- Standard template v1 (sheets list/help/hidden lists with template_version cell); headers, sheet names, status values and skip list come from lookups (issue_import_*). Importer refuses files without the version marker or required headers.
- Flow: chunked upload (context issue:import) -> preview JSON (no writes) -> browser composes each picture + overlay shapes (or loose shape groups) into PNG, uploads via /uploads/image with context issue:import:{file}:{no} -> apply re-parses and creates all chosen rows in one transaction via IssueService::createInternal (origin pm, status new). Changed rows (content_hash differs) can be written as addendum. Dedupe table issue_import_rows (project, source, source_no).
- FINDING: in this workbook many pictures have stale a:xfrm (e.g. sheet 34 picture xfrm at row 41, anchor at row 5). Excel draws from the cell anchors. Reader now uses anchors for top-level objects, xfrm only inside groups. Verified 44 objects against Excel COM Shape.Top/Left/Width/Height (match to 0.1 pt).
- Real shared workbook (in-memory test DB): 49 new, 10 skip, 0 error; preview 0.7 s, apply 1.1 s. Headless Edge render checked red boxes, text boxes, flowchart.
- Tests 270/270, pint ok, vite build ok. Manual 04-pm 4.8 + offline HTML/PDF regenerated. Upgrade SQL deploy/out/upgrade-20261127-0906.sql must run before deploy.
- Shared workbook updated with template_version=1 (scratchpad only, contains client data, not in repo).
- Not done: Laravel Boost install requested by cits/CLAUDE.md (would change composer.json/lock; needs user OK and full redeploy).
- DEPLOYED 69ac26e 2026-11-27 ~17:40 Asia/Taipei (pushed to origin/master). SQL: user ran the minimal statements (table + migration row + new lookups/settings only; the full upgrade-20261127-0906.sql was NOT used because its role_has_permissions INSERT IGNORE would re-grant permissions an admin removed). Verified read-only: 17 migrations, table utf8mb4_uca1400_ai_ci, unique (3 cols) + 3 FKs, 33 issue_import_* lookups + event_label imported + lookup_editable, 4 settings, header text bytes correct.
- Backup of the 5 changed app files + manifest (prod matched 401f652 exactly): %LOCALAPPDATA%/CITS/deployment-backups/69ac26e/before. Extractor (POST + X-Deploy-Token): 7532 files, env kept, stale routes 0, self deleted, 2nd POST 404. public/ 107 files. Downloaded 4 app files md5 = commit; manifest md5 match; 101/101 assets 200; import JS md5 match. Guest: /pm, /pm/issues/import, /template, /media -> 302 to home (login form 200); POST preview/apply -> 419. No new log file, no extractor/zip left.
- Not done: authenticated smoke test (AI does not sign in to production); user to check /pm button, template download and a preview of the shared workbook.
- 2026-11-27 Meeting items: paste-screenshot button (uncommitted). navigator.clipboard.read() on click, fallback paste box (contenteditable, receives Ctrl+V / phone keyboard paste); optional auto-annotate after one pasted picture (localStorage cits:meeting:auto-annotate); file picker demoted to a link; manual updated. No backend change. Meeting/Manual tests 31/31; mobile-size E2E on scratch SQLite: fallback box paste -> saved, auto-annotate opens drawio. Direct clipboard.read success path not testable here (clipboard write denied) - needs real Android/iOS check.
- DEPLOYED 4f42495 2026-11-27 ~19:05 Asia/Taipei, snapshot snap-20261127f (includes 69ac26e Excel import already live). Tests 270/270. app.zip vs snap-20261127e = Excel import files + meeting paste views/js/manual only. 2 new assets (app css, items), manifest md5 match, 101/101 assets 200. Extractor: 7528 files, env kept, stale routes 0, self deleted, 2nd POST 404. 3 downloaded files md5 match. Smoke admin/internal/client: same codes as before (+ /pm/issues/import 200 admin, 403 internal #4 / client); /meetings/3 shows data-image-paste + new items JS. No new log file. Note: role permissions have no in-app editor (only seed / direct DB), so the earlier full upgrade-20261127-0737.sql role_has_permissions INSERT IGNORE only matters if someone removed a grant directly in the DB.
- 2026-11-27 Issue recycle bin (uncommitted): new issues can be deleted (single: issue page; bulk: PM list checkboxes, max 200, skipped rows listed) by internal_pm (issue.delete) or the creator; status from lookup issue_deletable. SoftDeletes on Issue + whereNull on 7 raw queries. Restore / purge (type issue number) in project-admin trash (project.trash). Purge deletes all issue_id rows + unused attachment files, meeting items keep a note. No ticket event (clients read ticket timelines); SettlementService::recompute flags the ticket for PM. Excel import preview errors when the row's issue is in the bin. Tests 277/277, pint clean on changed files, scratch-SQLite E2E OK. SQL: deploy/out/upgrade-issue-trash-20261127.sql (targeted, no full role grants) MUST run BEFORE deploying this code.
- 2026-11-27 Issue recycle bin follow-up: meeting item linked to a binned issue keeps issue_id on edit (empty issue_no box is ignored while MeetingService::issueTrashed), shows a note, convert blocked; project change log now lists issue_deleted/restored/purged (EventRepository::forProject $only filter). Tests 279/279.
- 2026-11-28 User ran upgrade-issue-trash-20261127.sql. Verified read-only (self-deleting script, 2nd call 404): issues.deleted_at datetime NULL, deleted_by bigint unsigned NULL, index (project_id, deleted_at), FK deleted_by -> users.id, migration row 1 (batch 14, 18 total), 4 lookups (issue_deletable new + 3 event_label), permission issue.delete granted to internal_pm only, 0 trashed / 5 issues. Code still uncommitted / not deployed.
- DEPLOYED 48039ad 2026-11-28 Asia/Taipei (pushed), snapshot snap-20261128a, after the user ran upgrade-issue-trash-20261127.sql. Tests 279/279. app.zip vs snap-20261127f = exactly the commit's app files + composer classmap (new class) + env.production template APP_KEY (server .env kept). 1 new asset items-Beg7IIJP.js, manifest md5 match, 101/101 assets 200. Extractor: 7531 files, env kept, stale routes 0, self deleted, 2nd POST 404. Cache-clear script: permission cache + status map cleared, issue.delete loaded, 4 new routes present, Issue uses SoftDeletes, self deleted (404 after). 4 downloaded files md5 = snapshot. Smoke admin/internal/client: same codes as before; /pm shows bulk delete for admin + internal #4; /project-admin/trash + /events 200 admin, 403 internal/client; meetings page loads new items JS. No new log file.
- 2026-11-28 Bug: Excel import apply 500 on prod = Duplicate entry YC-I2026-0002 (issues_issue_no_unique). Counters are per project but issue_no / ticket_no are unique system-wide; project #1 used prefix YC before (still owns YC-I2026-0002..0004, YC-2026-0001..0003), project #2 now has YC. Fix (uncommitted): NumberingService skips numbers already taken (CounterRepository::numberTaken, deleted issues included, max 10000 skips). User workbook (49 new / 10 skip / 44 pictures) imports fine in a test with the prod numbering situation. Tests 280/280. No SQL needed. Failed imports rolled back fully.
- 2026-11-28 Planned (not implemented; user will hand it to Codex): issue close feature, docs/issue-close-plan.md. PM close (closed_pm) + meeting close (closed_meeting) via intermediate status closing with per-role wrap-up tasks (table issue_close_tasks, lookup issue_close_wrapup), withdraw, permission issue.close, closed counts as resolved for ticket settlement and dependencies, package cleared on completion, pending due requests rejected. Targeted SQL before deploy + permission cache clear.
- 2026-11-28 Implemented PM/meeting issue closure, round-based wrap-up and withdrawal, settlement/dependencies/package/due review, purge, pages and manuals. Full SQLite suite: 301 passed (5463 assertions); Pint and Vite passed. Targeted deploy/out/upgrade-issue-close-20261128.sql generated and checked offline; not applied or deployed. See .histories/issue-close-20261128-173857.md.
- 2026-11-28 Issue close upgrade: user applied SQL. Read-only production MCP verification matched the targeted artifact: one migration record (batch 15), task table engine/collation, 18 columns, 5 FKs, 4 specified indexes, 3 statuses, 32 transitions, 23 lookups, and only the global internal_pm role grant for issue.close. No app deployment or cache clearing in this verification.
- DEPLOYED b685ace 2026-11-28 17:54 Asia/Taipei. Tested frozen Git snapshot (SQLite 301/301, 5463 assertions). Backed up 34 live app files and both public manifests. Installed scoped 39-file delta, verified 39 live app files, 101 assets and both manifests by SHA-256. Permission/status cache cleared; internal_pm grant, 3 POST routes, class loading and views verified. Login 200, six guest redirects, no log changes; one-shot endpoints returned 404 after use. Production DB was not modified; authenticated end-to-end close not exercised.
- 2026-11-28 Dev management: added project-scoped closure rounds and first PM-to-DEV dispatch time on all four tabs and Excel exports. Updated online and offline manuals. SQLite full suite: 306 passed, 5603 assertions; scoped Pint passed. No schema change, commit, push or deployment. See .histories/dev-close-list-20261128-181153.md.
- DEPLOYED 77d1e95 at 2026-11-28 18:25 Asia/Taipei. Dev closure list, PM dispatch times, Excel and online manuals. Frozen release: 306 SQLite tests, 5603 assertions, Pint and Vite passed. Backed up 8 previous live files and 2 manifests. Deployed 9 application/manual files and one new CSS asset. Verified the 9 live files, 101 public assets and both manifests by SHA-256, 5 guest redirects, login 200 and unchanged log listing. Read-only production MariaDB queries passed for rounds, pending ownership and dispatch using a nonexistent project; tab/view/export route present. Installer and diagnostic returned 404 after use. No DB change or authenticated production Excel download. Rollback copies: %LOCALAPPDATA%/CITS/deployment-backups/77d1e95.
