<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Hash;    
use Illuminate\Support\Facades\Mail; // Add this to use the Mail facade for sending emails
use App\Mail\RegistrationMail; // Import the email verification mailable class
use Illuminate\Http\Request;
use App\Models\Tenant;
use App\Models\User;

class UserFunctionsController extends Controller
{
    public function addUser(Request $request, $tenant_slug){
        // Validate request data
        $validator = Validator::make($request->all(), [
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'user_type_id' => 'numeric|gte:1',
            'email' => 'required|email|unique:users,email',
            'phone' => 'required|unique:users,phone|regex:/^([0-9\s\-\+\(\)]*)$/',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
    
        //After Validating, we identify the tenant using slug

        $tenant = Tenant::where('slug', $tenant_slug)->first();

        if (!$tenant) {
            return response()->json(['message' => 'Tenant not found'], 404);
        }

        $user = $request->user();

        if($user->user_type_id == 3 || $tenant->id != $user->tenant_id){
            return response()->json(['message'=> 'You are not authorized'], 403);
        }

        // Retrieve validated data from the validator instance
        $validatedData = $validator->validated();

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

        // Send email verification link
        $response = $this->sendRegistrationEmail($messageContent);

        if($response){
            return response()->json(['message' => 'User added successfully! A verification email has been sent.', 'user' => $user], 201); 
        }

        return response()->json(['message'=> 'Something went wrong, Contact us for help'],500);
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
}
