<?php

namespace App\Ai\Agents;

use App\Models\Chunk;
use Illuminate\Database\Eloquent\Collection;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

/**
 * A low temperature keeps the answers close to the retrieved passages.
 */
#[Temperature(0.2)]
class Rag implements Agent, Conversational, HasProviderOptions
{
    use Promptable, RemembersConversations;

    /**
     * @param  Collection<int, Chunk>  $chunks  Knowledge base passages retrieved for the current question.
     */
    public function __construct(private Collection $chunks = new Collection) {}

    public function instructions(): string
    {
        return 'Farmaceutski asistent specijaliziran za onkološke lijekove i terapije. '.
            'Na medicinska pitanja odgovaraj isključivo na temelju odlomaka iz baze znanja navedenih na kraju ovih uputa. '.
            'Ako odlomci ne sadrže odgovor, jasno reci da ta informacija nije dostupna u bazi znanja — ne nagađaj '.
            'i ne odgovaraj iz vlastitog znanja. '.
            'Na poruke koje nisu medicinsko pitanje (npr. pozdrav, zahvala, pitanje o tome što možeš raditi) odgovori kratko. '.
            'Odgovaraj sažeto: izravno odgovori na pitanje u nekoliko rečenica ili kratkoj listi, bez uvoda, '.
            'ponavljanja pitanja i objašnjavanja koja nisu tražena. '.
            'Brojeve, doze, vremenske rokove i uvjete (npr. "tri ili više od", "najviše 72 sata") prenesi točno kako '.
            'pišu u izvoru. Kad izvor uz popis navodi uvjet (npr. koliko stavki mora biti ispunjeno), '.
            'uvijek navedi i taj uvjet, a ne samo popis. '.
            'Ne daj osobne savjete ni dijagnoze. Završi jednom kratkom rečenicom koja upućuje na liječnika ili '.
            'ljekarnika, a za hitne slučajeve na broj 112.'.
            "\n\n".$this->context();
    }

    /**
     * Disable Qwen3's thinking mode on the local model: it roughly doubles response time,
     * and answering from retrieved passages doesn't need it.
     *
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array
    {
        if ($provider !== 'local') {
            return [];
        }

        return ['chat_template_kwargs' => ['enable_thinking' => false]];
    }

    /**
     * Format the retrieved passages for the system prompt, so they are not stored in the conversation history.
     */
    private function context(): string
    {
        if ($this->chunks->isEmpty()) {
            return 'Odlomci iz baze znanja: baza znanja nije pronašla ništa relevantno za ovu poruku.';
        }

        return "Odlomci iz baze znanja:\n\n".$this->chunks
            ->map(fn (Chunk $chunk, int $index): string => '['.($index + 1).'] '.$chunk->document->title."\n".$chunk->content)
            ->implode("\n\n");
    }
}
