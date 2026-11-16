# CPSTL performance system — review, 16 November 2026

**Second follow-up, 16 November 2026:** R3–R6 are addressed. Delivery work now has a persisted `work_kind`, with a one-time compatibility backfill for existing records. Unstarted handoffs can be edited/cancelled through audited, version-checked operations; descendant allocations, submissions and replacement history block unsafe changes. Team owner selection survives searches with no matching results. Request-only read reuse and scoped KPI loading reduced the same local manager workspace observation from 2,018 queries / 6,881 ms to 289 queries / 363 ms (single local reads, not a concurrent-load benchmark). Validation: 172 backend tests / 2,361 assertions, 30 frontend unit tests, lint/build, and all 18 browser workflows passed. The metadata migration was applied locally; actual employee assignments were not reassigned or cancelled by the agent. R7 and the deployment/policy decisions below remain separate follow-up work.

**Follow-up, 16 November 2026:** R1 and R2 have been corrected. Progress/submit endpoints now reject unclaimed incoming manager activities, while explicitly retained tasks and historical started work remain usable. Login failures are limited per normalized identifier + IP (5 failures, 60 seconds), cleared on successful authentication, with a separate configurable IP-wide limit (`LOGIN_IP_PER_MINUTE`, default 300). The full backend regression suite passes: 169 tests, 2,322 assertions. The original findings below remain as review history; R3 and the other findings are separate follow-up work.

## Assessment

The principal workflows pass the existing automated checks. This is not a bug-free or production-security certification. Additional edge-case probes found four reproducible workflow/availability problems; code inspection and a local SQL Server measurement identified further usability and performance issues. Fix the P1 findings before rollout and resolve the P2 workflow issues before organization-wide acceptance testing.

No application implementation was changed during this review. Existing uncommitted work was retained. Live employee records, reporting relationships and assignments were not modified. The only live database operation was a read-only workspace measurement. Temporary reproduction tests ran against isolated in-memory SQLite and were removed after recording their results.

## Verification performed

| Check | Result |
| --- | --- |
| Full backend suite | 164 passed; 2,244 assertions |
| Frontend lint, unit tests and build | Passed; 30 unit tests |
| Complete browser suite | 18 passed, about 3.2 minutes |
| Additional review probes | 4 passed, 36 assertions confirming the undesirable current behavior described below |
| Production frontend dependency audit | npm audit --omit=dev: zero reported advisories |
| Backend locked dependency audit | One low-severity Laravel advisory |
| Production configuration check | Failed on the current local configuration; see deployment section |
| Local SQL Server workspace read | 2,018 queries; 6,881 ms for a manager with 8 managed activities and 2 linked KPAs |

Review coverage included EPF login and forced password change, session invalidation, CSRF, role restrictions, department-scoped planning and mixed plans, plan editing, full KPI handoffs, activity handoffs, recursive task breakdowns, approval/resubmission, weighted roll-up, personal allocation, extra work and approved replacement, monthly results, timeliness, notification navigation, grouped team views, reporting structure, responsive layouts and theme switching.

## Confirmed findings

### R1 — P1: Manager can submit an incoming activity without choosing Keep with me

**Evidence:** `backend/app/Http/Controllers/DeliveryController.php:403` and `:420`; workspace classification at `:202`.

**Reproduction:** Assign an entire activity to a reporting manager. Their workspace correctly returns `tasks: []` and one `managed` activity. Without selecting Keep with me, POST to `/api/delivery/tasks/{incoming-task-id}/submit` with its current version and evidence. The API returns 200 and records a submitted task. The same state guard is missing from the progress endpoint.

**Impact:** The recently requested manager acceptance/allocation step is enforced by screen visibility only. API clients and other task entry points can bypass it. This is a business-workflow bypass for the assignee's own work, not proof of access to another user's work.

**Recommended fix:** Persist an explicit incoming-activity versus accepted-personal-task state. Enforce acceptance/allocation in progress and submit handlers. Preserve historical submissions and existing in-progress work through a deliberate migration rule.

