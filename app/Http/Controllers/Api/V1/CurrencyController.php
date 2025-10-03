<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\CurrencyModel;
use Illuminate\Support\Facades\Validator;

class CurrencyController extends Controller
{
    // GET /api/v1/currencies
    public function index(request $request, $slug)
    {
        $user = $request->user();
    
        $currencies = CurrencyModel::where('tenant_id', $user->tenant_id)->get();
        return response()->json(['data' => $currencies], 200);
    }

    // POST /api/v1/currencies
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name'        => 'required|string|max:50',
          'symbol' => 'required|string|max:10|unique:curreny_models,symbol',
            'location_id' => 'required|integer|exists:locations,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        $request->merge(['tenant_id' => auth()->user()->tenant_id]);


        $currency = CurrencyModel::create($request->only(['name', 'symbol', 'tenant_id', 'location_id']));

        return response()->json(['message' => 'Currency created successfully', 'data' => $currency], 201);
    }

    // GET /api/v1/currencies/{id}
    public function show($id)
    {
        $currency = CurrencyModel::find($id);

        if (!$currency) {
            return response()->json(['error' => 'Currency not found'], 404);
        }

        return response()->json(['data' => $currency], 200);
    }

    // PUT /api/v1/currencies/{id}
    public function update(Request $request, $slug, $id)
    {
        $currency = CurrencyModel::find($id);
     

        if (!$currency) {
            return response()->json(['error' => 'Currency not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name'        => 'sometimes|required|string|max:50',
            'symbol'      => 'sometimes|required|string',
            'location_id' => 'sometimes|required|integer|exists:locations,id',
        ]);
 if ($validator->fails()) {
        return response()->json(['errors' => $validator->errors()], 422);
    }
  

    $currency->update($validator->validated());
         $request->merge(['tenant_id' => auth()->user()->tenant_id]);
        $currency->update($request->only(['name', 'symbol', 'tenant_id', 'location_id']));

        return response()->json(['message' => 'Currency updated successfully', 'data' => $currency], 200);
    }

    // DELETE /api/v1/currencies/{id}
    public function destroy($id)
    {
        $currency = CurrencyModel::find($id);

        if (!$currency) {
            return response()->json(['error' => 'Currency not found'], 404);
        }

        $currency->delete();

        return response()->json(['message' => 'Currency deleted successfully'], 200);
    }
public function fetchByLocation(Request $request)
{
    // validate input
    $validator = Validator::make($request->all(), [
        'location_id' => 'required|integer|exists:locations,id',
    ]);

    if ($validator->fails()) {
        return response()->json(['errors' => $validator->errors()], 422);
    }

    $location_id = $validator->validated()['location_id'];

    $response = CurrencyModel::where('location_id', $location_id)->get();

    return response()->json([
        'success' => true,
        'data'    => $response,
    ], 200);
}


}
