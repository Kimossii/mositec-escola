<?php

use Illuminate\Support\Facades\Route;

// Route::get('/login', function () {
//     return response()->json([
//         'success' => false,
//         'message' => 'Token não fornecido ou inválido',
//         'error_code' => 'UNAUTHENTICATED',
//         'status' => 401
//     ], 401);
// })->name('login');
// Route::get('/', function () {
//     return view('welcome');
// });
// Route::get('/', function () {
//     return view('dashboard.index');
// });
Route::get('/', function () {
    return \Inertia\Inertia::render('Dashboard/Index');
});
