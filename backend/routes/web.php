<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\DeliveryController;
use App\Http\Controllers\ExcelPlanImportController;
use App\Http\Controllers\HofWorkController;
use App\Http\Controllers\KpiAssignmentController;
use App\Http\Controllers\MdDashboardController;
use App\Http\Controllers\MonthlyKpiResultController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OrganizationChartController;
use App\Http\Controllers\PerformancePlanController;
use App\Http\Controllers\PersonalAllocationController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\TvDisplayController;
use App\Http\Middleware\EnforceExecutiveReadOnly;
use App\Http\Middleware\EnsureWorkspaceIdentity;
use App\Http\Middleware\RequireDeliveryAccess;
use App\Http\Middleware\RequirePlanningAccess;
use App\Http\Middleware\RequireSystemAdmin;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json(['application' => 'Pulse administration API', 'frontend' => 'http://127.0.0.1:5173']);
});

Route::prefix('api')->middleware([EnsureWorkspaceIdentity::class, EnforceExecutiveReadOnly::class])->group(function () {
    Route::get('/session', [SessionController::class, 'show']);
    Route::get('/organization-chart', [OrganizationChartController::class, 'show'])->middleware('auth');
    Route::get('/display/workspace', [TvDisplayController::class, 'workspace'])->middleware('auth');
    Route::middleware('auth')->group(function () {
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::post('/notifications/read-all', [NotificationController::class, 'read']);
        Route::post('/notifications/{notification}/read', [NotificationController::class, 'read'])->whereNumber('notification');
    });
    Route::get('/md/workspace', [MdDashboardController::class, 'workspace'])->middleware('auth');
    Route::middleware('auth')->prefix('kpi-results')->group(function () {
        Route::get('/workspace', [MonthlyKpiResultController::class, 'workspace']);
        Route::post('/submit', [MonthlyKpiResultController::class, 'submit'])->middleware('throttle:30,1');
        Route::post('/review', [MonthlyKpiResultController::class, 'review'])->middleware('throttle:30,1');
    });
    Route::post('/login', [SessionController::class, 'login'])->middleware('throttle:5,1');
    Route::post('/logout', [SessionController::class, 'logout'])->middleware('auth');
    Route::middleware('auth')->prefix('allocations')->group(function () {
        Route::get('/workspace', [PersonalAllocationController::class, 'workspace']);
        Route::get('/people/{owner}/{year}', [PersonalAllocationController::class, 'show'])->whereNumber(['owner', 'year']);
        Route::post('/draft', [PersonalAllocationController::class, 'save'])->middleware('throttle:30,1');
        Route::post('/{allocation}/publish', [PersonalAllocationController::class, 'publish'])->whereNumber('allocation')->middleware('throttle:30,1');
    });
    Route::middleware(['auth', RequireDeliveryAccess::class])->prefix('delivery')->group(function () {
        Route::get('/workspace', [DeliveryController::class, 'workspace']);
        Route::put('/assignments/{assignment}/tasks', [DeliveryController::class, 'breakdown'])->whereNumber('assignment')->middleware('throttle:30,1');
        Route::put('/tasks/{task}/progress', [DeliveryController::class, 'progress'])->whereNumber('task')->middleware('throttle:30,1');
        Route::put('/tasks/{task}/tasks', [DeliveryController::class, 'breakdownTask'])->whereNumber('task')->middleware('throttle:30,1');
        Route::post('/tasks/{task}/submit', [DeliveryController::class, 'submit'])->whereNumber('task')->middleware('throttle:30,1');
        Route::post('/submissions/{submission}/review', [DeliveryController::class, 'review'])->whereNumber('submission')->middleware('throttle:30,1');
    });
    Route::middleware('auth')->prefix('hof')->group(function () {
        Route::get('/workspace', [HofWorkController::class, 'workspace']);
        Route::post('/assignments', [HofWorkController::class, 'assign'])->middleware('throttle:30,1');
        Route::post('/kpi-assignments', [KpiAssignmentController::class, 'assign'])->middleware('throttle:30,1');
        Route::put('/assignments/{assignment}/progress', [HofWorkController::class, 'progress'])->whereNumber('assignment')->middleware('throttle:30,1');
    });
    Route::middleware(['auth', RequirePlanningAccess::class])->prefix('planning')->group(function () {
        Route::get('/workspace', [PerformancePlanController::class, 'workspace']);
        Route::get('/excel-import/options', [ExcelPlanImportController::class, 'options']);
        Route::post('/excel-import', [ExcelPlanImportController::class, 'import'])->middleware('throttle:30,1');
        Route::get('/plans/{plan}', [PerformancePlanController::class, 'show'])->whereNumber('plan');
        Route::get('/plans/{plan}/deletion', [PerformancePlanController::class, 'deletionPreview'])->whereNumber('plan');
        Route::post('/plans', [PerformancePlanController::class, 'store'])->middleware('throttle:30,1');
        Route::put('/plans/{plan}', [PerformancePlanController::class, 'update'])->whereNumber('plan')->middleware('throttle:30,1');
        Route::delete('/plans/{plan}', [PerformancePlanController::class, 'destroy'])->whereNumber('plan')->middleware('throttle:30,1');
        Route::post('/plans/{plan}/publish', [PerformancePlanController::class, 'publish'])->whereNumber('plan')->middleware('throttle:30,1');
    });
    Route::middleware(['auth', RequireSystemAdmin::class])->prefix('admin')->group(function () {
        Route::get('/workspace', [AdminController::class, 'workspace']);
        Route::put('/departments/{department}/reporting', [AdminController::class, 'saveDepartmentReporting'])->whereNumber('department');
        Route::post('/users', [AdminController::class, 'saveUser']);
        Route::put('/users/{user}', [AdminController::class, 'saveUser']);
        Route::delete('/users/{user}', [AdminController::class, 'removeUser'])->whereNumber('user');
        Route::post('/departments', [AdminController::class, 'saveDepartment']);
        Route::put('/departments/{department}', [AdminController::class, 'saveDepartment']);
        Route::delete('/departments/{department}', [AdminController::class, 'removeDepartment'])->whereNumber('department');
    });
});
