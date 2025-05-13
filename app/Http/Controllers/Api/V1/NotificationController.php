<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Jobs\SendNotificationMail;
use App\Mail\NotificationMail;
use Illuminate\Support\Facades\Mail;

use App\Models\User;
use App\Models\Tenant;
use App\Models\Notification;
use App\Models\NotificationRead;

class NotificationController extends Controller
{
    public function store(Request $request, $tenant_slug){
        $user = $request->user();

        $tenant = $this->checksAndValidations($user, $tenant_slug);

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'required|string',
            'user_type_id' => 'numeric|gte:1|exists:user_types,id',
        ]);
  
         if($validator->fails()){
          return response()->json(['error' => $validator->errors()], 422);
         }

         $notification = Notification::create([
            'name' => htmlspecialchars($request->name, ENT_QUOTES, 'UTF-8'),
            'description' => htmlspecialchars($request->description, ENT_QUOTES, 'UTF-8'),
            'for' => $request->user_type_id, 
            'tenant_id' => $tenant->id
         ]);

         return response()->json(['message' => 'Notification created', 'notification' => $notification], 201);
    
    }

    public function update(Request $request, $tenant_slug, $id){
        $user = $request->user();

        $this->checksAndValidations($user, $tenant_slug);

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'required|string',
        ]);
  
        if($validator->fails()){
            return response()->json(['error' => $validator->errors()], 422);
        }

        $notification = Notification::findOrFail($id);

        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'required|string',
        ]);

        $notification->update($request->only('name', 'description'));

        return response()->json(['message' => 'Notification updated', 'notification' => $notification]);
    }

    public function index(Request $request, $tenant_slug){
        $user = $request->user();

        $tenant = $this->checksAndValidations($user, $tenant_slug);

        $notifications = Notification::where('tenant_id', $tenant->id)->get();

        return response()->json(['data'=> $notifications]);
    }

    public function show(Request $request,$tenant_slug, $id){
        $user = $request->user();

        $this->checksAndValidations($user, $tenant_slug);

        $notifications = Notification::where('id', $id)->get();

        return response()->json(['data'=> $notifications]);
    }

    public function destroy(Request $request, $tenant_slug){
        $user = $request->user();

        $this->checksAndValidations($user, $tenant_slug);

        $validator = Validator::make($request->all(), [
            'id' => 'required|string|max:255',
        ]);
  
        if($validator->fails()){
            return response()->json(['error' => $validator->errors()], 422);
        }

        $notification = Notification::findOrFail($request->id);

        $response = $notification->delete();

        if(!$response){
            return response()->json(['message'=> 'Unable to delete, try again later'], 500);
        }

        return response()->json(['message'=> 'Notification deleted successfully'],204);
    }

    public function togglePublish(Request $request, $tenant_slug, $id){
        $user = $request->user();

        $tenant = $this->checksAndValidations($user, $tenant_slug);

        $item = Notification::find($id);

        if (!$item) {
            return response()->json(['message' => 'Item not found'], 404);
        }

        // Toggle the value
        $item->publish = $item->publish === 'yes' ? 'no' : 'yes';
        $item->save();


        if($item->publish === 'yes' && $item->for){
            $user = User::where('user_type_id', $item->for)->where('tenant_id', $tenant->id)->select('email', 'first_name')->get();

            $response = $this->sendQueuedEmails($user, $item);

            if(!$response){
                return response()->json(['message' => 'Something went wrong'], 500); 
            }
        }

        return response()->json([
            'message' => 'Publish status updated successfully',
            'new_status' => $item->publish
        ]);

    }

    public function markAsRead(Request $request, $tenant_slug, $id){
        $user = $request->user();

        $tenant = $this->checkTenant($tenant_slug);

        if($user->id == 1 || $user->id == 2){
            return response()->json(['message' => 'You are not authorized'], 403);
        }

        if($user->tenant_id !== $tenant->id){
            return response()->json(['message' => 'You are not authorized'], 403);
        }

        $notification = Notification::findOrFail($id);

        $alreadyRead = NotificationRead::where('user_id', $user->id)
            ->where('notification_id', $id)
            ->exists();

        if (!$alreadyRead) {
            NotificationRead::create([
                'user_id' => $user->id,
                'notification_id' => $id,
                'read_at' => now(),
                'tenant_id' => $tenant->id
            ]);
        }

        return response()->json(['message' => 'Marked as read', 'data'=> $notification]);
    }

    public function userIndex(Request $request, $tenant_slug){
        $user = $request->user();

        $tenant = $this->checkTenant($tenant_slug);

        if($user->id == 1 || $user->id == 2){
            return response()->json(['message' => 'You are not authorized'], 403);
        }

        if($user->tenant_id !== $tenant->id){
            return response()->json(['message' => 'You are not authorized'], 403);
        }
        
        $notifications = Notification::where('publish', 'yes')
            ->with('reads')
            ->latest()
            ->get()
            ->map(function ($notification) use ($user) {
                $notification->is_read = $notification->reads->contains('user_id', $user->id);
                return $notification;
            });

        return response()->json($notifications);
    }

    private function checkTenant($tenant_slug){
        $tenant = Tenant::where('slug', $tenant_slug)->first();

        if (!$tenant) {
            return response()->json(['message' => 'Tenant not found'], 404);
        }

        return $tenant;

    }

    private function checksAndValidations($user, $tenant_slug){

        //We identify the tenant using slug
        $tenant = $this->checkTenant($tenant_slug);

        if($user->user_type_id !== 1 && $user->user_type_id !== 2){
            return response()->json(['message' => 'You are not authorized'], 403);
        }

        if($user->tenant_id !== $tenant->id){
            return response()->json(['message' => 'You are not authorized'], 403);
        }

        return $tenant;
    }

    // private function sendQueuedEmails($user, $item)
    // {

    //     foreach ($user as $recipient) {
    //         $email = $recipient['email'];
    //         $data = [
    //             'name' => $recipient['first_name'],
    //             'title' => $item->name,
    //             'message' => $item->description,
    //         ];

    //         $response = SendNotificationMail::dispatch($email, $data);
    //     }

    //     return $response;
    // }

    private function sendQueuedEmails($user, $item)
    {
        $response = '';

        foreach ($user as $recipient) {
            $email = $recipient['email'];
            $data = [
                'name' => $recipient['first_name'],
                'title' => $item->name,
                'message' => $item->description,
            ];

            // Using Laravel's Mail functionality to send an email verification link
            try {
                $response = Mail::to($email)->send(new NotificationMail($data));
            } catch (\Exception $e) {
                // Log the error for debugging purposes
                \Log::error('Error sending verification email: ' . $e->getMessage());
                return response()->json(['message'=> $e->getMessage()],422);
            }
        }

        return $response;

    }
}
