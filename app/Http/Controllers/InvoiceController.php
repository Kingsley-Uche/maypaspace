<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    //
    public function create(Request $request){
        $request->validate([
            'user_id'=>'required|exists,users, id',
            'invoice_ref'=>'required|exists, book_spots,invoice_ref'
        ]);

    }
}
