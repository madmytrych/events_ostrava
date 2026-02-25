<?php

use Illuminate\Support\Facades\Route;

Route::get('/{any?}', function () {
    abort(503);
})->where('any', '.*');
