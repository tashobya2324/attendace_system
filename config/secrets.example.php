<?php
/**
 * Copy this file to config/secrets.php and fill in real values.
 * config/secrets.php is gitignored — never commit real credentials.
 */

if (!getenv('GEMINI_API_KEY')) {
    putenv('GEMINI_API_KEY=your-gemini-api-key-here');
}
