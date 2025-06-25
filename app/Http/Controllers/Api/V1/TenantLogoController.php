<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;

use App\Models\User;
use App\Models\Tenant;
use App\Models\TenantLogo;

class TenantLogoController extends Controller
{
    public function index($tenant_slug){
        $tenant = Tenant::where('slug', $tenant_slug)->first();

        if (!$tenant) {
            abort(response()->json(['message' => 'Tenant not found'], 404));
        }

        $tenant_details = TenantLogo::where('tenant_id', $tenant->id)->get();

        return response()->json([
            'data' => $tenant_details,
        ], 201);
    }
    public function create(Request $request, $tenant_slug){
        $user = $request->user();

        $tenant = $this->checkTenant($tenant_slug, $user);  
        
        $check = TenantLogo::where('tenant_id', $tenant->id)->get();

        if(!$check->isEmpty()){
            return response()->json(['message'=> 'You cannot create another account detail, update existing one'], 403);
        }

        $userType = User::where('id', $user->id)
            ->select('id', 'user_type_id')
            ->first();

        if (!$userType || $user->user_type_id != 1 && $user->user_type_id != 2) {
            return response()->json(['message' => 'You are not authorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'logo' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
            'colour' => ['required', 'string', 'regex:/^#(?:[0-9a-fA-F]{3}){1,2}$/']
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $validated = $validator->validated();

        // Handle the uploaded logo
        $filename = null;
        if ($request->hasFile('logo')) {
            $file = $request->file('logo');
            $filename = $tenant->slug . '_' . time() . '.' . $file->getClientOriginalExtension();
            $file->storeAs('uploads/tenant_logo', $filename, 'public');
        }

        $tenant_logo = TenantLogo::create([
            'logo' => $filename,
            'colour' => $validated['colour'],
            'tenant_id' => $tenant->id,
        ]);

        if (!$tenant_logo) {
            return response()->json(['message' => 'Something went wrong, try again later'], 500);
        }

        return response()->json([
            'message' => 'Details successfully created',
            'account_details' => $tenant_logo
        ], 201);

    }

    public function update(Request $request, $tenant_slug)
    {
        $user = $request->user();

        $tenant = $this->checkTenant($tenant_slug, $user);  

        $tenant_logo = TenantLogo::where('tenant_id', $tenant->id)->first();

        if (!$tenant_logo) {
            return response()->json(['message' => 'No existing account detail found. Create one first.'], 404);
        }

        $userType = User::where('id', $user->id)
            ->select('id', 'user_type_id')
            ->first();

        if (!$userType || ($user->user_type_id != 1 && $user->user_type_id != 2)) {
            return response()->json(['message' => 'You are not authorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'logo' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'colour' => ['required', 'string', 'regex:/^#(?:[0-9a-fA-F]{3}){1,2}$/']
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $validated = $validator->validated();

        // Handle logo update if provided
        if ($request->hasFile('logo')) {
            $file = $request->file('logo');
            $filename = $tenant->slug . '_' . time() . '.' . $file->getClientOriginalExtension();
            $file->storeAs('uploads/tenant_logo', $filename, 'public');

            // Update logo filename in DB
            $tenant_logo->logo = $filename;
        }

        $tenant_logo->colour = $validated['colour'];

        if (!$tenant_logo->save()) {
            return response()->json(['message' => 'Failed to update, try again later'], 500);
        }

        return response()->json([
            'message' => 'Details successfully updated',
            'account_details' => $tenant_logo
        ], 200);
    }


    private function checkTenant($tenant_slug, $user)
    {
        $tenant = Tenant::where('slug', $tenant_slug)->first();

        if (!$tenant) {
            abort(response()->json(['message' => 'Tenant not found'], 404));
        }

        if ((int)$user->tenant_id !== $tenant->id) {
            abort(response()->json(['message' => 'You are not authorized'], 403));
        }

        return $tenant;
    }
}
