<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\EmailVerify;
use App\Models\InviteCode;
use App\Models\Setting;
use App\Models\User;
use App\Services\Auth;
use App\Services\Captcha;
use App\Services\Mail;
use App\Utils\Check;
use App\Utils\Hash;
use App\Utils\ResponseHelper;
use App\Utils\TelegramSessionManager;
use App\Utils\Tools;
use Exception;
use Psr\Http\Message\ResponseInterface;
use Ramsey\Uuid\Uuid;
use Slim\Http\Response;
use Slim\Http\ServerRequest;
use Vectorface\GoogleAuthenticator;
use voku\helper\AntiXSS;

/**
 *  AuthController
 */
final class AuthController extends BaseController
{
    /**
     * @param array     $args
     */
    public function login(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $captcha = [];

        if (Setting::obtain('enable_login_captcha') === true) {
            $captcha = Captcha::generate();
        }

        if ($_ENV['enable_telegram_login'] === true) {
            $login_text = TelegramSessionManager::addLoginSession();
            $login = explode('|', $login_text);
            $login_token = $login[0];
            $login_number = $login[1];
        } else {
            $login_token = '';
            $login_number = '';
        }

        return $response->write($this->view()
            ->assign('login_token', $login_token)
            ->assign('login_number', $login_number)
            ->assign('base_url', $_ENV['baseUrl'])
            ->assign('telegram_bot', $_ENV['telegram_bot'])
            ->assign('captcha', $captcha)
            ->fetch('auth/login.tpl'));
    }

    /**
     * @param array     $args
     */
    public function loginHandle(ServerRequest $request, Response $response, array $args)
    {
        if (Setting::obtain('enable_login_captcha') === true) {
            $ret = Captcha::verify($request->getParams());
            if (!$ret) {
                return $response->withJson([
                    'ret' => 0,
                    'msg' => '系统无法接受您的验证结果，请刷新页面后重试。',
                ]);
            }
        }

        $code = $request->getParam('code');
        $passwd = $request->getParam('passwd');
        $rememberMe = $request->getParam('remember_me');
        $email = strtolower(trim($request->getParam('email')));

        $user = User::where('email', $email)->first();
        if ($user === null) {
            return $response->withJson([
                'ret' => 0,
                'msg' => '邮箱不存在',
            ]);
        }

        if (!Hash::checkPassword($user->pass, $passwd)) {
            // 记录登录失败
            $user->collectLoginIP($_SERVER['REMOTE_ADDR'], 1);
            return $response->withJson([
                'ret' => 0,
                'msg' => '邮箱或者密码错误',
            ]);
        }

        if ($user->ga_enable === 1) {
            $ga = new GoogleAuthenticator();
            $rcode = $ga->verifyCode($user->ga_token, $code);
            if (!$rcode) {
                return $response->withJson([
                    'ret' => 0,
                    'msg' => '两步验证码错误，如果您是丢失了生成器或者错误地设置了这个选项，您可以尝试重置密码，即可取消这个选项。',
                ]);
            }
        }

        $time = 3600 * 24;
        if ($rememberMe) {
            $time = 3600 * 24 * ($_ENV['rememberMeDuration'] ?: 7);
        }

        Auth::login($user->id, $time);
        // 记录登录成功
        $user->collectLoginIP($_SERVER['REMOTE_ADDR']);

        return $response->withJson([
            'ret' => 1,
            'msg' => '登录成功',
        ]);
    }

    /**
     * @param array     $args
     */
    public function qrcodeLoginHandle(ServerRequest $request, Response $response, array $args)
    {
        $token = $request->getParam('token');
        $number = $request->getParam('number');

        $ret = TelegramSessionManager::step2VerifyLoginSession($token, $number);
        if ($ret === 0) {
            return ResponseHelper::error($response, '此令牌无法被使用。');
        }

        $user = User::find($ret);

        Auth::login($user->id, 3600 * 24);
        // 记录登录成功
        $user->collectLoginIP($_SERVER['REMOTE_ADDR']);
        return ResponseHelper::successfully($response, '登录成功');
    }

