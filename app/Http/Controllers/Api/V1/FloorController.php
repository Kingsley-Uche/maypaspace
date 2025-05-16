<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;

use App\Models\Tenant;
use App\Models\Floor;
use App\Models\User;
use App\Models\Location;

class FloorController extends Controller
{
    public function create(Request $request, $tenant_slug){
        $user = $request->user();

        //We identify the tenant using slug
        $tenant = $this->checkTenant($tenant_slug);

        $userType = User::where('id', $user->id)->select('id', 'user_type_id')->with(['user_type:id,create_floor'])->get();

        $permission = $userType[0]['user_type']['create_floor'];

        if((int)$user->user_type_id !== 1 && $permission !== "yes"){
            return response()->json(['message' => 'You are not authorized'], 403);
        }

        Location::where('id', $request->location_id)->firstOrFail();

        $verifyName = Floor::where('name', $request->name)->where('location_id', $request->location_id)->first();

        if($verifyName){
            return response()->json(['message' => 'You have already named a floor "'.$request->name.'" in this location'], 422);
        }

        //validate request data
        $validator = Validator::make($request->all(), [
           'name' => 'required|string|max:255',
           'location_id' => 'required|numeric|gte:1',
        ]);
 
        if($validator->fails()){
         return response()->json(['error' => $validator->errors()], 422);
        }

        //retrieve Validated data from the validator instance
        $validatedData = $validator->validated();
        
        $floor = Floor::create([
         'name' => htmlspecialchars($validatedData['name'], ENT_QUOTES, 'UTF-8'),
         'location_id' => $request->location_id,
         'created_by_user_id' => $user->id,
         'tenant_id' => $tenant->id,
        ]);
 
        //return response if create fails
        if(!$floor){
           return response()->json(['message' => 'Something went wrong, try again later'], 422);
        }
 
        //return response if create was successful
        return response()->json(['message'=> 'Floor successfully created', 'location'=>$floor], 201);        
    }

    public function index($tenant_slug, $location_id){

        $tenant = $this->checkTenant($tenant_slug);

        $locations = Floor::where('tenant_id', $tenant->id)
        ->where('location_id', $location_id)
        ->where('deleted', 'no')
        ->with([
            'tenants',
            'createdBy:id,first_name,last_name',
            'deletedBy:id,first_name,last_name',
            'spaces' => function ($query) {
                $query->where('deleted', 'no') // Exclude deleted spaces
                    ->with('spots'); // Load related spots
            }
        ])
        ->paginate(10);
 
        return response()->json(['data'=>$locations], 200);
    }

    public function fetchOne($tenant_slug, $id){
        $tenant = $this->checkTenant($tenant_slug);

        $floor = Floor::where('tenant_id', $tenant->id)
        ->where('deleted', 'no')
        ->where('id', $id)
        ->with([
            'tenants',
            'createdBy:id,first_name,last_name',
            'deletedBy:id,first_name,last_name',
            'spaces' => function ($query) {
                $query->where('deleted', 'no')
                    ->with('spots');
            }
        ])
        ->firstOrFail();

        return response()->json(['data'=>$floor], 200);


    }

    public function update(Request $request, $tenant_slug, $id){
        $user = $request->user();

        $tenant = $this->checkTenant($tenant_slug);

        $userType = User::where('id', $user->id)->select('id', 'user_type_id')->with(['user_type:id,update_floor'])->get();

        $permission = $userType[0]['user_type']['update_floor'];

        if((int)$user->user_type_id !== 1 && $permission !== "yes"){
            return response()->json(['message' => 'You are not authorized'], 403);
        }

        $verifyName = Floor::where('name', $request->name)->where('location_id', $request->location_id)->first();

        if($verifyName){
            return response()->json(['message' => 'You have already named a floor "'.$request->name.'" in this location'], 422);
        }
         //validate request data
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
        ]);

        //send response if validation fails
        if($validator->fails()){
            return response()->json(['errors'=>$validator->errors()], 422);
        }

        //using the provided id, find the Floor to be updated
        $floor = Floor::findOrFail($id);

        //retrieve validatedData from the validator instance
        $validatedData = $validator->validated();

        //sanitize and save validated request data
        $floor->name = htmlspecialchars($validatedData['name'], ENT_QUOTES, 'UTF-8');

        $response = $floor->save();

        //If update fails, send response
        if(!$response){
            return response()->json(['message'=>'Something went wrong, please try again later'], 500);
        }

        //If update is successful, send response
        return response()->json(['message'=> 'floor updated successfully', 'data'=>$floor], 201);
    }

    public function destroy(Request $request, $tenant_slug){

        $user = $request->user();

        $tenant = $this->checkTenant($tenant_slug);

        $userType = User::where('id', $user->id)->select('id', 'user_type_id')->with(['user_type:id,delete_floor'])->get();

        $permission = $userType[0]['user_type']['delete_floor'];

        if((int)$user->user_type_id !== 1 && $permission !== "yes"){
            return response()->json(['message' => 'You are not authorized'], 403);
        }
         //validate the ID
        $validator = Validator::make($request->all(), [
            'id' => 'required|numeric|exists:floors,id'
        ]);

        if($validator->fails()){
            return response()->json(['errors'=> $validator->errors()], 422);
        }

        //find the category to be deleted using the Id
        $floor = Floor::findOrFail($request->id);

        $floor->deleted = "yes";
        $floor->deleted_by_user_id = $user->id;
        $floor->deleted_at = now();

        $response = $floor->save();

        //return response if delete fails
        if(!$response){
            return response()->json(['message'=> 'Failed to delete, try again'], 422);
        }
 
        //return response if delete is successful
        return response()->json(['message'=> 'Floor deleted successfully'], 200);
    }

    private function checkTenant($tenant_slug){
        $tenant = Tenant::where('slug', $tenant_slug)->first();

        if (!$tenant) {
            return response()->json(['message' => 'Tenant not found'], 404);
        }

        return $tenant;

    }
}
