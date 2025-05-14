<?php

namespace App\Http\Controllers\APi\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\BankModel;

class BankController extends Controller
{
    //
     public function store(Request $request, $slug)
    {
        $user = $request->user();
        $tenant = $this->checkTenant($slug, $user);

        if (!$tenant) {
            return response()->json(['error' => 'Tenant not found.'], 404);
        }

        // Validate the request data
    {
        $validator = Validator::make($request->all(), [
            'account_name' => 'required|string|max:255',
            'account_number' => 'required|string|max:20',
            'bank' => 'required|string|max:100',
            'location_id' => 'required|numeric|exists:locations,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $account = BankModel::create($validator->validated());

        return response()->json([
            'message' => 'Bank account created successfully.',
            'data' => $account
        ], 201);
    }

 } // READ ALL
    public function index()
    {
        return response()->json([
            'data' => BankAccount::all()
        ]);
    }

    // READ ONE
    public function show($id)
    {
        $account = BankModel::find($id);

        if (!$account) {
            return response()->json(['error' => 'Bank account not found.'], 404);
        }

        return response()->json(['data' => $account]);
    }

    // UPDATE
    public function update(Request $request, $id)
    {
        $account = BankModel::find($id);

        if (!$account) {
            return response()->json(['error' => 'Bank account not found.'], 404);
        }

        $validator = Validator::make($request->all(), [
            'account_name' => 'sometimes|required|string|max:255',
            'account_number' => 'sometimes|required|string|max:20',
            'bank' => 'sometimes|required|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $account->update($validator->validated());

        return response()->json([
            'message' => 'Bank account updated successfully.',
            'data' => $account
        ]);
    }

    // DELETE
    public function destroy($id)
    {
        $account = BankModel::find($id);

        if (!$account) {
            return response()->json(['error' => 'Bank account not found.'], 404);
        }

        $account->delete();

        return response()->json(['message' => 'Bank account deleted successfully.']);
    }
     private function checkTenant($tenant_slug, $user)
    {
    
        $tenant = Tenant::where('slug', $tenant_slug)->first();

        if (!$tenant) {
            abort(response()->json(['message' => 'Tenant not found'], 404));
        }

        if ($user->tenant_id != $tenant->id) {
            abort(response()->json(['message' => 'You are not authorized'], 403));
        }

        return $tenant;
    }
}
