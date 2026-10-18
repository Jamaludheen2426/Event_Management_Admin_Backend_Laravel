<?php

declare(strict_types=1);

use App\Modules\Templates\Http\Controllers\TemplateFontController;
use Illuminate\Support\Facades\Route;

// The font file for @font-face: browsers send no session with font requests.
Route::get('template-fonts/{id}/file', [TemplateFontController::class, 'file']);
