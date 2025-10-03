<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Controllers\Api\V1\TimeZoneController as TimeZone;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use App\Models\Category;
use App\Models\Tenant;
use App\Models\Location;
use App\Models\CategoryImagesModel;

class CategoryController extends Controller
{

public function create(Request $request, $tenant_slug)
{
    $user = $request->user();
    $tenant = $this->checkTenant($tenant_slug);
    
    if (!$tenant instanceof Tenant) return $tenant;

    if ($user->tenant_id != $tenant->id || !in_array($user->user_type_id, [1, 2])) {
        return response()->json(['message' => 'You are not authorized'], 403);
    }
    
    

  $validator = Validator::make($request->all(), [
    'category' => 'required|string|max:255',
    'location_id' => 'required|numeric|gte:1|exists:locations,id',
    'booking_type' => 'required|string|in:hourly,daily,weekly,monthly',
    'min_duration' => 'required|numeric|gte:1',
    'category_image' => 'nullable|array|max:3',
    'category_image.*' => 'image|mimes:jpeg,png,jpg,gif,svg|max:2048',
]);


    if ($validator->fails()) {
        return response()->json(['error' => $validator->errors()], 422);
    }

    $validated = $validator->validated();
    
     $timezone_status = new TimeZone();
            $timezoneCheck = $timezone_status->time_zone_status([
                'location_id' => $validated['location_id'],
                'tenant_id' => $tenant->id,
            ]);
            
            if (!$timezoneCheck) {
                return response()->json(['message' => 'Kindly set up your timezone'], 422);
            }

    // Check duplicate
    $exists = Category::where('category', $validated['category'])
        ->where('location_id', $validated['location_id'])
        ->exists();

    if ($exists) {
        return response()->json([
            'message' => 'You have already named a category "' . $validated['category'] . '" in this location'
        ], 422);
    }

    $category = Category::create([
        'category' => $validated['category'],
        'location_id' => $validated['location_id'],
        'booking_type' => $validated['booking_type'],
        'min_duration' => $validated['min_duration'],
        'tenant_id' => $tenant->id,
    ]);

    if (!$category) {
        return response()->json(['message' => 'Something went wrong, try again later'], 422);
    }

    if ($request->hasFile('category_image')) {
        $this->addCategoryImages($category, $request->file('category_image'));
    }

   $category->load(['images' => function ($q) {
    $q->select('id', 'image_path', 'category_id')->without('category');
}]);


    return response()->json([
        'message' => 'Category successfully created',
        'category' => $category
    ], 201);
}


   public function update(Request $request, $tenant_slug, $id)
{
    $tenant = $this->checkTenant($tenant_slug);
    if (!$tenant instanceof Tenant) return $tenant;

    $user = $request->user();
    if (!in_array($user->user_type_id, [1, 2])) {
        return response()->json(['message' => 'You are not authorized'], 403);
    }

    $validator = Validator::make($request->all(), [
        'category' => 'required|string|max:255',
        'location_id' => 'required|numeric|gte:1|exists:locations,id',
        'booking_type' => 'required|string|in:hourly,daily,weekly,monthly',
        'min_duration' => 'required|numeric|gte:1',
         'category_image' => 'nullable|array|max:3',
        'category_image.*' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
    ]);

    if ($validator->fails()) {
        return response()->json(['errors' => $validator->errors()], 422);
    }

    $validated = $validator->validated();
 $timezone_status = new TimeZone();
            $timezoneCheck = $timezone_status->time_zone_status([
                'location_id' => $validated['location_id'],
                'tenant_id' => $tenant->id,
            ]);
            
            if (!$timezoneCheck) {
                return response()->json(['message' => 'Kindly set up your timezone'], 422);
            }

    $category = Category::findOrFail($id);

    if ($category->tenant_id != $tenant->id) {
        return response()->json(['message' => 'You are not authorized'], 403);
    }

    $duplicate = Category::where('category', $validated['category'])
        ->where('location_id', $validated['location_id'])
        ->where('id', '!=', $id)
        ->exists();

    if ($duplicate) {
        return response()->json([
            'message' => 'You have already named a category "' . $validated['category'] . '" in this location'
        ], 422);
    }

    $category->update([
        'category' => $validated['category'],
        'location_id' => $validated['location_id'],
        'booking_type' => $validated['booking_type'],
        'min_duration' => $validated['min_duration'],
    ]);

    // Handle image updates
    if ($request->hasFile('category_image')) {
        foreach ($category->images as $img) {
            \Storage::disk('public')->delete($img->image_path);
            $img->delete();
        }

        $this->addCategoryImages($category, $request->file('category_image'));
    }

    $category->load(['images' => function ($query) {
        $query->select('id', 'image_path', 'category_id');
    }]);

    return response()->json([
        'message' => 'Category updated successfully',
        'data' => $category
    ], 201);
}


    public function index($tenant_slug)
    {
        $tenant = $this->checkTenant($tenant_slug);
        if (!$tenant instanceof Tenant) return $tenant;

       $categories = Category::where('tenant_id', $tenant->id)
    ->with(['images' => function ($q) {
        $q->select('id', 'image_path', 'category_id');
    }])->get();


        return response()->json(['data' => $categories], 200);
    }

    public function fetchCategoryByLocation($tenant_slug, $location)
    {
        $tenant = $this->checkTenant($tenant_slug);
        if (!$tenant instanceof Tenant) return $tenant;

        $categories = Category::where(function ($query) use ($tenant, $location) {
            $query->where('tenant_id', $tenant->id)
                  ->where('location_id', $location)
                  ->orWhereNull('tenant_id');
        })->with('images')->get();

        return response()->json(['data' => $categories], 200);
    }

    public function destroy(Request $request, $tenant_slug)
    {
        $tenant = $this->checkTenant($tenant_slug);
        if (!$tenant instanceof Tenant) return $tenant;

        $user = $request->user();
        if (!in_array((int)$user->user_type_id, [1, 2])) {
            return response()->json(['message' => 'You are not authorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'id' => 'required|numeric|exists:categories,id'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $category = Category::findOrFail($request->id);

        if ((int)$category->tenant_id !== (int)$tenant->id) {
            return response()->json(['message' => 'You are not authorized'], 403);
        }

        // Delete images
        foreach ($category->images as $img) {
            Storage::disk('public')->delete($img->image_path);
            $img->delete();
        }

        if (!$category->delete()) {
            return response()->json(['message' => 'Failed to delete, try again'], 422);
        }

        return response()->json(['message' => 'Category deleted successfully'], 200);
    }

    private function checkTenant($tenant_slug)
    {
        $tenant = Tenant::where('slug', $tenant_slug)->first();

        if (!$tenant) {
            return response()->json(['message' => 'Tenant not found'], 404);
        }

        return $tenant;
    }

    private function addCategoryImages($category, $images)
    {
        foreach ($images as $image) {
            $path = $image->store('category_images', 'public');

            CategoryImagesModel::create([
                'category_id' => $category->id,
                'image_path' => $path,
            ]);
        }
    }
}