    /**
     * @param array     $args
     */
    public function register(ServerRequest $request, Response $response, $next)
    {
        $captcha = [];

        if (Setting::obtain('enable_reg_captcha') === true) {
            $captcha = Captcha::generate();
        }

        $ary = $request->getQueryParams();
        $code = '';
        if (isset($ary['code'])) {
            $antiXss = new AntiXSS();
            $code = $antiXss->xss_clean($ary['code']);
        }

        if ($_ENV['enable_telegram_login'] === true) {
            $login_text = TelegramSessionManager::addLoginSession();
            $login = explode('|', $login_text);
            $login_token = $login[0];
            $login_number = $login[1];
        } else {
            $login_token = '';
            $login_number = '';
        }

        return $response->write($this->view()
            ->assign('code', $code)
            ->assign('base_url', $_ENV['baseUrl'])
            ->assign('login_token', $login_token)
            ->assign('login_number', $login_number)
            ->assign('telegram_bot', $_ENV['telegram_bot'])
            ->assign('enable_email_verify', Setting::obtain('reg_email_verify'))
            ->assign('captcha', $captcha)
            ->fetch('auth/register.tpl'));
    }

    /**
     * @param array     $args
     */
    public function sendVerify(ServerRequest $request, Response $response, $next)
    {
        if (Setting::obtain('reg_email_verify')) {
            $email = trim($request->getParam('email'));
            $email = strtolower($email);
            if ($email === '') {
                return ResponseHelper::error($response, '未填写邮箱');
            }
            // check email format
            $check_res = Check::isEmailLegal($email);
            if ($check_res['ret'] === 0) {
                return $response->withJson($check_res);
            }
            $user = User::where('email', $email)->first();
            if ($user !== null) {
                return ResponseHelper::error($response, '此邮箱已经注册');
            }
            $ipcount = EmailVerify::where('ip', '=', $_SERVER['REMOTE_ADDR'])
                ->where('expire_in', '>', \time())
                ->count();
            if ($ipcount >= Setting::obtain('email_verify_ip_limit')) {
                return ResponseHelper::error($response, '此IP请求次数过多');
            }
            $mailcount = EmailVerify::where('email', '=', $email)
                ->where('expire_in', '>', \time())
                ->count();
            if ($mailcount >= 3) {
                return ResponseHelper::error($response, '此邮箱请求次数过多');
            }
            $code = Tools::genRandomChar(6);
            $ev = new EmailVerify();
            $ev->expire_in = \time() + Setting::obtain('email_verify_ttl');
            $ev->ip = $_SERVER['REMOTE_ADDR'];
            $ev->email = $email;
            $ev->code = $code;
            $ev->save();
            try {
                Mail::send(
                    $email,
                    $_ENV['appName'] . '- 验证邮件',
                    'auth/verify.tpl',
                    [
                        'code' => $code,
                        'expire' => date('Y-m-d H:i:s', \time() + Setting::obtain('email_verify_ttl')),
                    ],
                    []
                );
            } catch (Exception $e) {
                return ResponseHelper::error($response, '邮件发送失败，请联系网站管理员。');
            }
            return ResponseHelper::successfully($response, '验证码发送成功，请查收邮件。');
        }
        return ResponseHelper::error($response, ' 不允许注册');
    }

