<?php

use App\Filament\Support\CsvWriter;

covers(CsvWriter::class);

/*
|--------------------------------------------------------------------------
| CSV formula / DDE injection guard
|--------------------------------------------------------------------------
|
| bookings.customer_name reaches the Reports export straight from the public
| booking wizard, and a spreadsheet runs any cell starting with = + - or @.
|
*/

test('guard neutralises every character a spreadsheet reads as a formula', function (string $payload) {
    expect(CsvWriter::guard($payload))->toBe("'".$payload);
})->with([
    '=cmd|\' /c calc\'!A1',
    '=HYPERLINK("https://evil.tld","click")',
    '+SUM(A1:A9)',
    '-2+3',
    '@SUM(A1)',
]);

// Excel strips leading whitespace before deciding whether a cell is a formula,
// so a guard testing only $value[0] would wave these through.
test('guard sees past leading whitespace and control characters', function (string $payload) {
    expect(CsvWriter::guard($payload))->toBe("'".$payload);
})->with([
    ' =cmd|\' /c calc\'!A1',
    "\t=HYPERLINK(\"https://evil.tld\",\"x\")",
    "\r\n=IMAGE(\"https://evil.tld\")",
    "  \t +SUM(A1:A9)",
]);

test('guard leaves ordinary text byte-identical', function (string $value) {
    expect(CsvWriter::guard($value))->toBe($value);
})->with([
    'Arben Krasniqi',
    'BK-2026-AB12CD',
    'Golf 7 1.6 TDI',
    'a=b',            // trigger char, but not in first position
    '',
    '   ',
    "\t",
]);

// Only free text can carry a formula; leaving other types alone is what keeps
// numeric columns (total, odometer) exact.
test('guard passes non-strings through untouched', function (mixed $value) {
    expect(CsvWriter::guard($value))->toBe($value);
})->with([
    fn () => 250,
    fn () => -12.5,
    fn () => null,
    fn () => true,
]);

test('putRow guards each cell and quotes to RFC 4180', function () {
    $handle = fopen('php://memory', 'r+');

    CsvWriter::putRow($handle, ['BK-2026-AB12CD', '=cmd|\' /c calc\'!A1', 'he said "hi"', 250]);

    rewind($handle);
    $csv = stream_get_contents($handle);
    fclose($handle);

    expect($csv)->toBe('BK-2026-AB12CD,"\'=cmd|\' /c calc\'!A1","he said ""hi""",250'."\n");
});

// PHP 8.4+ deprecates omitting fputcsv()'s $escape, and its historical default
// ('\\') is not RFC 4180 — no spreadsheet reads backslash escapes, so the
// default only ever produces output a strict parser reads differently.
test('putRow raises no deprecation and round-trips as RFC 4180', function () {
    $handle = fopen('php://memory', 'r+');
    $row = ['ends with a backslash \\', 'he said "hi"', '=cmd|\' /c calc\'!A1'];

    set_error_handler(function (int $severity, string $message): bool {
        throw new RuntimeException($message);
    }, E_DEPRECATED);

    try {
        CsvWriter::putRow($handle, $row);
    } finally {
        restore_error_handler();
    }

    rewind($handle);
    $parsed = fgetcsv($handle, escape: '');
    fclose($handle);

    expect($parsed)->toBe(['ends with a backslash \\', 'he said "hi"', '\'=cmd|\' /c calc\'!A1']);
});
