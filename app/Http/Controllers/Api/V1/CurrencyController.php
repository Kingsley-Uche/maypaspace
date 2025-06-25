<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\CurrencyModel;
use Illuminate\Support\Facades\Validator;

class CurrencyController extends Controller
{
    // GET /api/v1/currencies
    public function index()
    {
        $currencies = CurrencyModel::all();
        return response()->json(['data' => $currencies], 200);
    }

    // POST /api/v1/currencies
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name'        => 'required|string|max:50',
            'symbol'      => 'required|string|max:10|unique:currencies,symbol',
            'tenant_id'   => 'required|integer|exists:tenants,id',
            'location_id' => 'required|integer|exists:locations,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

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
    public function update(Request $request, $id)
    {
        $currency = CurrencyModel::find($id);

        if (!$currency) {
            return response()->json(['error' => 'Currency not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'name'        => 'sometimes|required|string|max:50',
            'symbol'      => 'sometimes|required|string|max:10|unique:currencies,symbol,' . $id,
            'tenant_id'   => 'sometimes|required|integer|exists:tenants,id',
            'location_id' => 'sometimes|required|integer|exists:locations,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

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
}
