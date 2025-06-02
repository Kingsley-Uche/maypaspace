<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use App\Models\Location;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;
use App\Models\TimeSetUpModel as WorkspaceHour;
use Illuminate\Support\Facades\Validator;
class TimeSetUp extends Controller
{
    /**
     * List all operating hours for a location
     */
    public function index(Request $request, $slug)
    {
        $request->validate([
            'location_id' => 'required|numeric|exists:locations,id',
        ]);

        $user = Auth::user();

        $location = Location::with(['tenants'])->where('id', $request->location_id)
        ->where('created_by_user_id', $user->id)
        ->whereHas('tenants', function ($query) use ($slug) {
            $query->where('slug', $slug);
        })
        ->first();
        
        if (!$location) {
        return response()->json(['message' => 'Access denied, please contact your admin to do this.'], 403);
        }

       

        $hours = WorkspaceHour::where('location_id', $location->id)
            ->where('tenant_id', $location->tenant_id)
            ->get();

        return response()->json($hours);
    }

    /**
     * Store or update multiple operating hours
     */
    public function store(Request $request, $slug)
    {
        $user = Auth::user();

        $userType = User::where('id', $user->id)->select('id', 'user_type_id')->with(['user_type:id,create_space'])->get();

        $permission = $userType[0]['user_type']['create_space'];

        if($user->user_type_id !== 1 && $permission !== "yes"){
            return response()->json(['message' => 'You are not authorized'], 403);
        }
    
        $validator = Validator::make($request->all(), [
            'location_id' => 'required|numeric|exists:locations,id',
            'hours' => 'required|array',
            'hours.*.day' => 'required|string|in:monday,tuesday,wednesday,thursday,friday,saturday,sunday',
            'hours.*.open_time' => 'required|string|date_format:H:i',
            'hours.*.close_time' => 'required|string|date_format:H:i',
        ]);

if ($validator->fails()) {
    return response()->json(['errors' => $validator->errors()], 422);
}


$location = Location::where('id', $request->location_id)
->where('created_by_user_id', $user->id)
->whereHas('tenants', function ($query) use ($slug) {
    $query->where('slug', $slug);
})
->first();



if (!$location) {
return response()->json(['message' => 'Access denied, please contact your admin to do this.'], 403);
}

$status = WorkspaceHour::where('location_id', $location->id)->exists();
if ($status) {
    return response()->json(['message' => 'Operating hours already set for this location. Kindly update if you so please'], 422);
}

        DB::beginTransaction();

        try {
            // Clear previous hours for the location and tenant
            WorkspaceHour::where('location_id', $location->id)
                ->where('tenant_id', $location->tenant_id)
                ->delete();

            // Create new hours
            foreach ($request->hours as $hour) {
                WorkspaceHour::create([
                    'tenant_id' => $location->tenant_id,
                    'location_id' => $location->id,
                    'day' => $hour['day'],
                    'open_time' => Carbon::createFromFormat('H:i', $hour['open_time'])->format('H:i:s'),
                    'close_time' => Carbon::createFromFormat('H:i', $hour['close_time'])->format('H:i:s'),
                    'total_minutes' => Carbon::parse($hour['open_time'])->diffInMinutes(Carbon::parse($hour['close_time'])),
                ]);
            }

            DB::commit();

            return response()->json(['message' => 'Operating hours updated successfully.']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to update operating hours.', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Show a specific day's operating hour
     */
    public function show(Request $request, $slug, $day)
    {
        $request->validate([
            'location_id' => 'required|numeric|exists:locations,id',
        ]);

        $location = Location::where('id', $request->location_id)
            ->whereHas('tenant', function ($query) use ($slug) {
                $query->where('slug', $slug);
            })
            ->firstOrFail();

        $hour = WorkspaceHour::where('location_id', $location->id)
            ->where('tenant_id', $location->tenant_id)
            ->where('day', $day)
            ->first();

        if (!$hour) {
            return response()->json(['message' => 'Operating hour not found'], 404);
        }

        return response()->json($hour);
    }

    /**
     * Delete all operating hours for a location
     */
    public function destroy(Request $request, $slug)
    {
        $request->validate([
            'location_id' => 'required|numeric|exists:locations,id',
        ]);
        
       $deleted = WorkspaceHour::where('location_id', strip_tags($request['location_id']))->get();
       foreach($deleted as $delete){
           $delete ->delete();
           
       }
        
       $deleted = true;
      
        
        if ($deleted) {
            return response()->json(['message' => 'Operating hours deleted successfully.']);
        } else {
            return response()->json(['message' => 'No operating hours found to delete.'], 404);
        }
    }
    public function update(Request $srequest, $slug){
          $user = Auth::user();

        $userType = User::where('id', $user->id)->select('id', 'user_type_id')->with(['user_type:id,create_space'])->get();

        $permission = $userType[0]['user_type']['create_space'];

        if($user->user_type_id !== 1 && $permission !== "yes"){
            return response()->json(['message' => 'You are not authorized'], 403);
        }
    
        $validator = Validator::make($request->all(), [
            'location_id' => 'required|numeric|exists:locations,id',
            'hours' => 'required|array',
            'hours.*.day' => 'required|string|in:monday,tuesday,wednesday,thursday,friday,saturday,sunday',
            'hours.*.open_time' => 'required|string|date_format:H:i',
            'hours.*.close_time' => 'required|string|date_format:H:i',
        ]);

if ($validator->fails()) {
    return response()->json(['errors' => $validator->errors()], 422);
}


$location = Location::where('id', $request->location_id)
->where('created_by_user_id', $user->id)
->whereHas('tenants', function ($query) use ($slug) {
    $query->where('slug', $slug);
})
->first();



if (!$location) {
return response()->json(['message' => 'Access denied, please contact your admin to do this.'], 403);
}

$status = WorkspaceHour::where('location_id', $location->id)->exists();
if (!$status) {
    return response()->json(['message' => 'Operating hours not Created'], 422);
}

        DB::beginTransaction();

        try {
            // Clear previous hours for the location and tenant
            WorkspaceHour::where('location_id', $location->id)
                ->where('tenant_id', $location->tenant_id)
                ->delete();

            // Create new hours
            foreach ($request->hours as $hour) {
                WorkspaceHour::create([
                    'tenant_id' => $location->tenant_id,
                    'location_id' => $location->id,
                    'day' => $hour['day'],
                    'open_time' => Carbon::createFromFormat('H:i', $hour['open_time'])->format('H:i:s'),
                    'close_time' => Carbon::createFromFormat('H:i', $hour['close_time'])->format('H:i:s'),
                    'total_minutes' => Carbon::parse($hour['open_time'])->diffInMinutes(Carbon::parse($hour['close_time'])),
                ]);
            }

            DB::commit();

            return response()->json(['message' => 'Operating hours updated successfully.']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to update operating hours.', 'error' => $e->getMessage()], 500);
        }
    }
}
