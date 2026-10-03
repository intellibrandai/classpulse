<?php

namespace Tests\Feature;

use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ImportTest extends TestCase
{
    use RefreshDatabase;

    private SchoolClass $class;

    protected function setUp(): void
    {
        parent::setUp();
        $this->class = SchoolClass::factory()->create(['name' => 'HNL 2O']);
        $this->actingAs(User::factory()->create());
    }

    private function url(string $suffix = ''): string
    {
        return '/classes/'.$this->class->id.'/import'.$suffix;
    }

    private function preview(string $csv)
    {
        return $this->post($this->url('/preview'), ['file' => UploadedFile::fake()->createWithContent('roster.csv', $csv)]);
    }

    public function test_import_page_has_file_input_and_paste_area(): void
    {
        $response = $this->get($this->url());

        $response->assertOk();
        $response->assertSee('accept=".csv,.txt"', false);
        $response->assertSee('Paste names, one per line');
        $response->assertSee('<textarea', false);
    }

    public function test_preview_page_sets_checkbox_states(): void
    {
        Student::factory()->create(['school_class_id' => $this->class->id, 'display_name' => 'Jordan Lee']);

        $response = $this->preview("name\nAlex Rivera\nJordan Lee\n\"\"\n");

        $response->assertOk();
        preg_match_all('/<input type="checkbox"[^>]*>/s', $response->getContent(), $boxes);
        $this->assertCount(3, $boxes[0]);
        $this->assertStringContainsString('checked', $boxes[0][0]);
        $this->assertStringNotContainsString('disabled', $boxes[0][0]);
        $this->assertStringNotContainsString('checked', $boxes[0][1]);
        $this->assertStringNotContainsString('disabled', $boxes[0][1]);
        $this->assertStringContainsString('disabled', $boxes[0][2]);
        $response->assertSee('name="token"', false);
        $response->assertSeeInOrder(['OK', 'WARNING', 'ERROR']);
        $response->assertSee('class="pill pill-ok">OK<', false);
        $response->assertSee('class="pill pill-warn">WARNING<', false);
        $response->assertSee('class="pill pill-error">ERROR<', false);
        $response->assertSee('Import selected students');
        $response->assertDontSee('No valid rows to import.');
    }

    public function test_commit_creates_selected_students_and_redirects_to_roster(): void
    {
        $page = $this->preview("name\nAlex Rivera\nSam Cole\n");
        preg_match('/name="token" value="([^"]+)"/', $page->getContent(), $m);

        $response = $this->post($this->url('/commit'), ['token' => $m[1], 'rows' => [1]]);

        $response->assertRedirect(url('/roster?class='.$this->class->id));
        $response->assertSessionHas('status', 'Imported 1 students.');
        $this->assertSame(['Alex Rivera'], Student::pluck('display_name')->all());
    }

    public function test_roster_page_shows_the_import_confirmation(): void
    {
        $page = $this->preview("name\nAlex Rivera\n");
        preg_match('/name="token" value="([^"]+)"/', $page->getContent(), $m);

        $this->followingRedirects()
            ->post($this->url('/commit'), ['token' => $m[1], 'rows' => [1]])
            ->assertOk()
            ->assertSee('Imported 1 students.');
    }

    public function test_unknown_token_redirects_back_with_token_error(): void
    {
        $response = $this->from($this->url())->post($this->url('/commit'), ['token' => 'unknown', 'rows' => [1]]);

        $response->assertRedirect($this->url());
        $response->assertSessionHasErrors('token');
        $this->assertSame(0, Student::count());
    }

    public function test_all_error_preview_shows_empty_state(): void
    {
        $this->preview("name\n\"\"\n")->assertOk()->assertSee('No valid rows to import.');
    }

    public function test_guests_are_redirected_to_login_and_create_nothing(): void
    {
        auth()->logout();
        $this->app['auth']->forgetGuards();

        $this->get($this->url())->assertRedirect('/login');
        $this->post($this->url('/preview'), ['pasted' => 'Alex'])->assertRedirect('/login');
        $this->post($this->url('/commit'), ['token' => 'x', 'rows' => [1]])->assertRedirect('/login');
        $this->assertSame(0, Student::count());
    }
}
