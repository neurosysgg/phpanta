# A login page

A recipe: the framework's pieces — [`Form`](../src/Form/Form.php),
[`Session`](../src/Http/Session.php), [`CsrfGuard`](../src/Service/Layer/CsrfGuard.php),
[`LoginGate`](../src/Service/Layer/LoginGate.php) and [`Login`](../src/Service/Login.php) —
put together into a page that signs a visitor in, a page behind it, and a way out. Nothing here is a
class the framework ships: a login page is a site's, down to its words and where it keeps its users.

The code below is what [`LoginRecipeTest`](../test/unit/LoginRecipeTest.php) runs end to end, under
the fixtures' names — `LoginFieldFixture` is `LoginField` here, and so on. The argument for each piece
is in [security.md](security.md#sessions-the-form-token-and-the-login).

## The form

Two fields, each saying what a password manager should fill it with:

```php
enum LoginField: string implements Autocompleting
{
    case Name     = 'name';
    case Password = 'password';

    public function label(): Translatable
    {
        return match ($this) {
            self::Name     => LoginText::Name,
            self::Password => LoginText::Password,
        };
    }

    public function type(): InputType
    {
        return match ($this) {
            self::Name     => InputType::Text,
            self::Password => InputType::Password,
        };
    }

    public function rules(): Collection
    {
        return new Collection(Rule::class)->with(new Required(), new MaxLength(128));
    }

    public function autocomplete(): Autocomplete
    {
        return match ($this) {
            self::Name     => Autocomplete::Username,
            self::Password => Autocomplete::CurrentPassword,
        };
    }
}
```

`LoginText` is a catalog of the site's own, holding the labels, the button, and the two messages a
session carries from one page to the next — `SignedIn` and `SignedOut` — as cases, so each is shown
in the visitor's language on whichever page reads it.

## The users

The site keeps a bcrypt digest per name. Where is the site's to decide; a data file beside the other
credentials is the smallest answer:

```php
// data/users.php — gitignored, excluded from a deploy, uploaded by hand like data/admin.php
return ['ada' => '$2y$12$…'];
```

```php
final readonly class Users
{
    public function hash(string $name): ?PasswordHash
    {
        $file  = App::current()->dataFile(SiteData::Users);
        $users = $file->exists() ? require $file->path : [];

        return PasswordHash::configured($users[$name] ?? '');
    }
}
```

A name the site does not know is null, and `Login` still pays for a comparison against it — so how
long an answer takes says nothing about which names exist.

## The login page

```php
final readonly class LoginController implements Controller
{
    public function __construct(private Login $login, private Users $users) {}

    public function handle(Request $request): Response
    {
        $form    = new Form(LoginField::class, SitePath::Login);
        $session = $request->session()->withToken();

        if ($request->isReadOnly()) {
            return self::page($form, $form->blank(), $session, HttpStatusCode::Ok);
        }

        $sent = $form->read($request, $session->token());

        if (!$sent->isValid()) {                         // not filled in: costs no attempt
            return self::page($form, $sent, $session, HttpStatusCode::UnprocessableContent);
        }

        $name   = $sent->value(LoginField::Name);
        $result = $this->login->attempt($request, $session, $name, $sent->value(LoginField::Password), $this->users->hash($name));

        return match (true) {
            $result instanceof Session  => $result->withMessage(LoginText::SignedIn)
                ->attachTo(new RedirectResponse(new Location(SitePath::Account->to()))),
            $result instanceof Response => $result,       // too many attempts: a 429
            default                     => self::page(
                $form,
                $sent->withError(LoginField::Password, FrameworkText::LoginRefused),
                $session,
                HttpStatusCode::UnprocessableContent,
            ),
        };
    }

    private static function page(Form $form, Submission $sent, Session $session, HttpStatusCode $status): Response
    {
        return $session->withoutMessages()->attachTo(new ViewResponse(
            new LoginPage($form->render($sent, (string) $session->token(), LoginText::SignIn), $session->messages()),
            $status,
            new Collection(Header::class)->with(new Header(ResponseHeader::CacheControl, CacheControl::doNotStore())),
        ));
    }
}
```

Six things in it are the recipe rather than the style:

- **The token is handed out on the read.** `withToken()` keeps the session's token or makes one, and
  the page attaches the session, so the form it renders posts a token `CsrfGuard` will find. A page
  that rendered the form without attaching its session would render a form whose every send is
  refused. The form reads the token back too: `read()` is handed the session's, and a send without
  it is a blank submission that says the form had expired — so a route that forgot its guard still
  refuses a forged send.
- **A form not filled in is checked before `Login` is asked**, so an empty password costs the
  visitor no attempt.
- **A wrong password and an unknown name are one answer**: the form again, the name kept, the
  password never written back, and `FrameworkText::LoginRefused` beside the password —
  `Submission::withError()` is the one refusal no field's rule could make.
- **Refused is a 422, not a 401.** A 401 must carry a `WWW-Authenticate` challenge, and a form login
  has none to give; 422 says the form came back to be fixed.
- **Signed in is a 303 to the page behind the login**, carrying the new session — whose token
  `Login` has rotated, so the one the login page wrote is worth nothing after it — and a message
  that page shows once.
- **Nothing here is kept by a cache**: every answer carries a session, so each says `no-store`.

## Signing out

A form with no fields, so what it sends is its token and nothing else:

```php
enum LogoutField: string implements Field
{
    // No cases. label(), type() and rules() are never asked of a form with no fields.
}

final readonly class LogoutController implements Controller
{
    public function handle(Request $request): Response
    {
        return $request->session()
            ->withoutUser()
            ->withMessage(LoginText::SignedOut)
            ->attachTo(new RedirectResponse(new Location(SitePath::Login->to())));
    }
}
```

`withoutUser()` forgets everything the session kept, the token included. The page behind the login
renders the form, with `new Form(LogoutField::class, SitePath::Logout)` and the session's token.

## The page behind it

Its route's gate has sent anyone else away before it runs, so the session here always has a user:

```php
$session = $request->session()->withToken();

return $session->withoutMessages()->attachTo(new ViewResponse(
    new AccountPage($session->user(), $session->messages(), $logoutForm),
    HttpStatusCode::Ok,
    $noStore,
));
```

## The routes

The guards are the routes', never the app's — see
[security.md](security.md#sessions-the-form-token-and-the-login) for why:

```php
new Route(SitePath::Login, fn(): Controller => new LoginController($login, new Users()),
    MethodSet::of(HttpMethod::Get, HttpMethod::Post))->through(new CsrfGuard()),
new Route(SitePath::Logout, fn(): Controller => new LogoutController(),
    MethodSet::of(HttpMethod::Post))->through(new CsrfGuard()),
new Route(SitePath::Account, fn(): Controller => new AccountController())
    ->through(new LoginGate(SitePath::Login)),
```

`$login` is `new Login(new Throttle($app->data()->directory('throttle'), limit: 5, window: 900))`.
Signing out takes a POST alone, so a link or a prefetch can never sign anybody out.

## What a deployment needs

- **`data/session.key`**, minted on the host it serves and never deployed — `SessionSeal`'s refusal
  says how. Without it the first page that keeps a session stops, loudly.
- **`data/throttle/`**, made by hand and writable by PHP. `Throttle` fails closed: without the
  directory every attempt is a 500, never an unlimited one.
- **`data/users.php`**, excluded from a deploy like the other credentials.

## What it does not do, and why

- **No return to the page the visitor wanted.** `LoginGate` sends a visitor to one fixed page and the
  login sends them to another. Carrying where they were going would be an address a request
  chose, redirected to — the open-redirect shape `Location` exists to refuse — so a site that wants
  it keeps a `Path` case in the session, never a URL.
- **A 429 is plain text, not the form.** Past the limit `Login` answers for itself, with
  `Retry-After`, and the page has nothing to add that the visitor could act on sooner.
- **No sign-up, no reset, no remember-me.** A session lasts two weeks already; the rest is a site's,
  and each needs a way to reach the visitor that this framework does not have.
