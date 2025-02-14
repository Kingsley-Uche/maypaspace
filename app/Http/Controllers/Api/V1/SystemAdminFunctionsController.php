<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Mail; // Add this to use the Mail facade for sending emails
use App\Mail\RegistrationMail; // Import the email verification mailable class
use Illuminate\Http\Request;

use App\Models\User;
use App\Models\Tenant;
use App\Models\Admin;

class SystemAdminFunctionsController extends Controller
{
    public function createTenant(Request $request){
        $admin = $request->user();

        $role = Admin::where('id', $admin->id)->select('id', 'role_id')->with(['role:id,create_tenant'])->get();

        if($role[0]['role']['create_tenant'] !== 'yes'){
            return response()->json(['message'=> 'You are not authorized to do this'], 403);
        }
       // Validate request data
       $validator = Validator::make($request->all(), [
            'company_name' => 'required|string|max:255|unique:tenants,company_name',
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'phone' => 'required|unique:users,phone|regex:/^([0-9\s\-\+\(\)]*)$/',
            'company_no_location' => 'required|numeric|gte:1',
            'company_countries' => 'required|array',
            'company_countries.*' => 'string',
       ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Retrieve validated data from the validator instance
        $validatedData = $validator->validated();

        $sanitizedSlug = strtolower(str_replace(' ', '', $request->company_name));

        $createdby = '';

        if($admin->role_id == 1){
            $createdby = null;
        }else{
            $createdby = $admin->id;
        }

        $tenant = Tenant::create([
            'company_name'=> $validatedData['company_name'],
            'slug' => $sanitizedSlug,
            'company_no_location' => $validatedData['company_no_location'],
            'company_countries' => json_encode($validatedData['company_countries']),
            'created_by_admin_id' => $createdby,
            'subscription_id' => null,
        ]); 

        if(!$tenant){
            return response()->json(['message'=> 'something went wrong, please try again'],500);
        }

        $password = $this->generateSecurePassword();

        $user = User::create([
            'first_name' => htmlspecialchars($validatedData['first_name'], ENT_QUOTES, 'UTF-8'),
            'last_name' => htmlspecialchars($validatedData['last_name'], ENT_QUOTES, 'UTF-8'),
            'email' => filter_var($validatedData['email'], FILTER_SANITIZE_EMAIL),
            'phone' => htmlspecialchars($validatedData['phone'], ENT_QUOTES, 'UTF-8'),
            'user_type_id' => 1,
            'tenant_id' => $tenant->id,
            'password' => Hash::make($password),
        ]);

        $messageContent = [];
        $messageContent['email'] = $user->email;
        $messageContent['firstName'] = $user->first_name;
        $messageContent['password'] = $password;
        $messageContent['slug'] = $tenant->slug;

        // Send email verification link
        $response = $this->sendRegistrationEmail($messageContent);

        if($response){
            return response()->json(['message' => 'Company added successfully! A verification email has been sent.', 'user' => $user, 'tenant' => $tenant], 201); 
        }

        return response()->json(['message'=> 'Account created but something went wrong, Contact us for help'],500);
    }

    public function getTenant(Request $request, $id){
        $admin = $request->user();

        $role = Admin::where('id', $admin->id)->select('id', 'role_id')->with(['role:id,view_tenant'])->get();

        if($role[0]['role']['view_tenant'] !== 'yes'){
            return response()->json(['message'=> 'You are not authorized to do this'], 403);
        }

        $tenant = Tenant::where('id', $id)->get();

        return response()->json(['data'=> $tenant],200);
    }

    public function getTenants(Request $request){
        $admin = $request->user();

        $role = Admin::where('id', $admin->id)->select('id', 'role_id')->with(['role:id,view_tenant'])->get();

        if($role[0]['role']['view_tenant'] !== 'yes'){
            return response()->json(['message'=> 'You are not authorized to do this'], 403);
        }

        $tenants = Tenant::paginate(20);

        return response()->json(['data'=> $tenants ],200);
    }

    public function destroyTenant(Request $request){
        $admin = $request->user();

        $role = Admin::where('id', $admin->id)->select('id', 'role_id')->with(['role:id,delete_tenant'])->get();

        if($role[0]['role']['delete_tenant'] !== 'yes'){
            return response()->json(['message'=> 'You are not authorized to do this'], 403);
        }

        $validator = Validator::make($request->all(), [
            'id' => 'required|numeric|gte:1',
        ]); 

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $tenant = Tenant::findOrFail($request->id);

        $response = $tenant->delete();

        if(!$response){
            return response()->json(['message'=> 'Failed to delete, try again later'], 500);
        }

        return response()->json(['message'=> 'Tenant deleted successfully','data'=> $role ],204);
    }

    public function updateTenant(Request $request, $id){
        $admin = $request->user();

        $role = Admin::where('id', $admin->id)->select('id', 'role_id')->with(['role:id,update_tenant'])->get();

        if($role[0]['role']['update_tenant'] !== 'yes'){
            return response()->json(['message'=> 'You are not authorized to do this'], 403);
        }
       // Validate request data
        $validator = Validator::make($request->all(), [
            'company_name' => 'required|string|max:255|exists:tenants,company_name',
            'company_no_location' => 'required|numeric|gte:1',
            'company_countries' => 'required|array',
            'company_countries.*' => 'string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }    

        // Retrieve validated data from the validator instance
        $validatedData = $validator->validated();

        $sanitizedSlug = strtolower(str_replace(' ', '', $request->company_name));

        $tenant = Tenant::findOrFail($id);

        $tenant->company_name = $validatedData['company_name'];
        $tenant->slug = $sanitizedSlug;
        $tenant->company_no_location = $validatedData['company_no_location'];
        $tenant->company_countries = json_encode($validatedData['company_countries']);

        $response = $tenant->save();

        if(!$response){
            return response()->json(['message'=> 'something went wrong, please try again'],500);    
        }

        return response()->json(['message'=>'Tenant Updated successfully', 'data'=>$tenant], 200);
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

    private function sendRegistrationEmail($messageContent)
    {
        $response = '';
        // Using Laravel's Mail functionality to send an email verification link
        try {
           $response = Mail::to($messageContent['email'])->send(new RegistrationMail($messageContent));
        } catch (\Exception $e) {
            // Log the error for debugging purposes
            \Log::error('Error sending verification email: ' . $e->getMessage());
            return response()->json(['message'=> $e->getMessage()],422);
        }

        return $response;
    }

    public function systemAdminDetails(Request $request){
        $admin = $request->user();

        $admin = Admin::where('id', $admin->id)->load('role')->get();

        return response()->json(['data'=> $admin],200);
    }

}
