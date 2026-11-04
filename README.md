# CPSTL — Performance Management System

The CPSTL KPI system includes React administration and performance planning workspaces backed by a Laravel API and SQL Server. It manages people, departments, scoped responsibilities, reporting relationships and versioned personal plans. The interface uses the supplied Ceylon Petroleum Storage Terminals Limited logo with a red, white and navy theme. The original logo is stored in `frontend/public/cpstl-logo.png`.

## Included

- Administrator login with an HTTP-only server session, CSRF protection and login throttling.
- Organization overview using live database counts.
- Create and edit users, unique employee/EPF numbers and email addresses, designations, system roles and account status.
- Create and edit departments; archive an empty department.
- Assign one person to multiple departments, with a separate HOF, manager or employee responsibility in each.
- Multiple reporting managers per person, scoped to the same department.
- Reject self-reporting and circular reporting. Require reassignment before removing a leader who has reporting people.
- Search, department/status filters, pagination and CSV export of the filtered people directory.
- Persisted audit events for administrator changes. Passwords are excluded from audit payloads.
- Responsive navigation, forms, loading/error states and keyboard-accessible dialogs.
- Plan Editor login routes directly to performance planning. System administrators use organization administration and cannot access performance plans.
- Create one personal plan per HOF/year across all their departments; enter KPAs, KPIs, targets, monitoring frequencies and supporting activities manually.
- Save incomplete drafts. Publishing requires 100% across the personal plan's KPAs, 100% across each KPA's KPIs and 100% across each KPI's activities, plus required target fields. Allocation is not a performance score.
- HOFs can sign in and view only their own published plans. Draft revisions leave the last published version available.
- Stable item IDs, revision conflict protection, item change history and three-day recent-change labels. Removed items are retained as inactive records.
- HOF login opens a work dashboard with published activities, own work, delegated work and a department-scoped team directory.
- Assign a complete KPI or individual activity to yourself, an active reporting manager, or an employee reachable under your HOF reporting scope in the same department.
- KPI ownership is recorded once and inherited by its activities. Explicit individual activity overrides are preserved in the same work tree; approved contribution is still calculated once per leaf activity.
- A delegated KPI appears to its recipient as a linked KPA responsibility. Managers see its activities and individual owner overrides alongside the existing task breakdown workflow.
- HOF direct employee work creates a single 100% delivery task per activity, reviewed by the HOF. It uses the same evidence, resubmission and approval history as manager-assigned tasks.
- HOF own-work updates record reported progress and notes. Reported progress remains unverified and never counts as an approved KPI score.
- Changed published activities flag assignments for review. Changing an owner or confirming changed source details resets reported progress; prior updates remain in audit history. Removed activities retain their assignments outside active totals.
- Manager and Employee login routes to task delivery. People with HOF and other responsibilities can switch between HOF and delivery workspaces.
- Managers split an assigned activity into employee and manager-owned tasks with a 100% task allocation. Only active employees reporting to that manager in the same department are eligible.
- Task owners report progress and submit a required work/evidence summary with an optional HTTP(S) evidence link. File uploads are not included in this milestone.
- Employee submissions are reviewed by the manager; manager-owned task submissions are reviewed by the HOF. Self-approval is blocked. Requests for changes require comments, and resubmissions retain earlier evidence and decisions.
- Only approved task weights count toward confirmed activity completion. That completion rolls up to the HOF's weighted work contribution; KPI outcome/target evaluation is separate.
- Task breakdowns lock after work starts. Changed or reassigned activities create a new task generation; earlier tasks and submissions are retained and excluded from current rollups.
- Personal allocation is separate from source contribution weights: members propose annual weights across all departments, and Plan Editors review and publish exactly 100%. Optional HOF weight suggestions are proposals, not automatic approval.
- Personal sources include HOF KPAs, delegated KPI responsibilities, standalone manager activities and employee tasks. Underlying work already covered by a person's delegated KPI or HOF KPA is excluded to prevent duplicate personal credit.
- New or changed responsibilities make an outdated published personal balance require review. Draft proposals leave the previous valid published allocation available; new publications retain prior weights and score snapshots in history. HOF plan contribution remains a separate view from the individual's approved allocation.

System roles comprise `super_admin`, `md`, `plan_editor` and `member`. Only active `super_admin` accounts can use administration endpoints; they cannot access planning or delivery endpoints. Active `plan_editor` accounts can edit plans. Active members with department HOF, manager or employee responsibilities receive their respective workspaces. Executive reporting is a future milestone. A designation such as CIO or DGM does not itself grant access.

## Run locally

Requirements: PHP 8.3 or newer compatible with the locked Laravel 13 dependencies, Composer, Node.js 20.19+/22.12+ and npm. The development environment used PHP 8.5 and Node.js 24. Use the committed lockfiles.

### Backend — current checkout

