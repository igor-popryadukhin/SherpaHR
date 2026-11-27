# Обзор «Требует внимания» и восстановление комментариев — план реализации

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Дать руководителю единый экран незавершённых задач, требующих внимания, с переходом в существующую карточку и восстановить сохранение комментариев.

**Architecture:** Один серверный маршрут `GET /api/attention` проверяет руководство и членство, вычисляет причины (блокировка, просрочка, явная просьба о помощи за семь дней) и отдаёт цитату. Angular показывает обзор, открывает существующую карточку и берёт проект задачи из `detail.project_id`. Сохранение комментария возвращается в `POST /api/tasks/{id}/comments`.

**Tech Stack:** PHP 8.4 + PDO SQLite (без Composer), Angular 22 standalone/signals с strict templates, smoke-тест PHP, Docker Compose.

**Spec:** `docs/superpowers/specs/2026-11-27-attention-overview-design.md`

## Global Constraints

- PHP 8.4+, PDO SQLite; новых зависимостей и Composer нет.
- Angular 22.2.3/22.2.4, TypeScript 6.0.3, strict templates; новых npm-зависимостей нет.
- Все даты и сроки — UTC, формат `Y-m-d\TH:i:s\Z`; просрочка считается по текущему времени сервера.
- Демо-пользователь выбирается заголовком `X-Demo-User`; настоящей аутентификации нет.
- Проверки руководства и членства — только на сервере; архивный проект не принимает изменения.
- Лимиты: название 200 байт, описание 10000, комментарий 5000, тело запроса 32 KiB.
- Комментарии — обычный текст; Angular экранирует разметку.
- Работать в ветке `feat/attention-overview` текущего checkout; worktree не создавать.
- Коммиты — по формату `CLAUDE.md`: `<context>: <что сделать>`, пустая строка, описание; без AI-атрибуции.
- Сдачу вести в issue #21 по `AGENTS.md` и `sherpa-hr-task-lifecycle`.

## Review Focus

1. Пользователь, который руководит проектом, но не является его участником, не должен видеть задачи проекта; проверка — фикстура без членства.
2. Задача со сроком в будущем или ровно сейчас не должна считаться просроченной; проверка — задачи с `due_at` в прошлом и будущем.
3. Отрицание, старая просьба и более свежий нейтральный комментарий не должны давать/снимать причину; проверка — три отдельных комментария.
4. Открытие задачи из обзора при выбранном другом проекте должно брать участников, архив и сохранение из проекта задачи; проверка — ручной сценарий из обзора.
5. Запоздавшие ответы после смены демо-пользователя не должны заполнять список или карточку; проверка — смена пользователя в браузере.

---

### Task 1: Комментарии и список задач без исполнителя

**Files:**
- Modify: `backend/src/Application.php:61-66, 101-107`
- Test: `backend/tests/smoke.php`

**Interfaces:**
- Produces: `POST /api/tasks/{id}/comments` → `201 {"data": {id, task_id, user_id, body, created_at, author}}`.
- Produces: `GET /api/projects/{id}/tasks` → все задачи проекта, включая `assignee_id = null`.

- [ ] **Step 1: Добавить проверку задачи без исполнителя в smoke**

В `backend/tests/smoke.php` после проверки списка проектов Анны добавить:

```php
[$status, $response] = request('GET', '/api/projects/2/tasks');
check($status === 200 && array_column($response['data'], 'id') === [5, 6, 7], 'project tasks include unassigned task');
```

- [ ] **Step 2: Запустить smoke и увидеть ожидаемые падения**

Run: `php backend/tests/smoke.php`
Expected: FAIL на `project tasks include unassigned task` (сейчас `[5, 6]` из-за `JOIN users`) либо на проверке комментария 201.

- [ ] **Step 3: Убрать INNER JOIN из списка задач**

В `Application.php` заменить запрос списка задач проекта на:

```php
$this->respond($this->all('SELECT t.* FROM tasks t WHERE t.project_id=? ORDER BY t.id', [$project['id']]));
```

- [ ] **Step 4: Сохранять комментарий**

В обработчике `POST /api/tasks/{id}/comments` после `text(...)`:

```php
$body = $this->text($data['body'] ?? null, 'Комментарий', 5000, true);
$now = gmdate('Y-m-d\TH:i:s\Z');
$stmt = $this->db->prepare('INSERT INTO comments (task_id, user_id, body, created_at) VALUES (?,?,?,?)');
$stmt->execute([$task['id'], $this->userId, $body, $now]);
$comment = $this->one('SELECT c.*, u.name AS author FROM comments c JOIN users u ON u.id=c.user_id WHERE c.id=?', [(int) $this->db->lastInsertId()]);
$this->respond($comment, 201);
return;
```

