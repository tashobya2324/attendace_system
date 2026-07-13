<?php
/**
 * Lightweight, dependency-free readers for the document formats the mass
 * staff-import feature accepts. No Composer/PhpSpreadsheet — .xlsx and
 * .docx are just zipped XML, so PHP's built-in ZipArchive + SimpleXML are
 * enough to pull out plain rows/text.
 *
 * Spreadsheets (CSV/XLSX) are tried against a direct header-based parse
 * first (fast, free, deterministic) before falling back to sending the
 * content to Gemini — see public/api/staff_import_upload.php for how these
 * are combined.
 */

/** @return array<int, array<int, string>> rows of cell strings */
function read_csv_rows(string $path): array
{
    $rows = [];
    if (($handle = fopen($path, 'r')) !== false) {
        while (($data = fgetcsv($handle)) !== false) {
            $rows[] = array_map('trim', $data);
        }
        fclose($handle);
    }
    return $rows;
}

/** @return array<int, array<int, string>> rows of cell strings, sheet 1 only */
function read_xlsx_rows(string $path): array
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return [];
    }

    // Shared strings table (most text cells reference into this rather than
    // storing the string inline).
    $shared = [];
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedXml !== false) {
        $sx = @simplexml_load_string($sharedXml);
        if ($sx !== false) {
            foreach ($sx->si as $si) {
                // A plain string has a direct <t>; rich text splits it across
                // <r><t> runs instead — concatenate those if present. (Using
                // property access rather than xpath() here deliberately:
                // xpath() with an unprefixed query doesn't match elements
                // sitting in the sheet's default XML namespace.)
                if (isset($si->t)) {
                    $text = (string) $si->t;
                } else {
                    $text = '';
                    foreach ($si->r as $run) { $text .= (string) $run->t; }
                }
                $shared[] = $text;
            }
        }
    }

    // First worksheet.
    $sheetName = null;
    foreach (['xl/worksheets/sheet1.xml'] as $candidate) {
        if ($zip->locateName($candidate) !== false) { $sheetName = $candidate; break; }
    }
    if ($sheetName === null) {
        $zip->close();
        return [];
    }
    $sheetXml = $zip->getFromName($sheetName);
    $zip->close();
    if ($sheetXml === false) {
        return [];
    }

    $sx = @simplexml_load_string($sheetXml);
    if ($sx === false) {
        return [];
    }

    $rows = [];
    foreach ($sx->sheetData->row as $row) {
        $cells = [];
        $colIndex = 0;
        foreach ($row->c as $c) {
            $ref = (string) $c['r'];
            $colIndex = $ref !== '' ? xlsx_col_letter_to_index($ref) : $colIndex;
            $type = (string) $c['t'];
            $value = isset($c->v) ? (string) $c->v : '';
            if ($type === 's' && $value !== '') {
                $value = $shared[(int) $value] ?? '';
            } elseif ($type === 'inlineStr') {
                $value = (string) ($c->is->t ?? '');
            }
            $cells[$colIndex] = trim($value);
            $colIndex++;
        }
        if (empty($cells)) continue;
        $max = max(array_keys($cells));
        $line = [];
        for ($i = 0; $i <= $max; $i++) { $line[] = $cells[$i] ?? ''; }
        $rows[] = $line;
    }
    return $rows;
}

function xlsx_col_letter_to_index(string $ref): int
{
    preg_match('/^([A-Z]+)/', $ref, $m);
    $letters = $m[1] ?? 'A';
    $index = 0;
    foreach (str_split($letters) as $ch) {
        $index = $index * 26 + (ord($ch) - 64);
    }
    return $index - 1;
}

/** Plain text extraction from a .docx, with paragraph/table structure approximated via tabs/newlines. */
function extract_docx_text(string $path): string
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return '';
    }
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();
    if ($xml === false) {
        return '';
    }

    $xml = str_replace(['</w:tc>', '</w:tr>', '</w:p>'], ["\t", "\n", "\n"], $xml);
    $text = strip_tags($xml);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1);
    $text = preg_replace('/[ \t]+\n/', "\n", $text);
    $text = preg_replace('/\n{3,}/', "\n\n", $text);
    return trim($text);
}

/**
 * Try to parse staff rows directly from a 2D array of cells, by finding a
 * header row that names recognisable columns. Returns null if no
 * confident header match is found, so the caller can fall back to AI.
 *
 * @param array<int, array<int, string>> $rows2D
 * @return ?array<int, array{full_name:string, department:?string, designation:?string, staff_no:?string}>
 */
function heuristic_parse_staff_rows(array $rows2D): ?array
{
    if (count($rows2D) < 2) return null;

    $headerRowIndex = null;
    $colMap = [];
    foreach ($rows2D as $i => $row) {
        $map = [];
        foreach ($row as $c => $cell) {
            $norm = strtolower(trim($cell));
            if (in_array($norm, ['name', 'full name', 'staff name', 'employee name'], true)) $map['full_name'] = $c;
            elseif (in_array($norm, ['department', 'dept', 'department name'], true)) $map['department'] = $c;
            elseif (in_array($norm, ['designation', 'title', 'job title', 'position'], true)) $map['designation'] = $c;
            elseif (in_array($norm, ['staff no', 'staff number', 'staff no.', 'id', 'employee no', 'employee no.'], true)) $map['staff_no'] = $c;
        }
        if (isset($map['full_name'])) {
            $headerRowIndex = $i;
            $colMap = $map;
            break;
        }
    }

    if ($headerRowIndex === null) return null;

    $out = [];
    for ($i = $headerRowIndex + 1; $i < count($rows2D); $i++) {
        $row = $rows2D[$i];
        $name = trim($row[$colMap['full_name']] ?? '');
        if ($name === '') continue;
        $out[] = [
            'full_name' => $name,
            'department' => isset($colMap['department']) ? (trim($row[$colMap['department']] ?? '') ?: null) : null,
            'designation' => isset($colMap['designation']) ? (trim($row[$colMap['designation']] ?? '') ?: null) : null,
            'staff_no' => isset($colMap['staff_no']) ? (trim($row[$colMap['staff_no']] ?? '') ?: null) : null,
        ];
    }
    return $out;
}

/** @param array<int, array<int, string>> $rows2D */
function rows2D_to_text(array $rows2D): string
{
    return implode("\n", array_map(fn($row) => implode("\t", $row), $rows2D));
}
