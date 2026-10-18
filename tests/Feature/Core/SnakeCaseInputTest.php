<?php

declare(strict_types=1);

use App\Core\Http\Middleware\SnakeCaseInput;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware('api')->post('/_test/echo', static fn (Request $request) => response()->json($request->all()));
});

it('converts JSON body keys to snake_case recursively', function () {
    $this->postJson('/_test/echo', [
        'eventCategoryId' => 3,
        'menuIds' => [1, 2],
        'buttonOne' => ['labelText' => 'Go', 'nested' => [['innerKey' => true]]],
        'already_snake' => 'x',
    ])->assertExactJson([
        'event_category_id' => 3,
        'menu_ids' => [1, 2],
        'button_one' => ['label_text' => 'Go', 'nested' => [['inner_key' => true]]],
        'already_snake' => 'x',
    ]);
});

it('converts every capital exactly like the Node rule', function () {
    expect(SnakeCaseInput::snake('userId'))->toBe('user_id')
        ->and(SnakeCaseInput::snake('OTP'))->toBe('_o_t_p')
        ->and(SnakeCaseInput::snake('btn2Label'))->toBe('btn2_label')
        ->and(SnakeCaseInput::snake('snake_already'))->toBe('snake_already');
});

it('leaves multipart (upload) bodies alone, as Node did', function () {
    $this->post('/_test/echo', ['folderName' => 'a'], ['Content-Type' => 'multipart/form-data'])
        ->assertJson(['folderName' => 'a']);
});
