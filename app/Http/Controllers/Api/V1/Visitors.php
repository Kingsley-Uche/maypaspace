<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Location;
use App\Models\Tenant;
use Illuminate\Support\Facades\Validator;
use App\Models\Category;
use App\Models\Spot;
class Visitors extends Controller
{
    //
     public function index($tenant_slug){

        $tenant = $this->checkTenant($tenant_slug);
        
         //fetch all Locations
        $locations = Location::where('tenant_id', $tenant->id)->where('deleted', 'no')->select('name', 'state', 'address', 'id', 'tenant_id')->get();
 
        return response()->json(['data'=>$locations], 200);
    }
    private function checkTenant($tenant_slug){
        $tenant = Tenant::where('slug', $tenant_slug)->first();

        if (!$tenant) {
            return response()->json(['message' => 'Tenant not found'], 404);
        }

        return $tenant;

    }
public function GetCategory(Request $request, $tenant_slug, $location_id = null)
{
    // Validate access code
    if ($request->header('code4access') !='b5b2920be76b17c5d1c7dc1c041af3db') {
        return response()->json(['message' => 'Invalid Access'], 403); // Use 403 for forbidden
    }

    // Validate tenant
    $tenant = $this->checkTenant($tenant_slug);
    if (!$tenant) {
        return response()->json(['message' => 'Invalid tenant'], 404);
    }

    // Sanitize location_id
    $location_id = strip_tags($location_id);

    // Build query for unique category IDs and names for available spots
    $query = Spot::where('spots.tenant_id', $tenant->id)
        ->where('spots.book_status', 'no')
        ->join('spaces', 'spots.space_id', '=', 'spaces.id')
        ->join('categories', 'spaces.space_category_id', '=', 'categories.id')
        ->select('categories.id as category_id', 'categories.category as category_name')
        ->distinct();

    if ($location_id) {
        $query->where('spots.location_id', $location_id);
    }

    // Fetch results
    $categories = $query->get();

    return response()->json(['categories' => $categories], 200);
}

}
