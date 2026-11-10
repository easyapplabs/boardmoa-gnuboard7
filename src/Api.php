<?php

namespace Plugins\Easyapplabs\Boardmoa;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * 요청 입구: ?r=<route> 를 처리하고 {"ok", "data"|"error"} 로 답한다 (docs/API.md).
 */
class Api
{
    const VERSION = '1.1.21';

    /** route => [메서드, 로그인 필요(true)/선택(false)/토큰 안 봄(null), 처리] */
    private const ROUTES = [
        'site' => ['GET', false, [Boards::class, 'site']],
        'menu' => ['GET', false, [Boards::class, 'menu']],
        'auth.login' => ['POST', null, [self::class, 'login']],
        'auth.refresh' => ['POST', null, [self::class, 'refresh']],
        'auth.logout' => ['POST', null, [self::class, 'logout']],
        'auth.devices' => ['GET', true, [self::class, 'devices']],
        'auth.devices.revoke' => ['POST', true, [self::class, 'revokeDevice']],
        'me' => ['GET', true, [self::class, 'me']],
        'feed' => ['GET', false, [Boards::class, 'feed']],
        'board' => ['GET', false, [Boards::class, 'board']],
        'document' => ['GET|POST', false, [Boards::class, 'document']],
        'comments' => ['GET', false, [Boards::class, 'comments']],
        'page' => ['GET', false, [Boards::class, 'page']],
        'resolve' => ['GET', false, [Boards::class, 'resolve']],
        'file' => ['GET', false, [Boards::class, 'file']],
        'document.write' => ['POST', false, [Write::class, 'write']],
        'document.source' => ['GET|POST', false, [Write::class, 'source']],
        'document.update' => ['POST', false, [Write::class, 'update']],
        'document.delete' => ['POST', false, [Write::class, 'delete']],
        'comment.write' => ['POST', false, [Write::class, 'commentWrite']],
        'comment.update' => ['POST', false, [Write::class, 'commentUpdate']],
        'comment.delete' => ['POST', false, [Write::class, 'commentDelete']],
        'comment.vote' => ['POST', false, [Write::class, 'commentVote']],
        // 댓글 신고 (1.1.21~)
        'comment.declare' => ['POST', true, [Write::class, 'commentDeclare']],
        'document.vote' => ['POST', true, [Write::class, 'vote']],
        'document.scrap' => ['POST', true, [Write::class, 'scrap']],
        'document.declare' => ['POST', true, [Write::class, 'declare']],
        'my.menu' => ['GET', true, [My::class, 'menu']],
        'my.documents' => ['GET', true, [My::class, 'documents']],
        'my.comments' => ['GET', true, [My::class, 'comments']],
        'my.scraps' => ['GET', true, [My::class, 'scraps']],
        'my.scrap.delete' => ['POST', true, [My::class, 'scrapDelete']],
        'member.menu' => ['GET', false, [My::class, 'memberMenu']],
        'member.info' => ['GET', true, [My::class, 'memberInfo']],
        'signup.form' => ['GET', false, [My::class, 'signupForm']],
        'signup' => ['POST', null, [My::class, 'signup']],
        'me.form' => ['GET', true, [My::class, 'form']],
        'me.update' => ['POST', true, [My::class, 'update']],
        'me.password' => ['POST', true, [My::class, 'password']],
        'me.image' => ['POST', true, [My::class, 'image']],
        'me.leave' => ['POST', true, [My::class, 'webOnly']],
        'notify.count' => ['GET', true, [My::class, 'notifyCount']],
        'notify.list' => ['GET', true, [My::class, 'notifyList']],
        'notify.read' => ['POST', true, [My::class, 'notifyRead']],
        'notify.readall' => ['POST', true, [My::class, 'notifyReadAll']],
    ];

    public static ?Request $request = null;

    /** 이번 요청의 회원 (비회원이면 null) */
    public static ?User $user = null;

    /** 이번 요청의 기기 줄 (토큰으로 들어왔을 때) */
    public static ?object $token = null;

    public function handle(Request $request): Response
    {
        self::$request = $request;
        self::$user = null;
        self::$token = null;
        Engine::$bearer = '';
        Engine::$secretViewToken = '';
        try {
            $route = $request->query('r', '');
            $route = is_string($route) ? $route : '';
            if (! isset(self::ROUTES[$route])) {
                throw new ApiError(404, 'unknown_route', 'unknown route');
            }
            [$method, $login, $handler] = self::ROUTES[$route];
            $methods = explode('|', $method);
            if (! in_array($request->method(), $methods, true) && ! (in_array('GET', $methods, true) && $request->method() === 'HEAD')) {
                throw new ApiError(405, 'method_not_allowed', 'method not allowed');
            }
            if ($login !== null) {
                self::authenticate($login);
            }
            $data = call_user_func($handler);
            if ($data instanceof Response) {
                return $data;
            }

            return self::json(['ok' => true, 'data' => $data], 200);
        } catch (ApiError $e) {
            $error = array_merge($e->extra, ['code' => $e->codeName, 'message' => $e->raw ? $e->getMessage() : Text::t($e->getMessage())]);

            return self::json(['ok' => false, 'error' => $error], $e->status);
        } catch (\Throwable $e) {
            report($e);

            return self::json(['ok' => false, 'error' => ['code' => 'server_error', 'message' => Text::t('서버에서 처리하지 못했습니다.')]], 500);
        } finally {
            Registry::maybeNotify();
        }
    }

