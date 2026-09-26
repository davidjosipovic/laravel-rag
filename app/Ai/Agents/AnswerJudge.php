<?php

namespace App\Ai\Agents;

use App\Enums\EvaluationGrade;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * Grades a RAG answer against the expected answer from the test set, so evaluation runs are
 * graded the same way every time instead of by hand. Temperature 0 keeps the grading repeatable.
 */
#[Temperature(0)]
class AnswerJudge implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return 'Ocjenjuješ odgovore sustava koji odgovara na pitanja o onkološkim lijekovima i terapijama na temelju '.
            'odlomaka iz baze znanja. Dobit ćeš pitanje, kategoriju pitanja, očekivani odgovor, odgovor sustava i '.
            'odlomke koje je sustav koristio.'."\n\n".
            'Ocjene:'."\n".
            '- tocno: odgovor sadrži bitne podatke iz očekivanog odgovora i nema netočnih tvrdnji. Drugačiji redoslijed, '.
            'drugačije riječi i dodatni točni detalji iz odlomaka su u redu.'."\n".
            '- djelomicno: sve tvrdnje su točne, ali nedostaje bitan dio očekivanog odgovora (npr. uvjet, doza, jedan od '.
            'navedenih lijekova ili koraka).'."\n".
            '- netocno: odgovor sadrži barem jednu netočnu ili izmišljenu tvrdnju, pripisuje podatak krivom lijeku, '.
            'prihvaća krivu premisu ili ne odgovara na pitanje.'."\n\n".
            'Pravila:'."\n".
            '- Tvrdnju smatraj izmišljenom ako je nema ni u očekivanom odgovoru ni u odlomcima.'."\n".
            '- Kod popisa (npr. koji lijekovi) svaki dodani lijek koji nije u očekivanom odgovoru čini odgovor netočnim, '.
            'a svaki izostavljeni čini ga djelomičnim.'."\n".
            '- Kategorija "kriva_premisa": točno je samo ako odgovor ispravi krivu tvrdnju iz pitanja.'."\n".
            '- Kategorija "neodgovorivo": točno je ako odgovor kaže da informacija nije dostupna ili da je izvor ne '.
            'obuhvaća; svaki sadržajan odgovor je netočan.'."\n".
            '- Završnu rečenicu koja upućuje na liječnika, ljekarnika ili broj 112 ne ocjenjuj.'."\n".
            '- U napomeni u jednoj kratkoj rečenici na hrvatskom navedi što nedostaje ili što je netočno. '.
            'Za točan odgovor napomena je prazna.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'grade' => $schema->string()->enum(EvaluationGrade::judgeable())->required(),
            'note' => $schema->string()->required(),
        ];
    }
}
