<?php

declare(strict_types=1);

namespace QrCode;

/**
 * ビット列を効率的に管理するクラス
 */
final class BitStream
{
    private array $bytes = [];
    private int $bitLen = 0;
    private int $readPos = 0;

    /**
     * 指定した値を追加
     */
    public function append(int $value, int $bitCount): void
    {
        if ($bitCount <= 0) {
            return;
        }
        if ($value >= (1 << $bitCount)) {
            throw new \InvalidArgumentException("Value {$value} is too large for {$bitCount} bits.");
        }

        // 1ビットずつバイト配列の該当位置へ書き込み
        for ($i = $bitCount - 1; $i >= 0; $i--) {
            $bit = ($value >> $i) & 1;
            $byteIndex = intdiv($this->bitLen, 8);
            $bitIndexInByte = $this->bitLen % 8;

            // 新しいバイトが必要な場合、0で初期化
            if ($bitIndexInByte === 0) {
                $this->bytes[$byteIndex] = 0;
            }
            // 該当ビットを立てる
            if ($bit) {
                $this->bytes[$byteIndex] |= (1 << (7 - $bitIndexInByte));
            }
            $this->bitLen++;
        }
    }

    /**
     * バイト配列の追加
     */
    public function appendBytes(array $newBytes): void
    {
        // 最適化: ビット長が8の倍数なら配列を直接結合
        if ($this->bitLen % 8 === 0) {
            $this->bytes = array_merge($this->bytes, $newBytes);
            $this->bitLen += count($newBytes) * 8;
        } else {
            // 8の倍数でない場合、1バイトずつ追加
            foreach ($newBytes as $byte) {
                $this->append($byte, 8);
            }
        }
    }

    /**
     * 先頭から1ビットを取り出し
     */
    public function popBit(): ?int
    {
        if ($this->readPos >= $this->bitLen) {
            return null;
        }
        $byteIndex = intdiv($this->readPos, 8);
        $bitIndexInByte = $this->readPos % 8;
        $byte = $this->bytes[$byteIndex];

        $bit = ($byte >> (7 - $bitIndexInByte)) & 1;
        $this->readPos++;

        return $bit;
    }

    /**
     * 現在の総ビット長を取得
     */
    public function getLength(): int
    {
        return $this->bitLen;
    }

    /**
     * 状態をリセット
     */
    public function clear(): void
    {
        $this->bytes = [];
        $this->bitLen = 0;
        $this->readPos = 0;
    }

    /**
     * ビット列をバイト配列として取得
     */
    public function getBytes(): array
    {
        if ($this->bitLen % 8 !== 0) {
            throw new \RuntimeException("Bit length must be a multiple of 8 to convert to bytes.");
        }
        return $this->bytes;
    }
}
