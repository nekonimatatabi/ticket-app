<?php

use Illuminate\Support\Facades\Validator;

beforeEach(function () {
    app()->setLocale('ja');
});

test('names the fields of the event and ticket type forms in Japanese', function (string $field, string $name) {
    $message = Validator::make([$field => ''], [$field => 'required'])->errors()->first($field);

    expect($message)->toContain($name)
        ->and($message)->not->toContain($field);
})->with([
    'title' => ['title', 'タイトル'],
    'description' => ['description', '説明'],
    'venue' => ['venue', '会場'],
    'starts_at' => ['starts_at', '開始日時'],
    'ends_at' => ['ends_at', '終了日時'],
    'name' => ['name', '名前'],
    'price' => ['price', '価格'],
    'capacity' => ['capacity', '定員'],
    'sales_starts_at' => ['sales_starts_at', '販売開始'],
    'sales_ends_at' => ['sales_ends_at', '販売終了'],
]);

test('has no untranslated passkey or pagination wording left in English', function () {
    expect(__('Showing'))->toBe('表示')
        ->and(__('results'))->toBe('件')
        ->and(__('Unable to register passkey. Please try again.'))->not->toContain('Unable')
        ->and(__('OTP Code'))->toBe('ワンタイムコード');
});
