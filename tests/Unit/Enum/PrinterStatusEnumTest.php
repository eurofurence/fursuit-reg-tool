<?php

namespace Tests\Unit\Enum;

use App\Enum\PrinterStatusEnum;
use PHPUnit\Framework\TestCase;

class PrinterStatusEnumTest extends TestCase
{
    public function test_from_status_code_maps_correctly()
    {
        $this->assertEquals(PrinterStatusEnum::ONLINE, PrinterStatusEnum::fromStatusCode('online'));
        $this->assertEquals(PrinterStatusEnum::OFFLINE, PrinterStatusEnum::fromStatusCode('offline'));
        $this->assertEquals(PrinterStatusEnum::MEDIA_EMPTY, PrinterStatusEnum::fromStatusCode('media-empty'));
        $this->assertEquals(PrinterStatusEnum::MEDIA_JAM, PrinterStatusEnum::fromStatusCode('media-jam'));
        $this->assertEquals(PrinterStatusEnum::COVER_OPEN, PrinterStatusEnum::fromStatusCode('cover-open'));
        $this->assertEquals(PrinterStatusEnum::PAUSED, PrinterStatusEnum::fromStatusCode('paused'));
        $this->assertEquals(PrinterStatusEnum::UNKNOWN, PrinterStatusEnum::fromStatusCode('some-unknown-code'));
        $this->assertEquals(PrinterStatusEnum::UNKNOWN, PrinterStatusEnum::fromStatusCode(''));
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
