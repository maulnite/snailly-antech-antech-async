<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/snailly');
});

require __DIR__ . '/snailly.php';