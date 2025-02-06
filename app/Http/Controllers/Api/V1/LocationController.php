<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

use App\Models\Tenant;
use App\Models\Location;

class LocationController extends Controller
{
    public function create(Request $request, $tenant_slug){
        $user = $request->user();

        if($user->user_type_id !== 1){
            return response()->json(['message' => 'You are not authorized'], 401);
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

        $tenant = Tenant::where('slug', $tenant_slug)->first();
 
        //retrieve Validated data from the validator instance
        $validatedData = $validator->validated();
        
        $location = Location::create([
         'name' => htmlspecialchars($validatedData['name'], ENT_QUOTES, 'UTF-8'),
         'state' => htmlspecialchars($validatedData['state'], ENT_QUOTES, 'UTF-8'),
         'address' => htmlspecialchars($validatedData['address'], ENT_QUOTES, 'UTF-8'),
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
        $tenant = Tenant::where('slug', $tenant_slug)->first();
         //fetch all categories
        $locations = Location::where('tenant_id', $tenant->id)->get();
 
        return response()->json(['data'=>$locations], 201);
    }

    public function update(Request $request, $tenant_slug, $id){
        $user = $request->user();

        if($user->user_type_id !== 1){
            return response()->json(['message' => 'You are not authorized'], 401);
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
        $location = Location::findOrFail($id);

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

    public function destroy(Request $request){

        $user = $request->user();

        if($user->user_type_id !== 1){
            return response()->json(['message' => 'You are not authorized'], 401);
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
        $response = $location->delete();

        //return response if delete fails
        if(!$response){
            return response()->json(['message'=> 'Failed to delete, try again'], 422);
        }
 
        //return response if delete is successful
        return response()->json(['message'=> 'Location deleted successfully'], 200);
    }

}
