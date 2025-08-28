<?php

declare(strict_types=1);

require_once __DIR__ . '/QrCodeGenerator.php';

use QrCode\QrCodeGenerator;
use QrCode\ErrorCorrectionLevel;
use QrCode\EncMode;

/**
 * アプリケーションのメイン処理
 * GETパラメータに基づいてQRコードを動的に生成する
 */
function main(): void
{
    $image = null;
    $imageSize = 256;

    try {
        // --- パラメータの取得と検証 ---
        $imageSize = isset($_GET['size']) ? (int)$_GET['size'] : 256;
        $margin = isset($_GET['margin']) ? (int)$_GET['margin'] : 20;

        // データ入力 (base64 > bytes > text の優先順位)
        if (isset($_GET['base64'])) {
            $decoded = base64_decode($_GET['base64'], true);
            if ($decoded === false) {
                throw new \InvalidArgumentException('Invalid Base64 string provided in \'base64\' parameter.');
            }
            // unpackは1基準の連想配列を返すため、0基準の配列に変換
            $data = array_values(unpack('C*', $decoded));
        } elseif (isset($_GET['bytes'])) {
            $data = array_map('intval', explode(',', $_GET['bytes']));
            foreach ($data as $byte) {
                if ($byte < 0 || $byte > 255) throw new \InvalidArgumentException('Byte value in \'bytes\' parameter must be between 0 and 255.');
            }
        } elseif (isset($_GET['text'])) {
            $data = array_map('ord', str_split($_GET['text']));
        } else {
            // デフォルトのサンプルQRコードを生成
            $data = array_map('ord', str_split('https://github.com/agtkh/'));
        }

        // バージョン
        $version = isset($_GET['version']) ? (int)$_GET['version'] : 7;
        if ($version < 1 || $version > 40) throw new \InvalidArgumentException('Version must be between 1 and 40.');

        // 誤り訂正レベル
        $eclInput = strtoupper($_GET['ecl'] ?? 'Q');
        $errorCorrectionLevel = match ($eclInput) {
            'L' => ErrorCorrectionLevel::L,
            'M' => ErrorCorrectionLevel::M,
            'Q' => ErrorCorrectionLevel::Q,
            'H' => ErrorCorrectionLevel::H,
            default => throw new \InvalidArgumentException("Invalid Error Correction Level. Use L, M, Q, or H."),
        };

        // マスクパターン (デフォルトは自動選択)
        $maskPattern = null;
        if (isset($_GET['mask'])) {
            if ($_GET['mask'] === 'auto') {
                $maskPattern = null;
            } elseif (is_numeric($_GET['mask'])) {
                $maskInput = (int)$_GET['mask'];
                if ($maskInput >= 0 && $maskInput <= 7) {
                    $maskPattern = $maskInput;
                } else {
                    throw new \InvalidArgumentException('Mask pattern must be between 0 and 7.');
                }
            } else {
                 throw new \InvalidArgumentException('Invalid mask pattern value.');
            }
        }
        
        $encodingMode = EncMode::BYTE;

        // --- QRコード生成 ---
        $generator = new QrCodeGenerator(
            $version,
            $errorCorrectionLevel,
            $imageSize,
            $margin,
            $maskPattern
        );

        $image = $generator->render($data, $encodingMode);

    } catch (\Exception $e) {
        error_log($e->getMessage());
        $image = createErrorImage($e->getMessage(), $imageSize, $imageSize);
    }

    // --- 画像出力 ---
    header('Content-Type: image/png');
    imagepng($image);
    imagedestroy($image);
}

/**
 * エラーメッセージを描画した画像を生成する
 */
function createErrorImage(string $message, int $width, int $height): \GdImage|bool
{
    $image = imagecreatetruecolor($width, $height);
    $backgroundColor = imagecolorallocate($image, 255, 255, 255);
    $textColor = imagecolorallocate($image, 211, 47, 47);

    imagefill($image, 0, 0, $backgroundColor);

    $padding = 20;
    $font = 5;
    $lineHeight = imagefontheight($font) + 5;
    $charsPerLine = (int)floor(($width - 2 * $padding) / imagefontwidth($font));
    if ($charsPerLine <= 0) $charsPerLine = 1;
    $wrappedText = wordwrap($message, $charsPerLine, "\n", true);
    $lines = explode("\n", $wrappedText);

    $totalTextHeight = count($lines) * $lineHeight;
    $y = ($height - $totalTextHeight) / 2;

    foreach ($lines as $line) {
        $x = ($width - strlen($line) * imagefontwidth($font)) / 2;
        imagestring($image, $font, (int)$x, (int)$y, $line, $textColor);
        $y += $lineHeight;
    }

    return $image;
}

main();
