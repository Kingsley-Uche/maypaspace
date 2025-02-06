<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;

use App\Models\Tenant;
use App\Models\Product;
use App\Models\ProductImage;

class ProductController extends Controller
{
    public function create(Request $request, $tenant_slug){
        $user = $request->user();

        if($user->user_type_id === 3){
            return response()->json(['message' => 'You are not authorized'], 401);
        }
        //validate request data
        $validator = Validator::make($request->all(), [
           'name' => 'required|string|max:255',
           'total_seats' => 'required|numeric|max:255',
           'category' => 'required|numeric|exists:categories,id|gte:1',
           'location' => 'required|numeric|exists:locations,id|gte:1',
           'floor' => 'required|numeric|exists:floors,id|gte:1',
           'product_type' => 'required|numeric|exists:product_types,id|gte:1',
           'images.*' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);
 
        if($validator->fails()){
         return response()->json(['error' => $validator->errors()], 422);
        }

        $tenant = Tenant::where('slug', $tenant_slug)->first();
 
        //retrieve Validated data from the validator instance
        $validatedData = $validator->validated();

        $imagesA = [];

        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $image) {
                $filename = $tenant->slug . '_' . time() . '.' . $image->getClientOriginalExtension();
                $image->storeAs('uploads', $filename, 'public');
                $imagesA[] = $filename;
            }
        }
        
        $product = Product::create([
         'name' => htmlspecialchars($validatedData['name'], ENT_QUOTES, 'UTF-8'),
         'total_seats' => htmlspecialchars($validatedData['total_seats'], ENT_QUOTES, 'UTF-8'),
         'category_id' => $validatedData['category'],
         'location_id' => $validatedData['location'],
         'floor_id' => $validatedData['floor'],
         'product_type_id' => $validatedData['product_type'],
         'images' => json_encode($imagesA),
         'tenant_id' => $tenant->id,
        ]);


        //return response if create fails
        if(!$product){
           return response()->json(['message' => 'Something went wrong, try again later'], 422);
        }
 
        //return response if create was successful
        return response()->json(['message'=> 'Product successfully created', 'product'=>$product], 201);
        
    }

    public function index($tenant_slug){
        $tenant = Tenant::where('slug', $tenant_slug)->first();
         //fetch all products
        $products = Product::where('tenant_id', $tenant->id)->get();
 
        return response()->json(['data'=>$products], 201);
    }

    public function update(Request $request, $tenant_slug, $id){
        $user = $request->user();

        if($user->user_type_id === 3){
            return response()->json(['message' => 'You are not authorized'], 401);
        }
         //validate request data
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'total_seats' => 'required|numeric|max:255',
            'category' => 'required|numeric|exists:categories,id|gte:1',
            'location' => 'required|numeric|exists:locations,id|gte:1',
            'floor' => 'required|numeric|exists:floors,id|gte:1',
            'product_type' => 'required|numeric|exists:product_types,id|gte:1',
        ]);

        //send response if validation fails
        if($validator->fails()){
            return response()->json(['errors'=>$validator->errors()], 422);
        }

        //using the provided id, find the product to be updated
        $product = Product::findOrFail($id);

        //retrieve validatedData from the validator instance
        $validatedData = $validator->validated();

        //sanitize and save validated request data
        $product->name = htmlspecialchars($validatedData['name'], ENT_QUOTES, 'UTF-8');
        $product->total_seats = htmlspecialchars($validatedData['total_seats'], ENT_QUOTES, 'UTF-8');
        $product->category_id = $validatedData['category'];
        $product->location_id = $validatedData['location'];
        $product->floor_id = $validatedData['floor'];
        $product->product_type_id = $validatedData['product_type'];

        $response = $product->save();

        //If update fails, send response
        if(!$response){
            return response()->json(['message'=>'Something went wrong, please try again later'], 422);
        }

        //If update is successful, send response
        return response()->json(['message'=> 'Product updated successfully', 'data'=>$product], 201);
    }

    public function destroy(Request $request){

        $user = $request->user();

        if($user->user_type_id === 3){
            return response()->json(['message' => 'You are not authorized'], 401);
        }
         //validate the ID
        $validator = Validator::make($request->all(), [
            'id' => 'required|numeric|exists:products,id'
        ]);

        if($validator->fails()){
            return response()->json(['errors'=> $validator->errors()], 422);
        }

        //find the product to be deleted using the Id
        $product = Product::findOrFail($request->id);

        //delete the product
        $response = $product->delete();

        //return response if delete fails
        if(!$response){
            return response()->json(['message'=> 'Failed to delete, try again'], 422);
        }
 
        //return response if delete is successful
        return response()->json(['message'=> 'Product deleted successfully'], 200);
    }
}