    /**
     * @param ServerRequest   $request
     * @param Response  $response
     * @param array     $args
     */
    public static function registerHelper($response, $name, $email, $passwd, $code, $imtype, $imvalue, $telegram_id, $money, $is_admin_reg)
    {
        if (Setting::obtain('reg_mode') === 'close') {
            return ResponseHelper::error($response, '暂时不对外开放注册');
        }

        if (Setting::obtain('reg_mode') === 'invite' && $code === '') {
            return ResponseHelper::error($response, '注册需要填写邀请码');
        }

        $c = InviteCode::where('code', $code)->first();
        if ($c === null) {
            if (Setting::obtain('reg_mode') === 'invite') {
                return ResponseHelper::error($response, '这个邀请码不存在');
            }
        } elseif ($c->user_id !== 0) {
            $gift_user = User::where('id', $c->user_id)->first();
            if ($gift_user === null) {
                return ResponseHelper::error($response, '邀请码已失效');
            }

            if ($gift_user->invite_num === 0) {
                return ResponseHelper::error($response, '邀请码不可用');
            }
        }

        $configs = Setting::getClass('register');
        // do reg user
        $user = new User();
        $antiXss = new AntiXSS();
        $current_timestamp = \time();

        $user->user_name = $antiXss->xss_clean($name);
        $user->email = $email;
        $user->remark = '';
        $user->pass = Hash::passwordHash($passwd);
        $user->passwd = Tools::genRandomChar(16);
        $user->uuid = Uuid::uuid3(Uuid::NAMESPACE_DNS, $email . '|' . $current_timestamp);
        $user->api_token = Uuid::uuid3(Uuid::NAMESPACE_DNS, $user->pass . '|' . $current_timestamp);
        $user->port = Tools::getAvPort();
        $user->t = 0;
        $user->u = 0;
        $user->d = 0;
        $user->method = $configs['sign_up_for_method'];
        $user->forbidden_ip = Setting::obtain('reg_forbidden_ip');
        $user->forbidden_port = Setting::obtain('reg_forbidden_port');
        $user->im_type = $imtype;
        $user->im_value = $antiXss->xss_clean($imvalue);

        $user->transfer_enable = Tools::toGB($configs['sign_up_for_free_traffic']);
        $user->invite_num = $configs['sign_up_for_invitation_codes'];
        $user->auto_reset_day = Setting::obtain('free_user_reset_day');
        $user->auto_reset_bandwidth = Setting::obtain('free_user_reset_bandwidth');
        $user->sendDailyMail = $configs['sign_up_for_daily_report'];

        if ($money > 0) {
            $user->money = $money;
        } else {
            $user->money = 0;
        }

        //dumplin：填写邀请人，写入邀请奖励
        $user->ref_by = 0;
        if ($c !== null && $c->user_id !== 0) {
            $invitation = Setting::getClass('invite');
            // 设置新用户
            $user->ref_by = $c->user_id;
            $user->money = $invitation['invitation_to_register_balance_reward'];
            // 给邀请人反流量
            $gift_user->transfer_enable += $invitation['invitation_to_register_traffic_reward'] * 1024 * 1024 * 1024;
            if ($gift_user->invite_num - 1 >= 0) {
                --$gift_user->invite_num;
                // 避免设置为不限制邀请次数的值 -1 发生变动
            }
            $gift_user->save();
        }

        if ($telegram_id) {
            $user->telegram_id = $telegram_id;
        }

        $ga = new GoogleAuthenticator();
        $secret = $ga->createSecret();
        $user->ga_token = $secret;
        $user->ga_enable = 0;
        $user->class_expire = date('Y-m-d H:i:s', \time() + $configs['sign_up_for_class_time'] * 86400);
        $user->class = $configs['sign_up_for_class'];
        $user->node_connector = $configs['connection_device_limit'];
        $user->node_speedlimit = $configs['connection_rate_limit'];
        $user->expire_in = date('Y-m-d H:i:s', \time() + $configs['sign_up_for_free_time'] * 86400);
        $user->reg_date = date('Y-m-d H:i:s');
        $user->reg_ip = $_SERVER['REMOTE_ADDR'];
        $user->theme = $_ENV['theme'];
        $random_group = Setting::obtain('random_group');
        if ($random_group === '') {
            $user->node_group = 0;
        } else {
            $user->node_group = $random_group[array_rand(explode(',', $random_group))];
        }

        if ($user->save() && !$is_admin_reg) {
            Auth::login($user->id, 3600);
            $user->collectLoginIP($_SERVER['REMOTE_ADDR']);

            return ResponseHelper::successfully($response, '注册成功！正在进入登录界面');
        }

        return ResponseHelper::error($response, '未知错误');
    }