The local `.env` is connected to `cpstl_kpi` on `.\SQLEXPRESS` using Windows Authentication. Migrations and the local demonstration records have been applied. Start it using the Windows account that has access to this instance:

```powershell
cd backend
php artisan serve --host=127.0.0.1 --port=8000
```

### Backend — new checkout using SQLite

```powershell
cd backend
composer install
Copy-Item .env.example .env
php artisan key:generate
New-Item -ItemType File -Path database/database.sqlite
php artisan migrate --seed
php artisan serve --host=127.0.0.1 --port=8000
```

These installation commands are for a new checkout only. **Do not overwrite an existing `.env` or recreate an existing database file.** For the configured local checkout, use the start command above.

### Frontend, in a second terminal

```powershell
cd frontend
npm ci
npm run dev
```

Open <http://127.0.0.1:5173>. Vite forwards `/api` to Laravel on port 8000, so authentication and CSRF operate on one browser origin.

### Local demonstration login

- Email: `admin@pulse.test`
- Password: `Pulse-Local-2026!`

Plan Editor demo: `emp011@example.test` / `Pulse-Local-2026!`. HOF demo: `emp001@example.test` / `Pulse-Local-2026!`. These are existing local sample accounts; account passwords are not reset by migrations.

Manager demo: `emp004@example.test`; Employee demos: `emp007@example.test` and `emp008@example.test`. Their existing local demo password is `Pulse-Local-2026!`.

The seeder creates fictitious sample accounts and departments to demonstrate multiple department membership. It is limited to `local` and `testing`, refuses production, and can run repeatedly without duplicating records. `DEMO_ADMIN_PASSWORD` may override the initial password before the first seed. Reseeding does not reset an existing account’s password.

Newly created accounts are saved immediately; no email invitations are sent. Share initial credentials only through an approved office channel. Plan Editor accounts open planning; HOF members open their work dashboard with access to published plans.

## SQL Server

The current local checkout uses **SQL Server Express** (`.\SQLEXPRESS`, database `cpstl_kpi`) with Windows Authentication. Eight migrations have completed, including performance planning, mixed KPI/activity assignments, task reviews and personal allocation versions. Existing activity ownership and history are retained. Foreign keys use `NO ACTION`, supported by SQL Server. Apply pending migrations on other existing installations using `php artisan migrate`; do not recreate their database.

The local connection settings are:

```dotenv
DB_CONNECTION=sqlsrv
DB_HOST='.\SQLEXPRESS'
DB_PORT=null
DB_DATABASE=cpstl_kpi
DB_USERNAME=null
DB_PASSWORD=null
DB_URL=null
DB_ENCRYPT=yes
DB_TRUST_SERVER_CERTIFICATE=true
```

Certificate trust is enabled only for this local instance's self-signed development certificate. Keep encryption enabled, and use a trusted server certificate with certificate verification in production. Windows Authentication uses the account running PHP, so the web-server identity must have access when deploying under a different account.

The original SQLite file remains available but is no longer the application's active database. Its records have not been transferred into SQL Server; SQL Server was initialized using the local demonstration seeder. `.env.example` remains a portable SQLite setup for new checkouts, and the automated feature tests remain isolated in an in-memory SQLite database.

For a new SQL Server deployment, use `backend/.env.sqlserver.example` as the configuration reference, provide a dedicated SQL Server database and restricted application account, enable the Microsoft ODBC/PDO SQL Server drivers, generate an application key, and run `php artisan migrate`. Do not run the demonstration seeder in production. Provision the first production administrator through your organization’s approved bootstrap process before opening access.

Keep HTTPS and certificate verification enabled. Serve the React production build and proxy `/api` to Laravel on the same origin; set secure session cookies. Never expose `.env`, storage or the project directory as the web root. Only Laravel's `public` directory is the API web root.

## Verification

```powershell
cd backend
php artisan test
```

```powershell
cd frontend
npm run build
```

The tests cover administration and planning safeguards, task submission/review, mixed KPI/activity ownership, HOF direct employee review, inheritance and overrides, cross-department personal allocation, 100% publication, reviewer isolation, stale source detection, and preserved weight/score history. They use an isolated in-memory SQLite database.

Browser verification covers administrator sign-in, department creation, multi-department profile editing, persisted reporting relationships after reload, and responsive layouts.

## Next milestones

1. Excel import with validation, preview and stable ID matching against the existing plan editor.
2. Private evidence-file uploads and richer activity history views.
3. Agreed KPI outcome calculations against measured actuals, separate from task completion.
4. Formal evaluation and period locking.
5. Production identity integration, organization-scoped authorization for the non-admin endpoints and deployment verification.

KPI outcome scoring, Excel import, evidence-file uploads and SSO/MFA remain unimplemented. HOF own-work percentage reporting remains unverified. Mixed KPI/activity delegation, HOF direct employee work, 100% personal allocation review, manager task breakdown, evidence-summary/link submission and approved-work rollups are implemented. HOFs view source plan definitions read-only.
