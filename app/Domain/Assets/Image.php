<?php

namespace App\Domain\Assets;

/** An 8-bit RGBA image: `pixels` holds four bytes per pixel, row by row from the top left. */
final readonly class Image
{
    public function __construct(
        public int $width,
        public int $height,
        public string $pixels,
    ) {}

    public static function blank(int $width, int $height): self
    {
        return new self($width, $height, str_repeat("\0\0\0\0", $width * $height));
    }

    /** Every pixel made `factor` × `factor`, nearest neighbour. */
    public function scale(int $factor): self
    {
        if ($factor === 1) {
            return $this;
        }

        $out = '';

        for ($y = 0; $y < $this->height; $y++) {
            $row = '';

            foreach (str_split(substr($this->pixels, $y * $this->width * 4, $this->width * 4), 4) as $pixel) {
                $row .= str_repeat($pixel, $factor);
            }

            $out .= str_repeat($row, $factor);
        }

        return new self($this->width * $factor, $this->height * $factor, $out);
    }

    /** Whether every `factor` × `factor` block is one colour: what an export at factor × 100% looks like. */
    public function isScaledUp(int $factor): bool
    {
        if ($factor < 2 || $this->width % $factor !== 0 || $this->height % $factor !== 0) {
            return false;
        }

        return $this->equals($this->sample($factor)->scale($factor));
    }

    /** A copy with `image` written in at (x, y), replacing what was there. */
    public function paste(self $image, int $x, int $y): self
    {
        $pixels = $this->pixels;

        for ($row = 0; $row < $image->height; $row++) {
            $pixels = substr_replace(
                $pixels,
                substr($image->pixels, $row * $image->width * 4, $image->width * 4),
                (($y + $row) * $this->width + $x) * 4,
                $image->width * 4,
            );
        }

        return new self($this->width, $this->height, $pixels);
    }

    public function crop(int $x, int $y, int $width, int $height): self
    {
        $pixels = '';

        for ($row = 0; $row < $height; $row++) {
            $pixels .= substr($this->pixels, (($y + $row) * $this->width + $x) * 4, $width * 4);
        }

        return new self($width, $height, $pixels);
    }

    public function alphaAt(int $x, int $y): int
    {
        return ord($this->pixels[($y * $this->width + $x) * 4 + 3]);
    }

    public function equals(self $other): bool
    {
        return $this->width === $other->width && $this->height === $other->height && $this->pixels === $other->pixels;
    }

    /** Every `factor`-th pixel, from the top left: the inverse of scale() on a scaled-up image. */
    private function sample(int $factor): self
    {
        $pixels = '';

        for ($y = 0; $y < $this->height; $y += $factor) {
            for ($x = 0; $x < $this->width; $x += $factor) {
                $pixels .= substr($this->pixels, ($y * $this->width + $x) * 4, 4);
            }
        }

        return new self(intdiv($this->width, $factor), intdiv($this->height, $factor), $pixels);
    }
}
