<?php

namespace App\Services\Moderation;

use Aws\Rekognition\RekognitionClient;
use App\Services\Helpers\FileService;
use App\Services\Helpers\LogService;
use Illuminate\Support\Facades\Storage;

class ContentModerationService
{
    private const REKOGNITION_MAX_BYTES = 5 * 1024 * 1024;

    private const REKOGNITION_S3_MAX_BYTES = 15 * 1024 * 1024;

    private RekognitionClient $client;

    private ?string $tempS3ModerationKey = null;

    public function __construct(?RekognitionClient $client = null)
    {
        $this->client = $client ?? new RekognitionClient([
            'version' => 'latest',
            'region' => config('moderation.region'),
        ]);
    }

    /**
     * @return array{isValid: bool, reasons: array<int, string>, labels: array<int, array<string, mixed>>}
     */
    public function checkImage(string $path): array
    {
        if (!config('moderation.enabled')) {
            return ['isValid' => true, 'reasons' => [], 'labels' => []];
        }

        try {
            $image = $this->buildImageInput($path);
            $minConfidence = config('moderation.min_confidence');

            $result = $this->client->detectModerationLabels([
                'Image' => $image,
                'MinConfidence' => $minConfidence,
            ]);

            $blockedCategories = config('moderation.blocked_categories');
            $reasons = [];
            $labels = [];

            foreach ($result['ModerationLabels'] ?? [] as $label) {
                $confidence = (float) ($label['Confidence'] ?? 0);
                if ($confidence < $minConfidence) {
                    continue;
                }

                $category = $label['ParentName'] ?? $label['Name'] ?? '';
                $labels[] = [
                    'name' => $label['Name'] ?? '',
                    'parent' => $label['ParentName'] ?? null,
                    'confidence' => $confidence,
                ];

                if (in_array($category, $blockedCategories, true)) {
                    $reasons[] = $label['Name'] ?? $category;
                }
            }

            return [
                'isValid' => empty($reasons),
                'reasons' => array_values(array_unique($reasons)),
                'labels' => $labels,
            ];
        } finally {
            $this->cleanupTempS3ModerationObject();
        }
    }

    private function buildImageInput(string $path): array
    {
        $s3Reference = $this->buildS3ObjectReference($path);
        if ($s3Reference !== null) {
            return $s3Reference;
        }

        $bytes = FileService::get($path);

        return [
            'Bytes' => $this->prepareBytesForRekognition($bytes),
        ];
    }

    private function buildS3ObjectReference(string $path): ?array
    {
        $bucket = config('filesystems.disks.s3.bucket');
        if (!$bucket) {
            return null;
        }

        if (FileService::getDefaultDisk() === FileService::$s3_disk && FileService::exists($path, FileService::$s3_disk)) {
            return [
                'S3Object' => [
                    'Bucket' => $bucket,
                    'Name' => $path,
                ],
            ];
        }

        if (!FileService::exists($path)) {
            return null;
        }

        $bytes = FileService::get($path);
        if (strlen($bytes) <= self::REKOGNITION_MAX_BYTES) {
            return null;
        }

        if (strlen($bytes) > self::REKOGNITION_S3_MAX_BYTES) {
            return null;
        }

        $this->tempS3ModerationKey = 'moderation-temp/' . ltrim($path, '/');
        Storage::disk(FileService::$s3_disk)->put($this->tempS3ModerationKey, $bytes);

        return [
            'S3Object' => [
                'Bucket' => $bucket,
                'Name' => $this->tempS3ModerationKey,
            ],
        ];
    }

    private function cleanupTempS3ModerationObject(): void
    {
        if ($this->tempS3ModerationKey === null) {
            return;
        }

        Storage::disk(FileService::$s3_disk)->delete($this->tempS3ModerationKey);
        $this->tempS3ModerationKey = null;
    }

    private function prepareBytesForRekognition(string $bytes): string
    {
        if (strlen($bytes) <= self::REKOGNITION_MAX_BYTES) {
            return $bytes;
        }

        if (!extension_loaded('gd')) {
            throw new \RuntimeException(
                'Image exceeds Rekognition 5MB byte-upload limit. Enable the PHP GD extension for local resizing, or configure S3 for moderation.'
            );
        }

        $source = @imagecreatefromstring($bytes);
        if ($source === false) {
            throw new \RuntimeException('Failed to decode image for moderation resize');
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $scale = sqrt(self::REKOGNITION_MAX_BYTES / strlen($bytes)) * 0.85;

        try {
            while ($scale >= 0.1) {
                $newWidth = max(1, (int) round($width * $scale));
                $newHeight = max(1, (int) round($height * $scale));
                $resized = imagecreatetruecolor($newWidth, $newHeight);

                if ($resized === false) {
                    break;
                }

                imagecopyresampled($resized, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

                foreach ([85, 75, 65, 55] as $quality) {
                    ob_start();
                    imagejpeg($resized, null, $quality);
                    $output = ob_get_clean();

                    if ($output !== false && strlen($output) <= self::REKOGNITION_MAX_BYTES) {
                        imagedestroy($resized);
                        return $output;
                    }
                }

                imagedestroy($resized);
                $scale *= 0.75;
            }
        } finally {
            imagedestroy($source);
        }

        throw new \RuntimeException('Unable to compress image below Rekognition 5MB limit');
    }
}
