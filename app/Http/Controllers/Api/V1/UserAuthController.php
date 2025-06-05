<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Mail\OtpMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Db;
use App\Models\User;
use App\Models\Tenant;

class UserAuthController extends Controller
{
    public function login(Request $request, $tenant_slug)
    {
        try{
            $request->validate([
                'email' => 'required|string|email',
                'password' => 'required|string',
            ]);

            //After Validating, we identify the tenant using slug

            $tenant = Tenant::where('slug', $tenant_slug)->first();

            if (!$tenant) {
                return response()->json(['message' => 'Tenant not found'], 404);
            }

            //We get the user from the users table using the tenant-id 

            $user = User::where('email', $request->email)
                ->where('tenant_id', $tenant->id)
                ->first();

            //if no user is found for that particular tenant using the tenant Id, return response

            if (!$user) {
                return response()->json(['message'=> 'invalid credentials'],404);
            }

            //If user is found but passwords don't match

            if (!$user || !Hash::check($request->password, $user->password)) {
                return response()->json(['message' => 'Invalid credentials'], 404);
            }

            //create Api Token

            $token = $user->createToken('API Token')->plainTextToken;

            if(!$token){
                return response()->json(['message' => 'Something went wrong. Please try again'], 500); 
            }
        
            return response()->json([
                'token' => $token,
                'user' => $user,
            ]);

        } 
        catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        }
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out successfully']);
    }

    public function sendOtp(Request $request)
    {
        try {
            $request->validate([
                'email' => 'required|string|email|exists:users,email',
            ]);

            $user = User::where('email', $request->email)->first();

            if ($user) {
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
                $messageContent['firstName'] = $user->first_name;


                // Send OTP via email
                Mail::to($request->email)->send(new OtpMail($messageContent));

                return response()->json(['message' => 'Please verify you own the account by providing OTP sent to your registered email', 'status'=> 201], 201);
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

            $user = User::where('email', $request->email)->first();

            if (!$user) {
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

            return response()->json(['message' => 'OTP verified successfully.']);

        }
        catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        }
    }

    public function resetPassword(Request $request){
        try{
            $request->validate([
                'email' => 'required|email|exists:users,email',
                'otp' => 'required|numeric',
                'password' => 'required|min:8|confirmed',
            ]);  

            $user = User::where('email', $request->email)->first();

            if (!$user) {
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

            $user->password = Hash::make($request->password);
            $result = $user->save();

            if(!$result){
                return response()->json(['message' => 'Something went wrong with password change'], 500); 
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


    private function generateOtp($length = 6)
    {
        return random_int(100000, 999999);
    }
}
