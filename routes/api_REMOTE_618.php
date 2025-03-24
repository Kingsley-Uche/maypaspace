<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\SystemAdminAuthController;
use App\Http\Controllers\Api\V1\UserAuthController;
use App\Http\Controllers\Api\V1\UserFunctionsController;
use App\Http\Controllers\Api\V1\SystemAdminFunctionsController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\LocationController;
use App\Http\Controllers\Api\V1\FloorController;
use App\Http\Controllers\Api\V1\SpaceController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\BookingController;
use App\Http\Controllers\Api\V1\OwnerController;
use App\Http\Controllers\Api\V1\SubscriptionController;
use App\Http\Controllers\Api\V1\UserTypeController;
use App\Http\Controllers\Api\V1\TeamController;

use App\Http\Middleware\EnsureAdmin;

Route::prefix('system-admin')->group(function(){
    Route::post('/login', [SystemAdminAuthController::class, 'login']);

    Route::post('/confirm-user', [SystemAdminAuthController::class,'sendOtp']);
    Route::post('/verify-otp', [SystemAdminAuthController::class,'verifyOtp']);

    Route::middleware(['auth:sanctum', EnsureAdmin::class])->group(function(){
        Route::post('/change-password', [SystemAdminAuthController::class, 'changePassword']);
        Route::post('/logout', [SystemAdminAuthController::class, 'logout']);

        //Tenant Management
        Route::post('/register-workspace', [SystemAdminFunctionsController::class, 'createTenant']);
        Route::post('/update-workspace/{id}', [SystemAdminFunctionsController::class, 'updateTenant']);
        Route::post('/delete-workspace', [SystemAdminFunctionsController::class, 'destroyTenant']);
        Route::get('/view-workspace/{id}', [SystemAdminFunctionsController::class, 'getTenant']);
        Route::get('/view-workspaces', [SystemAdminFunctionsController::class, 'getTenants']);


        //System Admin
        Route::post('/add', [OwnerController::class, 'addSystemAdmin']);
        Route::post('/update/{id}', [OwnerController::class, 'updateSystemAdmin']);
        Route::get('/view-all', [OwnerController::class, 'viewSystemAdmins']);
        Route::get('/view/{id}', [OwnerController::class, 'viewSystemAdmin']);
        Route::post('/delete', [OwnerController::class, 'deleteSystemAdmin']);

        Route::get('/details', [SystemAdminFunctionsController::class, 'systemAdminDetails']);

        //Roles
        Route::post('/create-role', [OwnerController::class, 'createRole']);
        Route::post('/update-role/{id}', [OwnerController::class, 'updateRole']);
        Route::post('/delete-role', [OwnerController::class, 'destroyRole']);
        Route::get('/view-role/{id}', [OwnerController::class, 'viewRole']);
        Route::get('/view-roles', [OwnerController::class, 'viewRoles']);

        //Plans Management
        Route::post('/create-plan', [SubscriptionController::class, 'createPlan']);
        Route::get('/view-plans', [SubscriptionController::class, 'viewPlans']);
        Route::get('/view-plan/{id}', [SubscriptionController::class, 'viewPlan']);
        Route::post('/update-plan/{id}', [SubscriptionController::class, 'updatePlan']);
        Route::post('/delete-plan', [SubscriptionController::class, 'deletePlan']);

        //Subscription for Subscription Management
        Route::post('/subscribe-tenant', [SubscriptionController::class, 'createSubscription']);
        Route::get('/view-subscriptions', [SubscriptionController::class, 'viewSubscriptions']);
        Route::get('/view-subscription/{id}', [SubscriptionController::class, 'viewSubscription']);
        Route::post('/delete-subscription', [SubscriptionController::class, 'deleteSubscription']);

    });
});

Route::prefix('{tenant_slug}')->middleware('settenant')->group(function(){
    Route::post('/login', [UserAuthController::class,'login']);
    Route::post('/confirm-user', [UserAuthController::class,'sendOtp']);
    Route::post('/verify-otp', [UserAuthController::class,'verifyOtp']);
    Route::post('/change-password', [UserAuthController::class,'resetPassword']);
    

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [UserAuthController::class, 'logout']);

        Route::post('/add-user', [UserFunctionsController::class, 'addUser']);
        Route::post('/update-user/{id}', [UserFunctionsController::class, 'updateUser']);
        Route::get('/view-users', [UserFunctionsController::class, 'viewUsers']);
        Route::get('/view-user/{id}', [UserFunctionsController::class, 'viewUser']);
        Route::post('/delete-user', [UserFunctionsController::class, 'destroyUser']);

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
        Route::get('/location/show/{id}', [LocationController::class, 'viewOne']);

        //CRUD FOR USERTYPE
        Route::post('/usertype/create', [UserTypeController::class, 'create']);
        Route::post('/usertype/update/{id}', [UserTypeController::class, 'update']);
        Route::post('/usertype/delete', [UserTypeController::class, 'destroy']);
        Route::get('/usertype/list-user-types', [UserTypeController::class, 'viewAll']);
        Route::get('/usertype/user-type/{id}', [UserTypeController::class, 'viewOne']);

        //CRUD FOR TEAM
        Route::post('/team/create', [TeamController::class, 'AddTeam']);
        Route::post('/team/add-member', [TeamController::class, 'AddMember']);
        Route::post('/team/update/{id}', [TeamController::class,'update']);

        //CRUD for Floor
        Route::post('/floor/create', [FloorController::class, 'create']);
        Route::post('/floor/update/{id}', [FloorController::class, 'update']);
        Route::post('/floor/delete', [FloorController::class, 'destroy']);
        Route::get('/floor/list-floors/{location_id}', [FloorController::class, 'index']);
        Route::get('/floor/show/{id}', [FloorController::class, 'fetchOne']);

        //CRUD for Product
        Route::post('/product/create', [ProductController::class, 'create']);
        Route::post('/product/update/{id}', [ProductController::class, 'update']);
        Route::post('/product/delete', [ProductController::class, 'destroy']);
        Route::get('/product/list-products', [ProductController::class, 'index']);

        //CRUD FOR SPACE
        Route::post('/space/create', [SpaceController::class, 'create']);
        Route::post('/space/update/{id}', [SpaceController::class, 'update']);
        Route::post('/space/delete', [SpaceController::class, 'destroy']);
        Route::get('/space/list-spaces/{location_id}/{floor_id}', [SpaceController::class, 'index']);
        Route::get('/space/show/{id}', [SpaceController::class, 'fetchOne']);

        //CRUD for Booking
        Route::post('/booking/create', [BookingController::class, 'create']);
        Route::post('/booking/admin-create', [BookingController::class, 'adminCreate']);
        Route::post('/booking/update/{id}', [BookingController::class, 'update']);
        Route::post('/booking/delete', [BookingController::class, 'destroy']);
        Route::get('/booking/list-bookings', [BookingController::class, 'index']);
    });
});