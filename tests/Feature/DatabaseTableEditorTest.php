<?php

namespace Tests\Feature;

use App\Filament\Pages\DatabaseBrowser;
use App\Models\User;
use App\Services\Admin\DatabaseTableEditor;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;
use Tests\TestCase;

class DatabaseTableEditorTest extends TestCase
{
    use RefreshDatabase;

    private string $programs;

    private array $environment = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->programs = storage_path('framework/testing/programs-'.bin2hex(random_bytes(4)));
        foreach (['ProgramFiles', 'ProgramW6432', 'LOCALAPPDATA'] as $name) {
            $this->environment[$name] = getenv($name);
            putenv($name.'='.$this->programs);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $name => $value) {
            putenv($value === false ? $name : $name.'='.$value);
        }
        @unlink($this->programs.'/DB Browser for SQLite/DB Browser for SQLite.exe');
        @rmdir($this->programs.'/DB Browser for SQLite');
        @rmdir($this->programs);
        parent::tearDown();
    }

    // Every process is faked and an unfaked one fails the test: an earlier
    // version let 'start' run for real and Windows tried to launch the empty
    // stand-in .exe this test writes.
    private function fakeProcesses(int $workers = 0): void
    {
        Process::preventStrayProcesses();
        Process::fake(fn ($process) => Process::result(str_contains(implode(' ', (array) $process->command), 'powershell') ? (string) $workers : ''));
    }

    private function install(): string
    {
        mkdir($this->programs.'/DB Browser for SQLite', 0777, true);
        touch($file = $this->programs.'\\DB Browser for SQLite\\DB Browser for SQLite.exe');

        return $file;
    }

    public function test_without_the_editor_it_says_how_to_install_it_and_starts_nothing(): void
    {
        $this->fakeProcesses();

        $outcome = app(DatabaseTableEditor::class)->open();

        $this->assertFalse($outcome['installed']);
        $this->assertStringContainsString(DatabaseTableEditor::PACKAGE, $outcome['message']);
        Process::assertNothingRan();
    }

    public function test_a_running_bot_gets_the_database_opened_read_only(): void
    {
        $editor = $this->install();
        $database = tempnam(sys_get_temp_dir(), 'db');
        config()->set('database.connections.sqlite.database', $database);
        $this->fakeProcesses(3);

        $outcome = app(DatabaseTableEditor::class)->open();

        $this->assertTrue($outcome['opened'] && $outcome['read_only']);
        Process::assertRan(fn ($process): bool => $process->command === ['cmd.exe', '/c', 'start', '', $editor, '-R', $database]);
        @unlink($database);
    }

    public function test_a_stopped_bot_gets_the_database_opened_for_editing(): void
    {
        $editor = $this->install();
        $database = tempnam(sys_get_temp_dir(), 'db');
        config()->set('database.connections.sqlite.database', $database);
        $this->fakeProcesses(0);

        $outcome = app(DatabaseTableEditor::class)->open();

        $this->assertTrue($outcome['opened']);
        $this->assertFalse($outcome['read_only']);
        Process::assertRan(fn ($process): bool => $process->command === ['cmd.exe', '/c', 'start', '', $editor, $database]);
        @unlink($database);
    }

    public function test_the_admin_button_opens_it_only_for_someone_at_this_computer(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
        config(['services.admin.name' => 'db-admin']);
        $this->actingAs(User::factory()->create(['name' => 'db-admin', 'is_admin' => true]));
        $this->fakeProcesses();

        Livewire::test(DatabaseBrowser::class)->assertActionVisible('tables')->callAction('tables')
            ->assertNotified(app(DatabaseTableEditor::class)->open()['message']);
        Process::assertNothingRan();

        // Through the public tunnel the request arrives forwarded.
        Livewire::withHeaders(['X-Forwarded-For' => '203.0.113.7'])->test(DatabaseBrowser::class)->assertActionHidden('tables');
    }
}