Существующие проверки `task()`, `writable()`, `input()` и `text()` сохранить; заглушку 501 удалить. Версию задачи не менять.

- [ ] **Step 5: Запустить smoke**

Run: `php backend/tests/smoke.php`
Expected: PASS всех существующих проверок, включая `comment records acting user`, `changes persist across requests`, `project tasks include unassigned task`, `initialization does not duplicate or reset data`.

- [ ] **Step 6: Commit**

```bash
git add backend/src/Application.php backend/tests/smoke.php
git commit -m "PHP API: восстановить добавление комментариев" -m "Сохранять комментарий от текущего демо-пользователя и возвращать его с автором. Показывать в списке проекта задачи без исполнителя."
```

---

### Task 2: Серверный обзор «Требует внимания»

**Files:**
- Modify: `backend/src/Application.php`
- Test: `backend/tests/smoke.php`

**Interfaces:**
- Consumes: `POST /api/tasks/{id}/comments` из Task 1.
- Produces: `GET /api/attention` → `200 {"data": AttentionItem[]}`; элемент: `id, project_id, project_name, title, status, assignee_id, assignee_name, due_at, reasons, overdue_days, help_quote`.
- Produces: `reasons` — массив из `blocked`, `overdue`, `help_request`; `help_quote` — `{comment_id, author, created_at, excerpt}` или `null`.

- [ ] **Step 1: Добавить тестовый блок обзора в smoke**

После существующей проверки `initialization does not duplicate or reset data` добавить фикстуры и проверки. Ключевые фрагменты:

```php
// Состарить засеянные обсуждения, чтобы окно семи дней не зависело от даты запуска.
$testDb = new PDO('sqlite:' . $environment['DB_PATH'], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$testDb->exec('PRAGMA busy_timeout = 5000');
$testDb->exec("UPDATE comments SET created_at = '2026-01-01T00:00:00Z'");
$now = time();
$past = gmdate('Y-m-d\TH:i:s\Z', $now - 2 * 86400);
$future = gmdate('Y-m-d\TH:i:s\Z', $now + 3 * 86400);
$later = gmdate('Y-m-d\TH:i:s\Z', $now + 5 * 86400);
```

Добавить помощник создания задачи:

```php
function createTask(int $project, array $fields): array {
    [$status, $response] = request('POST', "/api/projects/$project/tasks", $fields);
    if ($status !== 201) throw new RuntimeException('create task failed: ' . json_encode($response));
    return $response['data'];
}
```

Создать в проекте 1 задачи: `blocked` (blocked, будущий срок), `overdue` (in_progress, прошлый срок), `help` (open, будущий срок), `done` (done, прошлый срок), `oldHelp` (open, без срока и исполнителя), `helpThenNeutral` (open, без срока), `negated` (open, без срока), `ownHelp` (open, без срока), `three` (blocked, прошлый срок), `tieSoon` (blocked, срок +3 дня), `tieLate` (blocked, срок +5 дней). Использовать исполнителей 2/3 и точные сроки из переменных.

Добавить комментарии через API:

```php
[$status, $helpComment] = request('POST', '/api/tasks/' . $help['id'] . '/comments', ['body' => 'Нужна помощь с проверкой документов.'], 2);
check($status === 201 && $helpComment['data']['author'] === 'Борис · разработчик', 'help comment created');
[$status, $helpThenNeutralComment] = request('POST', '/api/tasks/' . $helpThenNeutral['id'] . '/comments', ['body' => 'Анна, нужна помощь с выбором варианта.'], 2);
request('POST', '/api/tasks/' . $helpThenNeutral['id'] . '/comments', ['body' => 'Пока жду ответа, работаю над другим пунктом.'], 3);
request('POST', '/api/tasks/' . $negated['id'] . '/comments', ['body' => 'Не нужна помощь, разобрались сами.'], 2);
request('POST', '/api/tasks/' . $ownHelp['id'] . '/comments', ['body' => 'Нужна помощь.'], 1);
request('POST', '/api/tasks/' . $three['id'] . '/comments', ['body' => 'Нужна помощь, выберите вариант.'], 2);
```

Для `oldHelp` вставить комментарий прямо в тестовую БД с датой `gmdate('Y-m-d\TH:i:s\Z', $now - 8 * 86400)` и телом `'Нужна помощь'`.

