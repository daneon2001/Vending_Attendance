<?php

namespace App\Services\Support;

use GdImage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SupportImageSanitizer
{
    public const VERSION = 'gd-raster-v1';

    private const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function sanitize(string $bytes, string $declaredMime): array
    {
        $maxBytes = (int) config('support.evidence.max_size_bytes', 5242880);
        if ($bytes === '' || strlen($bytes) > $maxBytes) {
            $this->invalid('EVIDENCE_SIZE_INVALID');
        }
        $allowed = array_intersect(array_keys(self::MIME_EXTENSIONS), (array) config('support.evidence.allowed_mimes', array_keys(self::MIME_EXTENSIONS)));
        if (! in_array($declaredMime, $allowed, true)) {
            $this->invalid('EVIDENCE_MIME_INVALID');
        }
        if (! extension_loaded('gd') || ! extension_loaded('fileinfo')) {
            throw new HttpException(503, 'No está disponible el procesamiento seguro de imágenes.');
        }
        $detectedMime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $dimensions = @getimagesizefromstring($bytes);
        if ($detectedMime !== $declaredMime || $dimensions === false || ($dimensions['mime'] ?? null) !== $declaredMime) {
            $this->invalid('EVIDENCE_MIME_MISMATCH');
        }
        $width = (int) $dimensions[0];
        $height = (int) $dimensions[1];
        $maxDimension = (int) config('support.evidence.max_dimension', 6000);
        $maxPixels = (int) config('support.evidence.max_pixels', 12000000);
        if ($width < 1 || $height < 1 || $width > $maxDimension || $height > $maxDimension || $width > intdiv(max(1, $maxPixels), $height)) {
            $this->invalid('EVIDENCE_DIMENSIONS_INVALID');
        }
        $this->assertMemoryBudget($width, $height, strlen($bytes));
        // Animated formats have no defined evidence transformation in this phase.
        if ($this->animated($bytes, $declaredMime)) {
            $this->invalid('EVIDENCE_ANIMATION_UNSUPPORTED');
        }

        $image = @imagecreatefromstring($bytes);
        if (! $image instanceof GdImage) {
            $this->invalid('EVIDENCE_IMAGE_INVALID');
        }
        $thumbnail = null;
        try {
            if ($declaredMime === 'image/jpeg') {
                $image = $this->orient($image, $this->orientation($bytes));
            }
            imagesavealpha($image, true);
            $sanitized = $this->encode($image, $declaredMime);
            if (strlen($sanitized) > $maxBytes) {
                $this->invalid('EVIDENCE_SANITIZED_SIZE_INVALID');
            }

            $bound = max(1, (int) config('support.evidence.thumbnail_max_dimension', 320));
            $scale = min(1, $bound / max(imagesx($image), imagesy($image)));
            $thumbWidth = max(1, (int) round(imagesx($image) * $scale));
            $thumbHeight = max(1, (int) round(imagesy($image) * $scale));
            $thumbnail = imagecreatetruecolor($thumbWidth, $thumbHeight);
            if (! $thumbnail instanceof GdImage) {
                throw new HttpException(503, 'No está disponible el procesamiento seguro de imágenes.');
            }
            imagealphablending($thumbnail, false);
            imagesavealpha($thumbnail, true);
            imagefill($thumbnail, 0, 0, imagecolorallocatealpha($thumbnail, 0, 0, 0, 127));
            if (! imagecopyresampled($thumbnail, $image, 0, 0, 0, 0, $thumbWidth, $thumbHeight, imagesx($image), imagesy($image))) {
                $this->invalid('EVIDENCE_IMAGE_INVALID');
            }
            $thumbnailBytes = $this->encode($thumbnail, $declaredMime);

            return [
                'bytes' => $sanitized,
                'mime' => $declaredMime,
                'extension' => self::MIME_EXTENSIONS[$declaredMime],
                'size_bytes' => strlen($sanitized),
                'sha256' => hash('sha256', $sanitized),
                'thumbnail_bytes' => $thumbnailBytes,
                'thumbnail_mime' => $declaredMime,
                'thumbnail_sha256' => hash('sha256', $thumbnailBytes),
                'sanitization_version' => self::VERSION,
            ];
        } finally {
            imagedestroy($image);
            if ($thumbnail instanceof GdImage) {
                imagedestroy($thumbnail);
            }
        }
    }

    private function orientation(string $bytes): int
    {
        // Metadata never leaves the sanitizer. Only the orientation affects pixels.
        if (! extension_loaded('exif')) {
            throw new HttpException(503, 'No está disponible el procesamiento seguro de imágenes.');
        }
        $stream = fopen('php://memory', 'w+b');
        if ($stream === false) {
            throw new HttpException(503, 'No está disponible el procesamiento seguro de imágenes.');
        }
        try {
            fwrite($stream, $bytes);
            rewind($stream);
            $metadata = @exif_read_data($stream, 'IFD0', true, false);
            $orientation = (int) ($metadata['IFD0']['Orientation'] ?? 1);

            return $orientation >= 1 && $orientation <= 8 ? $orientation : 1;
        } finally {
            fclose($stream);
        }
    }

    private function animated(string $bytes, string $mime): bool
    {
        if (! in_array($mime, ['image/png', 'image/webp'], true)) {
            return false;
        }
        $png = $mime === 'image/png';
        $offset = $png ? 8 : 12;
        $length = strlen($bytes);
        while ($offset + 8 <= $length) {
            $type = substr($bytes, $offset + ($png ? 4 : 0), 4);
            $size = unpack($png ? 'Nsize' : 'Vsize', substr($bytes, $offset + ($png ? 0 : 4), 4))['size'];
            if ($size > $length - $offset - ($png ? 12 : 8)) {
                $this->invalid('EVIDENCE_IMAGE_INVALID');
            }
            if (($png && $type === 'acTL') || (! $png && in_array($type, ['ANIM', 'ANMF'], true))) {
                return true;
            }
            if (! $png && $type === 'VP8X' && $size > 0 && (ord($bytes[$offset + 8]) & 2) !== 0) {
                return true;
            }
            if ($png && $type === 'IEND') {
                break;
            }
            $offset += $size + ($png ? 12 : 8 + ($size % 2));
        }

        return false;
    }

    private function assertMemoryBudget(int $width, int $height, int $inputBytes): void
    {
        $limit = trim((string) ini_get('memory_limit'));
        if ($limit === '-1' || ! preg_match('/^(\d+)\s*([KMG])?$/i', $limit, $parts)) {
            return;
        }
        $multiplier = match (strtoupper($parts[2] ?? '')) {
            'K' => 1024, 'M' => 1048576, 'G' => 1073741824, default => 1,
        };
        // Include decode, orientation copy, encoded representations and request
        // buffers. Reject before GD allocation when the PHP budget is smaller.
        $estimate = $width * $height * 12 + $inputBytes * 4 + 16777216;
        if ($estimate > (int) $parts[1] * $multiplier - memory_get_usage(true)) {
            $this->invalid('EVIDENCE_DIMENSIONS_INVALID');
        }
    }

    private function orient(GdImage $image, int $orientation): GdImage
    {
        if (in_array($orientation, [2, 5, 7], true)) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        } elseif ($orientation === 4) {
            imageflip($image, IMG_FLIP_VERTICAL);
        }
        $angle = match ($orientation) {
            3 => 180,
            5, 8 => 90,
            6, 7 => -90,
            default => 0,
        };
        if ($angle === 0) {
            return $image;
        }
        $rotated = imagerotate($image, $angle, 0);
        if (! $rotated instanceof GdImage) {
            $this->invalid('EVIDENCE_IMAGE_INVALID');
        }
        imagedestroy($image);

        return $rotated;
    }

    private function encode(GdImage $image, string $mime): string
    {
        ob_start();
        try {
            $success = match ($mime) {
                'image/jpeg' => imagejpeg($image, null, 90),
                'image/png' => imagepng($image, null, 6),
                'image/webp' => imagewebp($image, null, 85),
            };
            $bytes = ob_get_contents();
            if (! $success || ! is_string($bytes) || $bytes === '') {
                $this->invalid('EVIDENCE_IMAGE_INVALID');
            }

            return $bytes;
        } finally {
            ob_end_clean();
        }
    }

    private function invalid(string $code): never
    {
        $message = match ($code) {
            'EVIDENCE_SIZE_INVALID' => 'La evidencia está vacía o excede el tamaño permitido.',
            'EVIDENCE_MIME_INVALID' => 'Selecciona una imagen JPEG, PNG o WebP.',
            'EVIDENCE_MIME_MISMATCH' => 'El contenido de la imagen no coincide con el formato declarado.',
            'EVIDENCE_DIMENSIONS_INVALID' => 'Las dimensiones de la imagen exceden el límite permitido.',
            'EVIDENCE_ANIMATION_UNSUPPORTED' => 'Las imágenes animadas no están admitidas.',
            'EVIDENCE_SANITIZED_SIZE_INVALID' => 'La imagen procesada excede el tamaño permitido.',
            default => 'No fue posible leer una imagen válida.',
        };
        throw ValidationException::withMessages(['evidence' => $message]);
    }
}
