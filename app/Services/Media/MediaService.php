<?php

namespace App\Services\Media;

use App\Exceptions\ApiException;
use App\Models\Media;
use App\Models\Provider;
use App\Services\Settings\SettingsService;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Stores provider uploads on the media disk (`filesystems.media_disk`: public locally, s3/MinIO in production).
 * Raster images are re-encoded with GD: EXIF is dropped (after applying the orientation), and anything wider than
 * `media.max_width` is scaled down. Animated GIFs are stored untouched so they keep animating.
 */
class MediaService
{
    public const MIME_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];

    public function __construct(private SettingsService $settings) {}

    public function store(Provider $provider, UploadedFile $file): Media
    {
        $info = @getimagesize($file->getRealPath());
        $mime = $info['mime'] ?? null;

        if (! $info || ! isset(self::MIME_TYPES[$mime])) {
            throw new ApiException(__('api.media.unsupported'), 422, errors: ['file' => [__('api.media.unsupported')]]);
        }

        [$contents, $width, $height] = $mime === 'image/gif'
            ? [$file->getContent(), $info[0], $info[1]]
            : $this->optimise($file, $mime, (int) $this->settings->get('media.max_width'));

        $disk = config('filesystems.media_disk');
        $path = "providers/{$provider->id}/".Str::uuid().'.'.self::MIME_TYPES[$mime];
        Storage::disk($disk)->put($path, $contents, 'public');

        return Media::create([
            'provider_id' => $provider->id,
            'disk' => $disk,
            'path' => $path,
            'original_name' => Str::limit($file->getClientOriginalName(), 200, ''),
            'mime_type' => $mime,
            'size' => strlen($contents),
            'width' => $width,
            'height' => $height,
        ]);
    }

    public function delete(Media $media): void
    {
        Storage::disk($media->disk)->delete($media->path);
        $media->delete();
    }

    /** @return array{string, int, int} encoded bytes, width, height */
    private function optimise(UploadedFile $file, string $mime, int $maxWidth): array
    {
        $image = @imagecreatefromstring($file->getContent());

        if (! $image) {
            throw new ApiException(__('api.media.unsupported'), 422, errors: ['file' => [__('api.media.unsupported')]]);
        }

        if ($mime === 'image/jpeg') {
            $image = $this->applyOrientation($image, $file->getRealPath());
        }

        if (imagesx($image) > $maxWidth) {
            $image = imagescale($image, $maxWidth, -1, IMG_BICUBIC);
        }

        imagealphablending($image, false);
        imagesavealpha($image, true);

        ob_start();
        match ($mime) {
            'image/jpeg' => imagejpeg($image, null, 85),
            'image/png' => imagepng($image, null, 7),
            'image/webp' => imagewebp($image, null, 85),
        };
        $contents = (string) ob_get_clean();

        return [$contents, imagesx($image), imagesy($image)];
    }

    private function applyOrientation(GdImage $image, string $path): GdImage
    {
        $orientation = function_exists('exif_read_data') ? (@exif_read_data($path)['Orientation'] ?? 1) : 1;
        $degrees = [3 => 180, 6 => -90, 8 => 90][$orientation] ?? 0;

        return $degrees ? (imagerotate($image, $degrees, 0) ?: $image) : $image;
    }
}
