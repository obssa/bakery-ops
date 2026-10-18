<?php

use App\Http\Controllers\Api\HealthController;
use Illuminate\Support\Facades\Route;

// All routes here are served under /api/v1 (see bootstrap/app.php). Module routes are registered by
// each module's service provider under the same prefix from Phase 0 step 0.4 onward.
Route::get('/health', HealthController::class)->name('health');
