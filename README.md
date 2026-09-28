# Laravel RAG

A pharmaceutical assistant for oncology medicines and therapies. It answers questions strictly from the
documents in its knowledge base (RAG, *retrieval-augmented generation*) and returns the passages each
answer is based on. When the documents don't contain the answer, it says so instead of making one up.

## How it works

### Document processing

Documents are uploaded through the admin panel. A chain of queued jobs then processes each one step by
step:

```
upload (Filament)
  → ProcessDocument   Docling converts the PDF/DOCX to markdown (with OCR and tables)
  → ChunkDocument     Docling's HybridChunker splits the markdown into passages by heading
  → EmbedChunks       each passage gets a vector (qwen3-embedding, 1024 dimensions)
```

- **Headings from Word.** Before chunking, short lines that are entirely bold or entirely uppercase
  are turned into markdown headings, because Word documents often mark medicine names that way.
- **The heading is stored with the passage.** Each passage keeps its heading path (e.g.
  `Lijekovi > Docetaksel`). The heading is prepended to the text before embedding, and the model sees
  it alongside the passage, so it doesn't mix up neighbouring medicines.
- **The tokenizer matches the embedding model.** The chunker uses the embedding model's tokenizer
  (`Qwen/Qwen3-Embedding-0.6B`, at most 600 tokens, about 200 words). Docling's default English
  tokenizer splits Croatian text into chunks a third of the size, cut mid-sentence.

The document status follows the steps: `uploaded → extracted → chunking → chunked → embedding → embedded`.

### Answering a question

```
question
  → hybrid search       full-text (PostgreSQL) + vector (pgvector), 50 candidates each
  → RRF                 both lists merged with reciprocal rank fusion, 30 candidates
  → reranker            qwen3-reranker keeps at most 5 passages above the relevance threshold
  → model               answers only from those passages
  → answer check        rejects an answer that relies too little on the passages
```

- **Search** (`app/Actions/HybridSearch/`):
  - Full-text search combines words with OR and matches by prefix. PostgreSQL has no Croatian
    stemmer, so a likely case ending is stripped from each word (*docetaksela* → `docetakse:*`).
  - Vector search catches questions phrased differently from the documents.
  - The reranker decides what is actually relevant.
- **Follow-up questions.** For a follow-up in a conversation, the previous question is searched too,
  so "a koje su nuspojave?" ("and what are the side effects?") stays tied to the medicine asked about
  before.
- **Agent** (`app/Ai/Agents/Rag.php`):
  - It gets the passages in the system prompt, with the rules placed after them, because the small
    local model follows the last instructions most reliably.
  - After answering, it measures how much of the answer's wording appears in the passages. Too little
    overlap means the answer is probably made up, so the "information not available" reply is
    returned instead.
  - The advice to consult a doctor is added in code, not by the model.

## Tech stack

- **Laravel 13, PHP 8.5**, with the Laravel AI SDK for the agent, embeddings and reranking
- **PostgreSQL + pgvector** for passages, vectors and the full-text index
- **Docling** (`docling-serve`) for document conversion and chunking
- **Local models** through an OpenAI-compatible API: Qwen3 for answers, qwen3-embedding and
  qwen3-reranker
- **Filament 5** for the admin panel, **Sanctum** for API authentication, **Scramble** for API
  documentation
- **Pest, PHPStan (Larastan), Pint** for tests, static analysis and formatting

## Getting started

You need Docker (Laravel Sail) and access to the local models.

```bash
composer install
cp .env.example .env
vendor/bin/sail up -d          # app, PostgreSQL and Docling
vendor/bin/sail artisan key:generate
vendor/bin/sail artisan migrate
vendor/bin/sail artisan make:filament-user
vendor/bin/sail artisan queue:work
```

Set the model endpoints in `.env`:

| Variable | Purpose |
|---|---|
| `LOCAL_AI_URL`, `LOCAL_AI_MODEL` | the model that answers questions |
| `LOCAL_AI_EMBEDDING_URL`, `LOCAL_AI_EMBEDDING_MODEL` | the embedding model (1024 dimensions) |
| `LOCAL_AI_RERANK_URL`, `LOCAL_AI_RERANK_MODEL` | the reranker (Jina-compatible API) |
| `LOCAL_AI_API_KEY` | the key for all local models |
| `AI_RAG_MIN_RELEVANCE` | the reranker relevance threshold (0.15 for qwen3-reranker) |
| `AI_RAG_MAX_CHUNKS` | the maximum number of passages given to the model (5) |

If you change the embedding model, also change the tokenizer in `app/Services/DoclingService.php`
and reprocess the documents.

## Usage

- **Admin panel** (`/admin`): upload documents, browse passages and manage users.
- **API**: documentation is available at `/docs/api`.

| Method | Path | Description |
|---|---|---|
| `POST` | `/api/register`, `/api/login`, `/api/logout` | authentication (Sanctum token) |
| `POST` | `/api/chat` | ask a question; pass `conversation_id` to continue a conversation |
| `GET` | `/api/chat/list` | the user's conversations |
| `GET` | `/api/chat/history/{conversation}` | messages of one conversation |
| `DELETE` | `/api/chat/{conversation}` | delete a conversation |

## Evaluation

The evaluation uses a test set of 81 questions in `storage/app/private/evaluation/questions.csv`.
Each question has an expected answer and the document that contains it. The questions are grouped
into categories:

- factual and numeric;
- comparisons and aggregations (a list across several medicines);
- lay questions;
- questions with a false premise;
- unanswerable questions.

Retrieval and answers are evaluated separately, to show which part of the system is failing:

```bash
# search only, no model: deterministic and fast
vendor/bin/sail artisan rag:evaluate-retrieval --delay=0 --label=change-name --compare=latest

# the full flow, same as POST /api/chat; answers are stored for manual review
vendor/bin/sail artisan rag:answer-test-questions --delay=0 --label=change-name
```

**`rag:evaluate-retrieval`** measures three things per question:
- **source hit**: whether at least one passage comes from the expected document;
- **coverage**: the share of the expected answer's words and numbers found in the retrieved passages.
  Words are compared by their first five letters so that Croatian case forms match, and numbers
  written as words are converted to digits. The measure is lexical, so it is meant for comparing runs
  rather than as an absolute score;
- **unanswerable questions**: whether any passages are returned for them.

**`rag:answer-test-questions`** only counts refusals automatically: refused questions that have an
answer, and answered questions that don't. Answer correctness is reviewed manually.

Every run is stored as JSON in `storage/app/private/evaluation/`. The results include the models, the
thresholds and the commit, marked `-dirty` when the code has uncommitted changes. `--delay` is only
needed for a rate-limited reranker (e.g. a Cohere trial key).

## Tests

```bash
vendor/bin/sail artisan test --compact
vendor/bin/sail composer test      # Pint, PHPStan and the tests
```
