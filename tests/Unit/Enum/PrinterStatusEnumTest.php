<?php

namespace Tests\Unit\Enum;

use App\Enum\PrinterStatusEnum;
use PHPUnit\Framework\TestCase;

class PrinterStatusEnumTest extends TestCase
{
    public function test_from_qz_status_code_maps_correctly()
    {
        $this->assertEquals(PrinterStatusEnum::Online, PrinterStatusEnum::fromQzStatusCode('online'));
        $this->assertEquals(PrinterStatusEnum::Offline, PrinterStatusEnum::fromQzStatusCode('offline'));
        $this->assertEquals(PrinterStatusEnum::MediaEmpty, PrinterStatusEnum::fromQzStatusCode('media-empty'));
        $this->assertEquals(PrinterStatusEnum::MediaJam, PrinterStatusEnum::fromQzStatusCode('media-jam'));
        $this->assertEquals(PrinterStatusEnum::CoverOpen, PrinterStatusEnum::fromQzStatusCode('cover-open'));
        $this->assertEquals(PrinterStatusEnum::Paused, PrinterStatusEnum::fromQzStatusCode('paused'));
        $this->assertEquals(PrinterStatusEnum::Unknown, PrinterStatusEnum::fromQzStatusCode('some-unknown-code'));
        $this->assertEquals(PrinterStatusEnum::Unknown, PrinterStatusEnum::fromQzStatusCode(''));
    }

    public function test_requires_attention_identifies_problem_states()
    {
        // States that require attention
        $this->assertTrue(PrinterStatusEnum::Offline->requiresAttention());
        $this->assertTrue(PrinterStatusEnum::Error->requiresAttention());
        $this->assertTrue(PrinterStatusEnum::MediaEmpty->requiresAttention());
        $this->assertTrue(PrinterStatusEnum::MediaJam->requiresAttention());
        $this->assertTrue(PrinterStatusEnum::CoverOpen->requiresAttention());

        // States that don't require attention
        $this->assertFalse(PrinterStatusEnum::Online->requiresAttention());
        $this->assertFalse(PrinterStatusEnum::Busy->requiresAttention());
        $this->assertFalse(PrinterStatusEnum::Paused->requiresAttention());
        $this->assertFalse(PrinterStatusEnum::Unknown->requiresAttention());
    }
}
