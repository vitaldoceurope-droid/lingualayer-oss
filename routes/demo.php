<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| LinguaLayer — Demo routes (non-production only)
|--------------------------------------------------------------------------
| Registered by the service provider only when the app is NOT in production,
| so published packages never expose a test surface to end users.
*/

Route::get('/demo', function () {
    return view('lingua::demo');
})->name('lingua.demo');

Route::post('/demo', function (Request $request) {
    return redirect()->route('lingua.demo')->with('submitted', $request->except('_token'));
})->name('lingua.demo.post');
