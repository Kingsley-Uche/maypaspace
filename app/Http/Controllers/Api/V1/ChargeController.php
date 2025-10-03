<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Models\Charge as ChargeModel;
use App\Models\Tenant;
use App\Models\Space;
use App\Models\User;
use App\Models\Category;
use App\Models\Location;

class ChargeController extends Controller
{
    /**
     * Create a new charge
     */

public function create(Request $request, $tenant_slug)
{
    $user = $request->user();
    $tenant = $this->checkTenant($tenant_slug);

    if (!$tenant instanceof Tenant) return $tenant;

    if ($user->tenant_id != $tenant->id || !in_array($user->user_type_id, [1, 2])) {
        return response()->json(['message' => 'You are not authorized'], 403);
    }

    // Validation for array inputs
    $validator = Validator::make($request->all(), [
        'name'        => 'required|array|min:1',
        'name.*'      => 'required|string|max:255',

        'space_id'  => 'required|numeric|exists:spaces,id',

        'value'       => 'required|array|min:1',
        'value.*'     => 'required|numeric',

        'is_fixed'    => 'required|array|min:1',
        'is_fixed.*'  => 'required|boolean',
    ]);

    if ($validator->fails()) {
        return response()->json(['errors' => $validator->errors()], 422);
    }

    $validated = $validator->validated();
    $charges   = [];
    $spaceId  = $validated['space_id'];

    foreach ($validated['name'] as $index => $name) {
        $value    = $validated['value'][$index] ?? null;
        $is_fixed = $validated['is_fixed'][$index] ?? null;

        // Prevent duplicates per tenant/space
        $exists = ChargeModel::where('name', $name)
            ->where('tenant_id', $tenant->id)
            ->where('space_id', $spaceId)
            ->exists();

        if ($exists) {
            // Skip duplicates
            continue;
        }

        $charges[] = ChargeModel::create([
            'name'      => $name,
            'tenant_id' => $tenant->id,
            'space_id'  => $spaceId,
            'is_fixed'  => $is_fixed,
            'value'     => $value,
        ]);
    }

    return response()->json([
        'message' => count($charges) > 0 
            ? 'Charges created successfully' 
            : 'No new charges were added (duplicates skipped)',
        'data'    => $charges
    ], 201);
}




public function update(Request $request, $tenant_slug)
{
    $user = $request->user();
    $tenant = $this->checkTenant($tenant_slug);

    if (!$tenant instanceof Tenant) return $tenant;

    if ($user->tenant_id != $tenant->id || !in_array($user->user_type_id, [1, 2])) {
        return response()->json(['message' => 'You are not authorized'], 403);
    }

    $data = $request->validate([
        'charges'                 => 'required|array|min:1',
        'charges.*.id'            => 'required|exists:charges,id',
        'charges.*.name'          => 'sometimes|string|max:255',
        'charges.*.space_id'      => 'sometimes|exists:spaces,id',
        'charges.*.value'         => 'sometimes|numeric',
        'charges.*.is_fixed'      => 'sometimes|boolean',
    ]);

    $updatedCharges = [];

    foreach ($data['charges'] as $chargeData) {
        $charge = ChargeModel::where('tenant_id', $tenant->id)
            ->findOrFail($chargeData['id']);

        // Optional duplicate check (skip if name already used in this tenant+space)
        if (isset($chargeData['name'])) {
            $exists = ChargeModel::where('tenant_id', $tenant->id)
                ->where('space_id', $chargeData['space_id'] ?? $charge->space_id)
                ->where('name', $chargeData['name'])
                ->where('id', '!=', $charge->id) // exclude current record
                ->exists();

            if ($exists) {
                continue; // skip duplicate update
            }
        }

        // Force tenant consistency
        $chargeData['tenant_id'] = $tenant->id;

        // Update only supplied fields
        $charge->fill($chargeData)->save();

        $updatedCharges[] = $charge;
    }

    return response()->json([
        'message' => count($updatedCharges) > 0
            ? 'Charges updated successfully'
            : 'No charges were updated (duplicates skipped)',
        'data'    => $updatedCharges
    ]);
}



    /**
     * List all charges for a tenant
     */
   public function index($tenant_slug)
{
    $tenant = $this->checkTenant($tenant_slug);
    if (!$tenant instanceof Tenant) return $tenant;

    $charges = ChargeModel::where('tenant_id', $tenant->id)
        ->with([
            'space:id,space_name,location_id,floor_id,space_category_id',
            'space.location:id,name,state,address',
            'space.floor:id,name,location_id',
            'space.category:id,category'
        ])
        ->get();

    return response()->json(['data' => $charges], 200);
}


    /**
     * Show a single charge
     */
   public function show($tenant_slug, $space_id)
{
    $tenant = $this->checkTenant($tenant_slug);
    if (!$tenant instanceof Tenant) {
        return $tenant;
    }

    $charge = ChargeModel::where('tenant_id', $tenant->id)
        ->where('space_id', $space_id)
        ->get();

    if (!$charge) {
        return response()->json(['message' => 'Charge not found'], 404);
    }

    return response()->json(['data' => $charge], 200);
}

    /**
     * Delete a charge
     */
    public function destroy(Request $request, $tenant_slug, $id)
    {
        $user = $request->user();
        $tenant = $this->checkTenant($tenant_slug);

        if (!$tenant instanceof Tenant) return $tenant;
        if (!in_array($user->user_type_id, [1, 2])) {
            return response()->json(['message' => 'You are not authorized'], 403);
        }

        $charge = ChargeModel::findOrFail($id);

        if ($charge->tenant_id != $tenant->id) {
            return response()->json(['message' => 'You are not authorized'], 403);
        }

        $charge->delete();

        return response()->json(['message' => 'Charge deleted successfully'], 200);
    }

    /**
     * Reusable tenant check
     */
    private function checkTenant($tenant_slug)
    {
        $tenant = Tenant::where('slug', $tenant_slug)->first();

        if (!$tenant) {
            return response()->json(['message' => 'Tenant not found'], 404);
        }

        return $tenant;
    }
}
