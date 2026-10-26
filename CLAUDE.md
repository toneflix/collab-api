# collab-api

Laravel 13 API for Toneflix Music. Tests run on PostgreSQL (`php artisan test`, see `phpunit.xml`); run `php artisan migrate --seed` on the test database once first.

## Responses

- **Every controller method returns an API resource.** Create them with `php artisan make:resource SoundResource` (and `SoundCollection` for lists) under `App\Http\Resources`. The project stubs make them extend `Toneflix\ResourceModifier\Services\Json\JsonResource` / `Toneflix\ResourceModifier\Services\Json\ResourceCollection`. Streaming methods (file downloads, exports) are the only exception and return a `StreamedResponse` or `BinaryFileResponse`.
- The body is the resource with the usual envelope added through `additional()`:

  ```php
  return (new SoundResource($sound))
      ->additional([
          'message' => 'Sound updated.',
          'status' => 'success',
          'status_code' => HttpStatus::ACCEPTED,
      ])
      ->response()
      ->setStatusCode(HttpStatus::ACCEPTED->value);
  ```

  For a non-200 code chain `->response()->setStatusCode(code)`. A model created in the request makes Laravel answer 201, so chain `setStatusCode(HttpStatus::OK->value)` when that is not what you mean.
- Lists are paginated with `->paginate($request->perPage(20))` (or `->cursorPaginate()` for chat-style feeds) and returned as `new SoundCollection($sounds)`. The body is `{ data, meta: { currentPage, lastPage, perPage, total }, message, status, status_code }` (set in `config/resource-modifier.php`, no `links`), which the frontend's `ResponseBody`/`PageMeta` types read. Cursor pages carry `meta: { perPage, nextCursor, prevCursor }`. Extra counts for a list go beside `data` as their own key (`stats`, `summary`, `unread`), never inside `meta`.
- `$request->perPage($default, $max = 100)` reads `limit` and keeps it between 1 and `$max`. Never pass `$request->input('limit')` to `paginate()` directly.
- Every potentially unbounded result is paginated or processed in bounded batches. No `->get()` on a list that can grow with users or content.
- Errors are thrown, not returned: `abort_unless(...)`, `abort(HttpStatus::BAD_GATEWAY->value, $message)` or `ValidationException::withMessages([...])`. `App\Services\ExceptionHandler` renders them in the error envelope.
- `Providers::response()` is for the exception handler and middleware only, not controllers.
- Resource keys are camelCase. `prefer_camel_casing` camelizes each resource's top-level keys, but write them camelCase in `toArray()` anyway so the code reads like the response. Nested plain arrays are not converted. The envelope keys (`status_code`) and request fields stay snake_case.

## Models and migrations

- `$fillable` explicit; casts for JSON columns (`array`), dates and enums; relations typed.
- Route keys: models keep the key the frontend already uses (`id`, `slug` or `username`). Don't change one without changing the routes and the app.
- Migrations: one per table or change; PostgreSQL types (`jsonb`, `timestamptz`, `numeric`), indexes on every filtered column, foreign keys on every reference. Driver-specific SQL is guarded by `DB::getDriverName() === 'pgsql'`.

## PHP style

- **Fluent chains, one call per line.** A chain of two or more calls puts every `->call()` on its own line, indented once under the expression it continues; the root (`Sound::query()`, `$this`, `(new SoundResource($sound))`) stays on the first line. Single calls stay inline. This applies to every PHP file, tests and routes included.

  ```php
  $query = Release::query()
      ->with(['user', 'artist'])
      ->latest('id');
  ```

- Don't break a chain that need not break: consecutive statements that call methods on the same object, each returning it, are one chain. Compute the inputs first, then build the query once:

  ```php
  $sounds = Sound::query()
      ->library()
      ->search($search)
      ->when($category, fn(Builder $q) => $q->where('category', $category))
      ->latest('id')
      ->paginate($request->perPage(30));
  ```

- A call that takes a closure, or whose arguments would not fit comfortably on one line, lists one argument per line with the closing parenthesis on its own line:

  ```php
  ->when(
      $status === ArtistClaim::PENDING,
      fn(Builder $q) => $q->oldest('id'),
      fn(Builder $q) => $q->latest('id'),
  )
  ```

- A blank line separates a variable assignment from the statement that follows it. Consecutive one-line assignments may sit together as one block; a multi-line assignment always stands alone with a blank line before and after.
- Migration column definitions (`$table->string('slug')->unique();`) stay on one line; the chain is the column, not a flow.
- **Resources take a variable, nothing else.** `new SoundResource($sound)`, `new SoundCollection($sounds)`, `SafetyResource::collection($reports)`: the argument is always a plain local variable, never a query, a call, an array literal or an expression. The same holds for any constructor or function argument that would do work: a call that loads, saves or queries runs as its own statement first.

  ```php
  $takedown->load('moderator');

  return (new TakedownResource($takedown))
      ->additional([...])
      ->response()
      ->setStatusCode(HttpStatus::ACCEPTED->value);
  ```

- `pint.json` aligns Pint with the editor's formatter: `fn(` without a space before the parenthesis and spaces around `.` concatenation. Run `./vendor/bin/pint --dirty` before every commit; never hand-format against it.

## Comments

Comments say what a class, method, variable or block does now, in a line or two. No history, no warnings about other ways to write it. Leave them out when the code says it already.
