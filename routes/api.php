<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\SystemAdminAuthController;
use App\Http\Controllers\Api\V1\UserAuthController;
use App\Http\Controllers\Api\V1\UserFunctionsController;
use App\Http\Controllers\Api\V1\SystemAdminFunctionsController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\LocationController;
use App\Http\Controllers\Api\V1\FloorController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\BookingController;

use App\Http\Middleware\EnsureAdmin;

Route::prefix('system-admin')->group(function(){
    Route::post('/login', [SystemAdminAuthController::class, 'login']);

    Route::middleware(['auth:sanctum', EnsureAdmin::class])->group(function(){
        Route::post('/logout', [SystemAdminAuthController::class, 'logout']);
        Route::post('/register-workspace', [SystemAdminFunctionsController::class, 'registerCompany']);
    });
});

Route::prefix('{tenant_slug}')->middleware('settenant')->group(function(){
    Route::post('/login', [UserAuthController::class,'login']);
    Route::post('/confirm-user', [UserAuthController::class,'sendOtp']);
    Route::post('/change-password', [UserAuthController::class,'resetPassword']);
    

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [UserAuthController::class, 'logout']);
        Route::post('/add-user', [UserFunctionsController::class, 'addUser']);

        //CRUD For Category
        Route::post('/category/create', [CategoryController::class, 'create']);
        Route::post('/category/update/{id}', [CategoryController::class, 'update']);
        Route::post('/category/delete', [CategoryController::class, 'destroy']);
        Route::get('/category/list-categories', [CategoryController::class, 'index']);

        //CRUD for Location
        Route::post('/location/create', [LocationController::class, 'create']);
        Route::post('/location/update/{id}', [LocationController::class, 'update']);
        Route::post('/location/delete', [LocationController::class, 'destroy']);
        Route::get('/location/list-locations', [LocationController::class, 'index']);

        //CRUD for Floor
        Route::post('/floor/create', [FloorController::class, 'create']);
        Route::post('/floor/update/{id}', [FloorController::class, 'update']);
        Route::post('/floor/delete', [FloorController::class, 'destroy']);
        Route::get('/floor/list-floors', [FloorController::class, 'index']);

        //CRUD for Product
        Route::post('/product/create', [ProductController::class, 'create']);
        Route::post('/product/update/{id}', [ProductController::class, 'update']);
        Route::post('/product/delete', [ProductController::class, 'destroy']);
        Route::get('/product/list-products', [ProductController::class, 'index']);

        //CRUD for Booking
        Route::post('/booking/create', [BookingController::class, 'create']);
        Route::post('/booking/admin-create', [BookingController::class, 'adminCreate']);
        Route::post('/booking/update/{id}', [BookingController::class, 'update']);
        Route::post('/booking/delete', [BookingController::class, 'destroy']);
        Route::get('/booking/list-bookings', [BookingController::class, 'index']);

    });
});