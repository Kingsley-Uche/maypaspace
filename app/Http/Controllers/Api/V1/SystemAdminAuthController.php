<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Support\Facades\Validator;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use App\Mail\OtpMail;
use App\Mail\SystemPasswordMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Admin;
use App\Models\User;
use Illuminate\Http\Request;


class SystemAdminAuthController extends Controller
{
    public function login(Request $request)
    {
        try{
            $request->validate([
                'email' => 'required|string|email',
                'password' => 'required|string',
            ]);

            $admin = Admin::where('email', $request->email)->first();

            if (!$admin || !Hash::check($request->password, $admin->password)) {
                return response()->json(['message' => 'Invalid credentials'], 401);
            }

            $token = $admin->createToken('admin-token', ['admin'])->plainTextToken;

            if(!$token){
                return response()->json(['message' => 'Something went wrong. Please try again'], 500); 
            }

            return response()->json(['token' => $token, 'admin' => $admin], 200);
        } 
        catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        }
    }

    public function sendOtp(Request $request)
    {
        try {
            $request->validate([
                'email' => 'required|string|email|exists:admins,email',
            ]);

            $admin = Admin::where('email', $request->email)->first();

            if ($admin) {
                // send OTP
                $otp = $this->generateOtp(); 

                // Save OTP to the database
                DB::table('otps')->insert([
                    'email' => $request->email,
                    'otp' => $otp,
                    'expires_at' => now()->addMinutes(5),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $messageContent = [];
                $messageContent['otp'] = $otp;
                $messageContent['firstName'] = $admin->first_name;


                // Send OTP via email
                Mail::to($request->email)->send(new OtpMail($messageContent));

                return response()->json(['message' => 'Please verify you own the account by providing OTP sent to your registered email'], 201);
            }
                
            return response()->json(['message' => 'Could not find user'], 404);
        }
        catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        }
        
    }

    public function verifyOtp(Request $request)
    {
        try{
            $request->validate([
                'email' => 'required|string|email|unique:users,email',
                'otp' => 'required|numeric',
            ]);

            $admin = Admin::where('email', $request->email)->first();

            if (!$admin) {
                return response()->json(['message' => 'User not found'], 404);
            }

            $otpRecord = DB::table('otps')
            ->where('email', $request->email)
            ->where('otp', $request->otp)
            ->where('is_used', false)
            ->where('expires_at', '>=', now())
            ->first();

            if (!$otpRecord) {
                return response()->json(['message' => 'Invalid or expired OTP.'], 400);
            }

            // Mark the OTP as used
            DB::table('otps')->where('id', $otpRecord->id)->update(['is_used' => true]);

            $password = $this->generateSecurePassword();

            $admin->password = Hash::make($password);
            $admin->save();

            $messageContent = [];
            $messageContent['email'] = $admin->email;
            $messageContent['name'] = $admin->first_name." ".$admin->last_name;
            $messageContent['password'] = $password;

            try {
                $response = Mail::to('nemidav@gmail.com')->send(new SystemPasswordMail($messageContent));
             } catch (\Exception $e) {
                 // Log the error for debugging purposes
                 Log::error('Error sending Password: ' . $e->getMessage());

                 return response()->json(['message' => 'Error sending password.']); 
             }

            return response()->json(['message' => 'OTP verified successfully.'], 200);

        }
        catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        }
    }

    public function changePassword(Request $request){
        $admin = $request->user();

        $validator = Validator::make($request->all(), [
            'current_password' => 'required',
            'new_password' => 'required|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $admin = Admin::where('email', $admin->email)->first();

        if (!Hash::check($request->current_password, $admin->password)) {
            return response()->json(['message'=> 'You provided the wrong password'], 422);
        }

        //Update the password
        $response = $admin->update([
            'password' => Hash::make($request->new_password),
        ]);

        if(!$response){
            return response()->json(['message'=> 'Something went wrong'],422);
        }

        return response()->json(['message' => 'Password changed successfully.'], 200);

    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Admin logged out successfully']);
    }

    private function generateOtp($length = 6)
    {
        return random_int(100000, 999999);
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
}
