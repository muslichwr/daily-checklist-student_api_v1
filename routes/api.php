<?php

use App\Http\Controllers\Api\ActivityController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ChecklistController;
use App\Http\Controllers\Api\ChildController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PlanController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\UploadController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

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

// Public routes
Route::post('/register', [AuthController::class, 'registerTeacher']);
Route::post('/login', [AuthController::class, 'login']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    // Auth
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/change-password', [AuthController::class, 'changePassword']);
    Route::post('/register-parent', [AuthController::class, 'registerParent']);
    
    // Users
    Route::apiResource('users', UserController::class)->except(['store']);
    Route::put('/users/{id}/change-password', [UserController::class, 'changePassword']);
    
    // Children
    Route::apiResource('children', ChildController::class);
    
    // Activities
    Route::apiResource('activities', ActivityController::class);
    Route::post('/activities/{activity}/steps', [ActivityController::class, 'addCustomSteps']);
    
    // Plans
    Route::apiResource('plans', PlanController::class);
    Route::put('/planned-activities/{id}/status', [PlanController::class, 'updateActivityStatus']);
    
    // Checklists
    Route::apiResource('checklists', ChecklistController::class);
    Route::post('/checklists/{checklist}/home-observation', [ChecklistController::class, 'addHomeObservation']);
    Route::post('/checklists/{checklist}/school-observation', [ChecklistController::class, 'addSchoolObservation']);
    Route::put('/checklists/{checklist}/status', [ChecklistController::class, 'updateStatus']);
    
    // Notifications
    Route::apiResource('notifications', NotificationController::class);
    Route::put('/notifications/read-all', [NotificationController::class, 'markAllAsRead']);

    // Photo upload endpoint
    Route::post('/upload-photo', [UploadController::class, 'store']);
}); 