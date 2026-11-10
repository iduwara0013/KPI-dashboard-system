# CPSTL Performance Management System

React frontend, Laravel API and Microsoft SQL Server for department reporting, performance plans, weighted delegation, approval and monthly KPI measurement.

## Current workspaces

- **System administrator:** users, EPF numbers, designations, grades, departments, dynamic department responsibilities and reporting relationships. The company MD has no department membership.
- **Plan Editor:** overview, plans and revision-history tabs, embedded personal allocations and monthly results. A personal plan can belong to any active department member.
- **HOF:** overview, plan/assignment tabs, own work, delegated work/team tabs, task reviews, embedded allocations and monthly results. One HOF can lead several departments.
- **Manager / employee:** overview, task/activity/KPI tabs, team reviews and embedded performance pages, according to the person's responsibilities and reporting connections.
- **MD:** read-only organization, department, owner and delivery views.
- **TV display:** authorized MD/HOF/manager scope, weighted completion, KPI delivery, employee task-count cards, attention alerts, automatic paging, department rotation and fullscreen controls.

Designations are descriptive. System access and department/reporting assignments determine permissions.

## How work and percentages behave

A published plan has KPA weights totaling 100%. Each KPA's KPI weights total 100%; each KPI's activity weights total 100%. Drafts can remain incomplete. Targets are distinct from weights.

HOFs assign complete KPIs or activities to themselves or eligible reporting people. A recipient can divide work among their direct reporting people. Children total 100% of their immediate parent. For example, a task weighted 80% of its activity and a child weighted 30% of that task contribute **24% of the activity**.

Manager/employee personal plans are executable too. In **My work → My tasks**, choose an eligible reporting manager for an unstarted personal plan activity, then select **Start activity**. The task uses normal progress, evidence, approval and resubmission. If reporting has not been configured, an administrator must connect the reporting manager first. A changed plan or reviewer relationship invalidates the old assignment until it is restarted; its earlier task generation remains in history.

- Reported progress is provisional.
- Submitted work contributes to confirmed completion only after reviewer approval.
- HOFs confirm their own work with evidence; other people cannot approve their own work.
- Confirmed child weights roll up through the parent tree.
- Personal completion normalizes all responsibilities assigned to the person. New work can therefore reduce their personal percentage without changing completed work or upstream contribution weights.
- Monthly KPI actual/target measurement is separate from task completion. Monthly submissions, correction requests and approvals retain history and notify the responsible person.

## Notifications and navigation

The top-bar bell opens a notification drawer with All, Unread, Action required and Archived views, categories and an expanded view. Reading a notification does not complete its work. Links identify the task, submission or monthly result rather than relying on matching a title. Archived updates can be restored. Refresh retains the loaded history window.

Unsaved plan/allocation edits are guarded during sidebar navigation, notification navigation, browser Back/Forward and page unload. Session identity checks prevent an open form being saved under another account. Session recovery preserves form contents and never automatically replays a save.

Deadline reminders cover work due within three days and overdue work. They run from the scheduler, not from every notification poll. Events are deduplicated; retired/ineligible and approved work is excluded. The MD receives organization summaries.

## Run the current project

Requirements: compatible PHP 8.3+ with Composer, Node.js compatible with the locked Vite packages and npm. This checkout uses PHP 8.5, Node.js 24 and SQL Server Express. Use the committed lockfiles.

Start the Laravel API:

```powershell
cd "D:\KPI dashboard system\backend"
php artisan serve --host=127.0.0.1 --port=8000
```

In a second terminal, start React:

```powershell
cd "D:\KPI dashboard system\frontend"
npm run dev
```

Open http://127.0.0.1:5173. Vite proxies `/api` to port 8000. If 5173 is already running, open that server rather than starting a duplicate.

For scheduled reminders, keep a third terminal running:

```powershell
cd "D:\KPI dashboard system\backend"
php artisan schedule:work
```

The reminder command is scheduled hourly in Asia/Colombo. It can also be run explicitly with `php artisan notifications:overdue`.

To serve a built frontend and the API through a single development server instead of Vite:

```powershell
cd "D:\KPI dashboard system\frontend"
npm run build
npm start
```

The built server also uses 5173. Stop the other server on that port first. These PHP/Vite servers are development tools.