Проверить `GET /api/attention` для Анны: `help` содержит `help_request` и цитату с `помощ`; `negated` отсутствует; `helpThenNeutral` присутствует и `help_quote.comment_id` равен id первого комментария; `ownHelp` и `oldHelp` отсутствуют; `done`, №10 и №11 отсутствуют; `three` идёт первым; `tieSoon` раньше `tieLate`; `blocked` содержит `blocked`; `overdue` содержит `overdue` и `overdue_days >= 1`.

Проверить границы: у Бориса (`X-Demo-User: 2`) пустой список; у Веры (`3`) список `[11]`. В конце удалить членство Анны в проекте 3 (`DELETE FROM project_members WHERE project_id=3 AND user_id=1`) и проверить, что задачи проекта 3 не появились.

- [ ] **Step 2: Запустить smoke и увидеть падение**

Run: `php backend/tests/smoke.php`
Expected: FAIL на `GET /api/attention` (404 — маршрут не найден) или на отсутствующих причинах.

- [ ] **Step 3: Добавить маршрут**

В `dispatch()` после `/api/context`:

```php
if ($method === 'GET' && $path === '/api/attention') {
    $this->respond($this->attention());
    return;
}
```

- [ ] **Step 4: Реализовать `attention()`, `helpMatch()`, `negated()`, `excerpt()`**

`attention(): array`:

- `$now = gmdate('Y-m-d\TH:i:s\Z')`, `$since = gmdate('Y-m-d\TH:i:s\Z', time() - 7 * 86400)`.
- Выбрать задачи: `JOIN projects p`, `LEFT JOIN users u`, `p.owner_id = ?`, `p.archived = 0`, `t.status <> 'done'`, `EXISTS (SELECT 1 FROM project_members m WHERE m.project_id = p.id AND m.user_id = ?)`.
- Выбрать комментарии этих задач, `c.user_id <> ?`, `c.created_at >= ?`, `ORDER BY c.created_at DESC, c.id DESC`.
- Для каждой задачи причины: `blocked` при статусе; `overdue` при `due_at < $now` с `overdue_days = max(0, (int) floor((time() - strtotime($due_at)) / 86400))`; `help_request` при первом найденном подходящем комментарии.
- Пропускать задачи без причин; отдавать имена проекта и исполнителя.
- Сортировать: число причин по убыванию, затем `due_at` по возрастанию (`null` в конце), затем `id`.

`helpMatch(string $body): ?array`:

```php
$pattern = '/(?:нужн\p{L}*\s+(?:ваша\s+)?помощ|прош\p{L}*\s+помощ|помог(?:и|ите)\b|нужн\p{L}*\s+решени|нужен\s+ответ|требует\p{L}*\s+помощ|подскаж(?:и|ите)\b)/iu';
```

Вернуть `[byteOffset, byteLength]` первого совпадения, перед которым нет отрицания.

`negated(string $body, int $offset): bool` — проверить до 40 байт перед совпадением регуляркой `/(?:^|[^\p{L}])не\s+$/iu`.

`excerpt(string $body, int $offset, int $length): string` — разбить тело на символы `preg_split('//u', ...)`, взять примерно 80 символов до и 120 после совпадения, добавить `…` при обрезке; не разрывать UTF-8.

- [ ] **Step 5: Запустить smoke**

Run: `php backend/tests/smoke.php`
Expected: PASS всех проверок Task 1 и Task 2, включая `attention returns 200`, `explicit help is a reason`, `negated help is not a reason`, `newer neutral comment does not replace the help request`, `own help request is not a reason`, `help older than seven days is not a reason`, `owner without membership does not see project tasks`.

- [ ] **Step 6: Commit**

```bash
git add backend/src/Application.php backend/tests/smoke.php
git commit -m "PHP API: добавить обзор «Требует внимания»" -m "Отдавать незавершённые задачи активных проектов руководителя с причинами: блокировка, просрочка и явная просьба о помощи за семь дней. Проверять руководство и членство, не раскрывать архив и чужие проекты."
```

---

### Task 3: Экран «Требует внимания» в Angular

**Files:**
- Modify: `frontend/src/models.ts`
- Modify: `frontend/src/app.ts`
- Modify: `frontend/src/app.html`
- Modify: `frontend/src/styles.css`

**Interfaces:**
- Consumes: `GET /api/attention` из Task 2.
- Produces: `AttentionItem extends Task`; `view = signal<'project' | 'attention'>`; `attention = signal<AttentionItem[]>`; `cardProject = computed<Project | null>`; `openAttention()`, `refreshAttention()`.

