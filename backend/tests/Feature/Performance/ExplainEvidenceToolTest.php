<?php

namespace Tests\Feature\Performance;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\Performance\Support\ExplainEvidenceTool;
use Tests\TestCase;

final class ExplainEvidenceToolTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('perf_explain_probe', function (Blueprint $table): void {
            $table->id();
            $table->string('indexed_value')->index();
            $table->string('unindexed_value')->nullable();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('perf_explain_probe');

        parent::tearDown();
    }

    public function test_it_captures_indexed_read_plan_without_bindings(): void
    {
        DB::table('perf_explain_probe')->insert([
            'indexed_value' => 'public',
            'unindexed_value' => 'secret@example.test',
        ]);

        $evidence = (new ExplainEvidenceTool)->capture(
            'select * from perf_explain_probe where indexed_value = ?',
            ['secret@example.test'],
            ['perf_explain_probe'],
        );

        $serialized = json_encode($evidence, JSON_THROW_ON_ERROR);

        $this->assertSame('sqlite', $evidence['driver']);
        $this->assertFalse($evidence['investigation_required']);
        $this->assertSame([], $evidence['full_scan_tables']);
        $this->assertStringContainsString('where indexed_value = ?', $evidence['sql']);
        $this->assertStringNotContainsString('secret@example.test', $serialized);
        $this->assertNotEmpty($evidence['plan']);
    }

    public function test_it_flags_obvious_full_scan_on_declared_growing_table(): void
    {
        $evidence = (new ExplainEvidenceTool)->capture(
            'select * from perf_explain_probe where unindexed_value = ?',
            ['needle'],
            ['perf_explain_probe'],
        );

        $this->assertTrue($evidence['investigation_required']);
        $this->assertSame(['perf_explain_probe'], $evidence['full_scan_tables']);
    }

    public function test_it_refuses_write_sql(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ExplainEvidenceTool)->capture(
            'update perf_explain_probe set unindexed_value = ?',
            ['nope'],
            ['perf_explain_probe'],
        );
    }
}