**Acceptance:** A newly received manager activity cannot be progressed/submitted until explicitly kept or broken down. A retained child task can be submitted normally. Delegation to a subordinate does not create an additional personal task for the delegating manager.

### R2 — P1: Login throttle is shared across users behind the same IP

**Evidence:** `backend/routes/web.php:48`; installed framework `ThrottleRequests.php:224` resolves unauthenticated request keys using domain and IP.

**Reproduction:** Five failed logins with five different identifiers from one IP, followed by a valid login for another account from that IP. The legitimate login returns 429. Reproduced in an isolated HTTP test.

**Impact:** An office using a shared public IP can block legitimate staff logins. One client's failed attempts can affect unrelated users. The generic throttle counts requests, rather than being a failure-only per-account limiter.

**Recommended fix:** Use an explicitly named login limiter with an identifier-plus-IP failure limit and a separate, suitably sized IP-wide abuse limit. Define success/reset behavior and test a shared-office-IP scenario. Do not simply remove abuse protection.

### R3 — P2: Work type depends on editable task title

**Evidence:** `backend/app/Http/Controllers/DeliveryController.php:202-205`.

**Reproduction:** An incoming 100% task titled exactly like its source activity appears in Assigned activities, with My tasks empty. Change only its title to append "- updated" through the existing task-breakdown endpoint. The same recipient now receives one personal task and no managed activity.

**Impact:** An ordinary title edit changes routing and manager workflow. A one-task breakdown that happens to share its activity title can also be classified as a whole activity.

**Recommended fix:** Persist assignment kind/source identifiers. Keep editable display titles out of authorization and workflow classification. Reconcile historical records before switching classification.

### R4 — P2: Unstarted full-KPI handoff has no normal correction/cancel route

**Evidence:** `backend/app/Http/Controllers/KpiHandoffController.php:141-147`; task replacement guard in `DeliveryController::saveBreakdown`.

**Reproduction:** Manager A assigns a full KPI to B. Before B makes any submission, A attempts to correct the recipient to another eligible manager. The handoff endpoint returns 409 because a handoff already exists. The ordinary activity-breakdown endpoint also blocks modifications to mapped handoff tasks.

**Impact:** Selecting the wrong manager requires escalation or a wider HOF reassignment rather than a scoped correction. Existing history should be protected, but an untouched handoff needs a recovery path.

**Recommended fix:** Add an audited edit/cancel operation restricted to the assigning manager and scoped editor, with optimistic version checks. Permit it only when no descendant work, submission or replacement would be invalidated. Show a clear escalation route when changes are blocked.

### R5 — P2: Manager workspace performs excessive database queries

**Evidence:** One read-only local SQL Server load for EPF 16503 returned 8 activities and 2 linked KPAs using **2,018 database queries in 6.881 seconds**. This is one observation, not a concurrency benchmark.

**Hot paths:** `DeliveryController.php:135-257`; `KpiAssignmentController.php:36-47` repeatedly evaluates assignment context, timeliness and allocation sources. Context traversals and membership lookups are repeated across workspace and personal score construction.

**Impact:** Noticeable loading delays and unnecessary SQL Server load; organization-wide concurrent usage has not been validated.

**Recommended fix:** Batch memberships, reporting relationships, assignments, task trees and submissions; reuse immutable context/summaries within a request; add query-count and representative SQL Server performance checks. Validate invalidation before introducing cross-request caches.

### R6 — P2: Owner filter silently falls back to all owners after search

**Evidence:** `frontend/src/features/delivery/TeamWork.jsx:39`; incoming tasks are already filtered by the global search in `DeliveryWorkspace.jsx:80`.

**Code path:** Select owner B, then search for text found only in owner A's tasks. B disappears from the filtered groups, so `groups.has(owner)` is false and the selected owner becomes `all`. A's work is shown instead of a no-results message for B.

**Impact:** The user can mistake another person's work for results within the chosen owner scope. This is a filtering/UX issue, not evidence of a department authorization leak.

**Recommended fix:** Keep the owner selection independent of filtered results. Retain the full permitted owner option list and display an empty state when that owner's tasks do not match the search.

