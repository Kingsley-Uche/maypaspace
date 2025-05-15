<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use App\Models\TaxModel;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;

class TaxController extends Controller
{
    /**
     * Display a listing of tax models.
     */
    public function index()
    {
        $taxes = TaxModel::all();
        return response()->json($taxes);
    }

    /**
     * Store a newly created tax model in storage.
     */
       
    public function store(Request $request, $slug)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'percentage' => 'required|numeric|min:0|max:100',
        ]);
         $user = $request->user();
        $tenant = $this->checkTenant($slug, $user);

        $userType = User::where('id', $user->id)
            ->select('id', 'user_type_id')
            ->with(['user_type:id,create_space'])
            ->first();

        if (!$userType || ($user->user_type_id !== 1 && $userType->user_type->create_space !== 'yes')) {
            return response()->json(['message' => 'You are not authorized'], 403);
        }
        $validated['tenant_id'] = $tenant->id;
        $tax = TaxModel::create($validated);

        return response()->json([
            'message' => 'Tax created successfully',
            'data' => $tax
        ], 201);
    }

    /**
     * Display the specified tax model.
     */
    public function show(request $request, $slug, $id)
    {
        
        $user = $request->user();
        $tenant = $this->checkTenant($slug, $user);

        $userType = User::where('id', $user->id)
            ->select('id', 'user_type_id')
            ->with(['user_type:id,create_space'])
            ->first();

        if (!$userType || ($user->user_type_id !== 1 && $userType->user_type->create_space !== 'yes')) {
            return response()->json(['message' => 'You are not authorized'], 403);
        }
    
        $tax = TaxModel::find($id);

        if (!$tax) {
            return response()->json(['message' => 'Tax not found'], 404);
        }

        return response()->json($tax);
    }

    /**
     * Update the specified tax model in storage.
     */
   public function update(Request $request, $slug)
{
    $id = $request->tax_id;
    $tax = TaxModel::find($id);

    if (!$tax) {
        return response()->json(['message' => 'Tax not found'], 404);
    }

    $validated = $request->validate([
        'name' => 'nullable|string|max:255',
        'description' => 'nullable|string|max:1000',
        'percentage' => 'nullable|numeric|min:0|max:100',
    ]);

    // Remove null or empty values from validated input
    $filtered = array_filter($validated, function ($value) {
        return $value !== null && $value !== '';
    });

    $tax->update($filtered);

    return response()->json([
        'message' => 'Tax updated successfully',
        'data' => $tax
    ]);
}


    /**
     * Remove the specified tax model from storage.
     */
    public function destroy($id)
    {
        $tax = TaxModel::find($id);

        if (!$tax) {
            return response()->json(['message' => 'Tax not found'], 404);
        }

        $tax->delete();

        return response()->json(['message' => 'Tax deleted successfully']);
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