## SQL Server and installations

The current local connection is `cpstl_kpi` on `.\SQLEXPRESS`, using Windows Authentication. The Windows identity running PHP needs access to that instance and database. Database credentials stay in `backend/.env`, which is ignored by Git.

For an existing database, install dependencies if needed and apply pending migrations:

```powershell
cd "D:\KPI dashboard system\backend"
composer install
php artisan migrate
```

Do not overwrite an existing `.env`, reseed office data or use `migrate:fresh` against the office database. Demo seeding is limited to local/testing and is for a new disposable environment. It creates fictitious accounts, not the office's employee records.

Fresh development installations can use SQLite as described by Laravel's default `.env.example`; create the database and application key before migrations. Production hosting needs HTTPS, an appropriate PHP application server, database access, persistent scheduler operation and backup/restore procedures.

New and replacement account passwords require at least 6 characters. Existing passwords are not changed automatically. Password resets, account activation/deactivation and system-role changes revoke the person's existing sessions. Normal sign-out ends only the current session. Sessions created before the security update require a fresh login.

Use `backend/.env.sqlserver.example` as the production configuration reference, supplying the real HTTPS domain, application key, restricted database credentials and a SQL Server certificate trusted by the application host. Run `php artisan security:check` from `backend` before deployment; it exits unsuccessfully until the production settings are ready. Production HTTP requests are refused if HTTPS or the checked configuration is insecure. A TLS-terminating reverse proxy must be configured as a specifically trusted proxy in Laravel; forwarded headers are not trusted automatically. Apply equivalent security headers to the React site at its production web server; Laravel's API headers do not configure a separate frontend host.

## Checks

Frontend lint, unit tests and production build:

```powershell
cd "D:\KPI dashboard system\frontend"
npm ci
npm run check
```

ESLint checks undefined identifiers, invalid JSX component references and hook ordering as well as the recommended JavaScript rules. Browser tests use the installed Google Chrome in headless mode:

```powershell
npm run test:e2e
```

Browser tests start their own server on **5174**, recreate only `frontend/test-results/kpi-browser.sqlite`, seed fictional accounts and use a separate session cookie. They do not connect to SQL Server. The checks exercise browser login, nested weighted delegation, correction/resubmission/approval, notification links, unsaved allocation protection, MD navigation, personal-plan execution and monthly results. Failure traces, screenshots and the HTML report are stored in ignored test-output folders.

Backend's normal regression suite uses **SQLite `:memory:`** through `backend/phpunit.xml`:

```powershell
cd "D:\KPI dashboard system\backend"
php artisan test
vendor/bin/pint --dirty --test
```

For SQL Server integration checks, use **only** the dedicated `cpstl_kpi_test_review_20261110` database. Create it once in your local test instance if it does not exist, then run:

```powershell
php -d max_execution_time=0 vendor/phpunit/phpunit/phpunit --configuration=phpunit.sqlserver.xml
```

This suite recreates tables in that disposable test database. Its bootstrap rejects database names outside `cpstl_kpi_test_*`, DB_URL overrides and cached configuration. Never point it at office data. The SQL Server suite checks migrations and the same permissions, transaction/version, reporting and workflow behavior against the actual database engine.

## Source organization

- `frontend/src/app`: application routing and guarded browser history.
- `frontend/src/services`: API/session handling and external-navigation guards.
- `frontend/src/components`: shared UI, organization/delivery analytics and action-center components.
- `frontend/src/features`: organization, planning, delivery, HOF, MD, KPI results, allocations, notifications and TV display.
- `frontend/src/features/delivery`: separate task cards, task forms, evidence history, shared delivery formatting and personal-plan startup.
- `frontend/src/styles`: shared foundation, theme and workspace navigation styles.
- `backend/app/Http/Controllers`: authorization-scoped organization, planning and delivery endpoints.
- `backend/tests/Feature`: workflow and permission regression tests; `frontend/e2e`: browser regression tests.

Audit/version records preserve organization edits, plan revisions, work assignments and delivery decisions. Task evidence currently supports a summary and HTTP(S) link; binary file uploads are not implemented. Excel import remains a backend pilot capability and is not shown in the manual plan creation menu.
