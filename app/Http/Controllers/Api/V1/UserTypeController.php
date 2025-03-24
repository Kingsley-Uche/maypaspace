<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;

use App\Models\Tenant;
use App\Models\UserType;

class UserTypeController extends Controller
{
    public function create(Request $request, $tenant_slug){
        $user = $request->user();

        //We identify the tenant using slug
        $tenant = Tenant::where('slug', $tenant_slug)->first();

        if (!$tenant) {
            return response()->json(['message' => 'Tenant not found'], 404);
        }

        if($user->user_type_id !== 1){
            return response()->json(['message'=> 'You are not authorized to do this'], 403);
        }

         // Validate request data
        $validator = Validator::make($request->all(), [
            'user_type' => 'required|string|max:255',
            'create_admin' => [Rule::in(['yes', 'no'])],
            'update_admin' => [Rule::in(['yes', 'no'])],
            'delete_admin' => [Rule::in(['yes', 'no'])],
            'view_admin' => [Rule::in(['yes', 'no'])],
            'create_user' => [Rule::in(['yes', 'no'])],
            'update_user' => [Rule::in(['yes', 'no'])],
            'delete_user' => [Rule::in(['yes', 'no'])],
            'view_user' => [Rule::in(['yes', 'no'])],
            'create_location' => [Rule::in(['yes', 'no'])],
            'update_location' => [Rule::in(['yes', 'no'])],
            'delete_location' => [Rule::in(['yes', 'no'])],
            'view_location' => [Rule::in(['yes', 'no'])],
            'create_floor' => [Rule::in(['yes', 'no'])],
            'update_floor' => [Rule::in(['yes', 'no'])],
            'delete_floor' => [Rule::in(['yes', 'no'])],
            'view_floor' => [Rule::in(['yes', 'no'])],
            'create_space' => [Rule::in(['yes', 'no'])],
            'update_space' => [Rule::in(['yes', 'no'])],
            'delete_space' => [Rule::in(['yes', 'no'])],
            'view_space' => [Rule::in(['yes', 'no'])],
            'create_booking' => [Rule::in(['yes', 'no'])],
            'update_booking' => [Rule::in(['yes', 'no'])],
            'delete_booking' => [Rule::in(['yes', 'no'])],
            'view_booking' => [Rule::in(['yes', 'no'])],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $userTypeNameCheck = UserType::where('user_type', $request->user_type)->where('tenant_id', $tenant->id)->get();

        $reservedUserType = ['Owner', 'Admin', 'Client']; 

        //Check if user type has already been created by Tenant or If the user type being created has the name of any of the reserved usertypes.
        if(!$userTypeNameCheck->isEmpty() || in_array(ucFirst($request->user_type), $reservedUserType)){
            return response()->json(['error' => 'Usertype already exists'], 422);
        }

        // $userType = User::where('id', $user->id)->where('tenant_id',$tenant->id)->select('id', 'user_type_id,tenant_id')->get();

        $response = UserType::create([
            'user_type' => $request->user_type,
            'tenant_id' => $tenant->id,
            'create_admin' => $request->create_admin,
            'update_admin' => $request->update_admin,
            'delete_admin' => $request->delete_admin,
            'view_admin' => $request->view_admin,
            'create_user' => $request->create_user,
            'update_user' => $request->update_user,
            'delete_user' => $request->delete_user,
            'view_user' => $request->view_user,
            'create_location' => $request->create_location,
            'update_location' => $request->update_location,
            'delete_location' => $request->delete_location,
            'view_location' => $request->view_location,
            'create_floor' => $request->create_floor,
            'update_floor' => $request->update_floor,
            'delete_floor' => $request->delete_floor,
            'view_floor' => $request->view_floor,
            'create_space' => $request->create_space,
            'update_space' => $request->update_space,
            'delete_space' => $request->delete_space,
            'view_space' => $request->view_space,
            'create_booking' => $request->create_booking,
            'update_booking' => $request->update_booking,
            'delete_booking' => $request->delete_booking,
            'view_booking' => $request->view_booking,
        ]);

        
        if($response){
            return response()->json(['message' => 'User Type added successfully!', 'data'=>$response], 201); 
        }

        return response()->json(['message'=> 'Something went wrong'],500);
    }

    public function update(Request $request, $tenant_slug, $id){
        $user = $request->user();

        if($request->id == 1){
            return response()->json(['message'=> 'You are not authorized to do this'], 403);
        }

        //We identify the tenant using slug
        $tenant = Tenant::where('slug', $tenant_slug)->first();

        if (!$tenant) {
            return response()->json(['message' => 'Tenant not found'], 404);
        }

        if($user->user_type_id !== 1){
            return response()->json(['message'=> 'You are not authorized to do this'], 403);
        }

        $user_type = UserType::findOrFail($id);

        // Validate request data
        $validator = Validator::make($request->all(), [
            'user_type' => 'required|string|max:255',
            'create_admin' => [Rule::in(['yes', 'no'])],
            'update_admin' => [Rule::in(['yes', 'no'])],
            'delete_admin' => [Rule::in(['yes', 'no'])],
            'view_admin' => [Rule::in(['yes', 'no'])],
            'create_user' => [Rule::in(['yes', 'no'])],
            'update_user' => [Rule::in(['yes', 'no'])],
            'delete_user' => [Rule::in(['yes', 'no'])],
            'view_user' => [Rule::in(['yes', 'no'])],
            'create_location' => [Rule::in(['yes', 'no'])],
            'update_location' => [Rule::in(['yes', 'no'])],
            'delete_location' => [Rule::in(['yes', 'no'])],
            'view_location' => [Rule::in(['yes', 'no'])],
            'create_floor' => [Rule::in(['yes', 'no'])],
            'update_floor' => [Rule::in(['yes', 'no'])],
            'delete_floor' => [Rule::in(['yes', 'no'])],
            'view_floor' => [Rule::in(['yes', 'no'])],
            'create_space' => [Rule::in(['yes', 'no'])],
            'update_space' => [Rule::in(['yes', 'no'])],
            'delete_space' => [Rule::in(['yes', 'no'])],
            'view_space' => [Rule::in(['yes', 'no'])],
            'create_booking' => [Rule::in(['yes', 'no'])],
            'update_booking' => [Rule::in(['yes', 'no'])],
            'delete_booking' => [Rule::in(['yes', 'no'])],
            'view_booking' => [Rule::in(['yes', 'no'])],
        ]); 

        $userTypeNameCheck = UserType::where('user_type', $request->user_type)->where('tenant_id', $tenant->id)->whereNot('id', $id)->get();

        $reservedUserType = ['Owner', 'Admin', 'Client']; 

        //Check if user type has already been created by Tenant or If the user type being created has the name of any of the reserved usertypes.
        if(!$userTypeNameCheck->isEmpty() || in_array(ucFirst($request->user_type), $reservedUserType)){
            return response()->json(['error' => 'Usertype already exists'], 422);
        }

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $user_type->update($request->all());

        $response = $user_type->save();

        if(!$response){
            return response()->json(['message'=> 'Something went wrong'], 500);
        }

        return response()->json(['message' => 'User Type Updated successfully', 'data'=> $user_type ],200);
    }

    public function viewAll(Request $request, $tenant_slug){
        $user = $request->user();

        $tenant = Tenant::where('slug', $tenant_slug)->first();

        if (!$tenant) {
            return response()->json(['message' => 'Tenant not found'], 404);
        }

        if($user->tenant_id !== $tenant->id){
            return response()->json(['message'=> 'You are not authorized to do this'], 403);
        }

        $usertypes = UserType::where('tenant_id', $user->tenant_id)->orWhere('tenant_id', null)->paginate(20); 

        return response()->json(['data'=> $usertypes], 200);
        
    }

    public function viewOne($tenant_slug, $id){

        $tenant = Tenant::where('slug', $tenant_slug)->first();

        if (!$tenant) {
            return response()->json(['message' => 'Tenant not found'], 404);
        }

        $userToview = UserType::where('id', $id)->firstOrFail();

        if($userToview->tenant_id !== $tenant->id){
            return response()->json(['message'=> 'You are not authorized to do this'], 403);
        }

        return response()->json(['data'=> $userToview ],200);

    }

    public function destroy(Request $request, $tenant_slug){
        $user = $request->user();

        $tenant = Tenant::where('slug', $tenant_slug)->first();

        if (!$tenant) {
            return response()->json(['message' => 'Tenant not found'], 404);
        }

        //this ensures only an owner can delete a user type
        if($user->user_type_id !== 1){
            return response()->json(['message'=> 'You are not authorized to do this'], 403);
        }

        // Validate request data
        $validator = Validator::make($request->all(), [
            'id' => 'required|numeric|gte:1',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        //this ensures an owner cannot be deleted
        if($request->id == 1){
            return response()->json(['message'=> 'You are not authorized to do this'], 403);
        }

        $userType = UserType::findOrFail($request->id);

        //this ensures a tenant can only delete user types they created themselves
        if($userType->tenant_id !== $tenant->id){
            return response()->json(['message'=> 'You are not authorized to do this'], 403);
        }

        $response = $userType->delete();

        if(!$response){
            return response()->json(['message'=> 'Failed to delete, try again later'], 500);
        }

        return response()->json($response,204);
    }
}
