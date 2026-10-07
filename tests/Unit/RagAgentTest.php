<?php

use App\Ai\Agents\Rag;
use App\Ai\Tools\SearchKnowledgeBase;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Tests\TestCase;

pest()->extend(TestCase::class);

test('the agent searches the knowledge base with its tool', function () {
    expect(app(Rag::class)->tools())->sequence(
        fn ($tool) => $tool->toBeInstanceOf(SearchKnowledgeBase::class),
    );
});

test('the agent is told to answer in Croatian', function () {
    expect(app(Rag::class)->instructions())->toContain('Always answer in Croatian.');
});

test('answers are limited in length so a looping model cannot hit the request timeout', function () {
    expect(TextGenerationOptions::forAgent(app(Rag::class))->maxTokens)->toBe(600);
});

test('the temperature comes from the config', function () {
    config(['ai.rag.temperature' => 0.0]);

    expect(TextGenerationOptions::forAgent(app(Rag::class))->temperature)->toBe(0.0);
});
