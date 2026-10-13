<?php

use App\Services\TicketQrCode;
use Illuminate\Support\Str;

test('makes an SVG QR code for a ticket code', function () {
    $svg = app(TicketQrCode::class)->svg((string) Str::ulid());

    expect($svg)->toStartWith('<svg')
        ->and($svg)->toContain('</svg>')
        ->and($svg)->not->toContain('<?xml');
});

test('gives the same image for the same code and a different one for another code', function () {
    $qr = app(TicketQrCode::class);

    expect($qr->svg('01HZX3K9F2M7N8P4Q5R6S7T8V1'))->toBe($qr->svg('01HZX3K9F2M7N8P4Q5R6S7T8V1'))
        ->and($qr->svg('01HZX3K9F2M7N8P4Q5R6S7T8V1'))->not->toBe($qr->svg('01HZX3K9F2M7N8P4Q5R6S7T8V2'));
});

test('does not make a QR code from text that is not a ticket code', function (string $text) {
    expect(fn () => app(TicketQrCode::class)->svg($text))->toThrow(InvalidArgumentException::class);
})->with([
    'empty' => '',
    'lowercase' => '01hzx3k9f2m7n8p4q5r6s7t8v1',
    'too short' => '01HZX3K9F2',
    'a url' => 'https://example.com/',
    'markup' => '<script>alert(1)</script>',
]);

test('reflects the size argument in the image', function () {
    $qr = app(TicketQrCode::class);

    expect($qr->svg('01HZX3K9F2M7N8P4Q5R6S7T8V1', 100))->toContain('width="100" height="100"')
        ->and($qr->svg('01HZX3K9F2M7N8P4Q5R6S7T8V1', 300))->toContain('width="300" height="300"');
});

test('contains nothing but drawing, no scripts, links or event handlers', function () {
    $svg = app(TicketQrCode::class)->svg('01HZX3K9F2M7N8P4Q5R6S7T8V1');

    expect($svg)->not->toContain('<script')
        ->and($svg)->not->toContain('href')
        ->and($svg)->not->toContain('<image')
        ->and($svg)->not->toContain('<foreignObject')
        ->and($svg)->not->toMatch('/\son\w+=/i');
});