- [ ] **Step 1: Добавить типы**

В `models.ts`:

```ts
export type AttentionReason = 'blocked' | 'overdue' | 'help_request';
export interface HelpQuote { comment_id: number; author: string; created_at: string; excerpt: string }
export interface AttentionItem extends Task {
  project_name: string;
  assignee_name: string | null;
  reasons: AttentionReason[];
  overdue_days: number;
  help_quote: HelpQuote | null;
}
```

- [ ] **Step 2: Добавить состояние и вычисление проекта карточки**

В `app.ts` импортировать `computed` и `AttentionItem`. Добавить:

```ts
view = signal<'project' | 'attention'>('project');
attention = signal<AttentionItem[]>([]);
attentionLoading = signal(false);
private attentionGeneration = 0;
cardProject = computed<Project | null>(() => {
  const detail = this.detail();
  if (detail) return this.projects().find(p => p.id === detail.project_id) ?? null;
  return this.project();
});
```

`load()`: очищать `attention`, запрашивать `/attention` параллельно с `/context` и `/projects`, сохранять с проверкой `attentionGeneration`, при `view() === 'attention'` не выбирать проект.

`switchUser()`: очищать `attention` и `project`/`tasks`/`context`; вызывать `load()`; счётчики поколений должны отбрасывать запоздавшие ответы.

`selectProject()`: устанавливать `view.set('project')`.

Добавить `openAttention()` (переключить view, закрыть карточку, очистить выбранный проект, вызвать `refreshAttention()`) и `refreshAttention()` (инкремент `attentionGeneration`, запрос `/attention`, запись только при совпадении поколения).

- [ ] **Step 3: Сохранение из обзора**

В `saveTask()` брать проект существующей задачи из `this.cardProject()`, а не из `this.project()`; создание задачи — из `this.project()`. Обновлять `this.tasks` по `id`, как сейчас (если задача есть в текущем списке), и после успешного `PATCH` вызывать `refreshAttention()`, чтобы строка исчезла из обзора. Проверка поколения должна отбрасывать результат, если пользователь переключился или карточка закрыта.

- [ ] **Step 4: Добавить разметку**

В боковом меню над `Ваши проекты` — кнопка «Требует внимания» со счётчиком и `[class.selected]="view() === 'attention'"`.

В `main` перед веткой проекта добавить `@if (view() === 'attention') { ... }`: заголовок, пояснение правил, кнопка «Обновить», таблица с колонками проект/задача/исполнитель/срок/причины. В ячейке задачи — кнопка `openTask(item)` и, при `help_quote`, блок цитаты с автором и временем. Причины — метки с `reasonLabel(reason, item)`.

В карточке заменить `project()?.archived` на `cardProject()?.archived`, `project()?.members` на `cardProject()?.members`; добавить название проекта под заголовком карточки. Ветку проекта в сайдбаре оставить с `view() === 'project' && project()?.id === p.id`.

Добавить `reasonLabel(reason, item)` и `daysLabel(days)` (1 день, 2 дня, 5 дней).

- [ ] **Step 5: Добавить стили**

В `styles.css`: `.attention-link`, `.attention-table .project-cell/.task-cell` с `white-space: normal`, `.quote` с левой границей и переносом, `.reasons`, `.reason.blocked/.overdue/.help_request`, `.drawer-project`.

- [ ] **Step 6: Собрать фронтенд**

