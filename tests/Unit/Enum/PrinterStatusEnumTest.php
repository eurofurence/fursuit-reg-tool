<?php

namespace Tests\Unit\Enum;

use App\Enum\PrinterStatusEnum;
use PHPUnit\Framework\TestCase;

class PrinterStatusEnumTest extends TestCase
{
    public function test_from_qz_status_code_maps_correctly()
    {
        $this->assertEquals(PrinterStatusEnum::ONLINE, PrinterStatusEnum::fromQzStatusCode('online'));
        $this->assertEquals(PrinterStatusEnum::OFFLINE, PrinterStatusEnum::fromQzStatusCode('offline'));
        $this->assertEquals(PrinterStatusEnum::MEDIA_EMPTY, PrinterStatusEnum::fromQzStatusCode('media-empty'));
        $this->assertEquals(PrinterStatusEnum::MEDIA_JAM, PrinterStatusEnum::fromQzStatusCode('media-jam'));
        $this->assertEquals(PrinterStatusEnum::COVER_OPEN, PrinterStatusEnum::fromQzStatusCode('cover-open'));
        $this->assertEquals(PrinterStatusEnum::PAUSED, PrinterStatusEnum::fromQzStatusCode('paused'));
        $this->assertEquals(PrinterStatusEnum::UNKNOWN, PrinterStatusEnum::fromQzStatusCode('some-unknown-code'));
        $this->assertEquals(PrinterStatusEnum::UNKNOWN, PrinterStatusEnum::fromQzStatusCode(''));
    }

    public function test_requires_attention_identifies_problem_states()
    {
        // States that require attention
        $this->assertTrue(PrinterStatusEnum::OFFLINE->requiresAttention());
        $this->assertTrue(PrinterStatusEnum::ERROR->requiresAttention());
        $this->assertTrue(PrinterStatusEnum::MEDIA_EMPTY->requiresAttention());
        $this->assertTrue(PrinterStatusEnum::MEDIA_JAM->requiresAttention());
        $this->assertTrue(PrinterStatusEnum::COVER_OPEN->requiresAttention());
        $this->assertTrue(PrinterStatusEnum::PAUSED->requiresAttention());

        // States that don't require attention
        $this->assertFalse(PrinterStatusEnum::ONLINE->requiresAttention());
        $this->assertFalse(PrinterStatusEnum::BUSY->requiresAttention());
        $this->assertFalse(PrinterStatusEnum::IDLE->requiresAttention());
        $this->assertFalse(PrinterStatusEnum::UNKNOWN->requiresAttention());
    }
}
