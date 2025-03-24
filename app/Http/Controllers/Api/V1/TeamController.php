<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Hash;    
use Illuminate\Support\Facades\Mail; 
use App\Mail\RegistrationMail;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Team;
use App\Models\TeamUser;
use App\Models\TaxNumber;

class TeamController extends Controller
{
    public function AddTeam(Request $request, $tenant_slug){
        $user = $request->user();

        $manager = '';

        //We identify the tenant using slug
        $tenant = $this->checkTenant($tenant_slug);

        $userType = User::where('id', $user->id)->select('id', 'user_type_id')->with(['user_type:id,create_user'])->get();

        if($userType[0]['user_type']['create_user'] !== 'yes' || $tenant->id != $user->tenant_id){
            return response()->json(['message'=> 'You are not authorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'company' => 'required|string|max:255',
            'department' => 'string|max:255',
            'business_id' => 'numeric|gte:1',
            'external_id' => 'numeric|gte:1',
            'description' => 'string|max:2555',
        ]);
  
        if($validator->fails()){
            return response()->json(['error' => $validator->errors()], 422);
        }

        //The is the Logic to handle the manager column in the Teams table. If you want to add a new user as manager of a new team, This will check if first name is provided in the input.
        if($request->has('first_name')){
            $validator = Validator::make($request->all(), [
                'first_name' => 'required|string|max:255',
                'last_name' => 'required|string|max:255',
                'user_type_id' => 'numeric|gte:1',
                'email' => 'required|email|unique:users,email',
                'phone' => 'required|unique:users,phone|regex:/^([0-9\s\-\+\(\)]*)$/',
            ]);  
            
            if($validator->fails()){
                return response()->json(['error' => $validator->errors()], 422);
            }

            $validatedData = $validator->validated();

            $savedUser = $this->completeCreate($validatedData, $tenant);

            $manager = $savedUser['user']->id;

            if(!$savedUser){
                return response()->json(['message'=> 'Something went wrong, Contact us for help'],500);
            }
        }

        //The logic ends here

        //If the request does not have a first_name field, that means a manager Id has to be provided from the existing users
        if(!$request->has('first_name')){
            $validator = Validator::make($request->all(), [
                'manager' => 'required|numeric|gte:1|exists:users,id',
            ]);

            if($validator->fails()){
                return response()->json(['error' => $validator->errors()], 422);
            }

            User::findOrFail($request->manager);

            $manager = $request->manager;
        }

        $team = Team::create([
            'company' => htmlspecialchars($request->company, ENT_QUOTES, 'UTF-8'),
            'department'=> htmlspecialchars($request->department, ENT_QUOTES, 'UTF-8'),
            'business_number' => htmlspecialchars($request->business_id, ENT_QUOTES, 'UTF-8'),
            'external_id' => htmlspecialchars($request->external_id, ENT_QUOTES, 'UTF-8'),
            'description' => htmlspecialchars($request->description, ENT_QUOTES, 'UTF-8'),
            'tenant_id' => $tenant->id,
            'created_by_user_id' => $user->id,
        ]);

        if(!$team){
            return response()->json(['message'=> 'Something went wrong, Contact us for help'],500);
        }

        $teamUser = TeamUser::create([
            'team_id' => $team->id,
            'user_id' => $manager,
            'manager' => 'yes',
            'tenant_id' => $tenant->id,
        ]);

        if(!$teamUser){
            return response()->json(['message'=> 'Something went wrong, Contact us for help'],500);
        }

        if($request->has('tax_name')){
            $validator = Validator::make($request->all(), [
                'tax_name' => 'required|string|max:255',
                'value' => 'required|numeric|gte:1',
            ]);  
            
            if($validator->fails()){
                return response()->json(['error' => $validator->errors()], 422);
            }     
            
            $tax = TaxNumber::create([
                'name' => $request->tax_name,
                'value' => $request->value,
                'team_id' => $team->id,
                'tenant_id' => $tenant->id,
            ]);     

            if(!$tax){
                return response()->json(['message'=> 'Something went wrong, Contact us for help'],500);
            }
        }

        return response()->json(['message' => 'Team created Successfully', 'data'=>$team], 200);
    }

    public function update(Request $request, $tenant_slug, $id){
        $user = $request->user();

        $manager = '';

        //We identify the tenant using slug
        $tenant = $this->checkTenant($tenant_slug);

        $userType = User::where('id', $user->id)->select('id', 'user_type_id')->with(['user_type:id,create_user'])->get();

        if($userType[0]['user_type']['create_user'] !== 'yes' || $tenant->id != $user->tenant_id){
            return response()->json(['message'=> 'You are not authorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'company' => 'required|string|max:255',
            'department' => 'string|max:255',
            'business_id' => 'numeric|gte:1',
            'external_id' => 'numeric|gte:1',
            'description' => 'string|max:2555',
        ]);
  
        if($validator->fails()){
            return response()->json(['error' => $validator->errors()], 422);
        }   

        $validatedData = $validator->validated();
        
        $team = Team::findOrFail($id);

        $team->company = $validatedData['company'];
        $team->department = $validatedData['department'];
        $team->business_number = $validatedData['business_id'];
        $team->external_id = $validatedData['external_id'];
        $team->description = $validatedData['description'];

        if($request->has('tax_name')){
            $validator = Validator::make($request->all(), [
                'tax_name' => 'required|string|max:255',
                'value' => 'required|numeric|gte:1',
            ]); 
      
            if($validator->fails()){
                return response()->json(['error' => $validator->errors()], 422);
            }
            
            $tax = TaxNumber::where('team_id', $team->id)->get();

            $validatedData = $validator->validated();

            if($tax->isEmpty()){
                $taxNumber = TaxNumber::create([
                    'name' => $validatedData['tax_name'],
                    'value' => $validatedData['value'],
                    'team_id' => $team->id,
                    'tenant_id' => $tenant->id,
                ]);     
    
                if(!$taxNumber){
                    return response()->json(['message'=> 'Something went wrong'],500);
                } 
            }else{
                $taxing = TaxNumber::findOrFail($tax[0]->id);

                $taxing->name = $validatedData['tax_name'];
                $taxing->value = $validatedData['value'];

                $response = $taxing->save();

                if(!$response){
                    return response()->json(['message'=> 'Something went wrong'],500);
                }    
            }         
        }

        $res = $team->save();

            if(!$res){
                return response()->json(['message'=> 'Something went wrong'],500);
            } 

            return response()->json(['message'=> 'Team Updated Successfully', 'data' => $team],201);
    }

    public function addMember(Request $request, $tenant_slug){
        $user = $request->user();

        //We identify the tenant using slug
        $tenant = $this->checkTenant($tenant_slug);

        $userType = User::where('id', $user->id)->select('id', 'user_type_id')->with(['user_type:id,create_user'])->get();

        if($userType[0]['user_type']['create_user'] !== 'yes' || $tenant->id != $user->tenant_id){
            return response()->json(['message'=> 'You are not authorized'], 403);
        }   

        if($request->has('first_name')){
            $validator = Validator::make($request->all(), [
                'first_name' => 'required|string|max:255',
                'last_name' => 'required|string|max:255',
                'user_type_id' => 'numeric|gte:1',
                'email' => 'required|email|unique:users,email',
                'phone' => 'required|unique:users,phone|regex:/^([0-9\s\-\+\(\)]*)$/',
                'team_id' => 'required|numeric|gte:1',
            ]);  
            
            if($validator->fails()){
                return response()->json(['error' => $validator->errors()], 422);
            }

            $validatedData = $validator->validated();

            $savedUser = $this->completeCreate($validatedData, $tenant);


            if(!$savedUser){
                return response()->json(['message'=> 'Something went wrong, Contact us for help'],500);
            }

            $teamUser = TeamUser::create([
                'team_id' => $request->team_id,
                'user_id' => $savedUser['user']->id,
                'tenant_id' => $tenant->id,
            ]);

            if(!$teamUser){
                return response()->json(['message'=> 'Something went wrong'],500);
            }

            return response()->json(['message'=>'Member added successfully', 'data' => $teamUser], 201);
        }

        $validator = Validator::make($request->all(), [
            'user_id' => 'required|numeric|gte:1',
            'team_id' => 'required|numeric|gte:1',
        ]);  

        if($validator->fails()){
            return response()->json(['error' => $validator->errors()], 422);
        }

        User::findOrFail($request->user_id);

        $result = TeamUser::where('team_id', $request->team_id)->where('user_id', $request->user_id)->get();

        if(!$result->isEmpty()){
            return response()->json(['message' => 'Already a member of this group'], 422);
        }

        $teamUser = TeamUser::create([
            'team_id' => $request->team_id,
            'user_id' => $request->user_id,
            'tenant_id' => $tenant->id,
        ]);

        if(!$teamUser){
            return response()->json(['message'=> 'Something went wrong'],500);
        }

        return response()->json(['message'=>'Member added successfully', 'data' => $teamUser], 201);
    }

    private function checkTenant($tenant_slug){
        $tenant = Tenant::where('slug', $tenant_slug)->first();

        if (!$tenant) {
            return response()->json(['message' => 'Tenant not found'], 404);
        }

        return $tenant;

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

        return ["response" => $response, "user"=>$user];

    }
}
