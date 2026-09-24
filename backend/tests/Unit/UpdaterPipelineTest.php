<?php

namespace Tests\Unit;

use App\Domain\Updater\UpdatePipeline;
use PHPUnit\Framework\TestCase;

class UpdaterPipelineTest extends TestCase
{
    public function test_backup_precedes_maintenance_and_extract(): void
    {
        $backup=array_search('backup_database',UpdatePipeline::STAGES,true);
        $maintenance=array_search('maintenance_mode',UpdatePipeline::STAGES,true);
        $extract=array_search('extract_release',UpdatePipeline::STAGES,true);

        $this->assertIsInt($backup);
        $this->assertIsInt($maintenance);
        $this->assertIsInt($extract);
        $this->assertLessThan($maintenance,$backup);
        $this->assertLessThan($extract,$maintenance);
    }
}
