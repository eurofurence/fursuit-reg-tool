<?php

namespace Tests\Unit\Enum;

use App\Enum\PrintJobStatusEnum;
use PHPUnit\Framework\TestCase;

class PrintJobStatusEnumTest extends TestCase
{
    public function test_can_transition_from_pending()
    {
        $status = PrintJobStatusEnum::Pending;
        
        $this->assertTrue($status->canTransitionTo(PrintJobStatusEnum::Queued));
        $this->assertTrue($status->canTransitionTo(PrintJobStatusEnum::Cancelled));
        $this->assertFalse($status->canTransitionTo(PrintJobStatusEnum::Printed));
        $this->assertFalse($status->canTransitionTo(PrintJobStatusEnum::Printing));
    }

    public function test_can_transition_from_queued()
    {
        $status = PrintJobStatusEnum::Queued;
        
        $this->assertTrue($status->canTransitionTo(PrintJobStatusEnum::Printing));
        $this->assertTrue($status->canTransitionTo(PrintJobStatusEnum::Cancelled));
        $this->assertTrue($status->canTransitionTo(PrintJobStatusEnum::Failed));
        $this->assertFalse($status->canTransitionTo(PrintJobStatusEnum::Printed));
        $this->assertFalse($status->canTransitionTo(PrintJobStatusEnum::Pending));
    }

    public function test_can_transition_from_printing()
    {
        $status = PrintJobStatusEnum::Printing;
        
        $this->assertTrue($status->canTransitionTo(PrintJobStatusEnum::Printed));
        $this->assertTrue($status->canTransitionTo(PrintJobStatusEnum::Failed));
        $this->assertFalse($status->canTransitionTo(PrintJobStatusEnum::Queued));
        $this->assertFalse($status->canTransitionTo(PrintJobStatusEnum::Cancelled));
    }

    public function test_can_transition_from_failed()
    {
        $status = PrintJobStatusEnum::Failed;
        
        $this->assertTrue($status->canTransitionTo(PrintJobStatusEnum::Retrying));
        $this->assertTrue($status->canTransitionTo(PrintJobStatusEnum::Cancelled));
        $this->assertFalse($status->canTransitionTo(PrintJobStatusEnum::Printed));
        $this->assertFalse($status->canTransitionTo(PrintJobStatusEnum::Queued));
    }

    public function test_can_transition_from_retrying()
    {
        $status = PrintJobStatusEnum::Retrying;
        
        $this->assertTrue($status->canTransitionTo(PrintJobStatusEnum::Queued));
        $this->assertTrue($status->canTransitionTo(PrintJobStatusEnum::Cancelled));
        $this->assertFalse($status->canTransitionTo(PrintJobStatusEnum::Printed));
        $this->assertFalse($status->canTransitionTo(PrintJobStatusEnum::Failed));
    }

    public function test_terminal_states_cannot_transition()
    {
        $printed = PrintJobStatusEnum::Printed;
        $cancelled = PrintJobStatusEnum::Cancelled;
        
        $this->assertTrue($printed->isTerminal());
        $this->assertTrue($cancelled->isTerminal());
        
        foreach (PrintJobStatusEnum::cases() as $status) {
            $this->assertFalse($printed->canTransitionTo($status));
            $this->assertFalse($cancelled->canTransitionTo($status));
        }
    }

    public function test_active_states()
    {
        $this->assertTrue(PrintJobStatusEnum::Queued->isActive());
        $this->assertTrue(PrintJobStatusEnum::Printing->isActive());
        $this->assertTrue(PrintJobStatusEnum::Retrying->isActive());
        
        $this->assertFalse(PrintJobStatusEnum::Pending->isActive());
        $this->assertFalse(PrintJobStatusEnum::Printed->isActive());
        $this->assertFalse(PrintJobStatusEnum::Failed->isActive());
        $this->assertFalse(PrintJobStatusEnum::Cancelled->isActive());
    }

    public function test_terminal_states()
    {
        $this->assertTrue(PrintJobStatusEnum::Printed->isTerminal());
        $this->assertTrue(PrintJobStatusEnum::Cancelled->isTerminal());
        
        $this->assertFalse(PrintJobStatusEnum::Pending->isTerminal());
        $this->assertFalse(PrintJobStatusEnum::Queued->isTerminal());
        $this->assertFalse(PrintJobStatusEnum::Printing->isTerminal());
        $this->assertFalse(PrintJobStatusEnum::Failed->isTerminal());
        $this->assertFalse(PrintJobStatusEnum::Retrying->isTerminal());
    }
}