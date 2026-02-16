<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Mail\SendUserEmail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

use App\Models\{User, Tenant, SentEmail, EmailAttachment};

class EmailUserController extends Controller
{
    public function sendMail(Request $request, $tenant_slug){
        $user = $request->user();

        //check Tenant
        $tenant = Tenant::where('slug', $tenant_slug)
                    ->with('subscription.plan')
                    ->with('logo')
                    ->first();

         
        $userType = User::where('id', $user->id)
                        ->select('id', 'user_type_id')
                        ->first();

        if($userType->user_type_id != 1 && $userType->user_type_id != 2){
            return response()->json(['message' => 'You are not authorized'], 403);
        }
        
        $validator = Validator::make($request->all(), [
            'attachment' => 'nullable|array|max:5',
            'attachment.*' => 'file|max:2048',
            'user_id' => 'numeric|gte:1|exists:users,id',
            'content' => 'required|string|max:10000',
            'subject' => 'required|string|max:300',
        ]);

        if($validator->fails()){
            return response()->json(['error' => $validator->errors()], 422);
        }

        $validatedData = $validator->validated();

        $emailReceiver = User::select(['id', 'first_name','email'])
                            ->where('id', $validatedData['user_id'])
                            ->where('tenant_id', $tenant->id)
                            ->first();

        

        $emailContent = [
            'receiver_name'=> $emailReceiver->first_name,
            'body' => $validatedData['content'],
            'company' => $tenant->company_name,
            'subject' => $validatedData['subject'],
        ];

        DB::beginTransaction();

        try {

            // Create the sent_emails record
            $sentEmail = SentEmail::create([
                'tenant_id' => $tenant->id,
                'user_id'   => $validatedData['user_id'],
                'content'   => $validatedData['content'],
                'subject' => $validatedData['subject'],
            ]);

            $emailContent['sentEmailId'] = $sentEmail->id;

            $savedAttachments = [];

            // Save attachments (if any)
            if ($request->hasFile('attachment')) {
                foreach ($request->file('attachment') as $file) {

                    // Generate a unique file name with timestamp + original name
                    $filename = time() . '_' . $file->getClientOriginalName();
                    // Store the file in "public/email_attachments/{sentEmailId}" folder
                    $path = $file->storeAs("email_attachments/{$sentEmail->id}", $filename, 'public');

                    // Save original name + stored path in database
                    $savedAttachments[] = EmailAttachment::create([
                        'sent_email_id' => $sentEmail->id,
                        'name'          => $filename, // for display  
                        'path' => $path
                    ]);
                }
            }


            // Send email via Mailable
            Mail::to($emailReceiver->email)->send(
                new SendUserEmail($emailContent, $savedAttachments)
            );

            // If mail sent successfully → commit
            DB::commit();

            return response()->json([
                'message' => 'Email sent successfully'
            ]);

        } catch (\Throwable $e) {

            //Rollback if email sending fails
            DB::rollBack();

            return response()->json([
                'error'   => 'Failed to send email',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    public function listSentEmails(Request $request, $tenant_slug){
        $user = $request->user();

        //check Tenant
        $tenant = Tenant::where('slug', $tenant_slug)
                    ->with('subscription.plan')
                    ->with('logo')
                    ->first();

         
        $userType = User::where('id', $user->id)
                        ->select('id', 'user_type_id')
                        ->first();

        if($userType->user_type_id != 1 && $userType->user_type_id != 2){
            return response()->json(['message' => 'You are not authorized'], 403);
        }

        $emails = SentEmail::select(['id','content', 'subject', 'user_id', 'created_at'])
                    ->with(['user:id,first_name,last_name,email', 'attachments:id,sent_email_id,path'])
                    ->where('tenant_id', $tenant->id)
                    ->latest()
                    ->paginate(15);

        return response()->json($emails);
    }

    public function viewSentEmail(Request $request, $tenant_slug, $email_id)
    {
        $user = $request->user();

        // Check Tenant
        $tenant = Tenant::where('slug', $tenant_slug)
            ->with(['subscription.plan', 'logo'])
            ->first();

        if (!$tenant) {
            return response()->json(['message' => 'Tenant not found'], 404);
        }

        // Check user type
        $userType = User::where('id', $user->id)
            ->select('id', 'user_type_id')
            ->first();

        if (!in_array($userType->user_type_id, [1, 2])) {
            return response()->json(['message' => 'You are not authorized'], 403);
        }

        // Fetch the email
        $email = SentEmail::select(['id', 'tenant_id', 'content', 'subject', 'user_id', 'created_at'])
            ->with([
                'user:id,first_name,last_name,email',
                'attachments:id,sent_email_id,path,name'
            ])
            ->where('id', $email_id)
            ->where('tenant_id', $tenant->id)
            ->first();

        if (!$email) {
            return response()->json(['message' => 'Email not found'], 404);
        }

        return response()->json([
            'message' => 'Email retrieved successfully',
            'data' => $email
        ]);
    }

}
