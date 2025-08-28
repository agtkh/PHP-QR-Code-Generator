<?php

declare(strict_types=1);

namespace QrCode;

require_once __DIR__ . '/ReedSolomonEncoder.php';
require_once __DIR__ . '/BitStream.php';

enum ErrorCorrectionLevel: int
{
    case L = 1;
    case M = 0;
    case Q = 3;
    case H = 2;
}
enum EncMode: int
{
    case NUMERIC = 1;
    case ALPHANUMERIC = 2;
    case BYTE = 4;
    case KANJI = 8;
}

final class VersionInfo
{
    public function __construct(
        public readonly int $version,
        public readonly ErrorCorrectionLevel $errorCorrectionLevel,
        public readonly int $moduleCount,
        public readonly int $totalCodewordCount,
        public readonly int $alignPatternCount,
        public readonly int $charCountBits,
        public readonly int $eccPerBlock,
        public readonly int $ecBlockCount,
        public readonly int $dataCodewordCount
    ) {}
}

class QrCodeGenerator
{
    private VersionInfo $versionInfo;
    private ?array $matrix = null;

    public function __construct(
        private int $version,
        private ErrorCorrectionLevel $errorCorrectionLevel,
        private int $imageSize,
        private int $margin,
        private ?int $maskPattern = null
    ) {
        $this->versionInfo = self::getVersionInfo($this->version, $this->errorCorrectionLevel);
    }

    public function render(array $data, EncMode $encodingMode): \GdImage|bool
    {
        $dataCodewords = $this->buildDataCodewords($data, $encodingMode);
        $finalBitStream = $this->interleaveBlocks($dataCodewords);

        $this->maskPattern ??= $this->findBestMaskPattern($finalBitStream);

        $this->generateMatrix($finalBitStream, $this->maskPattern);
        return $this->createImage();
    }

    private function findBestMaskPattern(BitStream $bitStream): int
    {
        $minPenalty = PHP_INT_MAX;
        $bestMask = 0;

        for ($mask = 0; $mask < 8; $mask++) {
            $this->generateMatrix(clone $bitStream, $mask);
            $penalty = $this->calculatePenaltyScore();
            if ($penalty < $minPenalty) {
                $minPenalty = $penalty;
                $bestMask = $mask;
            }
        }
        return $bestMask;
    }

    private function generateMatrix(BitStream $bitStream, int $maskPattern): void
    {
        $this->initializeMatrix();
        $this->drawFixedPatterns();
        $this->drawFormatInfo($maskPattern);
        if ($this->versionInfo->version >= 7) {
            $this->drawVersionInfo();
        }
        $this->drawData($bitStream, $maskPattern);
    }

    private function createImage(): \GdImage|bool
    {
        if ($this->matrix === null) throw new \LogicException('Matrix not generated.');
        $matrixSize = count($this->matrix);
        $drawWidth = $this->imageSize - 2 * $this->margin;
        $drawHeight = $this->imageSize - 2 * $this->margin;
        if ($drawWidth <= 0 || $drawHeight <= 0) throw new \InvalidArgumentException('Margin is too large');

        $moduleWidth = $drawWidth / $matrixSize;
        $moduleHeight = $drawHeight / $matrixSize;

        $image = imagecreatetruecolor($this->imageSize, $this->imageSize);
        $colors = [imagecolorallocate($image, 255, 255, 255), imagecolorallocate($image, 0, 0, 0)];
        imagefill($image, 0, 0, $colors[0]);

        for ($y = 0; $y < $matrixSize; $y++) {
            for ($x = 0; $x < $matrixSize; $x++) {
                if ($this->matrix[$y][$x] !== -1) {
                    $left = (int)round($this->margin + $x * $moduleWidth);
                    $top = (int)round($this->margin + $y * $moduleHeight);
                    $right = (int)round($this->margin + ($x + 1) * $moduleWidth - 1);
                    $bottom = (int)round($this->margin + ($y + 1) * $moduleHeight - 1);
                    if ($right >= $left && $bottom >= $top) {
                        imagefilledrectangle($image, $left, $top, $right, $bottom, $colors[$this->matrix[$y][$x]]);
                    }
                }
            }
        }
        return $image;
    }

    private function calculatePenaltyScore(): int
    {
        return $this->calculatePenaltyRule1()
            + $this->calculatePenaltyRule2()
            + $this->calculatePenaltyRule3()
            + $this->calculatePenaltyRule4();
    }

