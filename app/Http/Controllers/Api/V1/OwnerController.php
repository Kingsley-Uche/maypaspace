<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use App\Mail\SystemAdminRegEmail;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;

use App\Models\Admin;
use App\Models\Role;

class OwnerController extends Controller
{
    public function addSystemAdmin(Request $request){
        $systemAdmin = $request->user();

        if($systemAdmin->role_id !== 1){
            return response()->json(['message'=> 'You are not authorized'], 401);
        }

        // Validate request data
        $validator = Validator::make($request->all(), [
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'role_id' => 'numeric|gte:2',
            'email' => 'required|email|unique:admins,email',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
    
        // Retrieve validated data from the validator instance
        $validatedData = $validator->validated();

        $password = $this->generateSecurePassword();

        $admin = Admin::create([
            'first_name' => htmlspecialchars($validatedData['first_name'], ENT_QUOTES, 'UTF-8'),
            'last_name' => htmlspecialchars($validatedData['last_name'], ENT_QUOTES, 'UTF-8'),
            'email' => filter_var($validatedData['email'], FILTER_SANITIZE_EMAIL),
            'role_id' => $request->role_id,
            'password' => Hash::make($password),
        ]);

        $messageContent = [];
        $messageContent['email'] = $admin->email;
        $messageContent['firstName'] = $admin->first_name;
        $messageContent['password'] = $password;

        // Send email verification link
        $response = $this->sendAdminRegistrationEmail($messageContent);

        if($response){
            return response()->json(['message' => 'Admin added successfully! A verification email has been sent.', 'admin' => $admin], 201); 
        }

        return response()->json(['message'=> 'Something went wrong'],422);
    }

    public function deleteSystemAdmin(Request $request){
        $admin = $request->user();

        if($admin->role_id !== 1){
            return response()->json(['message'=> 'You are not authorized to do this'], 403);
        }

       // Validate request data
       $validator = Validator::make($request->all(), [
            'id' => 'required|numeric|gte:1',
       ]); 

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $admin = Admin::findOrFail($request->id);

        $response = $admin->delete();

        if(!$response){
            return response()->json(['message'=> 'Failed to delete, try again later'], 500);
        }

        return response()->json(['message'=> 'Role deleted successfully','data'=> $admin ],204);
    }

    public function viewSystemAdmins(){
        $admins = Admin::with('role')->paginate(20);

        return response()->json(['data'=> $admins ],200);
    }

    public function viewSystemAdmin($id){
        $admin = Admin::with('role')->find($id);

        return response()->json(['data'=> $admin],200);
    }

    public function updateSystemAdmin(Request $request, $id){
        $admin = $request->user();

        if($admin->role_id !== 1){
            return response()->json(['message'=> 'You are not authorized to do this'], 403);
        }

        $admin = Admin::findOrFail($id); 

        // Validate request data
        $validator = Validator::make($request->all(), [
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'role_id' => 'numeric|gte:2',
            'email' => 'required|email|exists:admins,email',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $admin->update($request->all());

        $response = $admin->save();

        if(!$response){
            return response()->json(['message'=> 'Something went wrong'], 500);
        }

        return response()->json(['message' => 'Admin Updated successfully', 'data'=> $admin ],200);
    
    }

    public function createRole(Request $request){
        $admin = $request->user();

        if($admin->role_id !== 1){
            return response()->json(['message'=> 'You are not authorized to do this'], 403);
        }

       // Validate request data
       $validator = Validator::make($request->all(), [
        'role' => 'required|string|max:255|unique:roles,role',
        'create_tenant' => [Rule::in(['yes', 'no'])],
        'update_tenant' => [Rule::in(['yes', 'no'])],
        'delete_tenant' => [Rule::in(['yes', 'no'])],
        'view_tenant' => [Rule::in(['yes', 'no'])],
        'view_tenant_income' => [Rule::in(['yes', 'no'])],
        'create_plan' => [Rule::in(['yes', 'no'])],
       ]); 

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        
        $role = Role::create([
            'role' => $request->role,
            'create_tenant' => $request->create_tenant,
            'update_tenant' => $request->update_tenant,
            'delete_tenant' => $request->delete_tenant,
            'view_tenant' => $request->view_tenant,
            'view_tenant_income' => $request->view_tenant_income,
            'create_plan' => $request->create_plan,
        ]);

        if(!$role){
            return response()->json(['message'=> 'Something went wrong, try again'], 500);
        }

        return response()->json([   'message'=> 'Role created successfully', 'data'=> $role ],201);
    
    }

    public function updateRole(Request $request, $id){
        $admin = $request->user();

        if($admin->role_id !== 1){
            return response()->json(['message'=> 'You are not authorized to do this'], 403);
        }

        $role = Role::findOrFail($id);

        // Validate request data
        $validator = Validator::make($request->all(), [
            'role' => [
                'required',
                'string',
                Rule::unique('roles', 'role')->ignore($id),
                ],
            'create_tenant' => [Rule::in(['yes', 'no'])],
            'update_tenant' => [Rule::in(['yes', 'no'])],
            'delete_tenant' => [Rule::in(['yes', 'no'])],
            'view_tenant' => [Rule::in(['yes', 'no'])],
            'view_tenant_income' => [Rule::in(['yes', 'no'])],
            'create_plan' => [Rule::in(['yes', 'no'])],
        ]); 

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $role->update($request->all());

        $response = $role->save();

        if(!$response){
            return response()->json(['message'=> 'Something went wrong'], 500);
        }

        return response()->json(['message' => 'Role Updated successfully', 'data'=> $role ],200);
    }

    public function destroyRole(Request $request){
        $admin = $request->user();

        if($admin->role_id !== 1){
            return response()->json(['message'=> 'You are not authorized to do this'], 403);
        }

       // Validate request data
       $validator = Validator::make($request->all(), [
        'id' => 'required|numeric|gte:1',
       ]); 

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if($request->id == 1){
            return response()->json(['message'=> 'You are not authorized to do this'], 403);
        }

        $role = Role::findOrFail($request->id);

        $response = $role->delete();

        if(!$response){
            return response()->json(['message'=> 'Failed to delete, try again later'], 500);
        }

        return response()->json(['message'=> 'Role deleted successfully','data'=> $role ],204);
    }

    public function viewRoles(){
        $roles = Role::where('id', '!=', 1)->get();

        return response()->json(['data'=> $roles ],200);
    }

    public function viewRole($id){
        if($id === 1){
            return response()->json(['message'=> 'This role does not exist'], 403);  
        }
        $role = Role::where('id', $id)->get();

        return response()->json(['data'=> $role],200);
    }

    private function generateSecurePassword(): string
    {
        // Define character sets
        $symbols = '!@#$%^&*_:<>?';
        $uppercase = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $numbers = '0123456789';
        $lowercase = 'abcdefghijklmnopqrstuvwxyz';

        // Randomly select one character from each set
        $password = [
            $symbols[random_int(0, strlen($symbols) - 1)],
            $uppercase[random_int(0, strlen($uppercase) - 1)],
            $numbers[random_int(0, strlen($numbers) - 1)],
        ];

        // Fill the rest of the password with random lowercase letters
        for ($i = 0; $i < 5; $i++) {
            $password[] = $lowercase[random_int(0, strlen($lowercase) - 1)];
        }

        // Shuffle the characters to randomize their positions
        shuffle($password);

        // Convert the array to a string and return it
        return implode('', $password);
    }

    private function sendAdminRegistrationEmail($messageContent)
    {
        $response = '';
        // Using Laravel's Mail functionality to send an email verification link
        try {
           $response = Mail::to($messageContent['email'])->send(new SystemAdminRegEmail($messageContent));
        } catch (\Exception $e) {
            // Log the error for debugging purposes
            \Log::error('Error sending verification email: ' . $e->getMessage());
            return response()->json(['message'=> $e->getMessage()],422);
        }

        return $response;
    }
}
