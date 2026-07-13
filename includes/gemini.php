<?php
/**
 * Vision/text-model client used by the two AI-assisted document-import
 * pipelines: the paper attendance register backfill, and the mass staff
 * nominal-roll import. Both ask Gemini for a strict, structured
 * transcription — never a guess. Anything the model can't read confidently
 * comes back null and is left for the HR reviewer to fill in on the
 * relevant review screen; nothing here writes to the database directly.
 */

require_once __DIR__ . '/../config/database.php'; // pulls in config/secrets.php

define('GEMINI_MODEL', getenv('GEMINI_MODEL') ?: 'gemini-flash-lite-latest');
define('GEMINI_ENDPOINT', 'https://generativelanguage.googleapis.com/v1beta/models/' . GEMINI_MODEL . ':generateContent');

/**
 * Low-level call: send a prompt (plus an optional image/PDF) and get back
 * a JSON object matching $responseSchema, or a clear error.
 *
 * @return array{ok:bool, data:?array, raw:string, error:?string}
 */
function gemini_generate_structured(string $promptText, array $responseSchema, ?string $filePath = null, ?string $mimeType = null): array
{
    $apiKey = getenv('GEMINI_API_KEY');
    if (!$apiKey) {
        return ['ok' => false, 'data' => null, 'raw' => '', 'error' => 'GEMINI_API_KEY is not configured.'];
    }

    $parts = [['text' => $promptText]];
    if ($filePath !== null) {
        if (!is_readable($filePath)) {
            return ['ok' => false, 'data' => null, 'raw' => '', 'error' => 'Uploaded file could not be read.'];
        }
        $parts[] = ['inline_data' => ['mime_type' => $mimeType, 'data' => base64_encode(file_get_contents($filePath))]];
    }

    $payload = [
        'contents' => [['parts' => $parts]],
        'generationConfig' => [
            'temperature' => 0,
            'responseMimeType' => 'application/json',
            'responseSchema' => $responseSchema,
        ],
    ];

    $ch = curl_init(GEMINI_ENDPOINT);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-goog-api-key: ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 90,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return ['ok' => false, 'data' => null, 'raw' => '', 'error' => 'Network error contacting Gemini: ' . $curlError];
    }
    if ($httpCode !== 200) {
        return ['ok' => false, 'data' => null, 'raw' => (string) $response, 'error' => "Gemini API returned HTTP {$httpCode}: " . substr((string) $response, 0, 400)];
    }

    $decoded = json_decode($response, true);
    $text = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? null;
    if (!$text) {
        return ['ok' => false, 'data' => null, 'raw' => (string) $response, 'error' => 'Gemini response did not contain the expected content.'];
    }

    $structured = json_decode($text, true);
    if (!is_array($structured)) {
        return ['ok' => false, 'data' => null, 'raw' => $text, 'error' => 'Could not parse a structured result from the model response.'];
    }

    return ['ok' => true, 'data' => $structured, 'raw' => $response, 'error' => null];
}

// ============================================================
// 1. Paper attendance register → structured rows
// ============================================================

const GEMINI_REGISTER_PROMPT = <<<PROMPT
You are transcribing a single page of a handwritten or printed paper staff
attendance register for a Ugandan district local government office.

Read the page carefully and return every row you can find. For each row,
capture the staff member's name exactly as written (including apparent
spelling variants — do not "correct" or normalise it), and the check-in
time, check-out time, and any remarks column if present.

Rules:
- If a field is illegible, smudged, or simply blank, return null for that
  field. Never invent, guess, or estimate a value you are not confident
  about — a human will review and fill in anything you leave null.
- Normalise times you ARE confident about to 24-hour HH:MM format.
- If the page header shows a date for the register, return it as
  register_date in YYYY-MM-DD format if you can determine the year;
  otherwise null.
- Skip rows that are entirely blank (no name at all).
PROMPT;

