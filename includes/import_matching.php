<?php
/**
 * Fuzzy name matching for the OCR register-import pipeline.
 *
 * The vision model returns names exactly as handwritten — misspellings,
 * missing initials, different name order. This scores each extracted name
 * against the active staff establishment so the review screen can
 * pre-select a likely match, but it never auto-commits a match on its
 * own: anything below MATCH_CONFIDENT_THRESHOLD is left for the HR
 * reviewer to pick manually from a full staff dropdown.
 */

const MATCH_CONFIDENT_THRESHOLD = 72.0; // percent similarity to pre-select a match
const MATCH_SUGGEST_THRESHOLD = 45.0;   // percent similarity to still suggest it, unselected

function normalize_name_for_match(string $name): string
{
    $name = strtolower(trim($name));
    $name = preg_replace('/[^a-z\s]/', '', $name);
    $name = preg_replace('/\s+/', ' ', $name);
    return trim($name);
}

/**
 * @param array $activeStaff each item: ['id'=>int, 'full_name'=>string]
 * @return array{staff_id: ?int, score: float, label: string}
 */
function match_staff_by_name(string $rawName, array $activeStaff): array
{
    $needle = normalize_name_for_match($rawName);
    if ($needle === '') {
        return ['staff_id' => null, 'score' => 0.0, 'label' => 'No confident match'];
    }

    $needleParts = explode(' ', $needle);
    $best = ['staff_id' => null, 'score' => 0.0];

    foreach ($activeStaff as $s) {
        $hay = normalize_name_for_match($s['full_name']);
        if ($hay === '') continue;

        // similar_text on the full strings...
        similar_text($needle, $hay, $pctWhole);

        // ...plus a token-overlap bonus, so "Tumusiime Sarah" still matches
        // "Sarah Tumusiime" even though word order differs.
        $hayParts = explode(' ', $hay);
        $overlap = count(array_intersect($needleParts, $hayParts));
        $tokenScore = $overlap > 0 ? ($overlap / max(count($needleParts), count($hayParts))) * 100 : 0;

        $score = max($pctWhole, $tokenScore);

        if ($score > $best['score']) {
            $best = ['staff_id' => (int) $s['id'], 'score' => round($score, 1)];
        }
    }

    if ($best['score'] >= MATCH_CONFIDENT_THRESHOLD) {
        $best['label'] = 'Matched (' . $best['score'] . '% similar)';
    } elseif ($best['score'] >= MATCH_SUGGEST_THRESHOLD) {
        $best['label'] = 'Uncertain — please confirm (' . $best['score'] . '% similar)';
    } else {
        $best = ['staff_id' => null, 'score' => $best['score'], 'label' => 'No confident match — select manually'];
    }

    return $best;
}
