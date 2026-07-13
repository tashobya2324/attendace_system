<?php
/**
 * Vision-model client for the AI-assisted paper register backfill.
 *
 * Sends a photographed attendance register page to Gemini and asks for a
 * strict, structured transcription — never a guess. Anything the model
 * can't read confidently comes back null, is left for the HR reviewer to
 * fill in on the review screen (see public/import_review.php), and is
 * never silently written to attendance_records without a human looking at
 * it first (see public/api/import_commit.php).
 */

require_once __DIR__ . '/../config/database.php'; // pulls in config/secrets.php

define('GEMINI_MODEL', getenv('GEMINI_MODEL') ?: 'gemini-flash-lite-latest');
define('GEMINI_ENDPOINT', 'https://generativelanguage.googleapis.com/v1beta/models/' . GEMINI_MODEL . ':generateContent');

const GEMINI_EXTRACTION_PROMPT = <<<PROMPT
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

const GEMINI_RESPONSE_SCHEMA = [
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
    $apiKey = getenv('GEMINI_API_KEY');
    if (!$apiKey) {
        return ['ok' => false, 'register_date' => null, 'rows' => [], 'raw' => '', 'error' => 'GEMINI_API_KEY is not configured.'];
    }
    if (!is_readable($imagePath)) {
        return ['ok' => false, 'register_date' => null, 'rows' => [], 'raw' => '', 'error' => 'Uploaded image could not be read.'];
    }

    $imageData = base64_encode(file_get_contents($imagePath));

    $payload = [
        'contents' => [[
            'parts' => [
                ['text' => GEMINI_EXTRACTION_PROMPT],
                ['inline_data' => ['mime_type' => $mimeType, 'data' => $imageData]],
            ],
        ]],
        'generationConfig' => [
            'temperature' => 0,
            'responseMimeType' => 'application/json',
            'responseSchema' => GEMINI_RESPONSE_SCHEMA,
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
        CURLOPT_TIMEOUT => 60,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return ['ok' => false, 'register_date' => null, 'rows' => [], 'raw' => '', 'error' => 'Network error contacting Gemini: ' . $curlError];
    }
    if ($httpCode !== 200) {
        return ['ok' => false, 'register_date' => null, 'rows' => [], 'raw' => (string) $response, 'error' => "Gemini API returned HTTP {$httpCode}: " . substr((string) $response, 0, 400)];
    }

    $decoded = json_decode($response, true);
    $text = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? null;
    if (!$text) {
        return ['ok' => false, 'register_date' => null, 'rows' => [], 'raw' => (string) $response, 'error' => 'Gemini response did not contain the expected content.'];
    }

    $structured = json_decode($text, true);
    if (!is_array($structured) || !isset($structured['rows'])) {
        return ['ok' => false, 'register_date' => null, 'rows' => [], 'raw' => $text, 'error' => 'Could not parse a structured table from the model response.'];
    }

    return [
        'ok' => true,
        'register_date' => $structured['register_date'] ?? null,
        'rows' => $structured['rows'],
        'raw' => $response,
        'error' => null,
    ];
}
