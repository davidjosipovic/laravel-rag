<?php

namespace App\Ai\Agents;

use App\Ai\Tools\SearchKnowledgeBase;
use App\Models\Chunk;
use Illuminate\Database\Eloquent\Collection;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Promptable;

/**
 * The token limit keeps a model that starts repeating itself from running into the request timeout.
 */
#[MaxSteps(5)]
#[MaxTokens(600)]
class Rag implements Agent, Conversational, HasTools
{
    use Promptable, RemembersConversations;

    /**
     * The exact reply for medical questions the knowledge base cannot answer.
     */
    public const string NOT_AVAILABLE = 'Nažalost, ta informacija nije dostupna u bazi znanja. Obratite se liječniku ili ljekarniku.';

    public function __construct(private SearchKnowledgeBase $search) {}

    /**
     * A low temperature keeps the answers close to the retrieved passages. It is configurable,
     * so the evaluation can set it to 0 and get comparable runs.
     */
    public function temperature(): float
    {
        return config()->float('ai.rag.temperature');
    }

    public function instructions(): string
    {
        return 'You are a pharmaceutical assistant for oncology patients. '.
            'Decide whether to search by the kind of message, not by its topic. '.
            'Only greetings, thank-yous and questions about what you can do are answered directly, without the tool. '.
            'Every other message, whatever its topic, wording or language, and even if it is colloquial or not phrased as '.
            'a question, must be searched with the search tool first, even if you think you already know the answer '.
            'or think it is outside your domain. '.
            'Never give medical information from your own knowledge; answer only on the basis of what the tool returns. '.
            'If the tool finds nothing relevant, reply with exactly this sentence: "'.self::NOT_AVAILABLE.'" Do not guess. '.
            'Do not give personal advice or diagnoses. Always recommend a doctor or pharmacist, and in an emergency calling 112. '.
            'Always answer in Croatian. '.
            'Keep answers short and to the point: at most 5 sentences or a short bullet list. '.
            'Answer only the specific thing asked. Do not add other facts from the passages, even if related, unless the '.
            'question asks for them. Do not repeat the question or add general introductions. '.
            'In list questions, include an item only if its own passage states exactly the property asked about. '.
            'A similar but different statement does not count. '.
            'Keep abbreviations, medical terms and lab names exactly as written in the passages; never expand an '.
            'abbreviation the passage does not expand.';
    }

    /**
     * Get the tools available to the agent.
     *
     * @return Tool[]
     */
    public function tools(): iterable
    {
        return [
            $this->search,
        ];
    }

    /**
     * @return Collection<int, Chunk>
     */
    public function retrievedChunks(): Collection
    {
        return $this->search->retrievedChunks;
    }
}
