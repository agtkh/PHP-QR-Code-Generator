<?php

declare(strict_types=1);

namespace QrCode;

/**
 * ガロア体上の多項式演算を定義
 */
final class Polynomial
{
    public array $coeffs; // 多項式の係数（最高次から順）
    public int $degree;   // 多項式の次数
    private GaloisField $field;

    /**
     * コンストラクタ。係数配列から多項式を生成。
     */
    public function __construct(GaloisField $field, array $coeffs)
    {
        $this->field = $field;

        if (empty($coeffs)) {
            $coeffs = [0];
        }

        // 多項式の正規化。先頭の余分な0を削除。
        $firstNonzero = 0;
        while ($firstNonzero < count($coeffs) && $coeffs[$firstNonzero] === 0) {
            $firstNonzero++;
        }

        if ($firstNonzero === count($coeffs)) {
            $this->coeffs = [0]; // すべてゼロならゼロ多項式
        } else {
            $this->coeffs = array_slice($coeffs, $firstNonzero);
        }

        $this->degree = count($this->coeffs) - 1;
    }

    // ゼロ多項式か判定
    public function isZero(): bool
    {
        return $this->coeffs === [0];
    }

    // 加減算。GF(2^n)ではXOR操作。
    public function addOrSubtract(self $other): self
    {
        if ($this->isZero()) {
            return $other;
        }
        if ($other->isZero()) {
            return $this;
        }

        // 効率化: 次数の大きい方を計算ベースとする
        $smallCoeffs = $this->coeffs;
        $largeCoeffs = $other->coeffs;
        if (count($smallCoeffs) > count($largeCoeffs)) {
            [$smallCoeffs, $largeCoeffs] = [$largeCoeffs, $smallCoeffs];
        }

        $sum = $largeCoeffs;
        $diff = count($largeCoeffs) - count($smallCoeffs);

        for ($i = 0; $i < count($smallCoeffs); $i++) {
            // 同次数の項係数を加算(XOR)
            $sum[$diff + $i] = $this->field->add($sum[$diff + $i], $smallCoeffs[$i]);
        }

        return new self($this->field, $sum);
    }

    // 乗算
    public function multiply(self $other): self
    {
        if ($this->isZero() || $other->isZero()) {
            return new self($this->field, [0]);
        }

        $aCoeffs = $this->coeffs;
        $bCoeffs = $other->coeffs;
        $product = array_fill(0, count($aCoeffs) + count($bCoeffs) - 1, 0);

        for ($i = 0; $i < count($aCoeffs); $i++) {
            for ($j = 0; $j < count($bCoeffs); $j++) {
                $product[$i + $j] = $this->field->add(
                    $product[$i + $j],
                    $this->field->multiply($aCoeffs[$i], $bCoeffs[$j])
                );
            }
        }

        return new self($this->field, $product);
    }

    /**
     * 単項式による乗算
     * 係数を`coefficient`倍し、次数を`degree`上げる
     */
    public function multiplyByMonomial(int $degree, int $coefficient): self
    {
        if ($degree < 0) {
            throw new \InvalidArgumentException('Degree must be non-negative.');
        }
        if ($this->isZero() || $coefficient === 0) {
            return new self($this->field, [0]);
        }

        $size = count($this->coeffs);
        $product = array_fill(0, $size + $degree, 0);

        for ($i = 0; $i < $size; $i++) {
            $product[$i] = $this->field->multiply($this->coeffs[$i], $coefficient);
        }

        return new self($this->field, $product);
    }

    /**
     * 除算。商と剰余を返す。
     * 多項式の長除法アルゴリズム。
     */
    public function divide(self $other): array
    {
        if ($other->isZero()) {
            throw new \InvalidArgumentException('Division by zero.');
        }

        $quotient = new self($this->field, [0]);
        $remainder = $this;

        $otherDegree = $other->degree;
        $otherLeadCoeff = $other->coeffs[0];

        while (!$remainder->isZero() && $remainder->degree >= $otherDegree) {
            $degreeDiff = $remainder->degree - $otherDegree;
            $scale = $this->field->divide($remainder->coeffs[0], $otherLeadCoeff);

            $term = $other->multiplyByMonomial($degreeDiff, $scale);

            $monoCoeffs = array_fill(0, $degreeDiff + 1, 0);
            $monoCoeffs[0] = $scale;
            $monomial = new self($this->field, $monoCoeffs);

            $quotient = $quotient->addOrSubtract($monomial);
            $remainder = $remainder->addOrSubtract($term);
        }

        return [$quotient, $remainder];
    }
}
