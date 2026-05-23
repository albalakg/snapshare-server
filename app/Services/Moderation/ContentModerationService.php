<?php

namespace App\Services\Moderation;

use Aws\Rekognition\RekognitionClient;
use App\Services\Helpers\FileService;
use App\Services\Helpers\LogService;

class ContentModerationService
{
    private RekognitionClient $client;

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
    }

    private function buildImageInput(string $path): array
    {
        if (FileService::getDefaultDisk() === FileService::$s3_disk) {
            $bucket = config('filesystems.disks.s3.bucket');
            if ($bucket) {
                return [
                    'S3Object' => [
                        'Bucket' => $bucket,
                        'Name' => $path,
                    ],
                ];
            }
        }

        return [
            'Bytes' => FileService::get($path),
        ];
    }
}
