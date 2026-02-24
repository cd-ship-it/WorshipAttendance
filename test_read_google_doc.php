<?php
/**
 * Test script: read a Google Doc using credentials.json and Google Docs API.
 * Paths come from .env (or server environment); differs between development and production.
 * Run from project root: php test_read_google_doc.php
 */

$docId = '1Uv-mNyCuXdRNcX83p1AcqgXVHB04qevCNGJ8FyhRM38';

$projectRoot = __DIR__;

// Load .env into $_ENV (simple parser, no dependency)
$envFile = $projectRoot . '/.env';
if (is_file($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) {
            continue;
        }
        if (strpos($line, '=') !== false) {
            [$name, $value] = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value, " \t\"'");
            if ($name !== '') {
                $_ENV[$name] = $value;
                putenv("$name=$value");
            }
        }
    }
}

$environment = $_ENV['ENVIRONMENT'] ?? getenv('ENVIRONMENT') ?: 'development';

function resolvePath(string $path, string $projectRoot): string {
    if ($path === '') {
        return $path;
    }
    if ($path[0] === '/' || (strlen($path) > 1 && $path[1] === ':')) {
        return $path;
    }
    return rtrim($projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($path, '/');
}

$autoload = resolvePath($_ENV['AUTOLOAD_PATH'] ?? getenv('AUTOLOAD_PATH') ?: '../vendor/autoload.php', $projectRoot);
if (!is_file($autoload)) {
    die("Autoload not found at {$autoload}. Run: composer install (from project root)\n");
}
require_once $autoload;

$credentialsPath = resolvePath($_ENV['CREDENTIALS_PATH'] ?? getenv('CREDENTIALS_PATH') ?: 'credentials.json', $projectRoot);
$clientSecretPath = resolvePath($_ENV['CLIENT_SECRET_PATH'] ?? getenv('CLIENT_SECRET_PATH') ?: 'client_secret.json', $projectRoot);

if (!is_file($credentialsPath)) {
    die("Credentials file not found at {$credentialsPath}\n");
}

$credentials = json_decode(file_get_contents($credentialsPath), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    die("Invalid credentials.json\n");
}

$client = new \Google\Client();
$client->setApplicationName('WorshipAttendance Doc Reader');
$client->setScopes([
    \Google\Service\Docs::DOCUMENTS, // full scope for read + write
    \Google\Service\Drive::DRIVE_READONLY,
]);
$client->setAuthConfig($clientSecretPath);
$client->setAccessType('offline');
$client->setPrompt('select_account consent');

// Use existing token from credentials.json
$client->setAccessToken([
    'access_token'  => $credentials['token'],
    'refresh_token' => $credentials['refresh_token'],
    'expires_in'    => 3600,
    'created'       => 0,
]);

if ($client->isAccessTokenExpired()) {
    $client->fetchAccessTokenWithRefreshToken($credentials['refresh_token']);
    $newToken = $client->getAccessToken();
    if (isset($newToken['access_token'])) {
        // Optionally save updated token back to credentials.json
        $credentials['token'] = $newToken['access_token'];
        if (isset($newToken['expires_in'])) {
            $credentials['expiry'] = date('c', time() + (int) $newToken['expires_in']);
        }
        file_put_contents($credentialsPath, json_encode($credentials, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}

$docs = new \Google\Service\Docs($client);

try {
    $document = $docs->documents->get($docId);
} catch (Exception $e) {
    die("Docs API error: " . $e->getMessage() . "\n");
}

// Extract plain text from document structure
function extractTextFromDoc($document) {
    $text = '';
    if (!isset($document->body->content)) {
        return $text;
    }
    foreach ($document->body->content as $element) {
        if (isset($element->paragraph)) {
            foreach ($element->paragraph->elements as $el) {
                if (isset($el->textRun->content)) {
                    $text .= $el->textRun->content;
                }
            }
        }
        if (isset($element->table)) {
            foreach ($element->table->tableRows as $row) {
                foreach ($row->tableCells as $cell) {
                    foreach ($cell->content as $c) {
                        if (isset($c->paragraph)) {
                            foreach ($c->paragraph->elements as $el) {
                                if (isset($el->textRun->content)) {
                                    $text .= $el->textRun->content;
                                }
                            }
                        }
                    }
                }
            }
        }
    }
    return $text;
}

$title = $document->getTitle();
$plainText = extractTextFromDoc($document);

echo "--- Document: " . $title . " ---\n\n";
echo $plainText;
echo "\n--- End ---\n";

// --- Write verification: insert then remove a line so the doc is unchanged ---
$writeTestMarker = '[Write test ' . date('c') . ']';
$insertText = "\n" . $writeTestMarker . "\n";

$insertReq = new \Google\Service\Docs\InsertTextRequest();
$insertReq->setText($insertText);
$endOfBody = new \Google\Service\Docs\EndOfSegmentLocation();
$endOfBody->setSegmentId(''); // empty = document body
$insertReq->setEndOfSegmentLocation($endOfBody);

$req = new \Google\Service\Docs\Request();
$req->setInsertText($insertReq);

$batchReq = new \Google\Service\Docs\BatchUpdateDocumentRequest();
$batchReq->setRequests([$req]);

try {
    $docs->documents->batchUpdate($docId, $batchReq);
    echo "\n[OK] Write verified: inserted then removing marker.\n";
} catch (Exception $e) {
    echo "\n[FAIL] Write failed: " . $e->getMessage() . "\n";
    exit(1);
}

// Remove the marker (and its surrounding newlines) so the document is unchanged
$replaceReq = new \Google\Service\Docs\ReplaceAllTextRequest();
$criteria = new \Google\Service\Docs\SubstringMatchCriteria();
$criteria->setText("\n" . $writeTestMarker . "\n");
$criteria->setMatchCase(true);
$replaceReq->setContainsText($criteria);
$replaceReq->setReplaceText('');

$req2 = new \Google\Service\Docs\Request();
$req2->setReplaceAllText($replaceReq);
$batchReq2 = new \Google\Service\Docs\BatchUpdateDocumentRequest();
$batchReq2->setRequests([$req2]);

try {
    $docs->documents->batchUpdate($docId, $batchReq2);
    echo "[OK] Marker removed; document unchanged.\n";
} catch (Exception $e) {
    echo "[WARN] Could not remove marker: " . $e->getMessage() . " (document was still written to)\n";
}