Run: `cd frontend && npm ci && npm run build`
Expected: `Application bundle generation complete`, без ошибок strict templates.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/models.ts frontend/src/app.ts frontend/src/app.html frontend/src/styles.css
git commit -m "Angular: добавить обзор «Требует внимания»" -m "Показывать единый список задач с причинами и цитатой, открывать существующую карточку и брать участников и права из проекта открытой задачи."
```

---

### Task 4: Документация

**Files:**
- Modify: `docs/api.md`
- Modify: `docs/product.md`
- Modify: `README.md`
- Modify: `docs/development.md`
- Add: `docs/superpowers/specs/2026-11-27-attention-overview-design.md`
- Add: `docs/superpowers/plans/2026-11-27-attention-overview.md`

- [ ] **Step 1: Обновить API**

В `docs/api.md` добавить `GET /api/attention` с полями ответа, причинами, семидневным окном и цитатой. Заменить строку про 501 у `POST /api/tasks/{id}/comments` на сохранение и `201` с автором.

- [ ] **Step 2: Обновить продукт и README**

В `docs/product.md` описать обзор, причины и то, что комментарии теперь работают. В `README.md` убрать «известное ограничение» про 501, добавить обзор в описание приложения и обновить заметку о smoke-тесте.

- [ ] **Step 3: Обновить разработку**

В `docs/development.md` обновить заметку о smoke (комментарии проходят) и перечислить новый маршрут и файлы обзора.

- [ ] **Step 4: Commit**

```bash
git add docs README.md
git commit -m "Docs: описать обзор внимания и восстановленные комментарии" -m "Обновить API, продукт, README и справку разработки; добавить дизайн и план решения."
```

---

### Task 5: Проверка на свежем Compose volume и сдача

**Files:** нет изменений кода.

- [ ] **Step 1: Поднять отдельный Compose-проект со свежим volume**

```bash
COMPOSE_PROJECT_NAME=sherpa-attention-check PORT=8099 docker compose up --build -d --wait
```

Ожидается: контейнер `sherpa-attention-check-app-1` healthy на `127.0.0.1:8099`; рабочий volume `sherpahr_project_data` не затрагивается.

- [ ] **Step 2: Запустить smoke в контейнере**

```bash
docker compose -p sherpa-attention-check exec -T app php backend/tests/smoke.php
```

Ожидается: все проверки PASS, включая новые проверки обзора.

- [ ] **Step 3: Проверить сценарий в браузере**

На `http://localhost:8099`:

1. Анна → «Требует внимания»: №5, №1, №2, №7 в этом порядке; №10 и №11 отсутствуют.
2. №7 → комментарий → закрыть → открыть снова: комментарий на месте.
3. №5 → «Готово» → сохранить: после `PATCH` строка исчезает; проект берётся из `detail.project_id`.
4. Смена пользователя: список очищается, у Бориса пусто, у Веры №11; запоздавшие ответы не применяются.
5. Архив: №10 нет в обзоре; комментарий в архивный проект отклонён (409).

Записать команды, результаты, SHA и ссылки в issue #21.

- [ ] **Step 4: Собрать и проверить локально**

```bash
php backend/tests/smoke.php
cd frontend && npm run build
```

Ожидается: PASS и успешная сборка.

- [ ] **Step 5: Остановить отдельный Compose-проект**

```bash
COMPOSE_PROJECT_NAME=sherpa-attention-check PORT=8099 docker compose down --volumes
```

- [ ] **Step 6: Push, PR и сдача**

```bash
git push -u origin feat/attention-overview
gh pr create --repo igor-popryadukhin/SherpaHR --base main --head feat/attention-overview --title "Обзор «Требует внимания» и восстановление комментариев" --body-file <(printf '%s\n' 'Реализует task_v7: обзор задач, требующих внимания руководителя, и сохранение комментариев.' '' 'Центральная issue: https://github.com/SherpaRPA/SherpaHR/issues/21' '' 'Проверки: smoke PHP, сборка Angular, сценарий на свежем Compose volume.')
```

Опубликовать в issue #21 итоговый отчёт по шаблону `submission.md`, затем отдельный комментарий с `@fxpw`, ссылкой на PR, head SHA и отчётом. Проверить комментарии чтением. Если доступно, запросить review у `fxpw` в PR (`gh pr edit --add-reviewer fxpw`); при ошибке указать причину и использовать упоминание в issue. Закрыть issue #21 как «сдано на проверку»; PR оставить открытым.

---

## Доработка P004 (2026-11-27)

- Обзор обновляется после любого успешного сохранения задачи (`POST` или `PATCH`), до проверки состояния карточки: закрытие карточки до ответа сервера не отменяет обновление, создание первой блокированной задачи сразу показывает пункт «Требует внимания».
- Загрузка обзора завершается при смене демо-пользователя: `attentionLoading` сбрасывается, пустое состояние и доступная кнопка «Обновить» возвращаются. Загрузка `/attention` развязана с загрузкой контекста и проектов; ошибка обзора хранится в `attentionError` и не блокирует проекты.
- Правило просьб о помощи дополнено формулировками «Нужна срочная помощь», «Нужно ваше решение», «Нужен ваш ответ»; совпадение начинается на границе слова, поэтому «ненужную помощь» не считается просьбой; явные отрицания в коротких и длинных комментариях сохраняются в тестах. Семидневное окно и цитаты не меняются.
- Проверки: `php backend/tests/smoke.php` (67 проверок), `npm --prefix frontend run build`, браузерные сценарии сохранения с закрытием карточки, создания первой блокированной задачи, смены пользователя во время обновления и недоступного `/attention`.
