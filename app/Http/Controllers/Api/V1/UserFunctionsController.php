<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Hash;    
use Illuminate\Support\Facades\Mail; 
use App\Mail\RegistrationMail;
use Illuminate\Http\Request;
use App\Models\Tenant;
use App\Models\User;

class UserFunctionsController extends Controller
{
    public function addUser(Request $request, $tenant_slug)
    {
        $user = $request->user();

        //We identify the tenant using slug
        $tenant = Tenant::where('slug', $tenant_slug)->with('subscription.plan')->first();

        $tenantUsers = User::where('tenant_id', $tenant->id)->get();

        $tenantUsers = count($tenantUsers);
        $planUsers = $tenant->subscription?->plan->num_of_users;

        if($tenantUsers >= $planUsers){
            return response()->json(['message'=> 'The plan you are trying to subscribe to does not support the number of users this tenant has'], 422);
        }

        if (!$tenant) {
            return response()->json(['message' => 'Tenant not found'], 404);
        }

        //Check Email
        $checkEmail = User::where('email', $request->email)->where('tenant_id', $tenant->id)->get();

        if(!$checkEmail->isEmpty()){
            return response()->json(['errors' => 'This Email has already been registered on this platform'], 422);
        }

        //Check Number
        $checkPhone = User::where('phone', $request->phone)->where('tenant_id', $tenant->id)->get();

        if(!$checkPhone->isEmpty()){
            return response()->json(['errors' => 'This phone number has already been registered on this platform'], 422);
        }

         // Validate request data
        $validator = Validator::make($request->all(), [
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'user_type_id' => 'numeric|gte:1',
            'email' => 'required|email',
            'phone' => 'required|regex:/^([0-9\s\-\+\(\)]*)$/',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Retrieve validated data from the validator instance
        $validatedData = $validator->validated();

        $userType = User::where('id', $user->id)->select('id', 'user_type_id')->with(['user_type:id,create_user,create_admin'])->get();

        $response = "";

        if($validatedData['user_type_id'] == 1){
            return response()->json(['message'=> 'You cannot create an owner'], 403);
        }

        if($validatedData['user_type_id'] == 2 && $validatedData['user_type_id'] != 1 && $validatedData['user_type_id'] != 3){

            if($userType[0]['user_type']['create_admin'] !== 'yes' || $tenant->id != $user->tenant_id){
                return response()->json(['message'=> 'You are not authorized'], 403);
            }

            $response = $this->completeCreate($validatedData, $tenant);
        }

        if($validatedData['user_type_id'] == 3){
            
            if($userType[0]['user_type']['create_user'] !== 'yes' || $tenant->id != $user->tenant_id){
                return response()->json(['message'=> 'You are not authorized'], 403);
            }

            $response = $this->completeCreate($validatedData, $tenant);
        }

        
        if($response){
            return response()->json(['message' => 'User added successfully! A verification email has been sent.', 'user' => $response['user']], 201); 
        }

        return response()->json(['message'=> 'Something went wrong, Contact us for help'],500);
    }

    public function updateUser(Request $request, $tenant_slug, $id){
        $user = $request->user();

        //We identify the tenant using slug
        $tenant = Tenant::where('slug', $tenant_slug)->first();

        if (!$tenant) {
            return response()->json(['message' => 'Tenant not found'], 404);
        }

         // Validate request data
        $validator = Validator::make($request->all(), [
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'user_type_id' => 'numeric|gte:1',
            'email' => [
                            'required',
                            'email',
                            Rule::unique('users', 'email')->ignore($id),
                            ],
            'phone' =>  [
                            'required',
                            'regex:/^([0-9\s\-\+\(\)]*)$/',
                            Rule::unique('users', 'phone')->ignore($id), // Exclude current user
                        ],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Retrieve validated data from the validator instance
        $validatedData = $validator->validated();

        $userType = User::where('id', $user->id)->select('id', 'user_type_id')->with(['user_type:id,update_user,update_admin'])->get();

        $response = "";

        if($validatedData['user_type_id'] == 1){
            return response()->json(['message'=> 'You cannot update to owner'], 403);
        }

        if($validatedData['user_type_id'] != 1 && $validatedData['user_type_id'] != 3){

            if($userType[0]['user_type']['update_admin'] !== 'yes' || $tenant->id != $user->tenant_id){
                return response()->json(['message'=> 'You are not authorized'], 403);
            }

            $response = $this->completeUpdate($validatedData, $id);
        }

        if($validatedData['user_type_id'] == 3){
            
            if($userType[0]['user_type']['update_user'] !== 'yes' || $tenant->id != $user->tenant_id){
                return response()->json(['message'=> 'You are not authorized'], 403);
            }

            $response = $this->completeUpdate($validatedData, $id);
        }

        
        if($response){
            return response()->json(['message' => 'User updated successfully!', 'user' => $response['user']], 201); 
        }

        return response()->json(['message'=> 'Something went wrong, Contact us for help'],500);
    }

    public function viewUsers(Request $request, $tenant_slug){
        $user = $request->user();

        $tenant = Tenant::where('slug', $tenant_slug)->first();

        if (!$tenant) {
            return response()->json(['message' => 'Tenant not found'], 404);
        }

        $userType = User::where('id', $user->id)->select('id', 'user_type_id')->with(['user_type:id,view_user,view_admin'])->get();

        $view_admin = $userType[0]['user_type']['view_admin'];
        $view_user = $userType[0]['user_type']['view_user'];

        if($user->user_type_id == 1){
            $users = User::where('tenant_id', $tenant->id)->with('user_type')->paginate(20); 

            return response()->json(['data'=> $users], 200);
        }

        if($view_user == 'yes' && $view_admin !== 'yes'){
            $users = User::where('tenant_id', $tenant->id)->where('user_type_id', 3)->with('user_type')->paginate(20);

            return response()->json(['data'=> $users], 200);
        }

        if($view_user !== 'yes' && $view_admin == 'yes'){
            $users = User::where('tenant_id', $tenant->id)->whereNotIn('user_type_id', [1])->whereNotIn('user_type_id',[2])->with('user_type')->paginate(20);

            return response()->json(['data'=> $users], 200);
        }

        if($view_user == 'yes' && $view_admin == 'yes'){
    
            $users = User::where('tenant_id', $tenant->id)->whereNotIn('user_type_id', [1])->with('user_type')->paginate(20);

            return response()->json(['data'=> $users], 200);
        }

        return response()->json(['message'=> 'You are not authorized'], 403);
    }

    public function viewUser(Request $request, $tenant_slug, $id){
        $user = $request->user();

        $tenant = Tenant::where('slug', $tenant_slug)->first();

        if (!$tenant) {
            return response()->json(['message' => 'Tenant not found'], 404);
        }

        $permission = User::where('id', $user->id)->select('id', 'user_type_id')->with(['user_type:id,view_user,view_admin'])->first();

        $userToview = User::where('id', $id)->with(['user_type'])->firstOrFail();

        if($permission->user_type->view_user === 'yes' && $userToview->user_type->user_type === 'User'){
            return response()->json(['data'=> $userToview]);
        }

        if($permission->user_type->view_admin === 'yes' && $userToview->user_type->user_type !== 'User'){
            return response()->json(['data'=> $userToview]);
        }

        return response()->json(['message', 'You are not authorized'], 403);

    }

    public function destroyUser(Request $request, $tenant_slug){
        $user = $request->user();

        $tenant = Tenant::where('slug', $tenant_slug)->first();

        if (!$tenant) {
            return response()->json(['message' => 'Tenant not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'id' => 'required|numeric|gte:1',
        ]); 

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $userr = User::findOrFail($request->id);

        $permission = User::where('id', $user->id)->select('id', 'user_type_id')->with(['user_type:id,delete_user,delete_admin'])->first();

        $userTodelete = User::where('id', $request->id)->with(['user_type'])->firstOrFail();

        if($permission->user_type->delete_user === 'yes' && $userTodelete->user_type->user_type === 'User'){

            $response = $userr->delete();

            if(!$response){
                return response()->json(['message'=> 'Unable to delete, try again later'], 500);
            }

            return response()->json(['message'=> 'User deleted successfully'],204);
        }

        if($permission->user_type->delete_admin === 'yes' && $userTodelete->user_type->user_type !== 'User'){

            $response = $userr->delete();

            if(!$response){
                return response()->json(['message'=> 'Failed to delete, try again later'], 500);
            }

            return response()->json(['message'=> 'Account deleted successfully'],204);
        }

        return response()->json(['message'=> 'You are not authorized'],403);
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

    private function completeCreate($validatedData, $tenant){
        $password = $this->generateSecurePassword();

        $user = User::create([
            'first_name' => htmlspecialchars($validatedData['first_name'], ENT_QUOTES, 'UTF-8'),
            'last_name' => htmlspecialchars($validatedData['last_name'], ENT_QUOTES, 'UTF-8'),
            'email' => filter_var($validatedData['email'], FILTER_SANITIZE_EMAIL),
            'phone' => htmlspecialchars($validatedData['phone'], ENT_QUOTES, 'UTF-8'),
            'user_type_id' => $validatedData['user_type_id'],
            'tenant_id' => $tenant->id,
            'password' => Hash::make($password),
        ]);

        $messageContent = [];
        $messageContent['email'] = $user->email;
        $messageContent['firstName'] = $user->first_name;
        $messageContent['password'] = $password;
        $messageContent['slug'] = $tenant->slug;
        // $messageContent['tenant'] = $tenant->company_name;

        // Send email verification link
        $response = $this->sendRegistrationEmail($messageContent);

        return ["response" => $response, "user"=>$user];

    }

    private function completeUpdate($validatedData, $id){
        $user = User::findOrFail($id);

        $user->first_name =  htmlspecialchars($validatedData['first_name'], ENT_QUOTES, 'UTF-8');
        $user->last_name = htmlspecialchars($validatedData['last_name'], ENT_QUOTES, 'UTF-8');
        $user->email = filter_var($validatedData['email'], FILTER_SANITIZE_EMAIL);
        $user->phone = htmlspecialchars($validatedData['phone'], ENT_QUOTES, 'UTF-8');
        $user->user_type_id = $validatedData['user_type_id'];

        $response = $user->save();

        return ["response" => $response, "user"=>$user];

    }
    public function create_visitor_user($data, $tenant)
{
    // Count existing users for the tenant
    $tenantUsers = User::where('tenant_id', $tenant->id)->count();

    // Get the tenant with subscription and plan data
    $tenant_user = Tenant::with([
        'subscription:id,plan_id',
        'subscription.plan:id,num_of_users'
    ])->where('id', $tenant->id)->first();

    // Safely get the number of allowed users in the plan
    $planUsers = $tenant_user->subscription?->plan->num_of_users ?? 0;

    // Check if user limit has been reached
    if ($tenantUsers >= $planUsers) {
        return ['error' => 'user capacity reached'];
    }

    // Set user type for visitor
    $data['user_type_id'] = 3;

    // Create the user
    $user = $this->completeCreate($data, $tenant);

    // Return only the created user
    return $user['user'];
}


}
