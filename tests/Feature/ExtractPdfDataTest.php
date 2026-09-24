<?php

use App\Actions\ExtractPdfData;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

test('it extracts markdown content and metadata from docling', function () {
    Http::fake([
        '*/v1/convert/file' => Http::response([
            'document' => ['filename' => 'guide.pdf', 'md_content' => '# Guide'],
            'status' => 'success',
            'processing_time' => 1.5,
            'errors' => [],
        ]),
    ]);

    $path = tempnam(sys_get_temp_dir(), 'pdf');
    file_put_contents($path, 'fake pdf contents');

    $data = app(ExtractPdfData::class)->handle($path);

    expect($data)->toBe([
        'source_path' => $path,
        'content' => '# Guide',
        'metadata' => [
            'filename' => 'guide.pdf',
            'status' => 'success',
            'processing_time' => 1.5,
        ],
    ]);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/v1/convert/file')
        && $request->isMultipart());

    unlink($path);
});

test('it throws when the file cannot be opened', function () {
    app(ExtractPdfData::class)->handle('/path/that/does/not/exist.pdf');
})->throws(RuntimeException::class, 'Unable to open file');
