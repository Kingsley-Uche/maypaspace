<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\{
    AnalyticsController,
    SystemAdminAuthController,
    UserAuthController,
    UserFunctionsController,
    SystemAdminFunctionsController,
    CategoryController,
    LocationController,
    FloorController,
    SpaceController,
    BookingController,
    OwnerController,
    SubscriptionController,
    UserTypeController,
    TeamController,
    BookSpotController,
    PaymentController,
    TimeSetUp,
    NotificationController,
    DiscountController,
    TaxController,
    BankController,
    InvoiceController,
    UserPrepaidController,
    Visitors,
    TenantLogoController
};
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureTenantHasActivePlan;
use App\Models\TenantLogo;

Route::prefix('system-admin')->middleware('throttle:api')->group(function(){
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

Route::prefix('{tenant_slug}')->middleware(['settenant', 'throttle:api'])->group(function(){
    Route::post('/get/name',  [UserAuthController::class,'getName']);
    Route::post('/login', [UserAuthController::class,'login']);
    Route::post('/confirm-user', [UserAuthController::class,'sendOtp']);
    Route::post('/verify-otp', [UserAuthController::class,'verifyOtp']);
    Route::post('/change-password', [UserAuthController::class,'resetPassword']);
    Route::get('/get/spaces/{id}', [BookSpotController::class,'getFreeSpots']);
    Route::get('/get/spaces/category/{location}', [BookSpotController::class,'getFreeSpotsCateg']);
    Route::post('/initiate/pay/spot', [PaymentController::class,'initiatePay']);
    Route::post('/confirm/pay/spot', [PaymentController::class,'confirmPayment']);
    Route::get('/get/locations', [Visitors::class,'index']); 
    Route::post('/get/spaces/category/{location}', [Visitors::class,'GetCategory']);

    //view tenant details particularly logo and colour
    Route::get('/view-details', [TenantLogoController::class,'index']);

    Route::middleware(['auth:sanctum', EnsureTenantHasActivePlan::class])->group(function () {
        Route::post('/settings/workspace/time/create', [TimeSetUp::class,'store']);
        Route::post('/settings/workspace/time/update', [TimeSetUp::class,'update']);
        Route::post('/settings/workspace/time/delete', [TimeSetUp::class,'destroy']);
        Route::post('/settings/workspace/time/Single', [TimeSetUp::class,'show']);
        Route::get('/settings/workspace/time/all', [TimeSetUp::class,'index']);
        Route::post('/spot/book', [BookSpotController::class, 'create']);  
        Route::post('/spot/cancel', [BookSpotController::class, 'cancelBooking']);
        Route::get('/spot/get', [BookSpotController::class, 'getBookings']);  
        Route::post('/spot/update', [BookSpotController::class, 'update']);  
        Route::get('/spot/available', [BookSpotController::class, 'getAllSpots']); 
        Route::get('/spot/single', [BookSpotController::class, 'getSingle']);  
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
        Route::get('/category/list-category-by-location/{location}', [CategoryController::class, 'fetchCategoryByLocation']);

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
        Route::post('/team/create', [TeamController::class, 'addTeam']);
        Route::post('/team/add-member', [TeamController::class, 'addMember']);
        Route::post('/team/update/{id}', [TeamController::class,'update']);
        Route::post('/team/promote', [TeamController::class,'promoteUser']);
        Route::get('/teams', [TeamController::class, 'viewAll']);
        Route::get('/team/{id}', [TeamController::class, 'viewOne']);
        Route::get('/team/members/{id}', [TeamController::class, 'viewTeamMembers']);
        Route::get('/team/member/{teamId}/{userId}', [TeamController::class, 'viewTeamMember']);
        Route::post('/team/member/delete/{teamId}', [TeamController::class, 'deleteMember']);
        Route::post('/team/delete', [TeamController::class, 'deleteTeam']);

        //CRUD for Floor
        Route::post('/floor/create', [FloorController::class, 'create']);
        Route::post('/floor/update/{id}', [FloorController::class, 'update']);
        Route::post('/floor/delete', [FloorController::class, 'destroy']);
        Route::get('/floor/list-floors/{location_id}', [FloorController::class, 'index']);
        Route::get('/floor/show/{id}', [FloorController::class, 'fetchOne']);

        //CRUD FOR SPACE
        Route::post('/space/create', [SpaceController::class, 'create']);
        Route::post('/space/update/{id}', [SpaceController::class, 'update']);
        Route::post('/space/delete', [SpaceController::class, 'destroy']);
        Route::get('/space/list-spaces/{location_id}/{floor_id}', [SpaceController::class, 'index']);
        Route::get('/space/show/{id}', [SpaceController::class, 'fetchOne']);

        // CRUD FOR NOTIFICATION THIS WILL BE CREATED BY TENANT OWNER
        Route::post('/notification/create', [NotificationController::class, 'store']);
        Route::get('/notification/list-notifications', [NotificationController::class, 'index']);
        Route::get('/notification/show/{id}', [NotificationController::class, 'show']);
        Route::post('/notification/update/{id}', [NotificationController::class, 'update']);
        Route::post('/notification/delete', [NotificationController::class, 'destroy']);
        Route::post('/notification/toggle-publish/{id}', [NotificationController::class, 'togglePublish']);
        Route::post('/notification/{id}/read', [NotificationController::class, 'markAsRead']);
        Route::get('/notification/view-my-notifications', [NotificationController::class, 'userIndex']);

        //CRUD for Discount
        Route::post('/discount/create', [DiscountController::class, 'create']);
        Route::post('/discount/update/{id}', [DiscountController::class, 'update']);
        Route::get('/discount/list-discounts', [DiscountController::class, 'index']);
        Route::get('/discount/view/{id}', [DiscountController::class, 'viewOne']);
        Route::post('/discount/delete', [DiscountController::class, 'destroy']);

        // Tax CRUD
        Route::get('/taxes', [TaxController::class, 'index']);
        Route::get('/taxes/{id}', [TaxController::class, 'show']);
        Route::post('/taxes/create', [TaxController::class, 'store']);
        Route::post('/taxes/update/{id}', [TaxController::class, 'update']);
        Route::post('/taxes/delete/{id}', [TaxController::class, 'destroy']);
        // CRUD for bank account
        Route::get('/banks', [BankController::class, 'index']);
        Route::get('/banks/{id}', [BankController::class, 'show']);
        Route::post('/bank/create', [BankController::class, 'store']);
        Route::post('/bank/update/{id}', [BankController::class, 'update']);
        Route::post('/bank/delete', [BankController::class, 'destroy']);

        //crud for invoice
        Route::get('/invoices/all', [InvoiceController::class, 'index']);
        Route::get('/invoice/show/{id}', [InvoiceController::class, 'show']);
        Route::post('/invoice/create', [InvoiceController::class, 'create']);
        Route::post('/invoice/update/{id}', [InvoiceController::class, 'update']);
        Route::post('/invoice/delete', [InvoiceController::class, 'destroy']);
        Route::post('/invoice/close', [InvoiceController::class, 'CloseInvoice']);
        // CRUD for prepaid user
        Route::post('/user/initiate/pay', [UserPrepaidController::class, 'initiatePay']);
        Route::post('/user/confirm/pay', [UserPrepaidController::class, 'confirmPayment']);


        //Analytis Zone
        Route::get('/analytics/list', [AnalyticsController::class, 'index']);
        Route::get('/analytics/payment', [AnalyticsController::class, 'indexPayment']);
        Route::get('/analytics/accounts', [AnalyticsController::class, 'getAccountsAndRevenue']);

         //set additional tenant details
        Route::post('/add-details', [TenantLogoController::class,'create']);
        Route::post('/update-details', [TenantLogoController::class,'update']);
    });



});
