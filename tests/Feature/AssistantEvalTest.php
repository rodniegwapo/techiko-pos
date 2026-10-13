<?php

namespace Tests\Feature;

use App\Ai\Agents\AppAssistantAgent;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\User;
use App\Services\Ai\AssistantEval;
use App\Services\Ai\AssistantTools;
use App\Services\Ai\GuideLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** The question set and `php artisan assistant:eval`, which scores the AI assistant's answers. */
class AssistantEvalTest extends TestCase
{
    use RefreshDatabase;

    private function questions(): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/assistant-questions.json')), true)['questions'];
    }

    private function owner(): User
    {
        $domain = Domain::query()->create([
            'name' => 'Eval Org', 'name_slug' => 'eval-org-'.Str::lower(Str::random(8)),
            'settings' => ['features' => ['ai' => true, 'finance' => true]],
        ]);
        $store = InventoryLocation::query()->create([
            'domain' => $domain->name_slug, 'name' => 'Main', 'code' => Str::upper(Str::random(8)),
            'type' => 'store', 'is_active' => true, 'is_default' => true,
        ]);

        return User::factory()->create(['domain' => $domain->name_slug, 'is_super_user' => true, 'location_id' => $store->id]);
    }

    public function test_answers_are_scored_by_tools_guides_and_wording(): void
    {
        $reply = fn (array $calls, string $answer = 'Open Inventory → Dashboard.') => [
            'answer' => $answer,
            'tools_called' => array_map(fn ($c) => ['name' => $c[0], 'arguments' => $c[1] ?? []], $calls),
        ];

        $this->assertSame([], AssistantEval::problems(['guide' => 'void-sale'], $reply([['search_guides'], ['get_guide', ['key' => 'void-sale']]])));
        $this->assertSame(['did not open the guide void-sale (opened find-receipt)'], AssistantEval::problems(['guide' => 'void-sale'], $reply([['get_guide', ['key' => 'find-receipt']]])));
        $this->assertSame([], AssistantEval::problems(['any_tool' => ['low_stock', 'stock_levels']], $reply([['stock_levels']])));
        $this->assertSame(['used none of: sales_summary'], AssistantEval::problems(['any_tool' => ['sales_summary']], $reply([])));
        $this->assertSame(['should not use propose_update_product'], AssistantEval::problems(['no_tool' => ['propose_update_product']], $reply([['propose_update_product']])));
        $this->assertSame([], AssistantEval::problems(['answer_mentions' => ['inventory']], $reply([])));
        $this->assertSame(['answer does not mention "Money movement"'], AssistantEval::problems(['answer_mentions' => ['Money movement']], $reply([])));
    }

    public function test_the_question_set_names_only_real_guides_and_tools(): void
    {
        $owner = $this->owner();
        $tools = array_map(
            fn ($tool) => $tool->name(),
            (new AssistantTools(app(GuideLibrary::class), $owner, Domain::query()->where('name_slug', $owner->domain)->sole(), null))->tools(),
        );
        $guides = array_keys(app(GuideLibrary::class)->all());

        $questions = $this->questions();
        $this->assertGreaterThanOrEqual(50, count($questions));
        $this->assertSame(count($questions), count(array_unique(array_column($questions, 'id'))), 'question ids are unique');

        foreach ($questions as $q) {
            $expect = $q['expect'] ?? [];
            $this->assertNotSame([], $expect, "{$q['id']} expects nothing");
            if (isset($expect['guide'])) {
                $this->assertContains($expect['guide'], $guides, "{$q['id']}: unknown guide");
            }
            foreach ([...($expect['any_tool'] ?? []), ...($expect['no_tool'] ?? [])] as $tool) {
                $this->assertContains($tool, $tools, "{$q['id']}: unknown tool {$tool}");
            }
        }
    }

    public function test_the_command_reports_each_answer_and_the_pass_rate(): void
    {
        config(['services.app_assistant.provider' => 'gemini', 'ai.providers.gemini.key' => 'test-key']);
        // A fake AI that answers without calling any tool.
        AppAssistantAgent::fake(['Sorry, I can only help with Techiko POS.', 'Sorry, I can only help with Techiko POS.']);
        $owner = $this->owner();

        $this->artisan('assistant:eval', ['--user' => $owner->email, '--only' => ['howto-void-sale', 'gap-off-topic']])
            ->expectsOutputToContain('Asking 2 questions as '.$owner->email)
            ->expectsOutputToContain('did not open the guide void-sale')
            ->expectsOutputToContain('Passed 1 of 2 answered (50%).')
            ->assertSuccessful();

        AppAssistantAgent::fake(['No.']);
        $this->artisan('assistant:eval', ['--user' => $owner->email, '--only' => ['howto-void-sale'], '--min' => 90])
            ->assertFailed();
    }

    public function test_the_command_needs_a_business_user(): void
    {
        $this->artisan('assistant:eval', ['--user' => 'nobody@example.com'])
            ->expectsOutputToContain('Give --user=')
            ->assertFailed();
    }
}
