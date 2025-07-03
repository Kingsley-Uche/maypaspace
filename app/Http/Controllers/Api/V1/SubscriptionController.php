<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Mail;
use App\Mail\SubscriptionMail;

use App\Models\Tenant;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Admin;

use Carbon\Carbon;

class SubscriptionController extends Controller
{
    public function createSubscription(Request $request){
        $validator = Validator::make($request->all(), [
            'tenant_id' => 'required|numeric|exists:tenants,id',
            'plan_id' => 'required|numeric|exists:plans,id'
        ]);

        if($validator->fails()){
            return response()->json(['errors'=> $validator->errors()], 422);
        }

        $tenant = Tenant::findOrFail($request->tenant_id);
        $plan = Plan::findOrFail($request->plan_id);

        $tenantLocations = $tenant->company_no_location;
        $planLocations = $plan->num_of_locations;

        if($planLocations < $tenantLocations){
            return response()->json(['message'=> 'The plan you are trying to subscribe to does not support the number of locations this tenant has'], 422);
        }

        $subscription = Subscription::create([
            'plan_id' => $plan->id,
            'starts_at' => now(),
            'ends_at' => now()->addMonths((int)$plan->duration),
            'status' => 'active',
        ]);

        if(!$subscription){
            return response()->json(['message'=> 'Something went wrong in creating the subscription'], 500);
        }

        $tenant->subscription_id = $subscription->id;
        $tenant_sub = $tenant->save();

        if(!$tenant_sub){
            return response()->json(['message' => 'Something went wrong in updating the tenant subscription ID'], 500);
        }

        $user = User::where('tenant_id', $tenant->id)->oldest()->first();

        $messageContent = [];
        $messageContent['firstName'] = $user->first_name;
        $messageContent['planName'] = $plan->name;
        $messageContent['planExpire'] = Carbon::parse($subscription->ends_at)->format('F j, Y g:i A');

        $response = '';
        // Using Laravel's Mail functionality to send an email verification link
        try {
           $response = Mail::to($user->email)->send(new SubscriptionMail($messageContent));
        } catch (\Exception $e) {
            // Log the error for debugging purposes
            \Log::error('Error sending verification email: ' . $e->getMessage());
            return response()->json(['message'=> $e->getMessage()],422);
        }

        if(!$response){
            return response()->json(['message'=> 'something went wrong'],422);
        }

        return response()->json(['message' => 'successfully subscribed company'], 201);
    }

    public function viewSubscriptions(){
        $subscriptions = Subscription::with(['plan','tenant:id,company_name,subscription_id'])->paginate(20);

        return response()->json(['data'=> $subscriptions ],200);
    }

    public function viewSubscription($id){
        $subscription = Subscription::where('id', $id)->with('plan')->first();

        return response()->json(['data'=> $subscription],200);
    }

    public function deleteSubscription(Request $request){
       // Validate request data
       $validator = Validator::make($request->all(), [
            'id' => 'required|numeric|gte:1',
       ]); 

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $sub = Subscription::findOrFail($request->id);

        $response = $sub->delete();

        if(!$response){
            return response()->json(['message'=> 'Failed to delete, try again later'], 500);
        }

        return response()->json(['message'=> 'subscription deleted successfully','data'=> $sub ],204);
    }

    public function createPlan(Request $request){

        $admin = $request->user();

        $role = Admin::where('id', $admin->id)->select('id', 'role_id')->with(['role:id,create_plan'])->get();

        if($role[0]['role']['create_plan'] !== 'yes'){
            return response()->json(['message'=> 'You are not authorized to do this'], 403);
        }

       // Validate request data
       $validator = Validator::make($request->all(), [
        'name' => 'required|string|max:255|unique:plans,name',
        'price' => 'required|numeric|gte:1',
        'num_of_locations' => 'required|numeric|gte:0',
        'num_of_users' => 'required|numeric|gte:0',
        'duration' => 'required|numeric|gt:0',
       ]); 

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        
        $plan = Plan::create([
            'name' => $request->name,
            'price' => $request->price,
            'duration' => $request->duration,
            'num_of_locations' => $request->num_of_locations,
            'num_of_users' => $request->num_of_users,
        ]);

        if(!$plan){
            return response()->json(['message'=> 'Something went wrong, try again'], 500);
        }

        return response()->json([   'message'=> 'Plan created successfully', 'data'=> $plan ],201);
    
    }

    public function updatePlan(Request $request, $id){
        $admin = $request->user();

        $role = Admin::where('id', $admin->id)->select('id', 'role_id')->with(['role:id,create_plan'])->get();

        if($role[0]['role']['create_plan'] !== 'yes'){
            return response()->json(['message'=> 'You are not authorized to do this'], 403);
        }
        $plan = Plan::findOrFail($id);

        // Validate request data
        $validator = Validator::make($request->all(), [
            'name' => [
                        'required',
                        'string',
                        Rule::unique('plans', 'name')->ignore($id),
                        ],
            'price' => 'required|numeric|gte:1',
            'duration' => 'required|numeric|gt:0',
            'num_of_users' => 'required|numeric|gte:0',
            'num_of_locations' => 'required|numeric|gt:0',
        ]); 

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $plan->update($request->all());

        $response = $plan->save();

        if(!$response){
            return response()->json(['message'=> 'Something went wrong'], 500);
        }

        return response()->json(['message' => 'Plan Updated successfully', 'data'=> $plan],200);
    }

    public function deletePlan(Request $request){
        $admin = $request->user();

        if($admin->role_id != 1){
            return response()->json(['message'=> 'You are not authorized to do this'], 403);
        }

       // Validate request data
       $validator = Validator::make($request->all(), [
        'id' => 'required|numeric|gte:1',
       ]); 

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $plan = Plan::findOrFail($request->id);

        $response = $plan->delete();

        if(!$response){
            return response()->json(['message'=> 'Failed to delete, try again later'], 500);
        }

        return response()->json(['message'=> 'Plan deleted successfully'],204);
    }

    public function viewPlans(){
        $plans = Plan::all();

        return response()->json(['data'=> $plans],200);
    }

    public function viewPlan($id){
        $plan = Plan::where('id', $id)->get();

        return response()->json(['data'=> $plan],200);
    }
}
