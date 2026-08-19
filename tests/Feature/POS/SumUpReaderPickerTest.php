<?php

namespace Tests\Feature\POS;

use App\Models\Machine;
use App\Models\Staff;
use App\Models\SumUpReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Terminals get swapped mid-convention - one dies, one is carried to another
 * counter - and the desk cannot wait for an admin to re-point the till, so the
 * card reader is chosen from the POS header.
 */
class SumUpReaderPickerTest extends TestCase
{
    use RefreshDatabase;

    private Machine $machine;

    private Staff $staff;

    protected function setUp(): void
    {
        parent::setUp();

        // SumUpReaderObserver registers every new reader with SumUp. These
        // readers only have to exist locally.
        Http::fake([
            '*' => Http::response(['id' => 'remote-stub'], 200),
        ]);

        $this->machine = Machine::factory()->create(['name' => 'Desk 1']);
        $this->staff = Staff::factory()->create();
    }

    private function reader(string $name): SumUpReader
    {
        return SumUpReader::create([
            'name' => $name,
            'remote_id' => 'remote-'.strtolower(str_replace(' ', '-', $name)),
            'paring_code' => 'ABCDEF',
        ]);
    }

    private function asDesk()
    {
        return $this->actingAs($this->machine, 'machine')
            ->actingAs($this->staff, 'machine-user');
    }

    #[Test]
    public function it_lists_every_reader_and_marks_the_one_this_till_uses(): void
    {
        $one = $this->reader('Alpha');
        $two = $this->reader('Bravo');

        $this->machine->update(['sumup_reader_id' => $two->id]);

        $response = $this->asDesk()->getJson(route('pos.machine.sumup-readers'));

        $response->assertOk()
            ->assertJsonPath('current_id', $two->id)
            ->assertJsonCount(2, 'readers')
            ->assertJsonPath('readers.0.name', 'Alpha')
            ->assertJsonPath('readers.0.id', $one->id)
            ->assertJsonPath('readers.1.name', 'Bravo');
    }

    #[Test]
    public function it_names_the_other_desks_already_pointing_at_a_reader(): void
    {
        $shared = $this->reader('Alpha');

        Machine::factory()->create(['name' => 'Desk 2', 'sumup_reader_id' => $shared->id]);

        $this->asDesk()
            ->getJson(route('pos.machine.sumup-readers'))
            ->assertOk()
            ->assertJsonPath('readers.0.in_use_by', ['Desk 2']);
    }

    #[Test]
    public function it_leaves_this_till_out_of_its_own_in_use_list(): void
    {
        $reader = $this->reader('Alpha');
        $this->machine->update(['sumup_reader_id' => $reader->id]);

        $this->asDesk()
            ->getJson(route('pos.machine.sumup-readers'))
            ->assertOk()
            ->assertJsonPath('readers.0.in_use_by', []);
    }

    #[Test]
    public function it_ignores_archived_desks_when_reporting_a_reader_as_in_use(): void
    {
        $reader = $this->reader('Alpha');

        Machine::factory()->create([
            'name' => 'Retired desk',
            'sumup_reader_id' => $reader->id,
            'archived_at' => now(),
        ]);

        $this->asDesk()
            ->getJson(route('pos.machine.sumup-readers'))
            ->assertOk()
            ->assertJsonPath('readers.0.in_use_by', []);
    }

    #[Test]
    public function it_points_the_till_at_the_chosen_reader(): void
    {
        $reader = $this->reader('Alpha');

        $this->asDesk()
            ->put(route('pos.machine.sumup-reader'), ['sumup_reader_id' => $reader->id])
            ->assertRedirect();

        $this->assertSame($reader->id, $this->machine->fresh()->sumup_reader_id);
    }

    #[Test]
    public function it_turns_card_payments_off_when_no_reader_is_chosen(): void
    {
        $reader = $this->reader('Alpha');
        $this->machine->update(['sumup_reader_id' => $reader->id]);

        $this->asDesk()
            ->put(route('pos.machine.sumup-reader'), ['sumup_reader_id' => null])
            ->assertRedirect();

        $this->assertNull($this->machine->fresh()->sumup_reader_id);
    }

    #[Test]
    public function it_refuses_a_reader_that_does_not_exist(): void
    {
        $this->asDesk()
            ->put(route('pos.machine.sumup-reader'), ['sumup_reader_id' => 9999])
            ->assertSessionHasErrors('sumup_reader_id');

        $this->assertNull($this->machine->fresh()->sumup_reader_id);
    }

    /**
     * The endpoint takes no machine id: a clerk chooses the terminal on their
     * own counter, and there is no reason for this screen to be able to
     * re-point the desk next door.
     */
    #[Test]
    public function it_only_ever_changes_the_till_holding_the_session(): void
    {
        $reader = $this->reader('Alpha');
        $other = Machine::factory()->create(['name' => 'Desk 2']);

        $this->asDesk()->put(route('pos.machine.sumup-reader'), ['sumup_reader_id' => $reader->id]);

        $this->assertSame($reader->id, $this->machine->fresh()->sumup_reader_id);
        $this->assertNull($other->fresh()->sumup_reader_id);
    }

    #[Test]
    public function it_keeps_a_logged_out_terminal_away_from_the_reader_list(): void
    {
        $this->getJson(route('pos.machine.sumup-readers'))->assertStatus(401);
    }
}
