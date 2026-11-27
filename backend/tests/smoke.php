<?php
declare(strict_types=1);

// Black-box API regression checks against a disposable server and database.
if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "This PHP interpreter needs pdo_sqlite. Use the Compose test command from README.\n");
    exit(1);
}
$directory = sys_get_temp_dir() . '/project-desk-test-' . bin2hex(random_bytes(6));
mkdir($directory, 0700);
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if (!$socket) {
    throw new RuntimeException($errstr);
}
$address = stream_socket_get_name($socket, false);
fclose($socket);
$base = 'http://' . $address;
$environment = getenv();
$environment['DB_PATH'] = $directory . '/test.sqlite';
$environment['SEED_NOW'] = '2026-11-11T08:00:00Z';
$process = proc_open([PHP_BINARY, '-S', $address, __DIR__ . '/../router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $directory . '/server.log', 'a'], 2 => ['file', $directory . '/server.log', 'a']],
    $pipes, dirname(__DIR__, 2), $environment);
if (!is_resource($process)) {
    throw new RuntimeException('Cannot start test server');
}
fclose($pipes[0]);
$count = 0;
$exitStatus = 0;

function request(string $method, string $path, mixed $body = null, int $user = 1): array
{
    global $base;
    $options = ['http' => [
        'method' => $method, 'ignore_errors' => true, 'timeout' => 5,
        'header' => "Content-Type: application/json\r\nX-Demo-User: $user\r\n",
    ]];
    if ($body !== null) {
        $options['http']['content'] = is_string($body) ? $body : json_encode($body, JSON_THROW_ON_ERROR);
    }
    $raw = file_get_contents($base . $path, false, stream_context_create($options));
    preg_match('/\s(\d{3})\s/', $http_response_header[0], $match);
    return [(int) $match[1], json_decode($raw, true, 512, JSON_THROW_ON_ERROR)];
}

function check(bool $condition, string $message): void
{
    global $count;
    ++$count;
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo 'PASS: ' . $message . PHP_EOL;
}

try {
    $ready = false;
    for ($attempt = 0; $attempt < 50; ++$attempt) {
        if (@file_get_contents($base . '/api/health') !== false) {
            $ready = true;
            break;
        }
        usleep(100000);
    }
    check($ready, 'server starts with fresh SQLite');
    [$status, $response] = request('GET', '/api/context');
    check($status === 200 && $response['data']['user']['id'] === 1, 'default persona is Anna');
    check($response['data']['seeded_at'] === '2026-11-11T08:00:00Z', 'seed clock can be reproduced');
    [$status, $response] = request('GET', '/api/projects');
    check($status === 200 && array_column($response['data'], 'id') === [1, 2, 3, 4], 'Anna sees only her projects');
    check($response['data'][3]['archived'] === true, 'archive marker is boolean');
    [$status, $response] = request('GET', '/api/projects/2/tasks');
    check($status === 200 && array_column($response['data'], 'id') === [5, 6, 7], 'project tasks include unassigned task');
    [$status, $response] = request('GET', '/api/projects', null, 2);
    check(array_column($response['data'], 'id') === [1, 2, 4], 'Boris has different membership');
    [$status, $response] = request('GET', '/api/projects', null, 3);
    check(array_column($response['data'], 'id') === [1, 3, 5], 'Vera can see her separate project');
    check(request('GET', '/api/projects', null, 999)[0] === 401, 'unknown persona rejected');
    check(request('GET', '/api/projects/5/tasks')[0] === 404, 'hidden project is not disclosed');
    check(request('GET', '/api/tasks/11')[0] === 404, 'hidden task is not disclosed');
    check(request('PATCH', '/api/tasks/11', ['title' => 'Forbidden', 'version' => 1])[0] === 404, 'hidden task cannot be edited');
    check(request('POST', '/api/tasks/11/comments', ['body' => 'Forbidden'])[0] === 404, 'hidden task cannot receive comments');
    check(request('POST', '/api/projects/5/tasks', ['title' => 'Forbidden'])[0] === 404, 'hidden project cannot receive tasks');
    [$status, $response] = request('GET', '/api/tasks/1');
    check($status === 200 && count($response['data']['comments']) === 2, 'task detail includes discussion');
    check($response['data']['due_at'] === '2026-11-12T08:00:00Z', 'deadline relative to initial seed');
    check(request('POST', '/api/projects/4/tasks', ['title' => 'No'])[0] === 409, 'archive rejects task creation');
    check(request('PATCH', '/api/tasks/10', ['title' => 'No', 'version' => 1])[0] === 409, 'archive rejects task edits');
    check(request('POST', '/api/tasks/10/comments', ['body' => 'No'])[0] === 409, 'archive rejects comments');
    check(request('POST', '/api/projects/1/tasks', ['title' => '  '])[0] === 422, 'empty title rejected');
    check(request('POST', '/api/projects/2/tasks', ['title' => 'Bad member', 'assignee_id' => 3])[0] === 422, 'assignee must be a project member');
    check(request('POST', '/api/projects/1/tasks', ['title' => 'Bad date', 'due_at' => '2026-02-31T00:00:00Z'])[0] === 422, 'impossible date rejected');
    check(request('POST', '/api/projects/1/tasks', ['title' => 'Bad status', 'status' => 'unknown'])[0] === 422, 'invalid status rejected');
    check(request('POST', '/api/projects/1/tasks', ['title' => 'Extra field', 'project_id' => 5])[0] === 422, 'cannot move task through extra field');
    check(request('POST', '/api/projects/1/tasks', '{broken')[0] === 400, 'malformed JSON returns 400');
    check(request('POST', '/api/projects/1/tasks', '[]')[0] === 400, 'body must be an object');
    check(request('POST', '/api/projects/1/tasks', str_repeat('x', 33000))[0] === 413, 'oversized body rejected');
    [$status, $response] = request('POST', '/api/projects/1/tasks', [
        'title' => 'Проверка сохранения', 'description' => 'Текст с <b>разметкой</b>',
        'assignee_id' => 2, 'due_at' => '2026-12-01T09:30:00Z',
    ]);
    $task = $response['data'];
    check($status === 201 && $task['id'] > 12 && $task['status'] === 'open', 'create task with defaults');
    check(request('PATCH', '/api/tasks/' . $task['id'], ['title' => 'New'])[0] === 422, 'edit requires version');
    [$status, $response] = request('PATCH', '/api/tasks/' . $task['id'], ['status' => 'done', 'assignee_id' => null, 'due_at' => null, 'version' => 1]);
    check($status === 200 && $response['data']['version'] === 2 && $response['data']['due_at'] === null, 'edit fields and increment version');
    check(request('PATCH', '/api/tasks/' . $task['id'], ['title' => 'Stale', 'version' => 1])[0] === 409, 'stale write does not overwrite newer data');
    check(request('POST', '/api/tasks/' . $task['id'] . '/comments', ['body' => ' '])[0] === 422, 'empty comment rejected');
    [$status, $response] = request('POST', '/api/tasks/' . $task['id'] . '/comments', ['body' => '<script>window.pwned=1</script>'], 2);
    check($status === 201 && $response['data']['author'] === 'Борис · разработчик', 'comment records acting user');
    [$status, $response] = request('GET', '/api/tasks/' . $task['id']);
    check($response['data']['status'] === 'done' && count($response['data']['comments']) === 1, 'changes persist across requests');
    check($response['data']['version'] === 2, 'comment does not change task version');
    check($response['data']['comments'][0]['body'] === '<script>window.pwned=1</script>', 'comment content retained as plain data');
    [$status, $response] = request('GET', '/api/projects/1/tasks');
    check(count($response['data']) === 6, 'initialization does not duplicate or reset data');

    // Attention overview: fixtures use the server clock so the seven-day window is deterministic.
    $testDb = new PDO('sqlite:' . $environment['DB_PATH'], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $testDb->exec('PRAGMA busy_timeout = 5000');
    $testDb->exec("UPDATE comments SET created_at = '2026-01-01T00:00:00Z'");
    $now = time();
    $past = gmdate('Y-m-d\TH:i:s\Z', $now - 2 * 86400);
    $future = gmdate('Y-m-d\TH:i:s\Z', $now + 3 * 86400);
    $later = gmdate('Y-m-d\TH:i:s\Z', $now + 5 * 86400);

    function createTask(int $project, array $fields): array
    {
        [$status, $response] = request('POST', "/api/projects/$project/tasks", $fields);
        if ($status !== 201) {
            throw new RuntimeException('create task failed: ' . json_encode($response));
        }
        return $response['data'];
    }

    $blocked = createTask(1, ['title' => 'Attention blocked', 'status' => 'blocked', 'assignee_id' => 2, 'due_at' => $future]);
    $overdue = createTask(1, ['title' => 'Attention overdue', 'status' => 'in_progress', 'assignee_id' => 3, 'due_at' => $past]);
    $help = createTask(1, ['title' => 'Attention help', 'status' => 'open', 'assignee_id' => 2, 'due_at' => $future]);
    $done = createTask(1, ['title' => 'Attention done', 'status' => 'done', 'assignee_id' => 2, 'due_at' => $past]);
    $oldHelp = createTask(1, ['title' => 'Attention old help', 'status' => 'open', 'assignee_id' => null, 'due_at' => null]);
    $helpThenNeutral = createTask(1, ['title' => 'Attention help then neutral', 'status' => 'open', 'assignee_id' => 2, 'due_at' => null]);
    $negated = createTask(1, ['title' => 'Attention negated', 'status' => 'open', 'assignee_id' => 2, 'due_at' => null]);
    $ownHelp = createTask(1, ['title' => 'Attention own help', 'status' => 'open', 'assignee_id' => 2, 'due_at' => null]);
    $three = createTask(1, ['title' => 'Attention three reasons', 'status' => 'blocked', 'assignee_id' => 2, 'due_at' => $past]);
    $tieSoon = createTask(1, ['title' => 'Attention blocked sooner', 'status' => 'blocked', 'assignee_id' => 2, 'due_at' => $future]);
    $tieLate = createTask(1, ['title' => 'Attention blocked later', 'status' => 'blocked', 'assignee_id' => 2, 'due_at' => $later]);
    $longNegated = createTask(1, ['title' => 'Attention long negated', 'status' => 'open', 'assignee_id' => 2, 'due_at' => null]);
    $unassigned = createTask(1, ['title' => 'Attention unassigned', 'status' => 'blocked', 'assignee_id' => null, 'due_at' => $future]);
    $urgent = createTask(1, ['title' => 'Attention urgent help', 'status' => 'open', 'assignee_id' => 2, 'due_at' => null]);
    $decision = createTask(1, ['title' => 'Attention decision help', 'status' => 'open', 'assignee_id' => 2, 'due_at' => null]);
    $answer = createTask(1, ['title' => 'Attention answer help', 'status' => 'open', 'assignee_id' => 2, 'due_at' => null]);
    $unneeded = createTask(1, ['title' => 'Attention unneeded help', 'status' => 'open', 'assignee_id' => 2, 'due_at' => null]);

    [$status, $helpComment] = request('POST', '/api/tasks/' . $help['id'] . '/comments', ['body' => 'Нужна помощь с проверкой документов.'], 2);
    check($status === 201 && $helpComment['data']['author'] === 'Борис · разработчик', 'help comment created');
    [$status, $helpThenNeutralComment] = request('POST', '/api/tasks/' . $helpThenNeutral['id'] . '/comments', ['body' => 'Анна, нужна помощь с выбором варианта.'], 2);
    check($status === 201, 'help-then-neutral request comment created');
    request('POST', '/api/tasks/' . $helpThenNeutral['id'] . '/comments', ['body' => 'Пока жду ответа, работаю над другим пунктом.'], 3);
    request('POST', '/api/tasks/' . $negated['id'] . '/comments', ['body' => 'Не нужна помощь, разобрались сами.'], 2);
    request('POST', '/api/tasks/' . $longNegated['id'] . '/comments', ['body' => 'Спасибо, разобрались, не нужна помощь.'], 2);
    request('POST', '/api/tasks/' . $urgent['id'] . '/comments', ['body' => 'Нужна срочная помощь с доступом.'], 2);
    request('POST', '/api/tasks/' . $decision['id'] . '/comments', ['body' => 'Нужно ваше решение по формату.'], 2);
    request('POST', '/api/tasks/' . $answer['id'] . '/comments', ['body' => 'Нужен ваш ответ до вечера.'], 2);
    request('POST', '/api/tasks/' . $unneeded['id'] . '/comments', ['body' => 'Это ненужная помощь, справимся сами.'], 2);
    request('POST', '/api/tasks/' . $ownHelp['id'] . '/comments', ['body' => 'Нужна помощь.'], 1);
    request('POST', '/api/tasks/' . $three['id'] . '/comments', ['body' => 'Нужна помощь, выберите вариант.'], 2);
    $oldStmt = $testDb->prepare('INSERT INTO comments (task_id, user_id, body, created_at) VALUES (?, ?, ?, ?)');
    $oldStmt->execute([$oldHelp['id'], 2, 'Нужна помощь', gmdate('Y-m-d\TH:i:s\Z', $now - 8 * 86400)]);

    [$status, $response] = request('GET', '/api/attention');
    check($status === 200, 'attention returns 200');
    $attention = array_column($response['data'], null, 'id');
    check(isset($attention[$help['id']]) && in_array('help_request', $attention[$help['id']]['reasons'], true), 'explicit help is a reason');
    check(isset($attention[$help['id']]['help_quote']) && str_contains($attention[$help['id']]['help_quote']['excerpt'], 'помощ'), 'help quote contains the request');
    check(isset($attention[$help['id']]['help_quote'])
        && $attention[$help['id']]['help_quote']['author'] === 'Борис · разработчик'
        && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $attention[$help['id']]['help_quote']['created_at']) === 1,
        'help quote carries author and UTC time');
    check(!isset($attention[$negated['id']]), 'negated help is not a reason');
    check(!isset($attention[$longNegated['id']]), 'long negated help is not a reason');
    foreach ([$urgent, $decision, $answer] as $taskWithHelp) {
        check(isset($attention[$taskWithHelp['id']]) && in_array('help_request', $attention[$taskWithHelp['id']]['reasons'], true), 'new help wording is a reason: ' . $taskWithHelp['title']);
    }
    check(!isset($attention[$unneeded['id']]), 'unneeded help is not a reason');
    check(isset($attention[$helpThenNeutral['id']]) && $attention[$helpThenNeutral['id']]['help_quote']['comment_id'] === $helpThenNeutralComment['data']['id'], 'newer neutral comment does not replace the help request');
    check(!isset($attention[$ownHelp['id']]), 'own help request is not a reason');
    check(!isset($attention[$oldHelp['id']]), 'help older than seven days is not a reason');
    check(!isset($attention[$done['id']]), 'done task is not in attention');
    check(!isset($attention[10]), 'archived task is not in attention');
    check(!isset($attention[11]), 'foreign task is not in attention');
    check($response['data'][0]['id'] === $three['id'], 'task with three reasons is first');
    $order = array_flip(array_column($response['data'], 'id'));
    check($order[$tieSoon['id']] < $order[$tieLate['id']], 'earlier due date wins the tie');
    check(in_array('blocked', $attention[$blocked['id']]['reasons'], true), 'blocked task is in attention');
    check(!in_array('overdue', $attention[$blocked['id']]['reasons'], true), 'future due date is not overdue');
    check(in_array('overdue', $attention[$overdue['id']]['reasons'], true) && $attention[$overdue['id']]['overdue_days'] >= 1, 'overdue task is in attention');
    check(isset($attention[$unassigned['id']]) && $attention[$unassigned['id']]['assignee_name'] === null, 'unassigned task is in attention');
    check($response['data'][count($response['data']) - 1]['due_at'] === null, 'tasks without a due date sort last');

    [$status, $response] = request('GET', '/api/attention', null, 2);
    check($status === 200 && $response['data'] === [], 'owner-less user has an empty attention list');
    [$status, $response] = request('GET', '/api/attention', null, 3);
    check($status === 200 && array_column($response['data'], 'id') === [11], 'Vera sees the task in her own project');

    $testDb->exec("UPDATE tasks SET status = 'blocked' WHERE id = 8");
    $testDb->exec('DELETE FROM project_members WHERE project_id = 3 AND user_id = 1');
    [$status, $response] = request('GET', '/api/attention');
    check(!in_array(8, array_column($response['data'], 'id'), true), 'owner without membership does not see project tasks');

    check(request('GET', '/api/tasks/9999')[0] === 404, 'missing task returns 404');
    check(request('GET', '/api/unknown')[0] === 404, 'unknown route returns 404');
    echo "PASS: $count API checks\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL . file_get_contents($directory . '/server.log'));
    $exitStatus = 1;
} finally {
    proc_terminate($process);
    proc_close($process);
    foreach (glob($directory . '/*') as $file) {
        unlink($file);
    }
    rmdir($directory);
}

exit($exitStatus);
