<?php

namespace Database\Seeders;

use App\Models\DealStage;
use App\Models\ProjectStage;
use Illuminate\Database\Seeder;

class StageSeeder extends Seeder
{
    public function run(): void
    {
        $dealStages = [
            ['name' => 'Новая',        'kk' => 'Жаңа',            'color' => '#3B82F6', 'is_won' => false],
            ['name' => 'Квалификация', 'kk' => 'Біліктілік',      'color' => '#6366F1', 'is_won' => false],
            ['name' => 'Предложение',  'kk' => 'Ұсыныс',          'color' => '#F59E0B', 'is_won' => false],
            ['name' => 'Переговоры',   'kk' => 'Келіссөздер',     'color' => '#8B5CF6', 'is_won' => false],
            ['name' => 'Счёт',         'kk' => 'Шот',             'color' => '#EC4899', 'is_won' => false],
            ['name' => 'Оплачено',     'kk' => 'Төленді',         'color' => '#10B981', 'is_won' => true],
        ];

        foreach ($dealStages as $i => $s) {
            $stage = DealStage::updateOrCreate(
                ['name' => $s['name'], 'type' => 'sale'],
                ['order' => $i + 1, 'color' => $s['color'], 'is_won' => $s['is_won'], 'is_active' => true, 'checklist' => []]
            );
            $stage->translations()->updateOrCreate(['locale' => 'ru'], ['name' => $s['name']]);
            $stage->translations()->updateOrCreate(['locale' => 'kk'], ['name' => $s['kk']]);
        }

        $projectStages = [
            ['name' => 'Планирование', 'kk' => 'Жоспарлау',   'color' => '#3B82F6', 'done' => false],
            ['name' => 'В работе',     'kk' => 'Жұмыста',      'color' => '#F59E0B', 'done' => false],
            ['name' => 'На проверке',  'kk' => 'Тексеруде',    'color' => '#8B5CF6', 'done' => false],
            ['name' => 'Завершён',     'kk' => 'Аяқталды',     'color' => '#10B981', 'done' => true],
        ];

        foreach ($projectStages as $i => $s) {
            $stage = ProjectStage::updateOrCreate(
                ['name' => $s['name'], 'type' => 'project'],
                ['order' => $i + 1, 'color' => $s['color'], 'is_completed' => $s['done'], 'is_active' => true, 'checklist' => []]
            );
            $stage->translations()->updateOrCreate(['locale' => 'ru'], ['name' => $s['name']]);
            $stage->translations()->updateOrCreate(['locale' => 'kk'], ['name' => $s['kk']]);
        }
    }
}
