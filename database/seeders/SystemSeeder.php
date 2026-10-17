<?php

namespace Database\Seeders;

use App\Domain\Organization\SopTemplate;
use App\Models\AiModel;
use App\Models\SystemSetting;
use Illuminate\Database\Seeder;

/**
 * Insert-if-absent only: values changed by operators are never overwritten,
 * and no AI results or reports are seeded.
 */
class SystemSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            'sop.template' => SopTemplate::defaultConfig(),
            'retention.defaults' => config('kitchen.retention_days'),
            'analysis.paused' => false,
        ];

        foreach ($settings as $key => $value) {
            SystemSetting::query()->firstOrCreate(['key' => $key], ['value' => $value]);
        }

        foreach (self::models() as $model) {
            AiModel::query()->firstOrCreate(
                ['name' => $model['name'], 'version' => $model['version']],
                $model + ['sha256' => hash('sha256', $model['name'].'@'.$model['version'])],
            );
        }
    }

    /** @return list<array<string, mixed>> */
    public static function models(): array
    {
        return [
            [
                'scope' => 'global',
                'name' => 'anpixel-simulator',
                'version' => '1.0.0',
                'runtime' => 'simulation',
                'labels' => ['person', 'hair_cover', 'apron'],
                'capabilities' => [
                    'person' => true,
                    'tracking' => true,
                    'ppe' => ['hair_cover' => true, 'apron' => true, 'glove' => false, 'mask' => false],
                    'activity' => ['zone_presence' => true, 'interaction' => false],
                    'simulated' => true,
                ],
                'license_ref' => 'internal',
                'validation_status' => 'ACTIVE',
            ],
            [
                'scope' => 'global',
                'name' => 'opencv-hog-person',
                'version' => '4.x',
                'runtime' => 'opencv',
                'labels' => ['person'],
                'capabilities' => [
                    'person' => true,
                    'tracking' => true,
                    'ppe' => ['hair_cover' => false, 'apron' => false, 'glove' => false, 'mask' => false],
                    'activity' => ['zone_presence' => true, 'interaction' => false],
                    'simulated' => false,
                    'notes' => 'Baseline deteksi orang bawaan OpenCV. Tidak mendeteksi APD; status APD selalu unknown (unsupported).',
                ],
                'license_ref' => 'OpenCV Apache-2.0',
                'validation_status' => 'VALIDATED',
            ],
            [
                'scope' => 'global',
                'name' => 'yolox-nano-person',
                'version' => '0.1.1rc0',
                'runtime' => 'onnx',
                'artifact_key' => 'yolox_nano.onnx',
                'sha256' => 'c789161ed43c8269fcd4e67c67eeeb4e80c622da2eb296a20bc6007bd18a0b7d',
                'labels' => ['person'],
                'capabilities' => [
                    'person' => true,
                    'tracking' => true,
                    'ppe' => ['hair_cover' => false, 'apron' => false, 'glove' => false, 'mask' => false],
                    'activity' => ['zone_presence' => true, 'interaction' => false],
                    'simulated' => false,
                    'notes' => 'Deteksi orang YOLOX-Nano (COCO). Akurat untuk orang yang tertutup meja; belum mendeteksi APD, status APD selalu unknown (unsupported).',
                ],
                'license_ref' => 'YOLOX Apache-2.0 (Megvii)',
                'validation_status' => 'VALIDATED',
            ],
            [
                'scope' => 'global',
                'name' => 'yolox-wholebody12-n',
                'version' => '0200-640',
                'runtime' => 'onnx-wholebody',
                'artifact_key' => 'yolox_n_wholebody12_640.onnx',
                'sha256' => 'f525570ce7abf0b9b9366db442f04368d551b43a91783403cbe5eb88b115aee1',
                'labels' => ['person'],
                'capabilities' => [
                    'person' => true,
                    'tracking' => true,
                    'ppe' => ['hair_cover' => false, 'apron' => false, 'glove' => false, 'mask' => false],
                    'activity' => ['zone_presence' => true, 'interaction' => false],
                    'simulated' => false,
                    'notes' => 'Deteksi tubuh YOLOX-WholeBody12 Nano. Lebih tahan untuk kamera dari atas (overhead), blur, dan tubuh yang terpotong di tepi frame; belum mendeteksi APD, status APD selalu unknown (unsupported).',
                ],
                'license_ref' => 'YOLOX-WholeBody12 Apache-2.0 (PINTO0309)',
                'validation_status' => 'VALIDATED',
            ],
        ];
    }
}
