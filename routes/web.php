<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});


Route::get('/test-email', function () {

    $messageContent = [];
    $messageContent['email'] = 'hello@gmail.com';
    $messageContent['firstName'] = 'emeka';
    $messageContent['password'] = 'david';
    $messageContent['slug'] = 'emekadavid';
    
    return view('emails.registrationEmail', compact('messageContent'));
});