<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;

use App\Models\Tenant;
use App\Models\Floor;
use App\Models\User;
use App\Models\Location;
use App\Models\Space;
use App\Models\Spot;

class SpaceController extends Controller
{
    public function create(Request $request, $tenant_slug){
        $user = $request->user();

        //We identify the tenant using slug
        $tenant = $this->checkTenant($tenant_slug);

        $userType = User::where('id', $user->id)->select('id', 'user_type_id')->with(['user_type:id,create_space'])->get();

        $permission = $userType[0]['user_type']['create_space'];

        if($user->user_type_id !== 1 && $permission !== "yes"){
            return response()->json(['message' => 'You are not authorized'], 403);
        }

        Location::where('id', $request->location_id)->firstOrFail();

        Floor::where('id', $request->floor_id)->firstOrFail();

        $verifyName = Space::where('space_name', $request->name)->where('location_id', $request->location_id)->where('floor_id', $request->floor_id)->first();

        if($verifyName){
            return response()->json(['message' => 'You have already named a Space "'.$request->name.'" in this floor and location'], 422);
        }

        //validate request data
        $validator = Validator::make($request->all(), [
           'name' => 'required|string|max:255',
           'space_number' => 'required|numeric|gte:1',
           'location_id' => 'required|numeric|gte:1',
           'floor_id' => 'required|numeric|gte:1',
           'space_fee' => 'required|numeric|gte:1',
           'space_category_id' => 'required|numeric|gte:1',
        ]);
 
        if($validator->fails()){
         return response()->json(['error' => $validator->errors()], 422);
        }

        //retrieve Validated data from the validator instance
        $validatedData = $validator->validated();
        
        $space = Space::create([
         'space_name' => htmlspecialchars($validatedData['name'], ENT_QUOTES, 'UTF-8'),
         'space_number' => htmlspecialchars($validatedData['space_number'], ENT_QUOTES, 'UTF-8'),
         'space_fee' => htmlspecialchars($validatedData['space_fee'], ENT_QUOTES, 'UTF-8'),
         'space_category_id' => htmlspecialchars($validatedData['space_category_id'], ENT_QUOTES, 'UTF-8'),
         'location_id' => $request->location_id,
         'floor_id' => $request->floor_id,
         'created_by_user_id' => $user->id,
         'tenant_id' => $tenant->id,
        ]);
 
        //return response if create fails
        if(!$space){
           return response()->json(['message' => 'Something went wrong, try again later'], 500);
        }

        $count = $request->space_number;

        for ($i = 0; $i < $count; $i++) {
            Spot::create([
                'space_id' => $space->id,
                'location_id' => $space->location_id,
                'floor_id' => $space->floor_id,
                'tenant_id' => $tenant->id,
            ]);
        }
 
        //return response if create was successful
        return response()->json(['message'=> 'Space and spots successfully created', 'location'=>$space], 201);        
    }

    public function update(Request $request, $tenant_slug, $id){
        $user = $request->user();

        $tenant = $this->checkTenant($tenant_slug);

        $userType = User::where('id', $user->id)->select('id', 'user_type_id')->with(['user_type:id,update_space'])->get();

        $permission = $userType[0]['user_type']['update_space'];

        if($user->user_type_id !== 1 && $permission !== "yes"){
            return response()->json(['message' => 'You are not authorized'], 403);
        }

        $verifyName = Space::where('space_name', $request->name)->where('location_id', $request->location_id)->where('floor_id', $request->floor_id)->first();

        if($verifyName){
            return response()->json(['message' => 'You have already named a Space "'.$request->name.'" in this floor and location'], 422);
        }

         //validate request data
        $validator = Validator::make($request->all(), [
            'space_name' => 'required|string|max:255',
            'space_number' => 'required|numeric|gte:1',
            'location_id' => $request->location_id,
            'floor_id' => $request->floor_id,
            'space_fee' => 'required|numeric|gte:1',
        ]);

        //send response if validation fails
        if($validator->fails()){
            return response()->json(['errors'=>$validator->errors()], 422);
        }

        //using the provided id, find the Floor to be updated
        $space = Space::findOrFail($id);

        //retrieve validatedData from the validator instance
        $validatedData = $validator->validated();

        //sanitize and save validated request data
        $space->space_name = htmlspecialchars($validatedData['space_name'], ENT_QUOTES, 'UTF-8');
        $space->space_number = htmlspecialchars($validatedData['space_number'], ENT_QUOTES, 'UTF-8');
        $space->space_fee = htmlspecialchars($validatedData['space_fee'], ENT_QUOTES, 'UTF-8');
        $space->location_id = htmlspecialchars($validatedData['location_id'], ENT_QUOTES, 'UTF-8');
        $space->floor_id = htmlspecialchars($validatedData['floor_id'], ENT_QUOTES, 'UTF-8');

        $response = $space->save();

        //If update fails, send response
        if(!$response){
            return response()->json(['message'=>'Something went wrong, please try again later'], 500);
        }

        //If update is successful, send response
        return response()->json(['message'=> 'Space updated successfully', 'data'=>$space], 201);
    }

    public function index($tenant_slug, $location_id, $floor_id){

        $tenant = $this->checkTenant($tenant_slug);

        $spaces = Space::where('tenant_id', $tenant->id)
        ->where('location_id', $location_id)
        ->where('floor_id', $floor_id)
        ->where('deleted', 'no')
        ->with([
            'tenants',
            'createdBy:id,first_name,last_name',
            'deletedBy:id,first_name,last_name',
            'spots',
        ])
        ->paginate(10);
 
        return response()->json(['data'=>$spaces], 200);
    }

    public function fetchOne($tenant_slug, $id){
        $tenant = $this->checkTenant($tenant_slug);

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

        return response()->json(['data'=>$floor], 200);


    }

    private function checkTenant($tenant_slug){
        $tenant = Tenant::where('slug', $tenant_slug)->first();

        if (!$tenant) {
            return response()->json(['message' => 'Tenant not found'], 404);
        }

        return $tenant;

    }

    public function destroy(Request $request, $tenant_slug){

        $user = $request->user();

        $tenant = $this->checkTenant($tenant_slug);

        $userType = User::where('id', $user->id)->select('id', 'user_type_id')->with(['user_type:id,delete_space'])->get();

        $permission = $userType[0]['user_type']['delete_space'];

        if($user->user_type_id !== 1 && $permission !== "yes"){
            return response()->json(['message' => 'You are not authorized'], 403);
        }
         //validate the ID
        $validator = Validator::make($request->all(), [
            'id' => 'required|numeric|exists:spaces,id'
        ]);

        if($validator->fails()){
            return response()->json(['errors'=> $validator->errors()], 422);
        }

        //find the category to be deleted using the Id
        $space = Space::findOrFail($request->id);

        $space->deleted = "yes";
        $space->deleted_by_user_id = $user->id;
        $space->deleted_at = now();

        $response = $space->save();

        Spot::where('space_id', $space->id)->delete();

        //return response if delete fails
        if(!$response){
            return response()->json(['message'=> 'Failed to delete, try again'], 422);
        }
 
        //return response if delete is successful
        return response()->json(['message'=> 'Space deleted successfully'], 200);
    }
}
