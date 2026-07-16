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

// ============================================================
// 3. Monthly attendance report -> AI-drafted narrative + insights
// ============================================================

const GEMINI_REPORT_SCHEMA = [
    'type' => 'OBJECT',
    'properties' => [
        'narrative' => ['type' => 'STRING'],
        'insights' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
    ],
    'required' => ['narrative', 'insights'],
];

/**
 * Ask Gemini to draft the CAO-facing narrative memo and the flagged-insight
 * bullets from the already-computed period statistics. The model never sees
 * raw attendance rows, only the aggregated figures the deterministic
 * generator in includes/analysis.php would otherwise use — so this is a
 * strict "write it up in prose" task, not a data-analysis one, and it fails
 * safe (caller falls back to the template generator on error).
 *
 * @return array{ok:bool, narrative:?string, insights:?array, error:?string}
 */
function gemini_generate_report_narrative(array $stats, array $deptStats, array $flagged, array $forecast, string $periodLabel, float $target): array
{
    $facts = [
        'period' => $periodLabel,
        'district_target_attendance_pct' => $target,
        'overall' => [
            'attendance_rate_pct' => $stats['attendanceRate'],
            'punctuality_rate_pct' => $stats['punctualityRate'],
            'absenteeism_rate_pct' => $stats['absenteeismRate'],
            'total_staff' => $stats['totalStaff'],
            'logged_staff_days' => $stats['total'],
            'working_days' => $stats['workDays'],
        ],
        'departments' => array_map(fn($d) => [
            'name' => $d['name'],
            'staff_count' => $d['staff_count'],
            'attendance_rate_pct' => round($d['rate'] * 100, 1),
            'punctuality_rate_pct' => round($d['punctuality'] * 100, 1),
        ], $deptStats),
        'flagged_staff_count' => count($flagged),
        'flagged_staff' => array_map(fn($f) => [
            'name' => $f['name'], 'department' => $f['dept'], 'late_count' => $f['late'], 'absent_count' => $f['absent'],
        ], array_slice($flagged, 0, 10)),
        'forecast' => $forecast,
    ];

    $prompt = <<<PROMPT
You are drafting a monthly staff attendance report for the Human Resource
Management Unit of Mbarara District Local Government, addressed to the
Chief Administrative Officer.

You are given pre-computed attendance statistics as JSON (below) — do not
invent, recompute, or contradict any figure in it. Use only these numbers.

Write:
1. "narrative": a formal 3-5 sentence prose memo body (no salutation, no
   sign-off — those are added by the page template) covering: the headline
   attendance/punctuality/absenteeism figures versus the district target,
   which department performed best/worst, how many staff were flagged for
   policy breaches, and the forecast trend if available. Professional
   Ugandan public-service register tone.
2. "insights": 2-4 short scannable bullet observations (each under 25
   words) a CAO would want called out — e.g. a department trending low, a
   worsening/improving trend, or a concentration of flagged staff. If
   nothing notable stands out beyond the narrative, return a single bullet
   saying so.

Statistics:
PROMPT . "\n" . json_encode($facts, JSON_PRETTY_PRINT);

    $result = gemini_generate_structured($prompt, GEMINI_REPORT_SCHEMA);
    if (!$result['ok'] || !isset($result['data']['narrative'], $result['data']['insights'])) {
        return ['ok' => false, 'narrative' => null, 'insights' => null, 'error' => $result['error'] ?? 'No narrative in the model response.'];
    }
    return ['ok' => true, 'narrative' => $result['data']['narrative'], 'insights' => $result['data']['insights'], 'error' => null];
}
