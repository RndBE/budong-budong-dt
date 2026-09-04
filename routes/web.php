<?php

use App\Http\Controllers\Api\AlertController;
use App\Http\Controllers\Api\DashboardDataController;
use App\Http\Controllers\Api\EnvironmentController;
use App\Http\Controllers\Api\IngestController;
use App\Http\Controllers\Api\MaintenanceController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\Api\StationController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\PageController;
use Illuminate\Support\Facades\Route;

Route::get('/login', [AuthController::class, 'show'])->middleware('guest')->name('login');
Route::post('/login', [AuthController::class, 'store'])->middleware('guest');
Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');

/*
| Device ingest: token authenticated, not part of the browser session.
| POST /api/ingest with header X-Ingest-Token.
*/
Route::post('/api/ingest', IngestController::class)->name('api.ingest');

Route::middleware('auth')->group(function () {
    Route::redirect('/', '/dashboard');

    Route::get('/dashboard', [PageController::class, 'dashboard'])->name('dashboard');

    /*
    | The digital twin is the interactive stage: dam render with sensor
    | markers, and the 360 panorama once a marker is picked.
    */
    Route::get('/digital-twin', [PageController::class, 'twin'])->name('twin');
    Route::get('/digital-twin/{station}', [PageController::class, 'twin'])->name('twin.station');

    // Kept so older links and bookmarks to /peta keep working.
    Route::redirect('/peta', '/digital-twin');
    Route::get('/peta/{station}', fn (string $station) => redirect()->route('twin.station', $station));
    Route::get('/sensor', [PageController::class, 'sensors'])->name('sensors');
    Route::get('/analisa', [PageController::class, 'analytics'])->name('analytics');
    Route::get('/perawatan', [PageController::class, 'maintenance'])->name('maintenance');
    Route::get('/peringatan', [PageController::class, 'alerts'])->name('alerts');
    Route::get('/laporan', [PageController::class, 'reports'])->name('reports');
    Route::get('/pengaturan', [PageController::class, 'settings'])->name('settings');

    /*
    | Browser JSON endpoints. Session authenticated: the dashboard polls these
    | on the cadence set in config/dam.php.
    */
    Route::prefix('api')->name('api.')->group(function () {
        Route::get('/environment', EnvironmentController::class)->name('environment');
        Route::get('/environment/curve', [EnvironmentController::class, 'curve'])->name('environment.curve');
        Route::get('/dashboard', DashboardDataController::class)->name('dashboard');
        Route::get('/stations', [StationController::class, 'index'])->name('stations.index');
        Route::get('/stations/{code}', [StationController::class, 'show'])->name('stations.show');
        Route::get('/stations/{code}/series/{metric}', [StationController::class, 'series'])->name('stations.series');
        Route::post('/stations/{code}/position', [StationController::class, 'move'])->name('stations.move');
        Route::post('/stations/{code}/sphere', [StationController::class, 'sphere'])->name('stations.sphere');
        Route::get('/alerts', [AlertController::class, 'index'])->name('alerts.index');
        Route::post('/alerts/{alert}/acknowledge', [AlertController::class, 'acknowledge'])->name('alerts.acknowledge');
        Route::post('/alerts/{alert}/resolve', [AlertController::class, 'resolve'])->name('alerts.resolve');
        Route::get('/maintenance', [MaintenanceController::class, 'index'])->name('maintenance.index');
        Route::post('/maintenance/{task}/status', [MaintenanceController::class, 'updateStatus'])->name('maintenance.status');
        Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
        Route::post('/reports', [ReportController::class, 'store'])->name('reports.store');
        Route::get('/reports/{report}/download', [ReportController::class, 'download'])->name('reports.download');
        Route::get('/settings', [SettingController::class, 'index'])->name('settings.index');
        Route::post('/settings', [SettingController::class, 'store'])->name('settings.store');
    });
});
