<?php

namespace App\Services;

use App\Models\Ticket;
use BaconQrCode\Renderer\Color\Rgb;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\Fill;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use InvalidArgumentException;

/**
 * チケットのコードを QR コードの SVG にする。QR の中身は、コードだけ。
 *
 * 受付画面が、読み取ったコードからイベントと支払いを確認する。コードの形（ULID）でないものは QR にしない。
 */
class TicketQrCode
{
    /**
     * @throws InvalidArgumentException コードが ULID の形でないとき
     */
    public function svg(string $code, int $size = 192): string
    {
        if (preg_match(Ticket::CODE_PATTERN, $code) !== 1) {
            throw new InvalidArgumentException('チケットのコードの形が正しくありません。');
        }

        $svg = (new Writer(
            new ImageRenderer(
                new RendererStyle($size, 1, null, null, Fill::uniformColor(new Rgb(255, 255, 255), new Rgb(24, 24, 27))),
                new SvgImageBackEnd,
            ),
        ))->writeString($code);

        return trim(substr($svg, strpos($svg, "\n") + 1));
    }
}
