<?php
/**
 * Worship Attendance: select campus → pick from last 10 rows (Column B, newest first) → edit row.
 * Paths from .env; requires Sheets API scope in credentials.
 */
$projectRoot = __DIR__;

// Load .env
$envFile = $projectRoot . '/.env';
if (is_file($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) continue;
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

function resolvePath(string $path, string $projectRoot): string {
    if ($path === '' || $path[0] === '/' || (strlen($path) > 1 && $path[1] === ':')) {
        return $path;
    }
    return rtrim($projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($path, '/');
}

$autoload = resolvePath($_ENV['AUTOLOAD_PATH'] ?? getenv('AUTOLOAD_PATH') ?: '../vendor/autoload.php', $projectRoot);
if (!is_file($autoload)) {
    http_response_code(500);
    echo '<!DOCTYPE html><html><body><p>Autoload not found. Run: composer install</p></body></html>';
    exit;
}
require_once $autoload;

$credentialsPath = resolvePath($_ENV['CREDENTIALS_PATH'] ?? getenv('CREDENTIALS_PATH') ?: 'credentials.json', $projectRoot);
$clientSecretPath = resolvePath($_ENV['CLIENT_SECRET_PATH'] ?? getenv('CLIENT_SECRET_PATH') ?: 'client_secret.json', $projectRoot);

// Campus id => [ label, spreadsheetId, gid (null = first sheet) ]
$CAMPUSES = [
    'san-leandro'  => [ 'San Leandro',  '1hFgXyLssTPX8rMjl5IzrhmFJGWBI1WlRAwCb8PLBvMI', 178023513 ],
    'milpitas'     => [ 'Milpitas',     '1xyx-LMVYVdZQyX64w9YRzKrMNJ7oWR2tbmedlY81ZwQ', null ],
    'peninsula'    => [ 'Peninsula',    '11Kuu6sG4UKMXdZBDFLoBbf_hCDU6en0eh8KVtII7MZg', null ],
    'tracy'        => [ 'Tracy',        '14QNh7Y3YPLoeIkw-GYtEemLjc7pQuKN-86SVYxievI0', 2068867284 ],
    'pleasanton'   => [ 'Pleasanton',   '1xsnUELFKcPxLFItnqqykv39oRLbpm_4VdOQ3o5LefXE', null ],
];

/** Convert 0-based column index to A1 letter. */
function columnLetter(int $index): string {
    $letter = '';
    do {
        $letter = chr(65 + ($index % 26)) . $letter;
        $index = (int) floor($index / 26) - 1;
    } while ($index >= 0);
    return $letter;
}

/** Parse date from cell for sorting (column B = Service Date). */
function parseDateForSort($value): float {
    if ($value === null || $value === '') return 0;
    if (is_numeric($value)) return (float) $value;
    $t = @strtotime((string) $value);
    return $t ? (float) $t : 0;
}

$campusId = isset($_GET['campus']) ? (string) $_GET['campus'] : (isset($_POST['campus']) ? (string) $_POST['campus'] : null);
$editRowIndex = isset($_GET['row']) ? (int) $_GET['row'] : (isset($_POST['last_row_index']) ? (int) $_POST['last_row_index'] : null);
if ($campusId !== null && !isset($CAMPUSES[$campusId])) {
    $campusId = null;
}

$error = null;
$saveSuccess = false;
$headers = [];
$editRow = [];
$sheetTitle = '';
$lastTenRows = []; // [ [ rowIndex (1-based), rowData [], colBValue ], ... ] newest first
$spreadsheetId = null;
$sheetTitleForRange = '';

if (!is_file($credentialsPath)) {
    $error = 'Credentials file not found.';
} elseif ($campusId === null && $editRowIndex === null && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    // No campus selected — no API calls
} else {
    $credentials = json_decode(file_get_contents($credentialsPath), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $error = 'Invalid credentials.json';
    } else {
        $client = new \Google\Client();
        $client->setApplicationName('WorshipAttendance Sheet Viewer');
        $client->setScopes([ \Google\Service\Sheets::SPREADSHEETS ]);
        $client->setAuthConfig($clientSecretPath);
        $client->setAccessType('offline');
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
                $credentials['token'] = $newToken['access_token'];
                if (isset($newToken['expires_in'])) {
                    $credentials['expiry'] = date('c', time() + (int) $newToken['expires_in']);
                }
                file_put_contents($credentialsPath, json_encode($credentials, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }
        }
        $sheets = new \Google\Service\Sheets($client);

        if ($campusId !== null) {
            $spreadsheetId = $CAMPUSES[$campusId][1];
            $sheetGid = $CAMPUSES[$campusId][2];
            try {
                $spreadsheet = $sheets->spreadsheets->get($spreadsheetId);
                $sheetTitle = null;
                foreach ($spreadsheet->getSheets() as $sheet) {
                    $props = $sheet->getProperties();
                    if ($props && ($sheetGid === null || (int) $props->getSheetId() === (int) $sheetGid)) {
                        $sheetTitle = $props->getTitle();
                        break;
                    }
                }
                if ($sheetTitle === null) {
                    $error = 'Sheet not found for this campus.';
                } else {
                    $sheetTitleForRange = $sheetTitle;
                    $range = "'" . str_replace("'", "''", $sheetTitle) . "'!A1:ZZ";
                    $response = $sheets->spreadsheets_values->get($spreadsheetId, $range);
                    $values = $response->getValues();
                    if (!$values || count($values) < 2) {
                        $error = 'Sheet has no data or only a header row.';
                    } else {
                        $headers = $values[0];
                        $dataRows = [];
                        for ($r = 1; $r < count($values); $r++) {
                            $rowData = array_pad($values[$r], count($headers), '');
                            $colB = isset($rowData[1]) ? $rowData[1] : '';
                            $dataRows[] = [ $r + 1, $rowData, $colB ]; // 1-based row index, row, column B
                        }
                        $last10 = array_slice($dataRows, -10);
                        usort($last10, function ($a, $b) {
                            $ta = parseDateForSort($a[2]);
                            $tb = parseDateForSort($b[2]);
                            return $tb <=> $ta; // descending (newest first)
                        });
                        $lastTenRows = $last10;

                        // Edit mode: load specific row
                        if ($editRowIndex !== null) {
                            $found = null;
                            foreach ($values as $r => $row) {
                                if ($r + 1 === $editRowIndex) {
                                    $found = array_pad($row, count($headers), '');
                                    break;
                                }
                            }
                            if ($found !== null) {
                                $editRow = $found;
                                // Handle save (POST)
                                if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['v'], $_POST['campus'], $_POST['last_row_index']) && $_POST['campus'] === $campusId && (int) $_POST['last_row_index'] === $editRowIndex) {
                                    $submitted = is_array($_POST['v']) ? $_POST['v'] : [];
                                    $newValues = [];
                                    for ($i = 0; $i < count($headers); $i++) {
                                        $newValues[] = isset($submitted[$i]) ? (string) $submitted[$i] : '';
                                    }
                                    $updateRange = "'" . str_replace("'", "''", $sheetTitle) . "'!A" . $editRowIndex . ":" . columnLetter(count($headers) - 1) . $editRowIndex;
                                    $valueRange = new \Google\Service\Sheets\ValueRange();
                                    $valueRange->setValues([$newValues]);
                                    try {
                                        $sheets->spreadsheets_values->update($spreadsheetId, $updateRange, $valueRange, ['valueInputOption' => 'USER_ENTERED']);
                                        $saveSuccess = true;
                                        $editRow = $newValues;
                                    } catch (Exception $e) {
                                        $error = 'Save failed: ' . $e->getMessage();
                                    }
                                }
                            } else {
                                $error = 'Row not found.';
                            }
                        }
                    }
                }
            } catch (Exception $e) {
                $error = 'Sheets API: ' . $e->getMessage();
            }
        }
    }
}