    /**
     * @param array     $args
     */
    public function registerHandle(ServerRequest $request, Response $response, array $args)
    {
        if (Setting::obtain('reg_mode') === 'close') {
            return ResponseHelper::error($response, '未开放注册。');
        }

        if (Setting::obtain('enable_reg_captcha') === true) {
            $ret = Captcha::verify($request->getParams());
            if (!$ret) {
                return ResponseHelper::error($response, '系统无法接受您的验证结果，请刷新页面后重试。');
            }
        }

        $name = $request->getParam('name');
        $email = $request->getParam('email');
        $email = trim($email);
        $email = strtolower($email);
        $passwd = $request->getParam('passwd');
        $repasswd = $request->getParam('repasswd');
        $code = trim($request->getParam('code'));

        if (Setting::obtain('enable_reg_im') === true) {
            $imtype = $request->getParam('im_type');
            $imvalue = $request->getParam('im_value');
            if ($imtype === '' || $imvalue === '') {
                return ResponseHelper::error($response, '请填上你的联络方式');
            }
            $user = User::where('im_value', $imvalue)->where('im_type', $imtype)->first();
            if ($user !== null) {
                return ResponseHelper::error($response, '此联络方式已注册');
            }
        } else {
            $imtype = 1;
            $imvalue = '';
        }

        // check email format
        $check_res = Check::isEmailLegal($email);
        if ($check_res['ret'] === 0) {
            return $response->withJson($check_res);
        }
        // check email
        $user = User::where('email', $email)->first();
        if ($user !== null) {
            return ResponseHelper::error($response, '邮箱已经被注册了');
        }

        if (Setting::obtain('reg_email_verify')) {
            $email_code = trim($request->getParam('emailcode'));
            $mailcount = EmailVerify::where('email', '=', $email)
                ->where('code', '=', $email_code)
                ->where('expire_in', '>', \time())
                ->first();
            if ($mailcount === null) {
                return ResponseHelper::error($response, '您的邮箱验证码不正确');
            }
        }

        // check pwd length
        if (strlen($passwd) < 8) {
            return ResponseHelper::error($response, '密码请大于8位');
        }

        // check pwd re
        if ($passwd !== $repasswd) {
            return ResponseHelper::error($response, '两次密码输入不符');
        }

        if (Setting::obtain('reg_email_verify')) {
            EmailVerify::where('email', $email)->delete();
        }

        return $this->registerHelper($response, $name, $email, $passwd, $code, $imtype, $imvalue, 0, 0, 0);
    }

    /**
     * @param array     $args
     */
    public function logout(ServerRequest $request, Response $response, $next)
    {
        Auth::logout();
        return $response->withStatus(302)
            ->withHeader('Location', '/auth/login');
    }

    /**
     * @param array     $args
     */
    public function qrcodeCheck(ServerRequest $request, Response $response, array $args)
    {
        $token = $request->getParam('token');
        $number = $request->getParam('number');
        $user = Auth::getUser();
        if ($user->isLogin) {
            return ResponseHelper::error($response, '用户已登陆');
        }
        if ($_ENV['enable_telegram_login'] === true) {
            $ret = TelegramSessionManager::checkLoginSession($token, $number);
            return $response->withJson([
                'ret' => $ret,
            ]);
        }
        return ResponseHelper::error($response, '不允许 QRCode 登陆');
    }

    /**
     * @param array     $args
     */
    public function telegramOauth(ServerRequest $request, Response $response, array $args)
    {
        if ($_ENV['enable_telegram_login'] === true) {
            $auth_data = $request->getQueryParams();
            if ($this->telegramOauthCheck($auth_data) === true) { // Looks good, proceed.
                $telegram_id = $auth_data['id'];
                $user = User::query()->where('telegram_id', $telegram_id)->firstOrFail(); // Welcome Back :)
                if ($user === null) {
                    return $response->write($this->view()
                        ->assign('title', '您需要先进行邮箱注册后绑定Telegram才能使用授权登录')
                        ->assign('message', '很抱歉带来的不便，请重新试试')
                        ->assign('redirect', '/auth/login')
                        ->fetch('telegram_error.tpl'));
                }
                Auth::login($user->id, 3600);
                $user->collectLoginIP($_SERVER['REMOTE_ADDR']);

                return $response->write($this->view()
                    ->assign('title', '登录成功')
                    ->assign('message', '正在前往仪表盘')
                    ->assign('redirect', '/user')
                    ->fetch('telegram_success.tpl'));
            }
            return $response->write($this->view()
                ->assign('title', '登陆超时或非法构造信息')
                ->assign('message', '很抱歉带来的不便，请重新试试')
                ->assign('redirect', '/auth/login')
                ->fetch('telegram_error.tpl'));
        }
        return $response->withRedirect('/404');
    }

    /**
     * @param Request   $request
     * @param Response  $response
     * @param array     $args
     */
    private function telegramOauthCheck($auth_data)
    {
        $check_hash = $auth_data['hash'];
        $bot_token = $_ENV['telegram_token'];
        unset($auth_data['hash']);
        $data_check_arr = [];
        foreach ($auth_data as $key => $value) {
            $data_check_arr[] = $key . '=' . $value;
        }
        sort($data_check_arr);
        $data_check_string = implode("\n", $data_check_arr);
        $secret_key = \hash('sha256', $bot_token, true);
        $hash = hash_hmac('sha256', $data_check_string, $secret_key);
        if (strcmp($hash, $check_hash) !== 0) {
            return false; // Bad Data :(
        }
        if (\time() - $auth_data['auth_date'] > 300) { // Expire @ 5mins
            return false;
        }
        return true; // Good to Go
    }
}