const GEMINI_REGISTER_SCHEMA = [
    'type' => 'OBJECT',
    'properties' => [
        'register_date' => ['type' => 'STRING', 'nullable' => true],
        'rows' => [
            'type' => 'ARRAY',
            'items' => [
                'type' => 'OBJECT',
                'properties' => [
                    'name' => ['type' => 'STRING'],
                    'check_in' => ['type' => 'STRING', 'nullable' => true],
                    'check_out' => ['type' => 'STRING', 'nullable' => true],
                    'remarks' => ['type' => 'STRING', 'nullable' => true],
                    'confidence' => ['type' => 'STRING', 'enum' => ['high', 'medium', 'low']],
                ],
                'required' => ['name', 'confidence'],
            ],
        ],
    ],
    'required' => ['rows'],
];

/**
 * @return array{ok:bool, register_date:?string, rows:array, raw:string, error:?string}
 */
function gemini_extract_register(string $imagePath, string $mimeType): array
{
    $result = gemini_generate_structured(GEMINI_REGISTER_PROMPT, GEMINI_REGISTER_SCHEMA, $imagePath, $mimeType);
    if (!$result['ok'] || !isset($result['data']['rows'])) {
        return ['ok' => false, 'register_date' => null, 'rows' => [], 'raw' => $result['raw'], 'error' => $result['error'] ?? 'No rows found in the model response.'];
    }
    return [
        'ok' => true,
        'register_date' => $result['data']['register_date'] ?? null,
        'rows' => $result['data']['rows'],
        'raw' => $result['raw'],
        'error' => null,
    ];
}

// ============================================================
// 2. Staff nominal roll (Excel/Word/PDF/photo) → structured rows
// ============================================================

const GEMINI_STAFF_LIST_PROMPT = <<<PROMPT
You are extracting a staff nominal roll / establishment list for a Ugandan
district local government office, from either a document's raw text or a
photographed/scanned page.

For every person listed, return their full name, department (as written),
designation/job title if shown, and staff number if shown.

Rules:
- If a field is missing, unclear, or not present for a given person,
  return null for that field. Never invent or guess a value.
- Do not "correct" or normalise names or department names — preserve them
  as written; matching against the existing establishment happens
  separately, after extraction.
- Skip rows that are entirely blank, or that are clearly headers/titles
  rather than a person (e.g. "Name", "Department", a page title).
PROMPT;

const GEMINI_STAFF_LIST_SCHEMA = [
    'type' => 'OBJECT',
    'properties' => [
        'rows' => [
            'type' => 'ARRAY',
            'items' => [
                'type' => 'OBJECT',
                'properties' => [
                    'full_name' => ['type' => 'STRING'],
                    'department' => ['type' => 'STRING', 'nullable' => true],
                    'designation' => ['type' => 'STRING', 'nullable' => true],
                    'staff_no' => ['type' => 'STRING', 'nullable' => true],
                ],
                'required' => ['full_name'],
            ],
        ],
    ],
    'required' => ['rows'],
];

/**
 * @return array{ok:bool, rows:array, raw:string, error:?string}
 */
function gemini_extract_staff_list(?string $filePath, ?string $mimeType, ?string $rawText): array
{
    $prompt = GEMINI_STAFF_LIST_PROMPT;
    if ($rawText !== null) {
        $prompt .= "\n\nDocument content follows (columns/rows may be separated by tabs):\n\n" . $rawText;
    }

    $result = gemini_generate_structured($prompt, GEMINI_STAFF_LIST_SCHEMA, $filePath, $mimeType);
    if (!$result['ok'] || !isset($result['data']['rows'])) {
        return ['ok' => false, 'rows' => [], 'raw' => $result['raw'], 'error' => $result['error'] ?? 'No rows found in the model response.'];
    }
    return ['ok' => true, 'rows' => $result['data']['rows'], 'raw' => $result['raw'], 'error' => null];
}
