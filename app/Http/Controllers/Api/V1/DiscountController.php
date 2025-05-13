<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Http\Controllers\Controller;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Discount;

class DiscountController extends Controller
{
    public function create(Request $request, $tenant_slug){
        $user = $request->user();
        $tenant = $this->checkTenant($tenant_slug, $user);

        $userType = User::where('id', $user->id)
            ->select('id', 'user_type_id')
            ->with(['user_type:id,create_user'])
            ->first();

        //The ability to create a discount is reserved for admins that have the ability to create users
        if (!$userType || ($user->user_type_id !== 1 && $userType->user_type->create_user !== 'yes')) {
            return response()->json(['message' => 'You are not authorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'discount' => 'required|numeric|gte:0',
            'user_type_id' => 'required|numeric|gte:1|exists:user_types,id',
        ],
            [
            'user_type_id' => 'This user type does not exist on this workspace, please check and try again',
            ]
        );

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        $validated = $validator->validated();

        $discountCheck = Discount::where('user_type_id', $request->user_type_id)
            ->where('tenant_id', $tenant->id)
            ->first();
        
        if($discountCheck){
            $discountCheck->delete();  
        }

        $discount = Discount::create([
            'discount' => htmlspecialchars($validated['discount'], ENT_QUOTES, 'UTF-8'),
            'user_type_id' => htmlspecialchars($validated['user_type_id'], ENT_QUOTES, 'UTF-8'),
            'tenant_id' => $tenant->id,
        ]);

        if (!$discount) {
            return response()->json(['message' => 'Something went wrong, try again later'], 500);
        }

        return response()->json([
            'message' => 'Discount successfully created',
            'discount' => $discount
        ], 201);

    }

    public function update(Request $request, $tenant_slug, $id)
    {
        $user = $request->user();
        $tenant = $this->checkTenant($tenant_slug, $user);

        $discount = Discount::where('id', $id)
            ->where('tenant_id', $tenant->id)
            ->firstOrFail();

        $userType = User::where('id', $user->id)
            ->select('id', 'user_type_id')
            ->with(['user_type:id,create_user'])
            ->first();

        //The ability to create a discount is reserved for admins that have the ability to create users
        if (!$userType || ($user->user_type_id !== 1 && $userType->user_type->create_user !== 'yes')) {
            return response()->json(['message' => 'You are not authorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'discount' => 'required|numeric|gte:0',
            ],
        );

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        $data = $validator->validated();

        $discount->update([
            'discount' => htmlspecialchars($data['discount'], ENT_QUOTES, 'UTF-8'),
        ]);

        return response()->json(['message' => 'Discount updated successfully', 'discount' => $discount], 200);
    }

    public function index(Request $request, $tenant_slug)
    {
        $user = $request->user();
        $tenant = $this->checkTenant($tenant_slug, $user);

        $discounts = Discount::with(['userType:id,user_type'])
            ->select('id','user_type_id', 'discount')
            ->where('tenant_id', $tenant->id)
            ->get();

        return response()->json($discounts, 200);
    }

    public function viewOne(Request $request, $tenant_slug, $id){
        $user = $request->user();
        $tenant = $this->checkTenant($tenant_slug, $user);

        $discount = Discount::with(['userType:id,user_type'])
            ->select('id','user_type_id', 'discount')
            ->where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->get();
            
        return response()->json($discount, 200);
    }

    public function destroy(Request $request, $tenant_slug)
    {
        $user = $request->user();
        $tenant = $this->checkTenant($tenant_slug, $user);

        $userType = User::where('id', $user->id)
            ->select('id', 'user_type_id')
            ->with(['user_type:id,delete_user'])
            ->get();

        $permission = $userType[0]['user_type']['delete_user'];

        if ($user->user_type_id !== 1 && $permission !== "yes") {
            return response()->json(['message' => 'You are not authorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'id' => 'required|numeric|gte:1',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $discount = Discount::where('id', $request->id)
            ->where('tenant_id', $tenant->id)
            ->firstOrFail();

        $response = $discount->delete();

        if (!$response) {
            return response()->json(['message' => 'Failed to delete, try again'], 422);
        }

        return response()->json(['message' => 'Discount deleted successfully'], 200);
    }


    private function checkTenant($tenant_slug, $user)
    {
        $tenant = Tenant::where('slug', $tenant_slug)->first();

        if (!$tenant) {
            abort(response()->json(['message' => 'Tenant not found'], 404));
        }

        if ($user->tenant_id !== $tenant->id) {
            abort(response()->json(['message' => 'You are not authorized'], 403));
        }

        return $tenant;
    }
}
