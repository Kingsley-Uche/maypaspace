<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;

use App\Models\Category;
use App\Models\Tenant;

class CategoryController extends Controller
{
    public function create(Request $request, $tenant_slug){
        $user = $request->user();

        if($user->user_type_id == 3){
            return response()->json(['message' => 'You are not authorized'], 401);
        }
        //validate request data
        $validator = Validator::make($request->all(), [
           'category' => 'required|string|max:255',
           'location_id' => 'required|numeric|gte:1',
           'space_id' => 'required|numeric|gte:1',
        ]);
 
        if($validator->fails()){
         return response()->json(['error' => $validator->errors()], 422);
        }

        $tenant = Tenant::where('slug', $tenant_slug)->first();
 
        //retrieve Validated data from the validator instance
        $validatedData = $validator->validated();
        
        $category = Category::create([
            'category' => htmlspecialchars($validatedData['category'], ENT_QUOTES, 'UTF-8'),
            'location_id' => htmlspecialchars($validatedData['location_id'], ENT_QUOTES, 'UTF-8'),
            'space_id' => htmlspecialchars($validatedData['space_id'], ENT_QUOTES, 'UTF-8'),
            'tenant_id' => $tenant->id,
        ]);
 
        //return response if create fails
        if(!$category){
           return response()->json(['message' => 'Something went wrong, try again later'], 422);
        }
 
        //return response if create was successful
        return response()->json(['message'=> 'Category successfully created', 'category'=>$category], 201);
        
    }
 
     public function index($tenant_slug){
        $tenant = Tenant::where('slug', $tenant_slug)->first();
         //fetch all categories
        //  $categories = Category::where('tenant_id', $tenant->id)->orWhereNull('tenant_id')->get();

        $categories = Category::where(function ($query) use ($tenant) {
            $query->where('tenant_id', $tenant->id)
                  ->orWhereNull('tenant_id');
        })->get();
 
         return response()->json(['data'=>$categories], 201);
     }
 
    public function update(Request $request, $tenant_slug, $id){
        $tenant = Tenant::where('slug', $tenant_slug)->first();

        $user = $request->user();

        if($user->user_type_id == 3){
            return response()->json(['message' => 'You are not authorized'], 401);
        }
         //validate request data
         $validator = Validator::make($request->all(), [
            'category' => 'required|string|max:255',
            'location_id' => 'required|numeric|gte:1',
            'space_id' => 'required|numeric|gte:1',
          ]);
 
         //send response if validation fails
         if($validator->fails()){
             return response()->json(['errors'=>$validator->errors()], 422);
         }
 
         //using the provided id, find the category to be updated
         $category = Category::findOrFail($id);

        if($category->tenant_id !== $tenant->id){
            return response()->json(['message' => 'You are not authorized'], 403);
        } 
 
         //retrieve validatedData from the validator instance
         $validatedData = $validator->validated();
 
         //sanitize and save validated request data
         $category->category = htmlspecialchars($validatedData['category'], ENT_QUOTES, 'UTF-8');
         $category->location_id = htmlspecialchars($validatedData['location_id'], ENT_QUOTES, 'UTF-8');
         $category->space_id = htmlspecialchars($validatedData['space_id'], ENT_QUOTES, 'UTF-8');
 
         $response = $category->save();
 
         //If update fails, send response
         if(!$response){
             return response()->json(['message'=>'Something went wrong, please try again later'], 422);
         }
 
         //If update is successful, send response
         return response()->json(['message'=> 'Category updated successfully', 'data'=>$category], 201);
    }
 
    public function destroy(Request $request, $tenant_slug){

        $tenant = Tenant::where('slug', $tenant_slug)->first();

        $user = $request->user();

        if($user->user_type_id !== 3){
            return response()->json(['message' => 'You are not authorized'], 403);
        }
         //validate the ID
        $validator = Validator::make($request->all(), [
            'id' => 'required|numeric|exists:categories,id'
        ]);

        if($validator->fails()){
            return response()->json(['errors'=> $validator->errors()], 422);
        }

        //find the category to be deleted using the Id
        $category = Category::findOrFail($request->id);

        if($category->tenant_id !== $tenant->id){
            return response()->json(['message' => 'You are not authorized'], 403);
        }

        //delete the category
        $response = $category->delete();

        //return response if delete fails
        if(!$response){
            return response()->json(['message'=> 'Failed to delete, try again'], 422);
        }
 
        //return response if delete is successful
        return response()->json(['message'=> 'Category deleted successfully'], 200);
    }
}
