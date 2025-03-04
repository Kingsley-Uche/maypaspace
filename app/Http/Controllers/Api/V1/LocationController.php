<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

use App\Models\Tenant;
use App\Models\Location;
use App\Models\User;

class LocationController extends Controller
{
    public function create(Request $request, $tenant_slug){
        $user = $request->user();

        //We identify the tenant using slug
        $tenant = $this->checkTenant($tenant_slug);

        $userType = User::where('id', $user->id)->select('id', 'user_type_id')->with(['user_type:id,create_location'])->get();

        $permission = $userType[0]['user_type']['create_location'];

        if($user->user_type_id !== 1 && $permission !== "yes"){
            return response()->json(['message' => 'You are not authorized'], 403);
        }
        //validate request data
        $validator = Validator::make($request->all(), [
           'name' => 'required|string|max:255',
           'state' => 'required|string|max:255',
           'address' => 'required|string|max:255',
        ]);
 
        if($validator->fails()){
         return response()->json(['error' => $validator->errors()], 422);
        }
 
        //retrieve Validated data from the validator instance
        $validatedData = $validator->validated();
        
        $location = Location::create([
         'name' => htmlspecialchars($validatedData['name'], ENT_QUOTES, 'UTF-8'),
         'state' => htmlspecialchars($validatedData['state'], ENT_QUOTES, 'UTF-8'),
         'address' => htmlspecialchars($validatedData['address'], ENT_QUOTES, 'UTF-8'),
         'created_by_user_id' => $user->id,
         'tenant_id' => $tenant->id,
        ]);
 
        //return response if create fails
        if(!$location){
           return response()->json(['message' => 'Something went wrong, try again later'], 422);
        }
 
        //return response if create was successful
        return response()->json(['message'=> 'Location successfully created', 'location'=>$location], 201);
        
    }

    public function index($tenant_slug){

        $tenant = $this->checkTenant($tenant_slug);
        
         //fetch all Locations
        $locations = Location::where('tenant_id', $tenant->id)->where('deleted', 'no')->with(['tenants','createdBy:id,first_name,last_name','deletedBy:id,first_name,last_name'])->paginate(10);
 
        return response()->json(['data'=>$locations], 200);
    }

    public function viewOne($tenant_slug, $id){

        $tenant = $this->checkTenant($tenant_slug);
        
         //fetch all categories
        $location = Location::where('id', $id)->where('tenant_id', $tenant->id)->where('deleted', 'no')->with(['tenants','createdBy:id,first_name,last_name','deletedBy:id,first_name,last_name'])->firstOrFail();
 
        return response()->json(['data'=>$location], 200);
    }

    public function update(Request $request, $tenant_slug, $id){
        $user = $request->user();

        $tenant = $this->checkTenant($tenant_slug);

        $userType = User::where('id', $user->id)->select('id', 'user_type_id')->with(['user_type:id,update_location'])->get();

        $permission = $userType[0]['user_type']['update_location'];

        if($user->user_type_id !== 1 && $permission !== "yes"){
            return response()->json(['message' => 'You are not authorized'], 403);
        }
         //validate request data
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'state' => 'required|string|max:255',
            'address' => 'required|string|max:255',
        ]);

        //send response if validation fails
        if($validator->fails()){
            return response()->json(['errors'=>$validator->errors()], 422);
        }

        //using the provided id, find the category to be updated
        $location = Location::where('id', $id)
        ->where('deleted', 'no')
        ->firstOrFail();


        //retrieve validatedData from the validator instance
        $validatedData = $validator->validated();

        //sanitize and save validated request data
        $location->name = htmlspecialchars($validatedData['name'], ENT_QUOTES, 'UTF-8');
        $location->state = htmlspecialchars($validatedData['state'], ENT_QUOTES, 'UTF-8');
        $location->address = htmlspecialchars($validatedData['address'], ENT_QUOTES, 'UTF-8');

        $response = $location->save();

        //If update fails, send response
        if(!$response){
            return response()->json(['message'=>'Something went wrong, please try again later'], 422);
        }

        //If update is successful, send response
        return response()->json(['message'=> 'Location updated successfully', 'data'=>$location], 201);
    }

    public function destroy(Request $request, $tenant_slug){

        $user = $request->user();

        $tenant = $this->checkTenant($tenant_slug);

        $userType = User::where('id', $user->id)->select('id', 'user_type_id')->with(['user_type:id,delete_location'])->get();

        $permission = $userType[0]['user_type']['delete_location'];

        if($user->user_type_id !== 1 && $permission !== "yes"){
            return response()->json(['message' => 'You are not authorized'], 403);
        }
         //validate the ID
        $validator = Validator::make($request->all(), [
            'id' => 'required|numeric|exists:locations,id'
        ]);

        if($validator->fails()){
            return response()->json(['errors'=> $validator->errors()], 422);
        }

        //find the category to be deleted using the Id
        $location = Location::findOrFail($request->id);

        //delete the category
        $location->deleted = "yes";
        $location->deleted_by_user_id = $user->id;
        $location->deleted_at = now();

        $response = $location->save();

        //return response if delete fails
        if(!$response){
            return response()->json(['message'=> 'Failed to delete, try again'], 422);
        }
 
        //return response if delete is successful
        return response()->json(['message'=> 'Location deleted successfully'], 204);
    }

    private function checkTenant($tenant_slug){
        $tenant = Tenant::where('slug', $tenant_slug)->first();

        if (!$tenant) {
            return response()->json(['message' => 'Tenant not found'], 404);
        }

        return $tenant;

    }

}
