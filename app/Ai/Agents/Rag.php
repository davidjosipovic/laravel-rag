<?php

namespace App\Ai\Agents;

use App\Models\Chunk;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

/**
 * A low temperature keeps the answers close to the retrieved passages. The token limit keeps
 * a model that starts repeating itself from running into the request timeout.
 */
#[MaxTokens(600)]
#[Temperature(0.2)]
class Rag implements Agent, Conversational, HasProviderOptions
{
    use Promptable, RemembersConversations;

    /**
     * The exact reply for medical questions the knowledge base cannot answer.
     */
    public const string NOT_AVAILABLE = 'Nažalost, ta informacija nije dostupna u bazi znanja. Obratite se liječniku ili ljekarniku.';

    /**
     * Below this share of answer words found in the passages, an answer is treated as made up.
     * Measured on the test questions: correct answers scored at least 0.67 (an English answer to
     * Croatian passages 0.43), a made-up list of side effects 0.25.
     */
    public const float MIN_GROUNDING = 0.35;

    /**
     * When the model answers but also says the answer is not available, it is unsure; the answer is
     * only kept when it closely follows the passages. Correct answers of this kind scored at least 0.89,
     * wrong ones at most 0.70.
     */
    public const float MIN_GROUNDING_WHEN_UNSURE = 0.75;

    /**
     * Word stems of the closing advice the model adds, which never come from the passages.
     */
    private const array ADVICE_STEMS = ['liječ', 'ljeka', 'obrat', 'hitno', 'hitni', 'sluča', 'pozov', 'nazov', 'uvije', 'proce', 'indiv', 'konzu', 'savje'];

    /**
     * @param  Collection<int, Chunk>  $chunks  Knowledge base passages retrieved for the current question.
     */
    public function __construct(private Collection $chunks = new Collection) {}

    /**
     * The rules come after the passages, because the small local model follows the last instructions most reliably.
     */
    public function instructions(): string
    {
        $role = 'Ti si farmaceutski asistent za onkološke lijekove i terapije. Odgovaraš isključivo na temelju '.
            'odlomaka iz baze znanja, nikada iz vlastitog znanja.';

        if ($this->chunks->isEmpty()) {
            return $role."\n\n".
                'Za ovu poruku u bazi znanja nije pronađen nijedan odlomak.'."\n\n".
                'Pravila:'."\n".
                '- Ako je poruka pozdrav, zahvala ili pitanje o tome što možeš raditi, odgovori kratko i ljubazno.'."\n".
                '- Za SVE ostale poruke odgovori točno ovom rečenicom i ničim više: "'.self::NOT_AVAILABLE.'"';
        }

        return $role."\n\n".$this->context()."\n\n".
            'Pravila:'."\n".
            '- Naslov iza oznake — kaže na koji se lijek ili temu odlomak odnosi. Podatak iz odlomka pripiši samo '.
            'lijeku iz njegovog naslova i ne miješaj podatke različitih lijekova.'."\n".
            '- Ako pitanje sadrži tvrdnju koju odlomci opovrgavaju ili ne potvrđuju (npr. da se lijek uzima kao tableta, '.
            'da nešto uzrokuje bolest, da se lijek daje određenoj skupini), ne odbijaj pitanje: reci da tvrdnja nije '.
            'točna i navedi što o tome piše u odlomcima.'."\n".
            '- Ako odlomci odgovaraju samo na dio pitanja, odgovori na taj dio.'."\n".
            '- Samo ako nijedan odlomak ne sadrži podatak o onome što se pita (isti lijek ili postupak i ista tema, '.
            'npr. nuspojave, doza, način primjene), odgovori točno ovom rečenicom i ničim više: "'.self::NOT_AVAILABLE.'" '.
            'Tu rečenicu koristi samo umjesto odgovora, nikada zajedno s odgovorom.'."\n".
            '- Nikada ne dodaji podatke kojih nema u odlomcima, ni djelomično.'."\n".
            '- Na poruke koje nisu medicinsko pitanje (npr. pozdrav, zahvala) odgovori kratko.'."\n".
            '- Odgovaraj sažeto: izravno, u nekoliko rečenica ili kratkoj listi, bez uvoda i ponavljanja pitanja.'."\n".
            '- Brojeve, doze, vremenske rokove i uvjete (npr. "tri ili više od", "najviše 72 sata") prenesi točno kako '.
            'pišu u izvoru. Kad izvor uz popis navodi uvjet (npr. koliko stavki mora biti ispunjeno), uvijek navedi i taj uvjet.'."\n".
            '- Ne daj osobne savjete ni dijagnoze. Završi jednom kratkom rečenicom koja upućuje na liječnika ili '.
            'ljekarnika, a za hitne slučajeve na broj 112.';
    }

    /**
     * Clean up the model's reply, or return null when the knowledge base has no answer.
     *
     * The model sometimes answers and then appends the not available sentence anyway, and it
     * occasionally makes up an answer the passages don't contain. Both cases are caught by
     * checking how much of the answer actually comes from the passages.
     */
    public function reply(string $text): ?string
    {
        $firstSentence = Str::before(self::NOT_AVAILABLE, '.').'.';
        $isUnsure = str_contains($text, $firstSentence);

        $answer = trim(preg_replace('/\s+/', ' ', str_replace([self::NOT_AVAILABLE, $firstSentence], '', $text)));

        if ($answer === '') {
            return null;
        }

        if ($this->chunks->isEmpty()) {
            return $isUnsure ? null : $answer;
        }

        $grounding = $this->grounding($answer);
        $minimum = $isUnsure ? self::MIN_GROUNDING_WHEN_UNSURE : self::MIN_GROUNDING;

        return $grounding === null || $grounding >= $minimum ? $answer : null;
    }

    /**
     * The share of the answer's word stems that also appear in the passages, or null for an answer
     * too short to judge. Stems (the first five letters) make different Croatian word forms match.
     */
    private function grounding(string $answer): ?float
    {
        $answerStems = array_diff($this->stems($answer), self::ADVICE_STEMS);

        if ($answerStems === []) {
            return null;
        }

        $passageStems = $this->stems($this->chunks->map(fn (Chunk $chunk): string => $chunk->heading.' '.$chunk->content)->implode(' '));

        return count(array_intersect($answerStems, $passageStems)) / count($answerStems);
    }

    /**
     * @return array<int, string>
     */
    private function stems(string $text): array
    {
        preg_match_all('/\p{L}{5,}/u', mb_strtolower($text), $matches);

        return array_values(array_unique(array_map(fn (string $word): string => mb_substr($word, 0, 5), $matches[0])));
    }

    /**
     * Disable Qwen3's thinking mode on the local model: it roughly doubles response time,
     * and answering from retrieved passages doesn't need it. The repeat penalty stops the
     * model from occasionally repeating the same words until it hits the token limit.
     *
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array
    {
        if ($provider !== 'local') {
            return [];
        }

        return [
            'chat_template_kwargs' => ['enable_thinking' => false],
            'repeat_penalty' => 1.1,
        ];
    }

    /**
     * Format the retrieved passages for the system prompt, so they are not stored in the conversation history.
     * The heading tells the model which medicine a passage is about, so it does not mix up neighbouring ones.
     */
    private function context(): string
    {
        return "Odlomci iz baze znanja:\n\n".$this->chunks
            ->map(fn (Chunk $chunk, int $index): string => '['.($index + 1).'] '.$chunk->document->title
                .($chunk->heading ? ' — '.$chunk->heading : '')."\n".$chunk->content)
            ->implode("\n\n");
    }
}