    private function calculatePenaltyRule1(): int
    {
        $penalty = 0;
        $size = $this->versionInfo->moduleCount;
        for ($i = 0; $i < $size; $i++) {
            $rowRunCount = 0;
            $colRunCount = 0;
            $lastRowModule = -1;
            $lastColModule = -1;
            for ($j = 0; $j < $size; $j++) {
                if ($this->matrix[$i][$j] === $lastRowModule) {
                    $rowRunCount++;
                } else {
                    if ($rowRunCount >= 5) $penalty += 3 + ($rowRunCount - 5);
                    $lastRowModule = $this->matrix[$i][$j];
                    $rowRunCount = 1;
                }
                if ($this->matrix[$j][$i] === $lastColModule) {
                    $colRunCount++;
                } else {
                    if ($colRunCount >= 5) $penalty += 3 + ($colRunCount - 5);
                    $lastColModule = $this->matrix[$j][$i];
                    $colRunCount = 1;
                }
            }
            if ($rowRunCount >= 5) $penalty += 3 + ($rowRunCount - 5);
            if ($colRunCount >= 5) $penalty += 3 + ($colRunCount - 5);
        }
        return $penalty;
    }

    private function calculatePenaltyRule2(): int
    {
        $penalty = 0;
        $size = $this->versionInfo->moduleCount;
        for ($y = 0; $y < $size - 1; $y++) {
            for ($x = 0; $x < $size - 1; $x++) {
                $color = $this->matrix[$y][$x];
                if ($color === $this->matrix[$y][$x + 1] && $color === $this->matrix[$y + 1][$x] && $color === $this->matrix[$y + 1][$x + 1]) {
                    $penalty += 3;
                }
            }
        }
        return $penalty;
    }

