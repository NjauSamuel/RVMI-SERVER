<?php

use App\Http\Controllers\Api\Admin\RoleController;
use App\Http\Controllers\Api\Admin\UserController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CommentController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\GalleryController;
use App\Http\Controllers\Api\Inventory\AssetController;
use App\Http\Controllers\Api\Inventory\CategoryController;
use App\Http\Controllers\Api\PersonController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\TagController;
use App\Http\Controllers\Api\UploadController;
use Illuminate\Support\Facades\Route;

// Public auth endpoints - no self-registration, only login for
// admin-provisioned accounts.
Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/login/google', [AuthController::class, 'loginWithGoogle']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/push-token', [AuthController::class, 'updatePushToken']);

    // Gallery / timeline - every authenticated member.
    Route::get('/gallery', [GalleryController::class, 'index']);
    Route::get('/gallery/{mediaItem}', [GalleryController::class, 'show']);
    Route::post('/gallery/{mediaItem}/view', [GalleryController::class, 'recordView']);
    Route::post('/gallery/{mediaItem}/favorite', [GalleryController::class, 'favorite']);
    Route::delete('/gallery/{mediaItem}/favorite', [GalleryController::class, 'unfavorite']);
    Route::get('/me/saved-photos', [GalleryController::class, 'saved']);
    Route::delete('/gallery/{mediaItem}', [GalleryController::class, 'destroy'])->middleware('permission:delete-records');

    // Events + the umbrella comment thread.
    Route::get('/events', [EventController::class, 'index']);
    Route::get('/events/deleted', [EventController::class, 'deleted'])->middleware('permission:manage-events');
    Route::get('/events/{event}', [EventController::class, 'show']);
    Route::get('/events/{event}/comments', [CommentController::class, 'index']);
    Route::post('/events/{event}/comments', [CommentController::class, 'store'])->middleware('permission:comment');

    Route::middleware('permission:manage-events')->group(function () {
        Route::post('/events', [EventController::class, 'store']);
        Route::patch('/events/{event}', [EventController::class, 'update']);
        Route::delete('/events/{event}', [EventController::class, 'destroy']);
        Route::post('/events/{id}/restore', [EventController::class, 'restore'])->whereNumber('id');
    });

    // Tags: browse, favorite, and the personalised suggestion rail.
    Route::get('/tags', [TagController::class, 'index']);
    Route::post('/tags/{tag}/favorite', [TagController::class, 'favorite']);
    Route::delete('/tags/{tag}/favorite', [TagController::class, 'unfavorite']);
    Route::get('/me/favorites', [TagController::class, 'myFavorites']);
    Route::get('/me/suggested', [TagController::class, 'suggested']);

    // Upload + staging - requires the upload permission.
    Route::middleware('permission:upload-media')->group(function () {
        Route::post('/uploads/presign', [UploadController::class, 'presign']);
        Route::post('/uploads/{mediaItem}/complete', [UploadController::class, 'complete']);
    });

    // Review / approve - requires the approve permission.
    Route::middleware('permission:approve-media')->group(function () {
        Route::get('/review/pending', [ReviewController::class, 'pending']);
        Route::patch('/review/{mediaItem}', [ReviewController::class, 'update']);
        Route::post('/review/{mediaItem}/approve', [ReviewController::class, 'approve']);
        Route::post('/review/{mediaItem}/reject', [ReviewController::class, 'reject']);
        Route::post('/review/bulk-approve', [ReviewController::class, 'bulkApprove']);
    });

    // People / face-training - requires manage-people.
    Route::middleware('permission:manage-people')->group(function () {
        Route::get('/people', [PersonController::class, 'index']);
        Route::post('/people', [PersonController::class, 'store']);
        Route::post('/people/train-from-gallery', [PersonController::class, 'trainFromGallery']);
        Route::post('/people/tag-selected', [PersonController::class, 'tagSelected']);
        Route::post('/people/{person}/presign-reference-photo', [PersonController::class, 'presignReferencePhoto']);
        Route::post('/people/{person}/train', [PersonController::class, 'trainFromReferencePhoto']);
    });

    // Inventory - requires manage-inventory (icon hidden entirely for
    // members at the client level too).
    Route::middleware('permission:manage-inventory')->group(function () {
        Route::get('/inventory/categories', [CategoryController::class, 'index']);
        Route::post('/inventory/categories', [CategoryController::class, 'store']);
        Route::delete('/inventory/categories/{category}', [CategoryController::class, 'destroy']);

        Route::get('/inventory/assets', [AssetController::class, 'index']);
        Route::post('/inventory/assets/presign', [AssetController::class, 'presign']);
        Route::post('/inventory/assets', [AssetController::class, 'store']);
        Route::get('/inventory/assets/{asset}', [AssetController::class, 'show']);
        Route::patch('/inventory/assets/{asset}', [AssetController::class, 'update']);
        Route::delete('/inventory/assets/{asset}', [AssetController::class, 'destroy']);
    });

    // User + role management - requires manage-users / manage-roles.
    Route::middleware('permission:manage-users')->group(function () {
        Route::get('/admin/users', [UserController::class, 'index']);
        Route::post('/admin/users', [UserController::class, 'store']);
        Route::patch('/admin/users/{user}', [UserController::class, 'update']);
        Route::delete('/admin/users/{user}', [UserController::class, 'destroy'])->middleware('permission:delete-records');
    });

    Route::middleware('permission:manage-roles')->group(function () {
        Route::get('/admin/roles', [RoleController::class, 'index']);
        Route::post('/admin/roles', [RoleController::class, 'store']);
        Route::patch('/admin/roles/{role}', [RoleController::class, 'update']);
        Route::delete('/admin/roles/{role}', [RoleController::class, 'destroy']);
    });
});
