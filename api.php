<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$dataDir = __DIR__ . '/data';
$dataFile = $dataDir . '/adi-state.json';

if (!is_dir($dataDir)) {
    mkdir($dataDir, 0755, true);
}

function blank_state(): array {
    return [
        'restaurants' => [],
        'actions' => [],
        'deletedActionIds' => [],
        'savedAt' => gmdate('c'),
    ];
}

function read_state(string $file): array {
    if (!is_file($file)) {
        return blank_state();
    }
    $raw = file_get_contents($file);
    $data = json_decode($raw ?: '', true);
    return is_array($data) ? array_merge(blank_state(), $data) : blank_state();
}

function write_state(string $file, array $state): void {
    $state['savedAt'] = gmdate('c');
    $tmp = $file . '.tmp';
    file_put_contents($tmp, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX);
    rename($tmp, $file);
}

function normalize_ids(array $ids): array {
    return array_values(array_unique(array_filter(array_map('strval', $ids))));
}

function action_key(array $action): ?string {
    if (!empty($action['id'])) {
        return (string) $action['id'];
    }
    if (!empty($action['restaurant']) && !empty($action['title'])) {
        return sha1(($action['restaurant'] ?? '') . '|' . ($action['title'] ?? '') . '|' . ($action['dueDate'] ?? '') . '|' . ($action['createdAt'] ?? ''));
    }
    return null;
}

function merge_actions(array $current, array $incoming, array $deletedIds): array {
    $deleted = array_flip($deletedIds);
    $map = [];
    foreach (array_merge($current, $incoming) as $action) {
        if (!is_array($action)) {
            continue;
        }
        $key = action_key($action);
        if (!$key || isset($deleted[$key])) {
            continue;
        }
        $previous = $map[$key] ?? [];
        $attachments = [];
        foreach (array_merge($previous['attachments'] ?? [], $action['attachments'] ?? []) as $file) {
            if (!is_array($file)) {
                continue;
            }
            $fileKey = $file['id'] ?? $file['name'] ?? sha1(json_encode($file));
            $attachments[$fileKey] = array_merge($attachments[$fileKey] ?? [], $file);
        }
        $map[$key] = array_merge($previous, $action);
        $map[$key]['id'] = $key;
        $map[$key]['attachments'] = array_values($attachments);
    }
    return array_values($map);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    echo json_encode(read_state($dataFile), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$incoming = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($incoming)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

$fp = fopen($dataFile . '.lock', 'c');
if (!$fp) {
    http_response_code(500);
    echo json_encode(['error' => 'Cannot open lock']);
    exit;
}

flock($fp, LOCK_EX);
$current = read_state($dataFile);
$deletedIds = normalize_ids(array_merge($current['deletedActionIds'] ?? [], $incoming['deletedActionIds'] ?? []));
$state = [
    'restaurants' => array_merge($current['restaurants'] ?? [], $incoming['restaurants'] ?? []),
    'actions' => merge_actions($current['actions'] ?? [], $incoming['actions'] ?? [], $deletedIds),
    'deletedActionIds' => $deletedIds,
];
write_state($dataFile, $state);
flock($fp, LOCK_UN);
fclose($fp);

echo json_encode(read_state($dataFile), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
