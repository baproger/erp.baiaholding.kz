<?php

namespace App\Services;

use App\Models\Deal;
use App\Models\Project;
use App\Models\ProjectStage;

class ProjectService
{
    public function __construct(private ProjectNumberService $numbers) {}

    /**
     * Create an execution Project from a won Deal (idempotent — returns the
     * existing project if one is already linked).
     */
    public function createFromDeal(Deal $deal): Project
    {
        if ($deal->project) {
            return $deal->project;
        }

        $firstStage = ProjectStage::where('is_active', true)->orderBy('order')->first();

        return Project::create([
            'number' => $this->numbers->generate(),
            'name' => $deal->name,
            'deal_id' => $deal->id,
            'client_id' => $deal->client_id,
            'responsible_user_id' => $deal->responsible_user_id,
            'department_id' => $deal->department_id,
            'project_stage_id' => $firstStage?->id,
            'budget' => $deal->budget,
            'deadline' => $deal->deadline,
            'description' => $deal->description,
            'status' => 'active',
            'started_at' => now(),
        ]);
    }
}