$selfUrl = $_SERVER['PHP_SELF'] ?? 'sheet_last_row.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $campusId ? htmlspecialchars($CAMPUSES[$campusId][0] ?? 'Sheet') : 'Worship Attendance'; ?> – Edit row</title>
    <style>
        :root { --bg: #f5f5f7; --card: #ffffff; --text: #1d1d1f; --muted: #6e6e73; --accent: #0066cc; }
        * { box-sizing: border-box; }
        body { font-family: 'Segoe UI', system-ui, sans-serif; background: var(--bg); color: var(--text); margin: 0; padding: 1.5rem; line-height: 1.5; }
        h1 { font-size: 1.25rem; font-weight: 600; margin: 0 0 1rem; color: var(--accent); }
        .error { background: #ffebee; color: #c62828; padding: 1rem; border-radius: 8px; margin-bottom: 1rem; }
        table { width: 100%; max-width: 42rem; border-collapse: collapse; background: var(--card); border-radius: 8px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
        th, td { padding: 0.6rem 1rem; text-align: left; border-bottom: 1px solid rgba(0,0,0,.08); }
        th { font-weight: 600; color: var(--muted); font-size: 0.875rem; }
        tr:last-child td, tr:last-child th { border-bottom: none; }
        td { word-break: break-word; }
        input[type="text"], input[type="number"], input[type="email"], textarea { width: 100%; padding: 0.4rem 0.5rem; background: #fff; border: 1px solid #d2d2d7; border-radius: 4px; color: var(--text); font: inherit; }
        .btn { margin-top: 1rem; padding: 0.5rem 1rem; background: var(--accent); color: #fff; border: none; border-radius: 6px; font-weight: 600; cursor: pointer; font-size: 0.9375rem; }
        .btn:hover { filter: brightness(1.05); }
        .btn-link { display: inline-block; margin-right: 0.5rem; margin-bottom: 0.5rem; padding: 0.5rem 1rem; background: var(--card); color: var(--accent); border: 1px solid #d2d2d7; border-radius: 6px; text-decoration: none; font-size: 0.9375rem; }
        .btn-link:hover { background: #f5f5f7; }

        #save-overlay { position: fixed; inset: 0; background: rgba(245, 245, 247, 0.9); display: none; align-items: center; justify-content: center; z-index: 9999; flex-direction: column; gap: 1rem; }
        #save-overlay.active { display: flex; }
        .saving-spinner { width: 48px; height: 48px; border: 3px solid #d2d2d7; border-top-color: var(--accent); border-radius: 50%; animation: save-spin 0.8s linear infinite; }
        .saving-text { color: var(--accent); font-weight: 600; }
        @keyframes save-spin { to { transform: rotate(360deg); } }

        .saved-completed { display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 60vh; text-align: center; }
        .saved-completed .message { font-size: 1.5rem; font-weight: 600; color: #2e7d32; margin-bottom: 1rem; }
        .saved-completed .back { color: var(--accent); text-decoration: none; margin-top: 0.5rem; }
        .saved-completed .back:hover { text-decoration: underline; }
        .saved-completed .btn-done { display: inline-block; margin-top: 1.5rem; padding: 1rem 2.5rem; font-size: 1.5rem; font-weight: 700; background: var(--accent); color: #fff; border: none; border-radius: 8px; text-decoration: none; box-shadow: 0 4px 12px rgba(0,102,204,.35); }
        .saved-completed .btn-done:hover { filter: brightness(1.08); transform: scale(1.02); }

        .campus-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 0.75rem; max-width: 32rem; }
        .campus-btn { display: block; padding: 1rem; background: var(--card); border: 1px solid #d2d2d7; border-radius: 8px; color: var(--text); text-align: center; text-decoration: none; font-weight: 600; box-shadow: 0 1px 2px rgba(0,0,0,.06); }
        .campus-btn:hover { border-color: var(--accent); color: var(--accent); }

        .row-list { list-style: none; padding: 0; margin: 0; max-width: 32rem; }
        .row-list li { margin-bottom: 0.5rem; }
        .row-list a { display: block; padding: 0.75rem 1rem; background: var(--card); border-radius: 6px; color: var(--text); text-decoration: none; border: 1px solid transparent; box-shadow: 0 1px 2px rgba(0,0,0,.06); }
        .row-list a:hover { border-color: var(--accent); }
        .row-list .date { font-weight: 600; }
        .readonly-value { color: var(--muted); }
    </style>
</head>
<body>
<?php if ($saveSuccess): ?>
    <div class="saved-completed">
        <p class="message">Saved Completed</p>
        <a href="<?php echo htmlspecialchars($selfUrl . '?campus=' . $campusId); ?>" class="back">Pick another date</a>
        <a href="https://crosspointchurchsv.org/gum" class="btn-done">I am done</a>
    </div>
<?php elseif ($campusId === null): ?>
    <h1>Select Campus</h1>
    <p style="margin-bottom: 1rem; color: var(--muted);">Choose the campus to edit attendance.</p>
    <div class="campus-grid">
        <?php foreach ($CAMPUSES as $id => $info): ?>
        <a href="<?php echo htmlspecialchars($selfUrl . '?campus=' . $id); ?>" class="campus-btn"><?php echo htmlspecialchars($info[0]); ?></a>
        <?php endforeach; ?>
    </div>
<?php else: ?>
    <h1><?php echo htmlspecialchars($CAMPUSES[$campusId][0]); ?><?php if ($sheetTitle) echo ' – ' . htmlspecialchars($sheetTitle); ?></h1>
    <a href="<?php echo htmlspecialchars($selfUrl); ?>" class="btn-link">← Change campus</a>
    <?php if ($error): ?>
        <p class="error"><?php echo htmlspecialchars($error); ?></p>
    <?php endif; ?>

    <?php if (!$error && $editRowIndex === null && count($lastTenRows) > 0): ?>
        <p style="margin: 1rem 0; color: var(--muted);">Pick a wroship date to edit.</p>
        <ul class="row-list">
            <?php foreach ($lastTenRows as $item): list($rowIdx, $rowData, $colB) = $item; ?>
            <li>
                <a href="<?php echo htmlspecialchars($selfUrl . '?campus=' . $campusId . '&row=' . $rowIdx); ?>">
                    <span class="date"><?php echo htmlspecialchars((string) $colB); ?></span>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if (!$error && $editRowIndex !== null && count($headers) > 0): ?>
        <a href="<?php echo htmlspecialchars($selfUrl . '?campus=' . $campusId); ?>" class="btn-link">← Pick different date</a>
        <div id="save-overlay" aria-hidden="true">
            <div class="saving-spinner" aria-hidden="true"></div>
            <span class="saving-text">Saving…</span>
        </div>
        <form method="post" action="<?php echo htmlspecialchars($selfUrl . '?campus=' . $campusId . '&row=' . $editRowIndex); ?>" id="sheet-form">
        <div>
    <button type="submit" class="btn" id="save-btn">Save to sheet</button>
    </div>
            <input type="hidden" name="campus" value="<?php echo htmlspecialchars($campusId); ?>">
            <input type="hidden" name="last_row_index" value="<?php echo (int) $editRowIndex; ?>">
            <?php foreach ($headers as $i => $colName): if (strtolower(trim((string) $colName)) === 'timestamp'): ?>
            <input type="hidden" name="v[<?php echo (int) $i; ?>]" value="<?php echo htmlspecialchars(isset($editRow[$i]) ? (string) $editRow[$i] : ''); ?>">
            <?php endif; endforeach; ?>
            <table>
                <tbody>
                    <?php foreach ($headers as $i => $colName):
                        if (strtolower(trim((string) $colName)) === 'timestamp') continue;
                        $isDateColumn = in_array(strtolower(trim((string) $colName)), [ 'service date', 'sunday date' ], true);
                        $cellVal = isset($editRow[$i]) ? (string) $editRow[$i] : ''; ?>
                    <tr>
                        <th scope="row"><?php echo htmlspecialchars((string) $colName); ?></th>
                        <td>
                            <?php if ($isDateColumn): ?>
                            <input type="hidden" name="v[<?php echo (int) $i; ?>]" value="<?php echo htmlspecialchars($cellVal); ?>">
                            <span class="readonly-value"><?php echo htmlspecialchars($cellVal); ?></span>
                            <?php else: ?>
                            <input type="text" name="v[<?php echo (int) $i; ?>]" value="<?php echo htmlspecialchars($cellVal); ?>">
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <button type="submit" class="btn" id="save-btn">Save to sheet</button>
        </form>
        <script>
            (function() {
                var form = document.getElementById('sheet-form');
                var overlay = document.getElementById('save-overlay');
                var btn = document.getElementById('save-btn');
                if (form && overlay) {
                    form.addEventListener('submit', function() {
                        overlay.classList.add('active');
                        if (btn) btn.disabled = true;
                    });
                }
            })();
        </script>
    <?php endif; ?>

    <?php if (!$error && $editRowIndex === null && count($lastTenRows) === 0 && $campusId !== null): ?>
        <p class="error">No data rows found for this sheet.</p>
    <?php endif; ?>
<?php endif; ?>
</body>
</html>