### R7 — P2 maintenance: Locked Laravel version has a low-severity advisory

Composer reports **CVE-2026-128083 / GHSA-63hm-g96r-2m2q**, "Laravel: Task Output Injection - Concurrency Process", against installed Laravel 13.39.0. The maintainer advisory lists 13.40.0 as the patched 13.x version:

https://github.com/laravel/framework/security/advisories/GHSA-63hm-g96r-2m2q

The advisory requires a reachable concurrency-process task with specific unsafe user-controlled output handling. A search of application code/routes found no `Concurrency::run` usage; direct exploitability in this application was not established. This is not a finding of demonstrated remote execution in CPSTL.

**Recommended fix:** Upgrade to a supported patched version in a separate dependency change, then rerun the complete suite and dependency audit. No dependency versions were changed during this review.

## Workflow decisions requiring clear product rules

1. **Monthly KPI reporter after manager handoff:** `MonthlyKpiResultController.php:194-225` chooses the reporter from the original `kpi_delegations` record. Later manager-to-manager handoffs do not change that reporter. This can be intentional: the original KPI owner remains accountable while delivery is delegated. Decide and explain whether the original manager or final receiving manager submits monthly outcomes; do not assume the current screen is automatically wrong.
2. **Completion, timeliness and outcome score:** Approved work completion and timeliness are separate calculations. Late approved work can show 100% completion with a lower timeliness result. No automatic salary deduction should be inferred from either figure. A payroll/final appraisal weighting policy needs an explicit approved definition and validation.
3. **Linked KPA is a source link:** Full KPI delegation creates a linked responsibility, not an automatically authored new subordinate KPA/KPI plan. Individual activity assignments retain their source hierarchy without creating a full linked KPA.
4. **Team view scope:** Team progress primarily lists direct tasks under the current manager's managed activities. It is not a complete flattened organization-wide descendant task list. Use an explicit drill-down/view label so managers understand the scope.
5. **Provisional timeliness:** Before all scheduled weight is assessed, the component emphasizes the estimated/provisional score. Explain provisional versus approved timing clearly, especially before using reports in appraisal discussions.

## Production readiness

The current local `security:check` reports:

- APP_ENV is not production.
- APP_URL does not use HTTPS.
- Session cookie security settings do not meet production requirements.
- Session lifetime is outside the configured production range of 1–60 minutes.
- SQL Server is not configured to verify a trusted server certificate.

These are deployment blockers if the same configuration is used for rollout, not evidence that an intentionally local HTTP development setup is itself misconfigured. The existing production middleware fails closed when required settings are insecure. Validate the actual deployed environment separately.

The requested initial-password-equals-EPF policy is predictable. Forced password change protects subsequent use but does not prove the identity of the first person to claim an unused account. Before broader rollout, decide whether controlled distribution, single-use activation or another identity-verification measure is required. The requested six-character minimum was not changed or treated as an implementation defect.

Also verify on staging: scheduler execution for deadline reminders, backup restoration, TLS/proxy handling, SQL Server transactional concurrency, recovery from interrupted deployment, and representative concurrent staff load.

## Suggested correction order

1. Fix R1 and replace title-based routing with explicit state/type (R3).
2. Correct login rate-limit isolation (R2).
3. Add safe unstarted handoff correction/cancellation (R4).
4. Reduce workspace query duplication and measure SQL Server load (R5).
5. Preserve filter scope and clarify monthly/timeliness labels (R6 and product decisions).
6. Patch Laravel and pass deployment configuration checks (R7 and production section).
7. Run department pilot/UAT covering HOF → manager → manager → employee, own-work claims, return/resubmit, extra-work replacement and reporting-link changes.

## Review limits

Automated functional writes used isolated SQLite, not the live SQL Server database. The live performance observation was read-only and single-user. No penetration-testing scanner, exploit attempt against production, backup restore, full SQL Server write/concurrency test, or external system/payroll integration test was performed. The frontend advisory check excluded development dependencies. Passing automated tests confirms covered scenarios; it does not establish that all possible workflows or security conditions are correct.
