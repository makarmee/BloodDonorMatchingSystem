<?php

namespace Database\Seeders;

use App\Models\ScreeningTestType;
use Illuminate\Database\Seeder;

class ScreeningTestTypeSeeder extends Seeder
{
    /**
     * Canonical mandatory BDMS screening test types from DATA-DICTIONARY.md and BUSINESS-RULES.md.
     *
     * @var list<array{code: string, name: string, description: string, is_active: bool}>
     */
    public const array TEST_TYPES = [
        [
            'code' => 'HIV',
            'name' => 'HIV',
            'description' => 'Human Immunodeficiency Virus infectious screening assay.',
            'is_active' => true,
        ],
        [
            'code' => 'HBV',
            'name' => 'Hepatitis B',
            'description' => 'Hepatitis B surface antigen and nucleic acid infectious screening assay.',
            'is_active' => true,
        ],
        [
            'code' => 'HCV',
            'name' => 'Hepatitis C',
            'description' => 'Hepatitis C antibody and viral RNA infectious screening assay.',
            'is_active' => true,
        ],
        [
            'code' => 'SYPH',
            'name' => 'Syphilis',
            'description' => 'Treponema pallidum serologic infectious screening assay.',
            'is_active' => true,
        ],
        [
            'code' => 'COMPAT',
            'name' => 'Blood Group Compatibility',
            'description' => 'ABO/Rh confirmation and crossmatch compatibility verification panel.',
            'is_active' => true,
        ],
    ];

    /**
     * Seed the five approved BDMS screening test types idempotently.
     */
    public function run(): void
    {
        foreach (self::TEST_TYPES as $testType) {
            ScreeningTestType::query()->updateOrCreate(
                ['code' => $testType['code']],
                [
                    'name' => $testType['name'],
                    'description' => $testType['description'],
                    'is_active' => $testType['is_active'],
                ]
            );
        }
    }
}
