<?php

namespace App\Services;

use App\Models\Deal;
use App\Models\Project;
use App\Models\ProjectStage;

class ProjectService
{
    public function __construct(private ProjectNumberService $numbers) {}

    /**
     * Create an execution Project from a Deal. Idempotent for an ACTIVE run
     * (returns it), but a completed prior run starts a fresh workshop cycle —
     * otherwise re-sending would close the deal with no active project (lost).
     */
    public function createFromDeal(Deal $deal): Project
    {
        if ($deal->project && $deal->project->status !== 'completed') {
            return $deal->project;
        }

        // Заказ попадает в цех СВОЕЙ компании (BAIA — мебельный, ASU — швейный).
        $firstStage = ProjectStage::funnel($deal->company_id ? (int) $deal->company_id : null)->first();

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
