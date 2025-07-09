<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\TimeZoneModel as TimeZone;
use App\Models\Location;
use Illuminate\Support\Facades\Auth;
use App\Models\Tenant;
use App\Models\User;
class TimeZoneController extends Controller
{
    // List all time zones for a tenant
    public function index(Request $request, $slug)
    {
        $user = $request->user();
          $userType = User::where('id', $user->id)
            ->select('id', 'user_type_id')
            ->with(['user_type:id,create_user'])
            ->first();

        //The ability to view timezone is reserved for admins that have the ability to create users
        if (!$userType || ($user->user_type_id != 1 && $userType->user_type->create_user !== 'yes')) {
            return response()->json(['message' => 'You are not authorized'], 403);
        }

    
        $tenant = $this->checkTenant($slug, $user);

        $timezones = TimeZone::with('location:id,name,state,address')->where('tenant_id', $tenant->id)->select('id','utc_time_zone','location_id')->get();

        return response()->json($timezones, 200);
    }

    // Create a new time zone
public function create(Request $request, $slug)
{
    $user = $request->user();
          $userType = User::where('id', $user->id)
            ->select('id', 'user_type_id')
            ->with(['user_type:id,create_user'])
            ->first();

        //The ability to create timezone is reserved for admins that have the ability to create users
        if (!$userType || ($user->user_type_id != 1 && $userType->user_type->create_user !== 'yes')) {
            return response()->json(['message' => 'You are not authorized'], 403);
        }
    $validated = $request->validate([
        'utc_time_zone' => 'required|string',
        'location_id' => 'required|integer|exists:locations,id',
    ]);


    $tenant = $this->checkTenant($slug, $user);

    $validated['tenant_id'] = $tenant->id;

    $exists = TimeZone::where('location_id', $validated['location_id'])
        ->where('tenant_id', $validated['tenant_id'])
        ->exists();

    if ($exists) {
        return response()->json([
            'message' => 'Time zone already set for this location, kindly modify if necessary',
        ], 422);
    }

    $timezone = TimeZone::create($validated);

    return response()->json([
        'message' => 'Time zone created successfully.',
        'data' => $timezone,
    ], 201);
}


    // Show a specific time zone by ID
    public function show($slug,$id)
    {
    
       $tenant = Tenant::where('slug',$slug)->first(); 
        $timezone = TimeZone::with('location:id,name,state,address')
    ->where('tenant_id', $tenant->id)
    ->where('id', $id)
    ->select('id', 'utc_time_zone', 'location_id')
    ->first();

        if (!$timezone) {
            return response()->json(['message' => 'Time zone not found.'], 404);
        }

        return response()->json($timezone, 200);
    }

    // Update an existing time zone
    public function update(Request $request,$slug, $id)
    {
        
        $user = $request->user();
          $userType = User::where('id', $user->id)
            ->select('id', 'user_type_id')
            ->with(['user_type:id,create_user'])
            ->first();

        //The ability to change timezone is reserved for admins that have the ability to create users
        if (!$userType || ($user->user_type_id != 1 && $userType->user_type->create_user !== 'yes')) {
            return response()->json(['message' => 'You are not authorized'], 403);
        }
        
    
        $tenant = $this->checkTenant($slug, $user);
        

        $timezone = TimeZone::find($id);

        if (!$timezone) {
            return response()->json(['message' => 'Time zone not found.'], 404);
        }

        
        $validated = $request->validate([
            'utc_time_zone' => 'sometimes|string',
            'location_id' => 'sometimes|integer|exists:locations,id',
        ]);

        $validated['tenant_id'] = $tenant->id;

        $timezone->update($validated);

        return response()->json([
            'message' => 'Time zone updated successfully.',
            'data' => $timezone,
        ], 200);
    }

    // Delete a time zone
    public function destroy($slug,$id)
    {
       $user = Auth::user();

          $userType = User::where('id', $user->id)
            ->select('id', 'user_type_id')
            ->with(['user_type:id,create_user'])
            ->first();
        

        //The ability to delete timezone is reserved for admins that have the ability to create users
        if (!$userType || ($user->user_type_id != 1 && $userType->user_type->create_user !== 'yes')) {
            return response()->json(['message' => 'You are not authorized'], 403);
        }
        
         $tenant = Tenant::where('slug',$slug)->first(); 
        if (!$tenant) {
            return response()->json(['message' => 'Unauthorized'], 404);
        }

        $timezone = TimeZone::find($id);
         

        if (!$timezone) {
            return response()->json(['message' => 'Time zone not found.'], 404);
        }

        $timezone->delete();

        return response()->json(['message' => 'Time zone deleted successfully.'], 200);
    }

    // Check if the authenticated user belongs to the given tenant slug
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
 public function time_zone_status(array $data): bool
{
    return TimeZone::where('location_id', $data['location_id'])
        ->where('tenant_id', $data['tenant_id'])
        ->exists();
}


}
