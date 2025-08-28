<?php

declare(strict_types=1);

namespace QrCode;

/**
 * ガロア体 GF(2^8) の演算を定義
 */
final class GaloisField
{
    private array $expTable; // 指数テーブル
    private array $logTable; // 対数テーブル
    private int $size;
    private int $mod;

    /**
     * コンストラクタ。GF(size)のテーブルを初期化。
     */
    public function __construct(int $size, int $primitivePolynomial)
    {
        $this->size = $size;
        $this->mod = $size - 1;

        $this->expTable = array_fill(0, $this->size, 0);
        $this->logTable = array_fill(0, $this->size, 0);
        $x = 1;

        for ($i = 0; $i < $this->mod; $i++) {
            $this->expTable[$i] = $x;
            $this->logTable[$x] = $i;
            $x *= 2;
            if ($x > $this->mod) {
                $x ^= $primitivePolynomial;
            }
        }
    }

    // 加算（XOR演算）
    public function add(int $a, int $b): int
    {
        return $a ^ $b;
    }

    // 乗算
    public function multiply(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }
        // log(a) + log(b) = log(a*b)
        $log = $this->log($a) + $this->log($b);
        return $this->exp($log % $this->mod);
    }

    // 対数
    public function log(int $a): int
    {
        if ($a === 0) {
            // log(0)は未定義のためエラー
            throw new \InvalidArgumentException('Log(0) is undefined.');
        }
        return $this->logTable[$a];
    }

    // 指数
    public function exp(int $a): int
    {
        return $this->expTable[$a];
    }

    // 除算
    public function divide(int $a, int $b): int
    {
        if ($b === 0) {
            // ゼロ除算は不可能
            throw new \InvalidArgumentException('Division by zero.');
        }
        if ($a === 0) {
            return 0;
        }
        // log(a) - log(b) = log(a/b)
        $log = $this->log($a) - $this->log($b) + $this->mod;
        return $this->exp($log % $this->mod);
    }
}
