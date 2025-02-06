<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Mail; // Add this to use the Mail facade for sending emails
use App\Mail\RegistrationMail; // Import the email verification mailable class
use App\Models\User;
use App\Models\Tenant;
use Illuminate\Http\Request;

class SystemAdminFunctionsController extends Controller
{
    public function registerCompany(Request $request){
       // Validate request data
       $validator = Validator::make($request->all(), [
            'company_name' => 'required|string|max:255|unique:tenants,company_name',
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'phone' => 'required|unique:users,phone|regex:/^([0-9\s\-\+\(\)]*)$/',
       ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Retrieve validated data from the validator instance
        $validatedData = $validator->validated();

        $sanitizedSlug = strtolower(str_replace(' ', '', $request->company_name));

        $tenant = Tenant::create([
            'company_name'=> $validatedData['company_name'],
            'slug' => $sanitizedSlug
        ]); 

        if(!$tenant){
            return response()->json(['message'=> 'something went wrong, please try again'],422);
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
            return response()->json(['message' => 'Company added successfully! A verification email has been sent.', 'user' => $user], 201); 
        }

        return response()->json(['message'=> 'Account created but something went wrong, Contact us for help'],422);
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
        }

        return $response;
    }

}
