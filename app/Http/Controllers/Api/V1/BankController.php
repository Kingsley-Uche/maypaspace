<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\BankModel;
use App\Models\Tenant;
use App\Models\User;

class BankController extends Controller
{
    /**
     * Store a new bank account.
     */
    public function store(Request $request, $slug)
    {
        $user = $request->user();
        $tenant = $this->checkTenant($slug, $user);

        if (!$this->isAuthorized($user)) {
            return response()->json(['message' => 'You are not authorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'account_name'   => 'required|string|max:255',
            'account_number' => 'required|string|max:20',
            'bank_name'      => 'required|string|max:100',
            'location_id'    => 'required|numeric|exists:locations,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        $data['tenant_id'] = $tenant->id;

        $account = BankModel::create($data);

        return response()->json([
            'message' => 'Bank account created successfully.',
            'data'    => $account,
        ], 201);
    }

    /**
     * Get all bank accounts for a tenant.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $tenant = $this->checkTenant($request->tenant_slug, $user);

        if (!$this->isAuthorized($user)) {
            return response()->json(['message' => 'You are not authorized'], 403);
        }

        $accounts = BankModel::where('tenant_id', $tenant->id)->get();

        return response()->json(['data' => $accounts]);
    }

    /**
     * Show a single bank account.
     */
    public function show($id)
    {
        $account = BankModel::find($id);

        if (!$account) {
            return response()->json(['error' => 'Bank account not found.'], 404);
        }

        return response()->json(['data' => $account]);
    }

    /**
     * Update a bank account.
     */
    public function update(Request $request, $id)
    {
        $user = $request->user();

        if (!$this->isAuthorized($user)) {
            return response()->json(['message' => 'You are not authorized'], 403);
        }

        $tenant = Tenant::where('slug', $request->tenant_slug)->first();

        if (!$tenant) {
            return response()->json(['error' => 'Tenant not found.'], 404);
        }

        $account = BankModel::where('tenant_id', $tenant->id)->find($id);

        if (!$account) {
            return response()->json(['error' => 'Bank account not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'account_name'   => 'sometimes|required|string|max:255',
            'account_number' => 'sometimes|required|string|max:20',
            'bank_name'      => 'sometimes|required|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $account->update($validator->validated());

        return response()->json([
            'message' => 'Bank account updated successfully.',
            'data'    => $account,
        ]);
    }

    /**
     * Delete a bank account.
     */
    public function destroy(Request $request)
    {
        
        $validator = Validator::make($request->all(), [
            'id' => 'required|numeric|exists:bank_accounts,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user = $request->user();

        if (!$this->isAuthorized($user)) {
            return response()->json(['message' => 'You are not authorized'], 403);
        }

        $account = BankModel::where('tenant_id', $user->tenant_id)->find($request->id);

        if (!$account) {
            return response()->json(['error' => 'Bank account not found.'], 404);
        }

        $account->delete();

        return response()->json(['message' => 'Bank account deleted successfully.']);
    }

    /**
     * Check if the tenant belongs to the user.
     */
    private function checkTenant($tenantSlug, $user)
    {
        $tenant = Tenant::where('slug', $tenantSlug)->first();

        if (!$tenant) {
            abort(response()->json(['message' => 'Tenant not found'], 404));
        }

        if ($user->tenant_id != $tenant->id) {
            abort(response()->json(['message' => 'You are not authorized'], 403));
        }

        return $tenant;
    }

    /**
     * Check if user has permission to manage bank accounts.
     */
    private function isAuthorized($user): bool
    {
        $userType = User::with('user_type:id,create_user')->find($user->id);

        return $userType && (
            $user->user_type_id == 1 ||
            optional($userType->user_type)->create_user === 'yes'
        );
    }
}
