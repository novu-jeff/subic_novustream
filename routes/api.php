<?php

use App\Http\Controllers\Api\CallbackController;
use App\Http\Controllers\Api\InspectionController;
use App\Http\Controllers\Api\LoginController;
use App\Http\Controllers\Api\MeterController;
use App\Http\Controllers\Api\ReprintController;
use App\Http\Controllers\Api\SyncController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ReadingController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\OfflineDataController;


/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::fallback(function () {
    abort(404);
});

Route::post('login', [LoginController::class, 'login']);
Route::post('logout', [LoginController::class, 'logout']);

// Flutter app: GET /api/app-version (set baseUrl / appVersionUrl in lib/config/merchant_config.dart)
Route::get('app-version', function () {
    return response()->json([
        'version'      => env('APP_VERSION', '1.0.0'),
        'build_number' => (int) env('APP_BUILD_NUMBER', 1),
        'apk_url'      => env('APK_URL', ''),
    ]);
});

Route::post('transaction/callback', [CallbackController::class, 'save'])
    ->name('transaction.callback');
Route::post('payment/status/{reference_no}', [CallbackController::class, 'status'])
    ->name('transaction.status');

Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('inspection')->group(function () {
        Route::post('search', [InspectionController::class, 'search']);
        Route::post('update', [InspectionController::class, 'update']);
    });

    Route::prefix('meter')->group(function () {
        Route::post('search', [MeterController::class, 'search']);
        Route::post('reading', [MeterController::class, 'reading']);
    });

    Route::prefix('reprint')->group(function () {
        Route::post('search', [ReprintController::class, 'search']);
        Route::post('search/{reference_no}', [ReprintController::class, 'view']);
    });

    Route::get('sync', [SyncController::class, 'sync']);

});

Route::prefix('v1')->group(function() {
    Route::post('callback/{reference_no}', [PaymentController::class, 'callback']);
});


Route::post('/offline/reading-sync', [ReadingController::class, 'store'])
    ->middleware('log.offline.api')
    ->withoutMiddleware([\App\Http\Middleware\VerifyCsrfToken::class])
    ->name('api.reading.sync');

Route::middleware('log.offline.api')->group(function () {
    Route::get('/offline/download', [OfflineDataController::class, 'download']);
    Route::get('/offline/reading-dates', [OfflineDataController::class, 'readingDates']);
});