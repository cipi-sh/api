<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('cipi::welcome');
});

Route::get('/docs', function () {
    return view('cipi::docs');
});
