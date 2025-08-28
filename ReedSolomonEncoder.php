<?php

declare(strict_types=1);

namespace QrCode;

require_once __DIR__ . '/GaloisField.php';
require_once __DIR__ . '/Polynomial.php';

/**
 * リード・ソロモン符号化器
 */
final class ReedSolomonEncoder
{
    private GaloisField $field;

    public function __construct()
    {
        // QRコードで使用されるGF(256)を定義
        $this->field = new GaloisField(
            256,
            0b100011101 // 生成多項式: x^8 + x^4 + x^3 + x^2 + 1
        );
    }

    /**
     * メッセージバイト列から誤り訂正コードワードを生成
     */
    public function encode(array $msgBytes, int $eccCount): array
    {
        if ($eccCount <= 0) {
            return [];
        }

        $generator = $this->getGeneratorPolynomial($eccCount);
        $infoPoly = new Polynomial($this->field, $msgBytes);

        // メッセージ多項式の次数を上げる (x^nを掛ける)
        $infoPoly = $infoPoly->multiplyByMonomial($eccCount, 1);

        // 生成多項式で除算し、剰余を求める
        [, $remainder] = $infoPoly->divide($generator);

        $coeffs = $remainder->coeffs;
        $paddingCount = $eccCount - count($coeffs);

        // 誤り訂正コードワードの長さを揃えるため、必要に応じて0でパディング
        return array_merge(array_fill(0, $paddingCount, 0), $coeffs);
    }

    /**
     * 生成多項式を取得
     */
    private function getGeneratorPolynomial(int $degree): Polynomial
    {
        $g = new Polynomial($this->field, [1]);
        for ($i = 0; $i < $degree; $i++) {
            $p = new Polynomial($this->field, [1, $this->field->exp($i)]);
            $g = $g->multiply($p);
        }
        return $g;
    }
}
