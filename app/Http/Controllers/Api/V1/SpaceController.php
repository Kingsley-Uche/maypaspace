<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;

use App\Models\Tenant;
use App\Models\Floor;
use App\Models\User;
use App\Models\Location;
use App\Models\Category;
use App\Models\Space;
use App\Models\Spot;

class SpaceController extends Controller
{
    public function create(Request $request, $tenant_slug)
    {
        $user = $request->user();
        $tenant = $this->checkTenant($tenant_slug, $user);

        $userType = User::where('id', $user->id)
            ->select('id', 'user_type_id')
            ->with(['user_type:id,create_space'])
            ->first();

        if (!$userType || ($user->user_type_id !== 1 && $userType->user_type->create_space !== 'yes')) {
            return response()->json(['message' => 'You are not authorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'space_number' => 'required|numeric|gte:1',
            'location_id' => 'required|numeric|gte:1|exists:locations,id',
            'floor_id' => 'required|numeric|gte:1|exists:floors,id',
           'space_fee' => 'required|numeric|regex:/^\d+(\.\d{1,2})?$/',
            'space_category_id' => 'required|numeric|gte:1|exists:categories,id',
            'min_space_discount_time' => 'required|numeric|gte:1',
            'space_discount' => 'required|numeric|gte:1',
        ],
    ['floor_id' => 'This floor does not exist on this workspace, please check and try again',
        'location_id' => 'This location does not exist on this workspace, please check and try again',
        'space_category_id' => 'This space category does not exist on this workspace, please check and try again',
    ]
);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        $validated = $validator->validated();

        Location::findOrFail($validated['location_id']);
        Category::findOrFail($validated['space_category_id']);
        Floor::findOrFail($validated['floor_id']);

        $verifyName = Space::where('space_name', $validated['name'])
            ->where('location_id', $validated['location_id'])
            ->where('floor_id', $validated['floor_id'])
            ->first();

        if ($verifyName) {
            return response()->json([
                'message' => 'You have already named a Space "' . $validated['name'] . '" in this floor and location'
            ], 422);
        }
        if(empty($validator->validated()['space_price_hourly']) && empty($validator->validated()['space_price_daily']) 
        && empty($validator->validated()['space_price_weekly']) && empty($validator->validated()['space_price_monthly']) 
        && empty($validator->validated()['space_price_semi_nnually']) && empty($validator->validated()['space_price_annually'])){
            return response()->json(['message' => 'You must provide at least one fee'], 422); }

        $space = Space::create([
            'space_name' => htmlspecialchars($validated['name'], ENT_QUOTES, 'UTF-8'),
            'space_number' => htmlspecialchars($validated['space_number'], ENT_QUOTES, 'UTF-8'),
            'space_category_id' => $validated['space_category_id'],
            'location_id' => $validated['location_id'],
            'floor_id' => $validated['floor_id'],
            'created_by_user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'space_fee' => $validated['space_fee'], 
            'min_space_discount_time'=>$validated['min_space_discount_time'],//
            'space_discount'=>$validated['space_discount'],//
        ]);

        if (!$space) {
            return response()->json(['message' => 'Something went wrong, try again later'], 500);
        }

        $count = (int)$validated['space_number'];

        for ($i = 0; $i < $count; $i++) {
            Spot::create([
                'space_id' => $space->id,
                'location_id' => $space->location_id,
                'floor_id' => $space->floor_id,
                'tenant_id' => $tenant->id,
            ]);
        }

        return response()->json([
            'message' => 'Space and spots successfully created',
            'location' => $space
        ], 201);
    }

    public function update(Request $request, $tenant_slug, $id)
    {
        $user = $request->user();
        $tenant = $this->checkTenant($tenant_slug, $user);

        $space = Space::where('id', $id)
            ->where('tenant_id', $tenant->id)
            ->firstOrFail();

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'space_number' => 'required|numeric|gte:1',
            'location_id' => 'required|numeric|gte:1',
            'floor_id' => 'required|numeric|gte:1',
            'space_fee' => 'required|decimal:2',
            'space_category_id' => 'required|numeric|gte:1',
            'min_space_discount_time' => 'required|numeric|gte:1',
            'space_discount' => 'required|numeric|gte:1',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        $data = $validator->validated();

        $space->update([
            'space_name' => htmlspecialchars($data['name'], ENT_QUOTES, 'UTF-8'),
            'space_number' => $data['space_number'],
            'location_id' => $data['location_id'],
            'floor_id' => $data['floor_id'],
            'space_category_id' => $data['space_category_id'],
            'space_fee' => $data['space_fee'],
            'min_space_discount_time' => $data['min_space_discount_time'],
            'space_discount' => $data['space_discount'],
        ]);

        return response()->json(['message' => 'Space updated successfully', 'space' => $space], 200);
    }

    public function index(Request $request, $tenant_slug)
    {
        $user = $request->user();
        $tenant = $this->checkTenant($tenant_slug, $user);

        $spaces = Space::with(['location', 'floor', 'category'])
            ->where('tenant_id', $tenant->id)->where('deleted', 'no')
            ->get();

        return response()->json($spaces, 200);
    }

    public function fetchOne($tenant_slug, $id)
    {
        $tenant = Tenant::where('slug', $tenant_slug)->first();

        if (!$tenant) {
            return response()->json(['message' => 'Tenant not found'], 404);
        }

        $floor = Space::where('tenant_id', $tenant->id)
            ->where('deleted', 'no')
            ->where('id', $id)
            ->with([
                'tenants',
                'createdBy:id,first_name,last_name',
                'deletedBy:id,first_name,last_name',
                'spots',
            ])
            ->firstOrFail();

        return response()->json(['data' => $floor], 200);
    }

    public function destroy(Request $request, $tenant_slug)
    {
        $user = $request->user();
        $tenant = $this->checkTenant($tenant_slug, $user);

        $userType = User::where('id', $user->id)
            ->select('id', 'user_type_id')
            ->with(['user_type:id,delete_space'])
            ->get();

        $permission = $userType[0]['user_type']['delete_space'];

        if ($user->user_type_id !== 1 && $permission !== "yes") {
            return response()->json(['message' => 'You are not authorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'space_number' => 'required|numeric|gte:1',
            'location_id' => 'required|numeric|gte:1',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $space = Space::where('space_name', $request->name)
            ->where('location_id', $request->location_id)
            ->where('floor_id', $request->floor_id)
            ->firstOrFail();

        $space->deleted = "yes";
        $space->deleted_by_user_id = $user->id;
        $space->deleted_at = now();

        $response = $space->save();

        Spot::where('space_id', $space->id)->delete();

        if (!$response) {
            return response()->json(['message' => 'Failed to delete, try again'], 422);
        }

        return response()->json(['message' => 'Space deleted successfully'], 200);
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