    private static function json(array $body, int $status): Response
    {
        return response()->json($body, $status, ['Cache-Control' => 'no-store, private', 'Vary' => 'Authorization, X-AppApi-Token, X-App-Lang'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function message(string $code): string
    {
        $messages = [
            'not_found' => '없는 페이지입니다.',
            'not_permitted' => '권한이 없습니다.',
            'login_required' => '로그인이 필요합니다.',
        ];

        return Text::t($messages[$code] ?? '처리하지 못했습니다.');
    }

    /** 글자 값 하나. 배열·객체로 온 값은 없는 것으로 본다 */
    public static function param(string $name, ?string $default = null): ?string
    {
        $value = self::$request->input($name);
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return $default;
    }

    public static function intParam(string $name, int $default = 0): int
    {
        $value = self::$request->input($name);

        return is_numeric($value) ? (int) $value : $default;
    }

    public static function boolParam(string $name): bool
    {
        $value = self::$request->input($name);

        return $value === true || in_array(strtolower((string) $value), ['1', 'y', 'true', 'on', 'yes'], true);
    }

    // ---- 인증

    public static function bearer(): string
    {
        $header = (string) self::$request->header('Authorization', '');
        if ($header === '') {
            $header = (string) ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        }

        if (stripos($header, 'Bearer ') === 0) {
            return trim(substr($header, 7));
        }

        // 공유 호스팅·프록시가 Authorization 을 떨어뜨려도: 앱이 같은 토큰을 이 머리말로 한 번 더 보낸다
        return trim((string) self::$request->header('X-AppApi-Token', ''));
    }

    /** Bearer 토큰으로 회원을 확인하고 이번 요청의 엔진 호출에 그 토큰을 넘긴다 */
    private static function authenticate(bool $required): void
    {
        $access = self::bearer();
        if ($access === '') {
            if ($required) {
                throw new ApiError(401, 'login_required', self::message('login_required'));
            }

            return;
        }
        $row = Store::findByAccess($access);
        if (! $row) {
            throw new ApiError(401, 'token_expired', 'access token expired');
        }
        $user = self::checkUser($row);
        Store::touch($row, (string) self::$request->ip());
        self::$token = $row;
        self::$user = $user;
        Engine::$bearer = $access;
        self::restoreUser();
    }

    /** 매번 회원 상태를 다시 본다: 탈퇴·차단·비밀번호 변경이면 끊는다 */
    private static function checkUser(object $row): User
    {
        $user = User::find($row->user_id);
        if (! $user || ! self::active($user)) {
            Store::revokeUser((int) $row->user_id);
            throw new ApiError(401, 'session_revoked', '로그인할 수 없는 계정입니다.');
        }
        if (! hash_equals((string) $row->password_sig, Store::passwordSig($user->password))) {
            Store::revoke((int) $row->id);
            throw new ApiError(401, 'session_revoked', 'password changed');
        }

        return $user;
    }

    private static function active(User $user): bool
    {
        $status = $user->status ?? 'active';
        $status = $status instanceof \BackedEnum ? $status->value : (string) $status;

        return $status === '' || $status === 'active';
    }

    /** 엔진 호출 뒤 이번 요청의 회원을 다시 넣는다(엔진 호출이 인증 상태를 지운다) */
    public static function restoreUser(): void
    {
        if (self::$user) {
            Auth::setUser(self::$user);
        }
    }

    /**
     * 엔진 로그인(/api/auth/login)을 그대로 실행한다: 실패 횟수 제한·계정 잠금·휴면·2단계 인증·비밀번호 만료가 웹과 같다.
     */
    public static function login(): array
    {
        $id = trim((string) self::param('user_id', ''));
        $password = (string) self::param('password', '');
        if ($id === '' || $password === '') {
            throw new ApiError(400, 'missing_credentials', '아이디와 비밀번호를 입력하세요.');
        }
        [$status, $json] = Engine::call('POST', '/api/auth/login', ['email' => $id, 'password' => $password]);
        $data = is_array($json) ? ($json['data'] ?? []) : [];
        if ($status >= 200 && $status < 300 && is_array($data)) {
            if (! empty($data['two_factor_required'])) {
                throw new ApiError(403, 'not_permitted', '2단계 인증을 쓰는 계정은 아직 앱에서 로그인할 수 없습니다. 웹사이트에서 이용해 주세요.', ['two_factor' => 'web']);
            }
            if (! empty($data['password_change_required'])) {
                throw new ApiError(403, 'not_permitted', '비밀번호를 바꿔야 하는 계정입니다. 웹사이트에서 로그인해 비밀번호를 바꾼 뒤 다시 로그인해 주세요.');
            }
            $token = (string) ($data['token'] ?? $data['access_token'] ?? '');
            $uuid = (string) ($data['user']['uuid'] ?? '');
            $user = $uuid !== '' ? User::where('uuid', $uuid)->first() : null;
            if (! $user && $token !== '') {
                $row = \Laravel\Sanctum\PersonalAccessToken::findToken($token);
                $user = $row ? User::find($row->tokenable_id) : null;
            }
            if (! $user) {
                throw new ApiError(401, 'login_failed', '아이디 또는 비밀번호가 맞지 않습니다.');
            }
            [$tokens, $deviceId] = Store::issue($user, $token, (string) self::param('device_name', ''), (string) self::param('platform', ''), (string) self::$request->ip());
            self::$user = $user;
            self::restoreUser();

            return ['tokens' => $tokens, 'member' => self::profile($user)];
        }
        if ($status === 401 || $status === 422) {
            throw new ApiError(401, 'login_failed', '아이디 또는 비밀번호가 맞지 않습니다.');
        }
        throw Engine::error($status, $json);
    }

    public static function refresh(): array
    {
        $result = Store::rotate((string) self::param('refresh_token', ''), (string) self::$request->ip());
        if (! $result) {
            throw new ApiError(401, 'refresh_invalid', 'login required');
        }
        self::checkUser($result[0]);

        return ['tokens' => $result[1]];
    }

    public static function logout(): array
    {
        $row = Store::findByAccess(self::bearer());
        if (! $row && self::param('refresh_token')) {
            $result = Store::rotate((string) self::param('refresh_token'), (string) self::$request->ip());
            $row = $result ? $result[0] : null;
        }
        if ($row) {
            Store::revoke((int) $row->id);
        }

        return ['logged_out' => true];
    }

    public static function devices(): array
    {
        $current = self::$token ? (int) self::$token->id : 0;
        $iso = fn ($time) => $time ? \Carbon\Carbon::createFromTimestamp((int) $time)->setTimezone((string) (g7_core_settings('general.timezone') ?: config('app.timezone', 'UTC')))->toIso8601String() : null;
        $devices = [];
        foreach (Store::devices((int) self::$user->getKey()) as $row) {
            $devices[] = [
                'device_srl' => (int) $row->id,
                'device_name' => (string) $row->device_name,
                'platform' => (string) $row->platform,
                'created' => $iso($row->created),
                'last_used' => $iso($row->last_used),
                'current' => (int) $row->id === $current,
            ];
        }

        return ['devices' => $devices];
    }

    public static function revokeDevice(): array
    {
        $srl = self::intParam('device_srl');
        if ($srl <= 0 || ! Store::revokeOwn((int) self::$user->getKey(), $srl)) {
            throw new ApiError(404, 'not_found', self::message('not_found'));
        }

        return ['revoked' => true];
    }

    public static function me(): array
    {
        return self::profile(self::$user);
    }

    /** 앱의 member 객체. 이름은 웹이 글쓴이로 보이는 이름(name) */
    public static function profile(?User $user): ?array
    {
        if (! $user) {
            return null;
        }
        $groups = [];
        try {
            foreach ($user->roles ?? [] as $role) {
                $name = method_exists($role, 'getLocalizedName') ? $role->getLocalizedName() : ($role->name ?? '');
                if (is_array($name)) {
                    $name = $name[app()->getLocale()] ?? reset($name);
                }
                if ((string) $name !== '') {
                    $groups[] = (string) $name;
                }
            }
        } catch (\Throwable $e) {
            $groups = [];
        }

        return [
            'member_srl' => (int) $user->getKey(),
            'user_id' => (string) $user->email,
            'nick_name' => (string) ($user->name ?: $user->nickname),
            'user_name' => null,
            'email_address' => $user->email ?: null,
            'profile_image' => self::avatar($user),
            'groups' => $groups,
            'is_admin' => (bool) $user->isAdmin(),
        ];
    }

    public static function avatar(?User $user): ?string
    {
        if (! $user) {
            return null;
        }
        $url = method_exists($user, 'getAvatarUrl') ? $user->getAvatarUrl() : null;

        return $url ? Boards::absolute((string) $url) : null;
    }
}