    private function calculatePenaltyRule3(): int
    {
        $penalty = 0;
        $size = $this->versionInfo->moduleCount;
        $patterns = [[1, 0, 1, 1, 1, 0, 1, 0, 0, 0, 0], [0, 0, 0, 0, 1, 0, 1, 1, 1, 0, 1]];
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size - 10; $x++) {
                // Horizontal check
                $h_match = true;
                for ($k = 0; $k < 11; $k++) if ($this->matrix[$y][$x + $k] !== $patterns[0][$k]) $h_match = false;
                if ($h_match) $penalty += 40;
                $h_match = true;
                for ($k = 0; $k < 11; $k++) if ($this->matrix[$y][$x + $k] !== $patterns[1][$k]) $h_match = false;
                if ($h_match) $penalty += 40;

                // Vertical check
                if ($y < $size - 10) {
                    $v_match = true;
                    for ($k = 0; $k < 11; $k++) if ($this->matrix[$y + $k][$x] !== $patterns[0][$k]) $v_match = false;
                    if ($v_match) $penalty += 40;
                    $v_match = true;
                    for ($k = 0; $k < 11; $k++) if ($this->matrix[$y + $k][$x] !== $patterns[1][$k]) $v_match = false;
                    if ($v_match) $penalty += 40;
                }
            }
        }
        return $penalty;
    }

    private function calculatePenaltyRule4(): int
    {
        $size = $this->versionInfo->moduleCount;
        $totalModules = $size * $size;
        $darkModules = 0;
        foreach ($this->matrix as $row) {
            $darkModules += array_sum($row);
        }
        $darkRatio = ($darkModules / $totalModules) * 100;
        $penalty = floor(abs($darkRatio - 50) / 5) * 10;
        return (int)$penalty;
    }

    private function initializeMatrix(): void
    {
        $moduleCount = $this->versionInfo->moduleCount;
        $this->matrix = array_fill(0, $moduleCount, array_fill(0, $moduleCount, -1));
    }

    private function buildDataCodewords(array $data, EncMode $encodingMode): array
    {
        $capacityBits = $this->versionInfo->dataCodewordCount * 8;
        $charCountBits = $this->versionInfo->charCountBits;

        $bitStream = new BitStream();
        $bitStream->append($encodingMode->value, 4);
        $bitStream->append(count($data), $charCountBits);

        foreach ($data as $byte) {
            $bitStream->append($byte, 8);
        }

        if ($bitStream->getLength() <= $capacityBits - 4) {
            $bitStream->append(0, 4);
        }
        while ($bitStream->getLength() % 8 !== 0) {
            $bitStream->append(0, 1);
        }

        $paddingBytes = [0xEC, 0x11];
        $paddingIndex = 0;
        while ($bitStream->getLength() < $capacityBits) {
            $bitStream->append($paddingBytes[$paddingIndex % 2], 8);
            $paddingIndex++;
        }

        return $bitStream->getBytes();
    }

    private function interleaveBlocks(array $dataCodewords): BitStream
    {
        $eccCount = $this->versionInfo->eccPerBlock;
        $blockCount = $this->versionInfo->ecBlockCount;
        $totalCount = $this->versionInfo->totalCodewordCount;

        $longBlockCount = $totalCount % $blockCount;
        $shortBlockCount = $blockCount - $longBlockCount;
        $shortBlockLen = (int)floor($totalCount / $blockCount);
        $longBlockLen = $shortBlockLen + 1;

        $shortBlockDataLen = $shortBlockLen - $eccCount;
        $longBlockDataLen = $longBlockLen - $eccCount;

        $dataBlocks = [];
        $eccBlocks = [];
        $dataOffset = 0;
        $encoder = new ReedSolomonEncoder();
        for ($i = 0; $i < $blockCount; $i++) {
            $dataLen = $i < $shortBlockCount ? $shortBlockDataLen : $longBlockDataLen;
            $dataBlock = array_slice($dataCodewords, $dataOffset, $dataLen);
            $dataOffset += $dataLen;
            $eccBlock = $encoder->encode($dataBlock, $eccCount);
            $dataBlocks[] = $dataBlock;
            $eccBlocks[] = $eccBlock;
        }

        $finalBitStream = new BitStream();
        for ($i = 0; $i < $longBlockDataLen; $i++) {
            foreach ($dataBlocks as $j => $dataBlock) {
                if ($j < $shortBlockCount && $i === $shortBlockDataLen) continue;
                $finalBitStream->append($dataBlock[$i], 8);
            }
        }
        for ($i = 0; $i < $eccCount; $i++) {
            foreach ($eccBlocks as $eccBlock) {
                $finalBitStream->append($eccBlock[$i], 8);
            }
        }

        if ($finalBitStream->getLength() !== $totalCount * 8) {
            throw new \RuntimeException("Final bit stream length mismatch");
        }
        return $finalBitStream;
    }

    private function drawData(BitStream $bitStream, int $maskPattern): void
    {
        $path = self::generateZigzagPath(count($this->matrix));
        foreach ($path as [$y, $x]) {
            if ($this->matrix[$y][$x] !== -1) continue;
            $bit = $bitStream->popBit() ?? 0;
            if (self::applyMask($maskPattern, $x, $y)) $bit ^= 1;
            $this->matrix[$y][$x] = $bit;
        }
    }

    private function drawFixedPatterns(): void
    {
        $moduleCount = $this->versionInfo->moduleCount;
        $alignCount = $this->versionInfo->alignPatternCount;

        $this->drawFinderPattern(-1, -1);
        $this->drawFinderPattern($moduleCount - 8, -1);
        $this->drawFinderPattern(-1, $moduleCount - 8);

        if ($alignCount > 1) {
            $spacing = intval(($moduleCount - 13) / ($alignCount - 1));
            for ($i = 0; $i < $alignCount; $i++) {
                for ($j = 0; $j < $alignCount; $j++) {
                    $x = $moduleCount - 9 - $j * $spacing;
                    $y = $moduleCount - 9 - $i * $spacing;
                    $this->drawAlignmentPattern($x, $y);
                }
            }
        }

        $this->drawTimingPattern(8, 6, $moduleCount - 14, false);
        $this->drawTimingPattern(6, 8, $moduleCount - 14, true);
        $this->drawTimingPattern(8, $moduleCount - 8, 1, false);
    }

    private function drawFormatInfo(int $maskPattern): void
    {
        $formatBits = self::generateFormatBits($this->errorCorrectionLevel, $maskPattern);
        $size = count($this->matrix);
        $positions = [
            [[0, 8], [1, 8], [2, 8], [3, 8], [4, 8], [5, 8], [7, 8], [8, 8], [8, 7], [8, 5], [8, 4], [8, 3], [8, 2], [8, 1], [8, 0]],
            [[8, $size - 1], [8, $size - 2], [8, $size - 3], [8, $size - 4], [8, $size - 5], [8, $size - 6], [8, $size - 7], [8, $size - 8], [$size - 7, 8], [$size - 6, 8], [$size - 5, 8], [$size - 4, 8], [$size - 3, 8], [$size - 2, 8], [$size - 1, 8]]
        ];
        foreach ($positions as $pos) {
            foreach ($pos as $index => [$y, $x]) {
                $this->matrix[$y][$x] = (($formatBits >> $index) & 1);
            }
        }
    }

    private function drawVersionInfo(): void
    {
        $versionBits = self::generateVersionInfoBits($this->versionInfo->version);
        $size = $this->versionInfo->moduleCount;

        for ($i = 0; $i < 18; $i++) {
            $bit = ($versionBits >> $i) & 1;
            $x = $size - 11 + ($i % 3);
            $y = (int)floor($i / 3);
            $this->matrix[$y][$x] = $bit;
            $this->matrix[$x][$y] = $bit;
        }
    }

    private function drawFinderPattern(int $baseX, int $baseY): void
    {
        $size = count($this->matrix);
        $pattern = [
            [0, 0, 0, 0, 0, 0, 0, 0, 0],
            [0, 1, 1, 1, 1, 1, 1, 1, 0],
            [0, 1, 0, 0, 0, 0, 0, 1, 0],
            [0, 1, 0, 1, 1, 1, 0, 1, 0],
            [0, 1, 0, 1, 1, 1, 0, 1, 0],
            [0, 1, 0, 1, 1, 1, 0, 1, 0],
            [0, 1, 0, 0, 0, 0, 0, 1, 0],
            [0, 1, 1, 1, 1, 1, 1, 1, 0],
            [0, 0, 0, 0, 0, 0, 0, 0, 0]
        ];
        for ($y = $baseY; $y < $baseY + 9; $y++) {
            for ($x = $baseX; $x < $baseX + 9; $x++) {
                if (0 <= $y && $y < $size && 0 <= $x && $x < $size) {
                    $this->matrix[$y][$x] = $pattern[$y - $baseY][$x - $baseX];
                }
            }
        }
    }

    private function drawAlignmentPattern(int $baseX, int $baseY): void
    {
        $size = count($this->matrix);
        $pattern = [[1, 1, 1, 1, 1], [1, 0, 0, 0, 1], [1, 0, 1, 0, 1], [1, 0, 0, 0, 1], [1, 1, 1, 1, 1]];
        for ($y = $baseY; $y < $baseY + 5; $y++) {
            for ($x = $baseX; $x < $baseX + 5; $x++) {
                if (0 <= $y && $y < $size && 0 <= $x && $x < $size && $this->matrix[$y][$x] !== -1) return;
            }
        }
        for ($y = $baseY; $y < $baseY + 5; $y++) {
            for ($x = $baseX; $x < $baseX + 5; $x++) {
                if (0 <= $y && $y < $size && 0 <= $x && $x < $size) {
                    $this->matrix[$y][$x] = $pattern[$y - $baseY][$x - $baseX];
                }
            }
        }
    }

    private function drawTimingPattern(int $x, int $y, int $length, bool $isVertical): void
    {
        for ($i = 0; $i < $length; $i++) {
            $targetX = $isVertical ? $x : $x + $i;
            $targetY = $isVertical ? $y + $i : $y;
            if (0 <= $targetY && $targetY < count($this->matrix) && 0 <= $targetX && $targetX < count($this->matrix[0])) {
                $this->matrix[$targetY][$targetX] = ($i % 2 === 0) ? 1 : 0;
            }
        }
    }

    private static function applyMask(int $maskPattern, int $x, int $y): bool
    {
        return match ($maskPattern) {
            0 => ($x + $y) % 2 === 0,
            1 => $y % 2 === 0,
            2 => $x % 3 === 0,
            3 => ($x + $y) % 3 === 0,
            4 => (int)($y / 2 + $x / 3) % 2 === 0,
            5 => (($x * $y) % 2 + ($x * $y) % 3) === 0,
            6 => ((($x * $y) % 2 + ($x * $y) % 3) % 2) === 0,
            7 => ((($x + $y) % 2 + ($x * $y) % 3) % 2) === 0,
            default => throw new \InvalidArgumentException("Invalid mask pattern: {$maskPattern}"),
        };
    }

    private static function generateZigzagPath(int $size): array
    {
        $path = [];
        $isGoingUp = true;
        $column = $size - 1;
        while ($column > 0) {
            if ($column === 6) $column--;
            $rows = $isGoingUp ? range($size - 1, 0) : range(0, $size - 1);
            foreach ($rows as $row) {
                $path[] = [$row, $column];
                $path[] = [$row, $column - 1];
            }
            $isGoingUp = !$isGoingUp;
            $column -= 2;
        }
        return $path;
    }

    private static function generateFormatBits(ErrorCorrectionLevel $errorCorrectionLevel, int $maskPattern): int
    {
        $formatInfo = ($errorCorrectionLevel->value << 3) | $maskPattern;
        $data = $formatInfo << 10;
        $generator = 0b10100110111;
        for ($i = 14; $i >= 10; $i--) {
            if (($data >> $i) & 1) $data ^= $generator << ($i - 10);
        }
        return (($formatInfo << 10) | ($data & 0x3FF)) ^ 0x5412;
    }

    private static function generateVersionInfoBits(int $version): int
    {
        $data = $version << 12;
        $generator = 0b1111100100101;
        for ($i = 17; $i >= 12; $i--) {
            if (($data >> $i) & 1) {
                $data ^= $generator << ($i - 12);
            }
        }
        return ($version << 12) | $data;
    }

    private static function getVersionInfo(int $version, ErrorCorrectionLevel $errorCorrectionLevel): VersionInfo
    {
        $eccCodewordsPerBlockTable = [
            [-1, 7, 10, 15, 20, 26, 18, 20, 24, 30, 18, 20, 24, 26, 30, 22, 24, 28, 30, 28, 28, 28, 28, 30, 30, 26, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
            [-1, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26, 26, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28],
            [-1, 13, 22, 18, 26, 18, 24, 18, 22, 20, 24, 28, 26, 24, 20, 30, 24, 28, 28, 26, 30, 28, 30, 30, 30, 30, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
            [-1, 17, 28, 22, 16, 22, 28, 26, 26, 24, 28, 24, 28, 22, 24, 24, 30, 28, 28, 26, 28, 30, 24, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
        ];
        $errorCorrectionBlockCountTable = [
            [-1, 1, 1, 1, 1, 1, 2, 2, 2, 2, 4, 4, 4, 4, 4, 6, 6, 6, 6, 7, 8, 8, 9, 9, 10, 12, 12, 12, 13, 14, 15, 16, 17, 18, 19, 19, 20, 21, 22, 24, 25],
            [-1, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16, 17, 17, 18, 20, 21, 23, 25, 26, 28, 29, 31, 33, 35, 37, 38, 40, 43, 45, 47, 49],
            [-1, 1, 1, 2, 2, 4, 4, 6, 6, 8, 8, 8, 10, 12, 16, 12, 17, 16, 18, 21, 20, 23, 23, 25, 27, 29, 34, 34, 35, 38, 40, 43, 45, 48, 51, 53, 56, 59, 62, 65, 68],
            [-1, 1, 1, 2, 4, 4, 4, 5, 6, 8, 8, 11, 11, 16, 16, 18, 16, 19, 21, 25, 25, 25, 34, 30, 32, 35, 37, 40, 42, 45, 48, 51, 54, 57, 60, 63, 66, 70, 74, 77, 81],
        ];

        $moduleCount = 21 + ($version - 1) * 4;
        $totalCodewords = (int)floor(self::calculateRawDataModuleCount($version) / 8);
        $alignPatternCount = match (true) {
            $version == 1 => 0,
            $version <= 6 => 1,
            default => intdiv($version, 7) + 2
        };
        $charCountBits = match (true) {
            $version <= 9 => 8,
            $version <= 26 => 16,
            default => 16
        };

        $eclIndex = match ($errorCorrectionLevel) {
            ErrorCorrectionLevel::L => 0,
            ErrorCorrectionLevel::M => 1,
            ErrorCorrectionLevel::Q => 2,
            ErrorCorrectionLevel::H => 3
        };
        $eccPerBlock = $eccCodewordsPerBlockTable[$eclIndex][$version];
        $ecBlockCount = $errorCorrectionBlockCountTable[$eclIndex][$version];
        $dataCodewords = $totalCodewords - ($eccPerBlock * $ecBlockCount);

        return new VersionInfo(
            $version,
            $errorCorrectionLevel,
            $moduleCount,
            $totalCodewords,
            $alignPatternCount,
            $charCountBits,
            $eccPerBlock,
            $ecBlockCount,
            $dataCodewords
        );
    }

    private static function calculateRawDataModuleCount(int $version): int
    {
        if ($version < 1 || $version > 40) throw new \InvalidArgumentException("Version must be between 1 and 40");
        $result = (16 * $version + 128) * $version + 64;
        if ($version >= 2) {
            $numAlign = intdiv($version, 7) + 2;
            $result -= (25 * $numAlign - 10) * $numAlign - 55;
            if ($version >= 7) $result -= 36;
        }
        return $result;
    }
}
