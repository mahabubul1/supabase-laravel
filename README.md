# Supabase Laravel

Use [Supabase](https://supabase.com) from a Laravel app. Query your database through the Supabase API, sign users in, store files, send Realtime messages, call Edge Functions, and run SQL migrations.

```php
use BlackDevs\SupabaseLaravel\Facades\Supabase;

$orders = Supabase::table('orders')
    ->select('id,total,status')
    ->eq('status', 'paid')
    ->orderBy('created_at', 'desc')
    ->limit(10)
    ->get();
```

## Contents

**Get started**

- [Requirements](#requirements)
- [Installation](#installation)
- [Connect to your database](#connect-to-your-database)
- [Choose who the request runs as](#choose-who-the-request-runs-as)

**Use the package**

- [Query data](#query-data)
- [Call Postgres functions (RPC)](#call-postgres-functions-rpc)
- [Handle errors](#handle-errors)
- [Authentication](#authentication)
- [Storage](#storage)
- [Edge Functions](#edge-functions)
- [Realtime](#realtime)
- [Migrations, functions and seeders](#migrations-functions-and-seeders)
- [Laravel integrations](#laravel-integrations)
- [Test your app](#test-your-app)

**Reference**

- [Logging and debugging](#logging-and-debugging)
- [Configuration reference](#configuration-reference)
- [Artisan commands](#artisan-commands)
- [Troubleshooting](#troubleshooting)

## Requirements

- PHP 8.4 or newer
- Laravel 11, 12 or 13
- A Supabase project
- The `pdo_pgsql` PHP extension, only if you use a [direct database connection](#step-4-add-a-direct-postgres-connection-optional)

## Installation

```bash
composer require bdevs/supabase-laravel
```

## Connect to your database

### How the connection works

The package reaches Supabase in two ways, and Eloquent is separate from both. Find what you want to do in this table, then set up only what it needs.

| You want to | Connection | What you need |
|---|---|---|
| Read and write rows with `Supabase::table()` or `Supabase::rpc()`, or use Auth, Storage, Realtime and Edge Functions | HTTPS to the Supabase APIs | `SUPABASE_URL` and API keys |
| Change the schema with `supabase:migrate` or `supabase:push-functions`, or run SQL seeders | Direct Postgres connection | `SUPABASE_DB_URL` and `pdo_pgsql` |
| Use Eloquent models, the `DB` facade or `php artisan migrate` | Laravel's own `pgsql` connection, not this package | `DB_*` settings |

Most apps start with the first row only.

> **Eloquent does not go through this package.** `Supabase::table()` returns plain arrays and does not use `DB_CONNECTION`. `User::all()` still uses whatever `DB_CONNECTION` is set to. To point Eloquent at Supabase, follow [step 5](#step-5-use-eloquent-with-supabase-optional).

### Step 1: Get your credentials

Open your project in the [Supabase dashboard](https://supabase.com/dashboard).

| `.env` variable | Value | Where to find it |
|---|---|---|
| `SUPABASE_URL` | `https://<project-ref>.supabase.co` | The project ref is in the dashboard address: `supabase.com/dashboard/project/<project-ref>` |
| `SUPABASE_ANON_KEY` | Publishable key (`sb_publishable_...`) or legacy `anon` key | Project Settings > API Keys |
| `SUPABASE_SERVICE_ROLE_KEY` | Secret key (`sb_secret_...`) or legacy `service_role` key | Project Settings > API Keys |
| `SUPABASE_JWT_SECRET` | Legacy JWT secret. Only needed for the [`supabase` auth guard](#protect-routes-with-the-supabase-guard) | Project Settings > JWT Keys |

> **Keep the secret key on the server.** It bypasses all Row Level Security. Never put it in JavaScript, in a `VITE_*` variable, or in Git.

### Step 2: Add them to `.env`

Run the installer:

```bash
php artisan supabase:install
```

It asks for your URL and keys, writes them to `.env`, publishes `config/supabase.php`, and offers to test the connection. It does not ask for `SUPABASE_DB_URL`.

Or add them by hand:

```env
SUPABASE_URL=https://abcdefghijkl.supabase.co
SUPABASE_ANON_KEY=sb_publishable_xxx
SUPABASE_SERVICE_ROLE_KEY=sb_secret_xxx
```

Publishing the config file is optional. You only need it to [add a second connection](#connect-to-more-than-one-project):

```bash
php artisan vendor:publish --tag=supabase-config
```

### Step 3: Test the connection

```bash
php artisan supabase:test
```

```
+-----------+--------+------+---------+-------+
| Check     | Status | HTTP | Latency | Error |
+-----------+--------+------+---------+-------+
| PostgREST | OK     | 200  | 86 ms   |       |
| Auth      | OK     | 200  | 47 ms   |       |
| Storage   | OK     | 200  | 59 ms   |       |
+-----------+--------+------+---------+-------+
```

This proves your server can reach the project. **It does not prove your keys are correct**, because any response below HTTP 500 counts as OK, including 401. It also does not check `SUPABASE_DB_URL`. The command exits with code 1 on failure, so you can use it in CI.

To test your keys, run a real query:

```bash
php artisan tinker --execute 'dump(Supabase::table("your_table")->limit(1)->get());'
```

| Result | Meaning |
|---|---|
| A collection, even an empty one | Everything works |
| `Invalid API key` | The key is wrong or empty |
| `Could not find the table` | Your keys work, but the table name is wrong |
| `Could not resolve host` | `SUPABASE_URL` is wrong, or the project is paused or removed |

### Step 4: Add a direct Postgres connection (optional)

You only need this for [migrations, pushing functions and SQL seeders](#migrations-functions-and-seeders), because the Supabase REST API cannot run schema changes.

**1. Install the PHP extension.** Check that `pdo_pgsql` is enabled:

```bash
php -m | grep pdo_pgsql
```

Composer does not check for this extension, so a missing one only shows up later as `could not find driver`.

**2. Copy a connection string.** In the dashboard, click **Connect** and choose a mode:

| Mode | Host and port | Works over IPv4 | Use it for |
|---|---|---|---|
| **Session pooler** (recommended) | `aws-<n>-<region>.pooler.supabase.com:5432` | Yes | These commands, and Eloquent on most servers |
| Transaction pooler | `aws-<n>-<region>.pooler.supabase.com:6543` | Yes | Serverless apps with many short connections. No prepared statements |
| Direct connection | `db.<project-ref>.supabase.co:5432` | Only with the paid IPv4 add-on | Servers that have IPv6 |

Copy the host from the dashboard. You cannot work out the `<n>` in the pooler host from your region. With the poolers, the username is `postgres.<project-ref>`, not just `postgres`.

**3. Add it to `.env`:**

```env
SUPABASE_DB_URL="postgresql://postgres.abcdefghijkl:YOUR-PASSWORD@aws-1-ap-southeast-1.pooler.supabase.com:5432/postgres"
```

Use your **database password** here, not an API key. You can reset it under Project Settings > Database. The commands always connect with `sslmode=require`.

**4. Encode special characters in the password.** A password containing `@ # / : ? % +` must be percent-encoded, or the connection fails. An unencoded `+` is the hardest to spot, because it silently becomes a space. This command prints the encoded form:

```bash
php -r 'echo rawurlencode($argv[1]), PHP_EOL;' 'my#pass+word'
# my%23pass%2Bword
```

**5. Test it:**

```bash
php artisan tinker --execute 'config(["database.connections.supabase_check" => ["driver" => "pgsql", "url" => config("supabase.connections.main.db_url"), "sslmode" => "require"]]); dump(DB::connection("supabase_check")->selectOne("select current_user, current_database()"));'
```

This uses a temporary connection that exists only for that command. It prints your database user if the connection works. See [Troubleshooting](#troubleshooting) if it fails.

### Step 5: Use Eloquent with Supabase (optional)

Supabase is a normal Postgres database, so Laravel can connect to it directly. Set this in `.env`, using the same session pooler string:

```env
DB_CONNECTION=pgsql
DB_URL="postgresql://postgres.abcdefghijkl:YOUR-PASSWORD@aws-1-ap-southeast-1.pooler.supabase.com:5432/postgres"
DB_SSLMODE=require
```

Then check it:

```bash
php artisan db:show
```

> **Secure your tables.** Any table in the `public` schema is reachable through the Supabase API. Tables created with SQL, which includes `php artisan migrate`, do not get Row Level Security automatically. Until you enable it, anyone with your publishable key can read and write every row, including `users` and `sessions`.
>
> Find the unprotected tables in the Supabase SQL editor:
>
> ```sql
> select tablename, rowsecurity from pg_tables where schemaname = 'public' order by tablename;
> ```
>
> Then enable RLS on each one:
>
> ```sql
> alter table public.users enable row level security;
> ```
>
> Eloquent keeps working if you connect as `postgres`, because the table owner is not restricted by RLS.

**Using the transaction pooler with Eloquent.** Port 6543 does not support prepared statements, so Laravel must emulate them. On Laravel 13, add `direct` to the `pgsql` connection in `config/database.php`. Laravel then emulates prepares and sends migrations through the direct settings:

```php
'pgsql' => [
    // ...existing settings...
    'direct' => array_filter([
        'port' => env('DB_DIRECT_PORT'),
    ]),
],
```

```env
DB_PORT=6543
DB_DIRECT_PORT=5432
```

Set `DB_HOST`, `DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD` separately for this setup. Port 5432 on the same pooler host is the session pooler, which can run migrations.

If your Laravel version has no pooled mode, add `'options' => [PDO::ATTR_EMULATE_PREPARES => true]` to the connection instead.

### Connect to more than one project

Publish the config file, then add a connection to `config/supabase.php`:

```php
'connections' => [
    'main' => [ /* ... */ ],

    'staging' => [
        'url' => env('SUPABASE_STAGING_URL'),
        'anon_key' => env('SUPABASE_STAGING_ANON_KEY'),
        'service_role_key' => env('SUPABASE_STAGING_SERVICE_ROLE_KEY'),
        'jwt_secret' => env('SUPABASE_STAGING_JWT_SECRET'),
        'db_url' => env('SUPABASE_STAGING_DB_URL'),
    ],
],
```

Pass the name as the last argument, or as `--connection` on a command:

```php
Supabase::table('orders', 'staging')->get();
Supabase::asUser($token, 'staging')->from('orders')->get();
Supabase::rpc('calculate_total', ['id' => 1], 'staging');
```

```bash
php artisan supabase:test --connection=staging
php artisan supabase:migrate --connection=staging
```

Set `SUPABASE_CONNECTION=staging` to change the default connection.

> **Do not run `supabase:install --connection=staging` to set this up.** It always writes to `SUPABASE_URL` and the other main variables, so it overwrites your `main` credentials. Add the `SUPABASE_STAGING_*` values to `.env` by hand.

## Choose who the request runs as

Every request runs as one of three roles. The choice decides whether Row Level Security (RLS) applies, so make it deliberately.

| Call | Runs as | RLS | Use it for |
|---|---|---|---|
| `Supabase::table('orders')` or `Supabase::serviceRole()->from('orders')` | Service role, using the secret key | **Bypassed** | Trusted server code such as jobs and admin tasks |
| `Supabase::asUser($token)->from('orders')` | The signed-in user | Enforced | Anything done on behalf of a user |
| `Supabase::anonymous()->from('orders')` | Anonymous visitor, using the publishable key | Enforced | Public data |

> **`Supabase::table()` ignores RLS.** It is a shortcut for the service role, so your code must do its own permission checks. In a request made by a user, prefer `asUser()`:
>
> ```php
> $todos = Supabase::asUser($request->bearerToken())->from('todos')->get();
> ```

## Query data

### Read rows

```php
$users = Supabase::table('users')
    ->select('id,name,email')
    ->eq('active', true)
    ->orderBy('name')             // or ->orderBy('name', 'desc')
    ->limit(10)
    ->offset(20)
    ->get();                      // Illuminate\Support\Collection of arrays

Supabase::table('users')->eq('id', $id)->single();       // array. Throws unless exactly one row matches
Supabase::table('users')->eq('id', $id)->maybeSingle();  // array or null
```

### Paginate

```php
$page = Supabase::table('users')->paginate(perPage: 15, page: 2);
// ['data' => [...], 'page' => 2, 'per_page' => 15, 'total' => 42, 'last_page' => 3]
```

This returns a plain array, not a Laravel paginator.

### Filter

```php
->eq('column', $value)
->neq('column', $value)
->gt('column', $value)
->gte('column', $value)
->lt('column', $value)
->lte('column', $value)
->in('column', [1, 2, 3])
->is('column', null)                      // also true or false
->like('column', 'Ada%')
->ilike('column', '%example.com')         // case-insensitive
->contains('tags', ['admin', 'staff'])    // array contains these values
->where('column', 'operator', $value)     // any other PostgREST operator
```

Filters on different columns are combined with `AND`.

> **Use only one filter per column.** A second filter on the same column replaces the first, with no error:
>
> ```php
> // Sends only age <= 65. The age >= 18 filter is lost.
> Supabase::table('people')->gte('age', 18)->lte('age', 65)->get();
> ```
>
> This also affects `update()` and `delete()`, where it can change more rows than you intended. For a range, use a [Postgres function](#call-postgres-functions-rpc).

**OR conditions:**

```php
Supabase::table('users')->orWhere([
    'status' => ['eq', 'active'],
    'role' => ['eq', 'admin'],
])->get();
// WHERE status = 'active' OR role = 'admin'
```

Each column can appear once, because PHP array keys must be unique. Call `orWhere()` only once per query: a second call produces a filter that PostgREST cannot parse.

### Insert, update, upsert and delete

```php
Supabase::table('users')->insert(['name' => 'Ada'])->get();
Supabase::table('users')->insert([['name' => 'Ada'], ['name' => 'Grace']])->get();  // many rows

Supabase::table('users')->eq('id', $id)->update(['name' => 'Ada Lovelace'])->get();

Supabase::table('users')->upsert(['email' => 'ada@example.com', 'name' => 'Ada'], onConflict: ['email'])->get();

Supabase::table('users')->eq('id', $id)->delete()->get();
```

> **Always finish with `->get()`.** `insert()`, `update()`, `upsert()` and `delete()` only build the request. Nothing is sent until you call `get()`, `single()` or `maybeSingle()`.

`insert`, `update` and `upsert` return the affected rows. Always add a filter before `update()` or `delete()`.

### Related tables

There is no `join()`. Select related tables through their foreign keys:

```php
$orders = Supabase::table('orders')
    ->select('id,total,user:users(id,name,email)')
    ->eq('status', 'paid')
    ->get();

$orders->first()['user']['name'];
```

A filter on a related table narrows the embedded rows, not the parent rows. To remove parent rows as well, use `!inner`:

```php
Supabase::table('orders')
    ->select('id,total,user:users!inner(id,name)')
    ->eq('user.active', true)
    ->get();
```

### Typed results

Map rows to a class instead of arrays. Columns you do not declare are ignored.

```php
use BlackDevs\SupabaseLaravel\Support\SupabaseResource;

class Order extends SupabaseResource
{
    public int $id;

    public ?string $note = null;
}

$orders = Supabase::table('orders')->getAs(Order::class);                     // Collection of Order
$order = Supabase::table('orders')->eq('id', 1)->singleAs(Order::class);      // Order
$order = Supabase::table('orders')->eq('id', 1)->maybeSingleAs(Order::class); // Order or null

$order->toArray();
```

> `php artisan supabase:schema-sync` is meant to generate these classes. It currently requests the project root instead of `/rest/v1/`, so it does not reach your schema. Write the classes by hand for now.

## Call Postgres functions (RPC)

```php
$total = Supabase::rpc('calculate_total', ['order_id' => $orderId]);

// Respect RLS by running as the user:
$total = Supabase::asUser($token)->rpc('calculate_total', ['order_id' => $orderId]);
```

The function must already exist in your database. See [Postgres functions](#postgres-functions) to create one.

## Handle errors

If Supabase returns an error, the package throws a subclass of `SupabaseException`. If Supabase cannot be reached, Laravel throws `ConnectionException`, which is **not** a `SupabaseException`, so catch both.

```php
use BlackDevs\SupabaseLaravel\Exceptions\SupabaseException;
use Illuminate\Http\Client\ConnectionException;

try {
    $orders = Supabase::table('orders')->get();
} catch (SupabaseException $e) {
    // Supabase replied with an error
    $e->getMessage();  // "Invalid API key"
    $e->status();      // 401
    $e->body();        // decoded response body
} catch (ConnectionException $e) {
    // Supabase could not be reached
}
```

| Exception | Thrown by |
|---|---|
| `SupabasePostgrestException` | Queries and RPC |
| `SupabaseAuthException` | `Supabase::auth()` |
| `SupabaseStorageException` | `Supabase::storage()` |
| `SupabaseFunctionsException` | Edge Functions |
| `SupabaseRealtimeException` | Realtime broadcast |

Connection failures and HTTP 429 or 503 responses are retried automatically. Other errors are not. See `SUPABASE_HTTP_RETRY_TIMES` in the [configuration reference](#configuration-reference).

## Authentication

### Sign users in

`Supabase::auth()` needs both the publishable and the secret key.

```php
$auth = Supabase::auth();

$auth->signUp('ada@example.com', 'password', ['name' => 'Ada']);

$session = $auth->signInWithPassword('ada@example.com', 'password');
$session['access_token'];
$session['refresh_token'];
$session['user'];

$session = $auth->refreshToken($session['refresh_token']);

$auth->getUser($accessToken);
$auth->updateUser($accessToken, ['data' => ['name' => 'Ada Lovelace']]);
$auth->signOut($accessToken);

$auth->signInWithMagicLink('ada@example.com', redirectTo: 'https://example.com/welcome');
$auth->verifyOtp('ada@example.com', $code, type: 'magiclink');
$auth->resetPasswordForEmail('ada@example.com', redirectTo: 'https://example.com/reset');

return redirect($auth->oauthAuthorizeUrl('github', redirectTo: 'https://example.com/callback'));

$auth->deleteUser($userId);  // permanent
```

### Protect routes with the supabase guard

The guard reads the `Authorization: Bearer <token>` header and verifies the JWT locally, without calling Supabase.

**1.** Set `SUPABASE_JWT_SECRET` in `.env`.

**2.** Add the guard to `config/auth.php`:

```php
'guards' => [
    // ...
    'supabase' => [
        'driver' => 'supabase',
        'connection' => null,  // optional: a connection name from config/supabase.php
    ],
],
```

**3.** Protect your routes with `auth:supabase`:

```php
Route::middleware('auth:supabase')->get('/me', function (Request $request) {
    $user = $request->user();

    return [
        'id' => $user->getAuthIdentifier(),  // the JWT "sub" claim
        'email' => $user->email,             // any claim, as a property
        'claims' => $user->claims(),
    ];
});
```

Clients should send `Accept: application/json`. Otherwise Laravel tries to redirect guests to a login page instead of returning 401.

> **Use `auth:supabase`, not the `supabase.auth` alias.** `supabase.auth` also blocks guests, but it does not make `supabase` the active guard. Behind it, `$request->user()` returns `null` and every Gate check is denied. If you must use it, read the user with `$request->user('supabase')`.

> **The guard only verifies HS256 tokens.** If your project uses asymmetric signing keys (ES256 or RS256), every token is rejected with a 401, even a valid one. To check, decode the header of an access token and look at `alg`. With asymmetric keys, skip the guard and validate tokens with `Supabase::auth()->getUser($token)`. That works with any algorithm, but makes a network call each time.

### Roles, Gates and Policies

Role helpers read from the verified token, so they do not run a query:

```php
$user->role();                           // app_metadata.role, or else the "role" claim
$user->roles();                          // app_metadata.roles, or else [role()]
$user->hasRole('admin');
$user->hasAnyRole(['admin', 'editor']);
```

```php
use BlackDevs\SupabaseLaravel\Auth\SupabaseUser;
use Illuminate\Support\Facades\Gate;

// In AppServiceProvider::boot()
Gate::define('manage-billing', fn (SupabaseUser $user) => $user->hasRole('admin'));
```

Set roles in `app_metadata`, using the Admin API or an [Auth Hook](#multi-tenancy). Never use `user_metadata`, because users can edit it themselves. Without an app role, `role()` returns the Postgres role, usually `authenticated`.

## Storage

### Upload, download and manage files

`Supabase::storage()` uses the secret key. Pass a user's token to apply their storage policies instead: `Supabase::storage($token)`.

```php
$storage = Supabase::storage();

$storage->upload('avatars', 'users/1.png', file_get_contents($path), 'image/png');
$storage->upload('avatars', 'users/1.png', $bytes, 'image/png', upsert: true);  // overwrite

// From a form. Generates a unique file name and detects the type.
$result = $storage->uploadFile('avatars', $request->file('image'), directory: 'users');

$bytes = $storage->download('avatars', 'users/1.png');
$storage->delete('avatars', ['users/1.png', 'users/2.png']);
$storage->move('avatars', 'users/1.png', 'archive/1.png');
$storage->copy('avatars', 'users/1.png', 'backups/1.png');
$objects = $storage->list('avatars', 'users', ['limit' => 100, 'offset' => 0]);
```

### File URLs

```php
// Public bucket. Builds the URL without making a request.
$url = $storage->getPublicUrl('avatars', 'users/1.png');

// Private bucket. The URL expires.
$url = $storage->createSignedUrl('avatars', 'users/1.png', expiresInSeconds: 60);

// Lets a client upload directly, without your secret key.
$upload = $storage->createSignedUploadUrl('avatars', 'users/1.png');
```

> **`createSignedUrl()` currently returns an incomplete URL.** It leaves out `/storage/v1`, so the link will not work. Add it back until this is fixed:
>
> ```php
> $url = str_replace('.supabase.co/object/', '.supabase.co/storage/v1/object/', $url);
> ```

### Buckets

```php
$storage->createBucket('avatars', ['public' => true, 'fileSizeLimit' => 5 * 1024 * 1024]);
$storage->getBucket('avatars');
$storage->listBuckets();
$storage->updateBucket('avatars', ['public' => false]);
$storage->emptyBucket('avatars');
$storage->deleteBucket('avatars');
```

### Use it as a Laravel disk

Add a disk to `config/filesystems.php`:

```php
'disks' => [
    // ...
    'supabase' => [
        'driver' => 'supabase',
        'bucket' => env('SUPABASE_STORAGE_BUCKET'),
        'connection' => null,
        'throw' => true,
    ],
],
```

```php
Storage::disk('supabase')->put('users/1.png', $bytes, ['mimetype' => 'image/png']);
Storage::disk('supabase')->get('users/1.png');
Storage::disk('supabase')->delete('users/1.png');
```

The disk has these limits:

- `put()` always overwrites an existing file.
- Files are stored as `application/octet-stream` unless you pass `mimetype`.
- `url()` throws `This driver does not support retrieving URLs.` Use `Supabase::storage()->getPublicUrl()` instead.
- `exists()`, `size()`, `mimeType()`, `lastModified()` and folder listings only look at the first 100 objects in a folder. A file beyond that looks like it does not exist.

## Edge Functions

```php
// As an anonymous visitor
$result = Supabase::invoke('hello', ['name' => 'Ada']);

// As a signed-in user
$result = Supabase::invoke('hello', ['name' => 'Ada'], accessToken: $token);

// With extra headers
$result = Supabase::functions($token)->invoke('hello', ['name' => 'Ada'], ['x-region' => 'eu']);
```

The result is the decoded JSON response, or the raw body if the response is not JSON.

An anonymous call sends your API key as the bearer token. New `sb_` keys are not JWTs, so a function that verifies the JWT in its own code will reject the call. Pass a user's token for those functions.

## Realtime

Laravel can send Realtime messages over HTTP. Receiving needs a WebSocket, which a PHP request cannot hold open, so that part happens in the browser.

### Send from Laravel

```php
Supabase::channel('room-1')->broadcast('message', ['text' => 'hi']);
Supabase::channel('orders.42')->broadcast('status.updated', ['status' => 'shipped'], private: true);
```

### Use Laravel's broadcasting system

Run `php artisan config:publish broadcasting` if you do not have `config/broadcasting.php`, then add a connection:

```php
'connections' => [
    // ...
    'supabase' => [
        'driver' => 'supabase',
        'connection' => null,
    ],
],
```

```env
BROADCAST_CONNECTION=supabase
```

Now events that implement `ShouldBroadcast` are sent through Supabase:

```php
class OrderShipped implements ShouldBroadcast
{
    public function __construct(public Order $order) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('orders.'.$this->order->id)];
    }
}
```

`routes/channels.php` is Laravel's own check. It does not stop anyone reading from Supabase's socket directly. To enforce that as well, add RLS policies on `realtime.messages`.

### Receive in the browser

This package ships no JavaScript. Install the official client:

```bash
npm install @supabase/supabase-js
```

```env
VITE_SUPABASE_URL="${SUPABASE_URL}"
VITE_SUPABASE_ANON_KEY="${SUPABASE_ANON_KEY}"
```

```ts
import { createClient } from '@supabase/supabase-js';

const supabase = createClient(
    import.meta.env.VITE_SUPABASE_URL,
    import.meta.env.VITE_SUPABASE_ANON_KEY,
);

// Public channel
const room = supabase
    .channel('room-1')
    .on('broadcast', { event: 'message' }, ({ payload }) => console.log(payload.text))
    .subscribe();

// Database changes. The table must be enabled for Realtime in Supabase.
supabase
    .channel('new-messages')
    .on(
        'postgres_changes',
        { event: 'INSERT', schema: 'public', table: 'messages', filter: 'room_id=eq.1' },
        (change) => console.log(change.new),
    )
    .subscribe();

// Stop listening
await supabase.removeChannel(room);
```

Private channels need the user's token, plus a `select` policy on `realtime.messages`. Without that policy, messages never arrive and you get no error.

```ts
await supabase.realtime.setAuth(userAccessToken);

supabase
    .channel('orders.42', { config: { private: true } })
    .on('broadcast', { event: 'status.updated' }, ({ payload }) => console.log(payload))
    .subscribe();
```

```sql
create policy "authenticated can receive broadcasts"
on realtime.messages for select to authenticated using (true);
```

This example policy lets any signed-in user read every private channel. Narrow it for real use. See [Realtime Authorization](https://supabase.com/docs/guides/realtime/authorization).

## Migrations, functions and seeders

These commands are separate from Laravel's own migrations. The ones that run SQL connect directly to Supabase, so they need [`SUPABASE_DB_URL`](#step-4-add-a-direct-postgres-connection-optional). They all accept `--connection` and `--dry-run`, and a dry run does not need a database connection. The `make` commands only create files.

### Table migrations

**1. Create a file:**

```bash
php artisan supabase:make-table create_invoices_table
# database/supabase/tables/2026_01_15_113537_create_invoices_table.sql
```

Names like `create_x_table` and `add_y_to_x_table` get working SQL. Any other name gets an empty template.

**2. Edit it.** The `-- up` section runs on migrate, and `-- down` runs on rollback.

```sql
-- up
create table if not exists public.invoices (
    id bigint generated by default as identity primary key,
    total numeric not null,
    created_at timestamptz not null default now()
);

alter table public.invoices enable row level security;

grant select, insert, update, delete on public.invoices to anon, authenticated, service_role;

-- down
drop table if exists public.invoices;
```

> **Add the `enable row level security` line yourself.** The generated file grants full access to `anon` and does not enable RLS. Without that line, anyone with your publishable key can read and change every row. With RLS on and no policies, only your secret key has access, until you add policies.

**3. Run it:**

```bash
php artisan supabase:migrate --dry-run     # see what would run
php artisan supabase:migrate               # run everything pending
php artisan supabase:migrate invoices      # only files with "invoices" in the name
```

**4. Roll back if needed:**

```bash
php artisan supabase:rollback-table                              # the last batch
php artisan supabase:rollback-table --step=2                     # the last 2 batches
php artisan supabase:rollback-table add_paid_to_invoices_table   # just this one
```

**Things to know before relying on these commands:**

- **History is stored in a local file, not in the database.** It lives at `storage/framework/supabase-migrations-{connection}.json`. On a new machine, CI runner or server with fresh storage, every migration looks pending and runs again. Either commit this file, or always migrate from one place.
- **A failed run forgets what it finished.** If the third file fails, the first two are applied but not recorded, so the next run applies them again. Make migrations safe to repeat, with `create table if not exists` and `add column if not exists`.
- **Avoid comments that start with `-- up` or `-- down`.** The markers are matched by prefix, so `-- update users` or `-- download count` splits the file in the wrong place. A migration with an empty `-- up` section is skipped with a warning.
- **Rollback runs the saved copy of `-- down`** from when the migration was applied, not the current file.

### Postgres functions

```bash
php artisan supabase:make-function calculate_total
# database/supabase/functions/calculate_total.sql
```

Edit the file, then push it. Only files that changed since the last push are applied.

```bash
php artisan supabase:push-functions --dry-run
php artisan supabase:push-functions
php artisan supabase:push-functions --force    # push everything
```

Call it with `Supabase::rpc('calculate_total', [...])`.

To undo, restore the previous version. The last 10 versions of each file are kept, and each rollback goes one step further back:

```bash
php artisan supabase:rollback-function calculate_total
```

> **Check the generated function's permissions.** The template uses `security definer`, so the function runs with its owner's rights and ignores RLS. Functions in `public` can also be called through the API with your publishable key. If the function is for your server only, add this to the file, using your function's real parameter types:
>
> ```sql
> revoke execute on function public.calculate_total(bigint) from public, anon, authenticated;
> ```

As with migrations, history is stored in local files under `storage/framework/`, and a failed run does not record the files it finished.

### Seeders

```bash
php artisan supabase:make-seeder invoices          # PHP class, goes through the API
php artisan supabase:make-seeder invoices --sql    # raw SQL file, goes through SUPABASE_DB_URL

php artisan supabase:seed                          # run all
php artisan supabase:seed invoices                 # only matching seeders
```

```php
// database/supabase/seeders/InvoicesSeeder.php
class InvoicesSeeder
{
    public function run(): void
    {
        Supabase::table('invoices')->insert([
            ['total' => 100],
            ['total' => 250],
        ])->get();
    }
}
```

SQL seeders run first, then PHP seeders. Seeders are not tracked, so each run repeats them. Write them to be safe to repeat, for example with `upsert()`.

## Laravel integrations

### Events

| Event (in `BlackDevs\SupabaseLaravel\Events`) | Fired by | Properties |
|---|---|---|
| `UserSignedUp` | `auth()->signUp()` | `$response` |
| `UserSignedIn` | `auth()->signInWithPassword()` | `$session` |
| `UserSignedOut` | `auth()->signOut()` | `$accessToken` |
| `FileUploaded` | `storage()->upload()`, `uploadFile()` | `$bucket`, `$path`, `$response` |
| `FileDeleted` | `storage()->delete()` | `$bucket`, `$paths` |

```php
Event::listen(UserSignedUp::class, function (UserSignedUp $event) {
    // $event->response['user']
});
```

Queries do not fire events.

### Queue

Run a Supabase call in the background so it cannot slow down or fail the request:

```php
use BlackDevs\SupabaseLaravel\Jobs\CallSupabase;

CallSupabase::dispatch(fn () => Supabase::table('audit_log')->insert([
    'action' => 'order.shipped',
    'order_id' => $order->id,
])->get());
```

It is a normal queued job, so `->onQueue()` and `->delay()` work.

### Notifications

Deliver a notification as a private Realtime message:

```php
use BlackDevs\SupabaseLaravel\Notifications\SupabaseChannel;

class OrderShipped extends Notification
{
    public function __construct(public Order $order) {}

    public function via(mixed $notifiable): array
    {
        return [SupabaseChannel::class];
    }

    public function toSupabase(mixed $notifiable): array
    {
        return ['order_id' => $this->order->id];
    }
}
```

The message goes to `notifications.{id}` with the event name `notification`. To use another topic, add `receivesSupabaseBroadcastsOn(Notification $notification): string` to the notifiable model. The browser needs a [private channel](#receive-in-the-browser) to receive it.

### Validation

```php
use BlackDevs\SupabaseLaravel\Validation\SupabaseUnique;

$request->validate([
    'email' => ['required', 'email', new SupabaseUnique('users')],
    'slug' => [(new SupabaseUnique('posts', 'slug'))->ignore($post->id)],
    'code' => [(new SupabaseUnique('coupons'))->connection('staging')],
]);
```

### Cache

> **This store does not work on Laravel 13.** `SupabaseStore` is missing the `touch()` method that Laravel 13 requires, so using it causes a fatal error. Use another cache store on Laravel 13.

On earlier versions, create the table and keep it private:

```sql
create table public.cache (
    key text primary key,
    value text not null,
    expiration bigint
);

alter table public.cache enable row level security;
```

Add the store to `config/cache.php`:

```php
'stores' => [
    // ...
    'supabase' => [
        'driver' => 'supabase',
        'table' => 'cache',
        'connection' => null,
        'prefix' => '',
    ],
],
```

```php
Cache::store('supabase')->put('key', 'value', 60);
```

`increment()` and `decrement()` are not atomic, so do not use them for counters that must be exact.

### Webhooks

Supabase Database Webhooks do not sign their requests, so the package checks a shared secret sent as a header.

**1.** Set the secret in `.env`:

```env
SUPABASE_WEBHOOK_SECRET=a-long-random-string
```

**2.** In the Supabase dashboard, add an HTTP header to the webhook: `X-Supabase-Signature: a-long-random-string`.

**3.** Protect the route. Put it in `routes/api.php`, because routes in `web.php` also need a CSRF exception.

```php
Route::post('/webhooks/supabase', SupabaseWebhookController::class)->middleware('supabase.webhook');
```

The route returns 401 if the header is wrong, and 500 if the secret is not set.

### Multi-tenancy

Scope queries to the tenant in the signed-in user's token. The filter is only applied to queries built with this trait, and those queries run with the secret key, so RLS will not catch a query you forget to scope.

**1.** Add a tenant claim with a [Custom Access Token Hook](https://supabase.com/docs/guides/auth/auth-hooks). This example assumes a `public.tenant_users` table that maps users to tenants:

```sql
create or replace function public.custom_access_token_hook(event jsonb)
returns jsonb
language plpgsql
stable
as $$
declare
    claims jsonb;
    current_tenant uuid;
begin
    select tenant_id into current_tenant
    from public.tenant_users
    where user_id = (event->>'user_id')::uuid;

    claims := event->'claims';
    claims := jsonb_set(claims, '{tenant_id}', coalesce(to_jsonb(current_tenant), 'null'));

    return jsonb_set(event, '{claims}', claims);
end;
$$;

grant usage on schema public to supabase_auth_admin;
grant execute on function public.custom_access_token_hook to supabase_auth_admin;
revoke execute on function public.custom_access_token_hook from authenticated, anon, public;

grant select on table public.tenant_users to supabase_auth_admin;
create policy "auth admin reads tenant_users" on public.tenant_users
    for select to supabase_auth_admin using (true);
```

Then enable the hook in the dashboard, under Authentication > Hooks.

**2.** Turn on tenancy in `.env`:

```env
SUPABASE_TENANCY_ENABLED=true
SUPABASE_TENANT_CLAIM=tenant_id
SUPABASE_TENANT_COLUMN=tenant_id
```

**3.** Use the trait. It needs the [`supabase` guard](#protect-routes-with-the-supabase-guard).

```php
use BlackDevs\SupabaseLaravel\Concerns\ScopesToTenant;

class InvoiceRepository
{
    use ScopesToTenant;

    public function all(): Collection
    {
        return $this->tenantTable('invoices')->get();  // adds tenant_id = <the user's tenant>
    }
}
```

It throws `RuntimeException` if tenancy is off, nobody is signed in, or the claim is missing. The claim is read from `app_metadata` first, then from the top level of the token.

### Vector search

Store and search [pgvector](https://supabase.com/docs/guides/ai) embeddings. Searching needs a Postgres function that does the matching, such as this `match_documents` example:

```sql
create function match_documents(query_embedding vector(1536), match_threshold float, match_count int)
returns table (id bigint, content text, similarity float)
language sql stable as $$
    select id, content, 1 - (embedding <=> query_embedding) as similarity
    from documents
    where 1 - (embedding <=> query_embedding) > match_threshold
    order by embedding <=> query_embedding
    limit match_count;
$$;
```

```php
Supabase::vector()->upsert('documents', ['id' => 1, 'content' => 'Hello'], 'embedding', $embedding);

$matches = Supabase::vector()->search('match_documents', $queryEmbedding, 5, ['match_threshold' => 0.7]);
```

## Test your app

`Supabase::fake()` fakes the responses, so your tests never reach a real project.

```php
use BlackDevs\SupabaseLaravel\Facades\Supabase;
use Illuminate\Support\Facades\Http;

it('lists paid orders', function () {
    Supabase::fake([
        'rest/v1/orders*' => Http::response([['id' => 1, 'status' => 'paid']]),
    ]);

    $this->getJson('/api/orders')->assertOk()->assertJsonCount(1);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'status=eq.paid'));
});
```

The keys are checked before any request is made, so set dummy values in `phpunit.xml`. Otherwise your tests fail in CI with `service_role_key is not set`.

```xml
<env name="SUPABASE_URL" value="https://example.supabase.co"/>
<env name="SUPABASE_ANON_KEY" value="test-anon-key"/>
<env name="SUPABASE_SERVICE_ROLE_KEY" value="test-secret-key"/>
```

## Logging and debugging

```env
SUPABASE_LOG_ENABLED=true   # method, URL, status and duration of each request
SUPABASE_DEBUG=true         # also logs request and response bodies
```

Both are off by default. `password`, `access_token`, `refresh_token`, `apikey` and `authorization` are masked in the logs, but other sensitive data in your rows is not, so do not leave debug mode on in production. Error responses are logged as `warning`. Requests and successful responses are logged as `debug`.

## Configuration reference

| Variable | Default | Purpose |
|---|---|---|
| `SUPABASE_CONNECTION` | `main` | The default connection |
| `SUPABASE_URL` | | Project URL. Needed for everything |
| `SUPABASE_ANON_KEY` | | Publishable key. Needed for `asUser()`, `anonymous()`, `auth()` and `functions()` |
| `SUPABASE_SERVICE_ROLE_KEY` | | Secret key. Needed for `table()`, `rpc()`, `auth()`, `storage()`, `channel()` and `vector()` |
| `SUPABASE_JWT_SECRET` | | Needed for the `supabase` guard |
| `SUPABASE_DB_URL` | | Postgres connection string for the SQL commands |
| `SUPABASE_STORAGE_BUCKET` | | Bucket name, if you use it in your disk config |
| `SUPABASE_HTTP_TIMEOUT` | `10` | Request timeout in seconds |
| `SUPABASE_HTTP_RETRY_TIMES` | `2` | Total attempts for each request |
| `SUPABASE_HTTP_RETRY_SLEEP_MS` | `200` | Base wait between attempts. A `Retry-After` header takes priority |
| `SUPABASE_LOG_ENABLED` | `false` | Log each request |
| `SUPABASE_LOG_CHANNEL` | App default | Log channel to use |
| `SUPABASE_DEBUG` | `false` | Also log request and response bodies |
| `SUPABASE_TENANCY_ENABLED` | `false` | Turn on tenant scoping |
| `SUPABASE_TENANT_CLAIM` | `tenant_id` | JWT claim that holds the tenant ID |
| `SUPABASE_TENANT_COLUMN` | `tenant_id` | Column to filter by |
| `SUPABASE_WEBHOOK_SECRET` | | Shared secret for `supabase.webhook` |
| `SUPABASE_WEBHOOK_HEADER` | `X-Supabase-Signature` | Header that carries the secret |

If your config is cached, run `php artisan config:clear` after changing `.env`.

## Artisan commands

| Command | Purpose | Main options |
|---|---|---|
| `supabase:install` | Set up credentials interactively | `--connection`, `--force` |
| `supabase:test` | Check the APIs are reachable | `--connection` |
| `supabase:make-table {name}` | Create a migration file | `--path` |
| `supabase:migrate {table?}` | Run pending migrations | `--connection`, `--path`, `--table`, `--dry-run` |
| `supabase:rollback-table {name?}` | Undo migrations | `--step`, `--connection`, `--dry-run` |
| `supabase:make-function {name}` | Create a function file | `--path`, `--force` |
| `supabase:push-functions` | Apply changed function files | `--connection`, `--path`, `--force`, `--dry-run` |
| `supabase:rollback-function {function}` | Restore a function's previous version | `--connection`, `--dry-run` |
| `supabase:make-seeder {name}` | Create a seeder | `--sql`, `--path` |
| `supabase:seed {name?}` | Run seeders | `--connection`, `--path`, `--dry-run` |
| `supabase:schema-sync` | Generate typed classes. [Not working yet](#typed-results) | `--connection`, `--path`, `--namespace`, `--only`, `--force` |

## Troubleshooting

**Connecting over HTTPS**

| Problem | Cause and fix |
|---|---|
| `Could not resolve host` | `SUPABASE_URL` is wrong, or the project is paused or removed. Check the project in the dashboard |
| `Supabase service_role_key is not set.` | The variable is missing from `.env`, or the config is cached. Set it, then run `php artisan config:clear` |
| `Invalid API key`, but `supabase:test` passes | `supabase:test` does not check keys. The key is wrong or empty. Copy it again |
| `Could not find the table` | The table does not exist, or the API has not noticed it yet. Run `notify pgrst, 'reload schema';` in the SQL editor |
| A filter seems to be ignored | You used [two filters on one column](#filter) |
| Writes do nothing, and there is no error | You left out `->get()` after `insert()`, `update()`, `upsert()` or `delete()` |
| Rows are missing with `asUser()` or `anonymous()` | RLS is hiding them. Check your policies |

**Connecting directly to Postgres**

| Problem | Cause and fix |
|---|---|
| `No db_url configured for the [main] connection` | Set `SUPABASE_DB_URL` |
| `could not find driver` | The `pdo_pgsql` extension is not enabled. On Ubuntu, install `php8.4-pgsql` |
| `Network is unreachable` or a timeout on `db.<ref>.supabase.co` | The direct host only supports IPv6. Use the session pooler |
| `tenant/user postgres.xxx not found` | The pooler host or username is wrong, or the project is paused or removed. Copy the string from **Connect** again |
| `password authentication failed` | Use the database password, not an API key, and [encode special characters](#step-4-add-a-direct-postgres-connection-optional) |
| `Invalid db_url` | The password has unencoded special characters. This error prints the whole URL, including the password, so clear it from any CI logs |
| `prepared statement ... does not exist` | Eloquent is using the transaction pooler. Use the session pooler, or [emulate prepares](#step-5-use-eloquent-with-supabase-optional) |
| `supabase:migrate` fails on a change that was already made | [The history file](#table-migrations) is missing or was not updated. Make the SQL safe to repeat, or restore the file |

**Authentication**

| Problem | Cause and fix |
|---|---|
| `Auth guard [supabase] is not defined.` | Add the guard to `config/auth.php` |
| 401 on every request, even with a valid token | The project uses asymmetric signing keys, or `SUPABASE_JWT_SECRET` is wrong. See [the guard](#protect-routes-with-the-supabase-guard) |
| A redirect instead of a 401 | The client must send `Accept: application/json` |
| `$request->user()` is `null`, or every Gate is denied | You used `supabase.auth`. Use `auth:supabase` |

**Storage, cache and Realtime**

| Problem | Cause and fix |
|---|---|
| `This driver does not support retrieving URLs.` | The disk has no `url()`. Use `Supabase::storage()->getPublicUrl()` |
| A signed URL returns 404 | [Add `/storage/v1`](#file-urls) to the URL |
| `SupabaseStore contains 1 abstract method` | The cache store does not work on Laravel 13. [Use another store](#cache) |
| Private Realtime messages never arrive | Add a `select` policy on `realtime.messages`, and call `setAuth()` before subscribing |

## License

MIT
