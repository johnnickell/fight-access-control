<?php

declare(strict_types=1);

/**
 * Draws the boxes, labels and arrows used by the repository's PNG diagrams
 */
final class DiagramCanvas
{
    private readonly GdImage $image;
    private readonly string $font;
    private readonly int $ink;

    /**
     * Constructs DiagramCanvas
     */
    public function __construct(int $width, int $height)
    {
        $font = $_SERVER['argv'][1] ?? '';
        if (!extension_loaded('gd') || !(gd_info()['FreeType Support'] ?? false)
            || !(gd_info()['PNG Support'] ?? false)
        ) {
            fwrite(STDERR, "Diagram rendering requires PHP GD with FreeType and PNG support.\n");
            exit(1);
        }
        $font = realpath($font);
        if ($_SERVER['argc'] !== 2 || $font === false || !is_file($font) || !is_readable($font)) {
            fwrite(STDERR, "Usage: php {$_SERVER['argv'][0]} /path/to/font.ttf\n");
            exit(1);
        }

        $this->font = $font;
        $this->image = imagecreatetruecolor($width, $height);
        $this->ink = 0x172B4D;
        imagefill($this->image, 0, 0, 0xFFFFFF);
        imageantialias($this->image, true);
    }

    /**
     * Draws left-aligned text from its first baseline
     */
    public function text(int $x, int $y, string $text, int $size = 17, ?int $color = null): void
    {
        foreach (explode("\n", $text) as $line) {
            if ($line !== ''
                && imagettftext($this->image, $size, 0, $x, $y, $color ?? $this->ink, $this->font, $line) === false
            ) {
                throw new RuntimeException('Could not draw text with the supplied font.');
            }
            $y += $size + 10;
        }
    }

    /**
     * Draws a box or a group background
     */
    public function box(int $x1, int $y1, int $x2, int $y2, string $label, int $fill = 0xFFFFFF): void
    {
        imagefilledrectangle($this->image, $x1, $y1, $x2, $y2, $fill);
        imagerectangle($this->image, $x1, $y1, $x2, $y2, $this->ink);
        $this->text($x1 + 16, $y1 + 30, $label);
    }

    /**
     * Draws an arrow along the listed points, with a head on the last segment
     *
     * @param list<array{int, int}> $points
     */
    public function arrow(array $points, bool $dotted = false, ?int $color = null): void
    {
        $color ??= $this->ink;
        $last = count($points) - 1;
        for ($index = 1; $index <= $last; $index++) {
            [$x1, $y1] = $points[$index - 1];
            [$x2, $y2] = $points[$index];
            $length = hypot($x2 - $x1, $y2 - $y1);
            $dx = ($x2 - $x1) / $length;
            $dy = ($y2 - $y1) / $length;
            $end = $index === $last ? $length - 12 : $length;
            $step = $dotted ? 10 : $length;

            for ($start = 0; $start < $end; $start += $step) {
                $stop = $dotted ? min($start + 4, $end) : $end;
                imageline(
                    $this->image,
                    (int) round($x1 + $start * $dx),
                    (int) round($y1 + $start * $dy),
                    (int) round($x1 + $stop * $dx),
                    (int) round($y1 + $stop * $dy),
                    $color
                );
            }
            if ($index === $last) {
                imagefilledpolygon($this->image, [
                    $x2,
                    $y2,
                    (int) round($x2 - $dx * 13 - $dy * 6),
                    (int) round($y2 - $dy * 13 + $dx * 6),
                    (int) round($x2 - $dx * 13 + $dy * 6),
                    (int) round($y2 - $dy * 13 - $dx * 6),
                ], $color);
            }
        }
    }

    /**
     * Writes the rendered PNG
     */
    public function save(string $path): void
    {
        ob_start();
        $encoded = imagepng($this->image);
        $png = ob_get_clean();
        if (!$encoded || !is_string($png) || $png === '' || file_put_contents($path, $png) !== strlen($png)) {
            throw new RuntimeException("Could not write diagram: {$path}");
        }
        printf("Rendered %s\n", $path);
    }
}
