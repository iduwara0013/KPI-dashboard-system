<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MonthlyKpiResultTest extends TestCase
{
    use RefreshDatabase;

    private User $hof;

    private User $editor;

    private int $plan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now('Asia/Colombo')->setDate(2026, 11, 15));
        $this->seed();
        $this->hof = $this->person('EMP001');
        $this->editor = $this->person('EMP011');
        $this->grantPlanningDepartments($this->editor);
        $department = DB::table('departments')->where('code', 'IT')->value('id');
        $kpi = [
            'code' => 'UPTIME', 'title' => 'System uptime', 'weight' => 0.5,
            'target_value' => 95, 'target_unit' => 'percent', 'frequency' => 'Monthly', 'direction' => 'higher',
            'activities' => [['code' => 'MONITOR', 'title' => 'Monitor uptime', 'weight' => 1]],
        ];
        $this->plan = $this->actingAs($this->editor)->postJson('/api/planning/plans', [
            'code' => 'RESULTS-2026', 'title' => 'IT plan', 'year' => 2026, 'owner_id' => $this->hof->id,
            'kpas' => [[
                'code' => 'SERVICE', 'title' => 'Service reliability', 'department_id' => $department, 'weight' => 1,
                'kpis' => [$kpi, [...$kpi, 'code' => 'RECOVERY', 'title' => 'Recovery time', 'frequency' => 'Quarterly',
                    'activities' => [['code' => 'RECOVER', 'title' => 'Recovery drill', 'weight' => 1]]]],
            ]],
        ])->assertCreated()->json('id');
        $this->postJson('/api/planning/plans/'.$this->plan.'/publish', ['revision' => 1])->assertOk();
    }

    public function test_delegated_owner_submits_and_hof_reviews_with_a_correction_history(): void
    {
        $manager = $this->person('EMP004');
        $other = $this->person('EMP002');
        $md = User::factory()->create(['system_role' => 'md', 'is_active' => true]);
        $this->actingAs($this->hof)->postJson('/api/hof/kpi-assignments', [
            'plan_id' => $this->plan, 'kpi_code' => 'UPTIME', 'source_revision' => 2, 'version' => 0, 'assignee_id' => $manager->id,
        ])->assertOk();

        $this->actingAs($other)->getJson('/api/kpi-results/workspace')->assertJsonCount(0, 'rows');
        $row = $this->actingAs($manager)->getJson('/api/kpi-results/workspace')->assertOk()->assertJsonCount(1, 'rows')
            ->assertJsonPath('rows.0.can_submit', true)->assertJsonPath('rows.0.status', 'pending_measurement')
            ->assertJsonPath('rows.0.actual', null)->json('rows.0');
        $payload = [...$this->context($row), 'month' => 11, 'attempt' => 0];
        $this->postJson('/api/kpi-results/submit', [...$payload, 'actual_value' => 92])->assertUnprocessable();
        $submission = [...$payload, 'actual_value' => 92, 'evidence' => 'Monthly uptime report.', 'evidence_url' => 'https://example.test/report'];
        $this->postJson('/api/kpi-results/submit', [...$submission, 'month' => 12])->assertUnprocessable();
        $this->postJson('/api/kpi-results/submit', $submission)->assertCreated();
        $this->postJson('/api/kpi-results/submit', $submission)->assertConflict();
        $review = [...$payload, 'attempt' => 1, 'decision' => 'approved'];
        $this->postJson('/api/kpi-results/review', $review)->assertForbidden();

        $this->actingAs($md)->getJson('/api/kpi-results/workspace')->assertJsonPath('rows.0.gap', -3)
            ->assertJsonPath('rows.0.status', 'submitted')->assertJsonMissingPath('rows.0.history.0.evidence');
        $this->postJson('/api/kpi-results/review', $review)->assertForbidden();
        $this->actingAs($this->hof)->postJson('/api/kpi-results/review', [...$review, 'decision' => 'needs_correction'])->assertUnprocessable();
        $this->postJson('/api/kpi-results/review', [...$review, 'decision' => 'needs_correction', 'review_note' => 'Exclude planned maintenance.'])->assertOk();

        $this->actingAs($manager)->postJson('/api/kpi-results/submit', [...$submission, 'attempt' => 1, 'actual_value' => '96.125'])->assertCreated();
        $this->actingAs($this->hof)->postJson('/api/kpi-results/review', $review)->assertConflict();
        $this->postJson('/api/kpi-results/review', [...$review, 'attempt' => 2])->assertOk();
        $this->actingAs($manager)->postJson('/api/kpi-results/submit', [...$submission, 'attempt' => 2])->assertConflict();
        $this->actingAs($md)->getJson('/api/kpi-results/workspace?period=2026-11')
            ->assertJsonPath('rows.0.target', 95)->assertJsonPath('rows.0.gap', 1.125)
            ->assertJsonPath('rows.0.status', 'approved')->assertJsonPath('rows.0.history.1.status', 'needs_correction');
        $this->getJson('/api/kpi-results/workspace?period=2026-10')->assertJsonPath('rows.0.status', 'pending_measurement');
        $this->assertDatabaseCount('monthly_kpi_submissions', 2);
    }

    public function test_hof_confirms_own_monthly_result_and_preserves_its_target(): void
    {
        $reviewer = $this->person('EMP002');
        $row = $this->actingAs($this->hof)->getJson('/api/kpi-results/workspace')
            ->assertJsonPath('rows.0.can_submit', true)->assertJsonPath('rows.0.self_confirm', true)->json('rows.0');
        $payload = [...$this->context($row), 'month' => 11, 'attempt' => 0, 'actual_value' => 0, 'evidence' => 'Monitoring report.'];
        $assign = [...$this->context($row), 'reviewer_id' => $reviewer->id];
        $this->postJson('/api/kpi-results/reviewer', $assign)->assertNotFound();
        $this->postJson('/api/kpi-results/submit', [...$payload, ...$this->context($row)])->assertCreated();

        $snapshot = DB::table('performance_plan_versions')->where('plan_id', $this->plan)->where('version', 2)->first();
        $document = json_decode($snapshot->snapshot, true);
        $document['kpas'][0]['kpis'][0]['target_value'] = 99;
        DB::table('performance_plan_versions')->insert([
            'plan_id' => $this->plan, 'actor_id' => $this->editor->id, 'version' => 3, 'event' => 'published',
            'snapshot' => json_encode($document), 'changes' => '[]', 'created_at' => now(),
        ]);
        DB::table('performance_plans')->where('id', $this->plan)->update(['published_revision' => 3]);

        $this->actingAs($reviewer)->getJson('/api/kpi-results/workspace')->assertJsonCount(0, 'rows');
        $fresh = $this->actingAs($this->hof)->getJson('/api/kpi-results/workspace')->assertJsonPath('rows.0.can_review', false)
            ->assertJsonPath('rows.0.status', 'approved')
            ->assertJsonPath('rows.0.target', 95)->assertJsonPath('rows.0.actual', 0)->json('rows.0');
        $this->postJson('/api/kpi-results/review', [...$this->context($fresh), 'month' => 11, 'attempt' => 1, 'decision' => 'approved'])->assertForbidden();
        $this->getJson('/api/kpi-results/workspace?period=2026-10')->assertJsonPath('rows.0.target', 99);

        $document['kpas'][0]['kpis'][0]['frequency'] = 'Annually';
        DB::table('performance_plan_versions')->insert([
            'plan_id' => $this->plan, 'actor_id' => $this->editor->id, 'version' => 4, 'event' => 'published',
            'snapshot' => json_encode($document), 'changes' => '[]', 'created_at' => now(),
        ]);
        DB::table('performance_plans')->where('id', $this->plan)->update(['published_revision' => 4]);
        $this->actingAs($this->hof)->getJson('/api/kpi-results/workspace')->assertJsonPath('rows.0.archived', true)
            ->assertJsonPath('rows.0.status', 'approved')->assertJsonPath('rows.0.target', 95);
        $this->getJson('/api/kpi-results/workspace?period=2026-10')->assertJsonCount(0, 'rows');
    }

    private function person(string $number): User
    {
        return User::where('employee_number', $number)->firstOrFail();
    }

    private function context(array $row): array
    {
        return ['plan_id' => $row['plan_id'], 'kpi_code' => $row['kpi_code'], 'context' => $row['context']];
    }

    public function test_monthly_notifications_follow_correction_resubmission_and_approval(): void
    {
        $manager = $this->person('EMP004');
        $this->actingAs($this->hof)->postJson('/api/hof/kpi-assignments', ['plan_id' => $this->plan, 'kpi_code' => 'UPTIME', 'source_revision' => 2, 'version' => 0, 'assignee_id' => $manager->id])->assertOk();
        $row = $this->actingAs($manager)->getJson('/api/kpi-results/workspace?period=2026-11')->json('rows.0');
        $payload = [...$this->context($row), 'month' => 11, 'attempt' => 0, 'actual_value' => 95, 'evidence' => 'Monitoring report.'];
        $this->postJson('/api/kpi-results/submit', $payload)->assertCreated();
        $this->actingAs($this->hof)->getJson('/api/notifications?view=actions&category=reviews')->assertJsonPath('items.0.type', 'kpi_submission')->assertJsonPath('items.0.target.period', '2026-11');
        $this->postJson('/api/kpi-results/review', [...$this->context($row), 'month' => 11, 'attempt' => 1, 'decision' => 'needs_correction', 'review_note' => 'Attach the correct source.'])->assertOk();
        $this->getJson('/api/notifications?view=actions&category=reviews')->assertJsonCount(0, 'items');
        $this->actingAs($manager)->getJson('/api/notifications?view=actions&category=decisions')->assertJsonPath('items.0.type', 'kpi_needs_correction');
        $this->postJson('/api/kpi-results/submit', [...$payload, 'attempt' => 1])->assertCreated();
        $this->actingAs($this->hof)->postJson('/api/kpi-results/review', [...$this->context($row), 'month' => 11, 'attempt' => 2, 'decision' => 'approved'])->assertOk();
        $this->actingAs($manager)->getJson('/api/notifications?category=decisions')->assertJsonPath('items.0.type', 'kpi_approved')->assertJsonPath('items.0.action_required', false);
    }
}
