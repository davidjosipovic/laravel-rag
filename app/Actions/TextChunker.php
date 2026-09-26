<?php

namespace App\Actions;

class TextChunker
{
    /**
     * Split text into chunks that respect headings, paragraphs and sentences.
     * Each chunk carries the heading path it came from (use it as metadata
     * and prepend it to the text you embed).
     *
     * @return \Generator<int, array{text: string, heading: ?string}>
     */
    public function chunk(string $text, int $maxWords = 200, int $overlapWords = 40): \Generator
    {
        if ($maxWords < 1) {
            throw new \InvalidArgumentException('Chunk size must be at least one word.');
        }

        if ($overlapWords >= $maxWords) {
            throw new \InvalidArgumentException('Overlap must be smaller than chunk size.');
        }

        foreach ($this->sections($text) as [$heading, $body]) {
            $buffer = [];
            $count = 0;

            foreach ($this->units($body, $maxWords) as $unit) {
                $n = $this->wordCount($unit);

                if ($count + $n > $maxWords && $count > 0) {
                    yield $this->make($buffer, $heading);

                    // Keep whole sentences/paragraphs from the end as overlap.
                    [$buffer, $count] = $this->tail($buffer, $overlapWords);

                    // Drop overlap if it would push the next chunk over the limit.
                    while ($buffer !== [] && $count + $n > $maxWords) {
                        $count -= $this->wordCount(array_shift($buffer));
                    }
                }

                $buffer[] = $unit;
                $count += $n;
            }

            if ($buffer !== []) {
                yield $this->make($buffer, $heading);
            }
        }
    }

    /**
     * Split on headings. Returns [headingPath, body] pairs.
     * A chunk never crosses a heading boundary.
     *
     * Besides markdown headings, a short line that is entirely bold or entirely
     * uppercase counts as a heading one level below the current markdown heading,
     * because Word documents often mark section titles (e.g. drug names) that way.
     *
     * @return list<array{?string, string}>
     */
    private function sections(string $text): array
    {
        $sections = [];
        $path = [];
        $markdownDepth = 0;
        $heading = null;
        $body = '';

        foreach ($this->split('/\R/u', $text) as $line) {
            if (preg_match('/^(#{1,6})\s+(.+)$/u', trim($line), $m)) {
                $markdownDepth = strlen($m[1]);
                $level = $markdownDepth;
                $title = $m[2];
            } elseif ($this->isPseudoHeading(trim($line))) {
                $level = $markdownDepth + 1;
                $title = trim($line);
            } else {
                $body .= $line."\n";

                continue;
            }

            if (trim($body) !== '') {
                $sections[] = [$heading, $body];
            }

            $path = array_slice($path, 0, $level - 1);
            $path[] = $this->cleanHeading($title);
            $heading = implode(' > ', $path);
            $body = '';
        }

        if (trim($body) !== '') {
            $sections[] = [$heading, $body];
        }

        return $sections;
    }

    /**
     * Break a section into the largest natural pieces that fit:
     * paragraph -> sentences -> word windows (last resort).
     * Each unit keeps its trailing separator so joining them restores formatting.
     *
     * @param  positive-int  $maxWords
     * @return list<string>
     */
    private function units(string $body, int $maxWords): array
    {
        $units = [];

        foreach ($this->split('/\n\s*\n/u', trim($body)) as $paragraph) {
            $paragraph = trim($paragraph);

            if ($paragraph === '') {
                continue;
            }

            if ($this->wordCount($paragraph) <= $maxWords) {
                $units[] = $paragraph."\n\n";

                continue;
            }

            $sentences = $this->split('/(?<=[.!?])\s+/u', $paragraph);
            $last = count($sentences) - 1;

            foreach ($sentences as $i => $sentence) {
                $sep = $i === $last ? "\n\n" : ' ';

                if ($this->wordCount($sentence) <= $maxWords) {
                    $units[] = $sentence.$sep;

                    continue;
                }

                $pieces = array_chunk($this->split('/\s+/u', $sentence), $maxWords);
                $lastPiece = count($pieces) - 1;

                foreach ($pieces as $j => $piece) {
                    $units[] = implode(' ', $piece).($j === $lastPiece ? $sep : ' ');
                }
            }
        }

        return $units;
    }

    /**
     * Take units from the end of the buffer until the overlap budget is used.
     *
     * @param  list<string>  $buffer
     * @return array{list<string>, int}
     */
    private function tail(array $buffer, int $overlapWords): array
    {
        $tail = [];
        $count = 0;

        for ($i = count($buffer) - 1; $i >= 0; $i--) {
            $n = $this->wordCount($buffer[$i]);

            if ($count + $n > $overlapWords) {
                break;
            }

            array_unshift($tail, $buffer[$i]);
            $count += $n;
        }

        return [$tail, $count];
    }

    /**
     * A short line that is entirely bold (possibly split into several bold runs)
     * or entirely uppercase.
     */
    private function isPseudoHeading(string $line): bool
    {
        $title = $this->cleanHeading($line);

        if ($title === '' || $this->wordCount($title) > 8 || preg_match('/[.:;!?]$/u', $title)) {
            return false;
        }

        if (preg_match('/^(\*\*[^*]*\*\*\s*)+$/u', $line)) {
            return true;
        }

        return preg_match_all('/\p{L}/u', $title) >= 3 && mb_strtoupper($title) === $title;
    }

    private function cleanHeading(string $heading): string
    {
        return trim(preg_replace('/\s+/u', ' ', str_replace('*', '', $heading)));
    }

    /**
     * @param  list<string>  $buffer
     * @return array{text: string, heading: ?string}
     */
    private function make(array $buffer, ?string $heading): array
    {
        return [
            'text' => trim(implode('', $buffer)),
            'heading' => $heading,
        ];
    }

    private function wordCount(string $text): int
    {
        $count = preg_match_all('/\S+/u', $text);

        if ($count === false) {
            throw $this->regexFailure();
        }

        return $count;
    }

    /**
     * @return list<string>
     */
    private function split(string $pattern, string $text): array
    {
        $parts = preg_split($pattern, $text);

        if ($parts === false) {
            throw $this->regexFailure();
        }

        return $parts;
    }

    /**
     * Regex functions fail instead of matching when the text is not valid UTF-8.
     */
    private function regexFailure(): \RuntimeException
    {
        return new \RuntimeException('Could not chunk text: '.preg_last_error_msg());
    }
}
