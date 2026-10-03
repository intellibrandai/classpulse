<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Api\DayController;
use App\Http\Controllers\Api\ReportCommentController;
use App\Http\Controllers\Api\StudentNoteController;
use App\Http\Controllers\Api\StudentSemesterController;
use App\Http\Controllers\Api\WeekController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\ClassController;
use App\Http\Controllers\DailyController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\SemesterController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\StudentHistoryController;
use App\Http\Controllers\WeeklyController;
use Illuminate\Support\Facades\Route;

// /up is registered in bootstrap/app.php.

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:login');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
    Route::redirect('/', '/daily');

    Route::get('/daily', DailyController::class);
    Route::get('/roster', [ClassController::class, 'index']);
    Route::get('/semester', [SemesterController::class, 'index']);
    Route::get('/export/semester.csv', [SemesterController::class, 'export']);
    Route::get('/weekly', [WeeklyController::class, 'index']);
    Route::get('/export/weekly.csv', [WeeklyController::class, 'export']);
    Route::post('/account/email', [AccountController::class, 'updateEmail'])->name('account.email');
    Route::post('/account/password', [AccountController::class, 'updatePassword'])->name('account.password');
    Route::post('/classes', [ClassController::class, 'store']);
    Route::put('/classes/{class}', [ClassController::class, 'update']);
    Route::delete('/classes/{class}', [ClassController::class, 'destroy']);

    Route::get('/classes/{class}/import', [ImportController::class, 'show']);
    Route::post('/classes/{class}/import/preview', [ImportController::class, 'preview']);
    Route::post('/classes/{class}/import/commit', [ImportController::class, 'commit']);
    Route::post('/classes/{class}/students', [StudentController::class, 'store']);
    Route::get('/students/{student}', [StudentHistoryController::class, 'show']);
    Route::get('/export/student/{student}.csv', [StudentHistoryController::class, 'export']);
    Route::put('/students/{student}', [StudentController::class, 'update']);
    Route::post('/students/{student}/archive', [StudentController::class, 'archive']);
    Route::post('/students/{student}/restore', [StudentController::class, 'restore']);

    Route::get('/api/classes/{class}/days/{date}', [DayController::class, 'show'])
        ->where('date', '[0-9]{4}-[0-9]{2}-[0-9]{2}');
    Route::post('/api/classes/{class}/days/{date}/operations', [DayController::class, 'store'])
        ->where('date', '[0-9]{4}-[0-9]{2}-[0-9]{2}');

    Route::get('/api/classes/{class}/weeks/{monday}', [WeekController::class, 'show'])
        ->where('monday', '[0-9]{4}-[0-9]{2}-[0-9]{2}');

    Route::scopeBindings()->get('/api/classes/{class}/students/{student}/semester', [StudentSemesterController::class, 'show']);

    Route::get('/api/classes/{class}/report-comments', [ReportCommentController::class, 'index']);

    Route::scopeBindings()->prefix('api/classes/{class}/students/{student}/report-comments')->group(function () {
        Route::put('/{period}', [ReportCommentController::class, 'upsert']);
        Route::delete('/{period}', [ReportCommentController::class, 'destroy']);
    });

    Route::scopeBindings()->prefix('api/classes/{class}/students/{student}/notes')->group(function () {
        Route::get('/', [StudentNoteController::class, 'index']);
        Route::put('/{date}', [StudentNoteController::class, 'upsert'])->where('date', '[0-9]{4}-[0-9]{2}-[0-9]{2}');
        Route::delete('/{date}', [StudentNoteController::class, 'destroy'])->where('date', '[0-9]{4}-[0-9]{2}-[0-9]{2}');
    });
});
