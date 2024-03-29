<?php

$type = gr('type');
$out['TYPE'] = $type;
if (gr('err_msg')) {
    $out['ERR_MSG'] = gr('err_msg');
}
if (gr('ok_msg')) {
    $out['OK_MSG'] = gr('ok_msg');
}

if (gr('refresh_devices')) {
    $this->refreshDevices();
}

if ($type == 'reset') {
    @unlink(YADEVICES_COOKIE_PATH);
    $this->redirect("?view_mode=" . $this->view_mode);
}

if ($type == 'otp') {
    $use_cookie_file = YADEVICES_COOKIE_PATH.'_otp';
    $track_id = gr('track_id');
    if ($track_id) {
        $otp = gr('otp');
        if ($otp!='') {
            $post = array(
                'csrf_token' => gr('csrf_token'),
                'track_id' => $track_id,
                'password' => $otp,
                'retpath' => 'https://passport.yandex.ru/am/finish?status=ok&from=Login',
            );
            $postvars = '';
            foreach($post as $key=>$value) {
                $postvars .= $key . "=" . urlencode($value) . "&";
            }
            $YaCurl = curl_init();
            curl_setopt($YaCurl, CURLOPT_URL, 'https://passport.yandex.ru/registration-validations/auth/multi_step/commit_password');
            curl_setopt($YaCurl, CURLOPT_POST, true);
            curl_setopt($YaCurl, CURLOPT_POSTFIELDS, $postvars);
            curl_setopt($YaCurl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($YaCurl, CURLOPT_COOKIEFILE, $use_cookie_file);
            curl_setopt($YaCurl, CURLOPT_COOKIEJAR, $use_cookie_file);
            $result = curl_exec($YaCurl);
            curl_close($YaCurl);
            $data = json_decode($result, true);
            if ($data['status']=='ok' || $data['errors'][0]=='account.auth_passed') {
                rename($use_cookie_file, YADEVICES_COOKIE_PATH);
                $checkCookie = $this->apiRequest('https://iot.quasar.yandex.ru/m/user/scenarios');
                if ($checkCookie['status'] != 'ok') {
                    @unlink(YADEVICES_COOKIE_PATH);
                    $out['ERR_MSG'] = 'Ошибка авторизации!';
                    return;
                } else {
                    $this->redirect("?view_mode=" . $this->view_mode . "&refresh_devices=1&ok_msg=" . urlencode("Успешная авторизация!"));
                }
            } else {
                $out['ERR_MSG'] = 'Авторизация не пройдена. Попробуйте ещё раз.';
            }
        }
        $out['TRACK_ID']=$track_id;
    } else {
        $username = gr('username');
        if ($username) {
            $csrf_token = $this->getCSRFToken($use_cookie_file);
            if ($csrf_token!='') {
                $out['CSRF_TOKEN'] = $csrf_token;
                $post = array(
                    'csrf_token' => $csrf_token,
                    'login' => $username,
                );
                $postvars = '';
                foreach($post as $key=>$value) {
                    $postvars .= $key . "=" . urlencode($value) . "&";
                }
                $YaCurl = curl_init();
                curl_setopt($YaCurl, CURLOPT_URL, 'https://passport.yandex.ru/registration-validations/auth/multi_step/start');
                curl_setopt($YaCurl, CURLOPT_POST, true);
                curl_setopt($YaCurl, CURLOPT_POSTFIELDS, $postvars);
                curl_setopt($YaCurl, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($YaCurl, CURLOPT_COOKIEFILE, $use_cookie_file);
                curl_setopt($YaCurl, CURLOPT_COOKIEJAR, $use_cookie_file);
                $result = curl_exec($YaCurl);

                curl_close($YaCurl);
                $data = json_decode($result, true);
                if ($data['status']=='ok') {
                    $track_id = $data['track_id'];
                    $out['TRACK_ID']=$track_id;
                } else {
                    $out['ERR_MSG']='Ошибка авторизации. Попробуйте ещё раз.';
                }

            } else {
                $out['ERR_MSG'] = 'Ошибка получения CSRF-токена';
            }
        }

    }
}

if ($type == 'qr') {
    $use_cookie_file = YADEVICES_COOKIE_PATH.'_qr';
    $track_id = gr('track_id');
    if ($track_id) {
        $csrf_token = gr('csrf_token');
        $post = array(
            'csrf_token' => $csrf_token,
            'track_id' => $track_id,
        );
        $postvars = '';
        foreach($post as $key=>$value) {
            $postvars .= $key . "=" . urlencode($value) . "&";
        }

        $YaCurl = curl_init();
        $url = 'https://passport.yandex.ru/auth/magic/status/';

        curl_setopt($YaCurl, CURLOPT_URL, $url);
        curl_setopt($YaCurl, CURLOPT_POST, true);
        curl_setopt($YaCurl, CURLOPT_POSTFIELDS, $postvars);
        curl_setopt($YaCurl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($YaCurl, CURLOPT_COOKIEFILE, $use_cookie_file);
        curl_setopt($YaCurl, CURLOPT_COOKIEJAR, $use_cookie_file);
        curl_setopt($YaCurl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($YaCurl, CURLOPT_SSL_VERIFYHOST, false);
        $result = curl_exec($YaCurl);
        curl_close($YaCurl);

        $data = json_decode($result, true);

        if ($data['status']=='ok' || $data['errors'][0]=='account.auth_passed') {
            rename($use_cookie_file, YADEVICES_COOKIE_PATH);
            $checkCookie = $this->apiRequest('https://iot.quasar.yandex.ru/m/user/scenarios');
            if ($checkCookie['status'] != 'ok') {
                @unlink(YADEVICES_COOKIE_PATH);
                $out['ERR_MSG'] = 'Ошибка авторизации!';
                return;
            } else {
                $this->redirect("?view_mode=" . $this->view_mode . "&refresh_devices=1&ok_msg=" . urlencode("Успешная авторизация!"));
            }
        } else {
            $out['ERR_MSG'] = 'Авторизация не пройдена. Попробуйте ещё раз.';
        }

        $out['TRACK_ID'] = $track_id;
        $out['QR_URL'] = 'https://passport.yandex.ru/auth/magic/code/?track_id=' . $track_id;
        $out['CSRF_TOKEN'] = $csrf_token;

    } else {
        $csrf_token = $this->getCSRFToken($use_cookie_file);
        if ($csrf_token) {
            $post = array(
                'csrf_token' => $csrf_token,
                'retpath' => 'https://passport.yandex.ru/profile',
                'with_code' => 1,
            );

            $postvars = '';
            foreach($post as $key=>$value) {
                $postvars .= $key . "=" . urlencode($value) . "&";
            }
            $YaCurl = curl_init();
            curl_setopt($YaCurl, CURLOPT_URL, 'https://passport.yandex.ru/registration-validations/auth/password/submit');
            curl_setopt($YaCurl, CURLOPT_POST, true);
            curl_setopt($YaCurl, CURLOPT_POSTFIELDS, $postvars);
            curl_setopt($YaCurl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($YaCurl, CURLOPT_COOKIEFILE, $use_cookie_file);
            curl_setopt($YaCurl, CURLOPT_COOKIEJAR, $use_cookie_file);
            curl_setopt($YaCurl, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($YaCurl, CURLOPT_SSL_VERIFYHOST, false);
            $result = curl_exec($YaCurl);
            curl_close($YaCurl);

            $data = json_decode($result, true);
            if ($data['status'] == 'ok') {
                $out['TRACK_ID'] = $data['track_id'];
                $out['CSRF_TOKEN'] = $data['csrf_token'];
                $out['QR_URL'] = 'https://passport.yandex.ru/auth/magic/code/?track_id=' . $data['track_id'];
            } else {
                $out['ERR_MSG'] = 'Ошибка получения QR-кода';
            }
        } else {
            $out['ERR_MSG'] = 'Ошибка получения CSRF-токена';
        }
    }
}

if ($type == 'cookie') {
    global $file;
    if (is_file($file)) {
        move_uploaded_file($file, YADEVICES_COOKIE_PATH);
        $checkCookie = $this->apiRequest('https://iot.quasar.yandex.ru/m/user/scenarios');
        if ($checkCookie['status'] != 'ok') {
            @unlink(YADEVICES_COOKIE_PATH);
            $out['ERR_MSG'] = 'Файл который вы загружаете не является Cookie файлом с сайта Яндекс или он устарел.';
            return;
        } else {
            $this->redirect("?view_mode=" . $this->view_mode . "&refresh_devices=1&ok_msg=" . urlencode("Успешная авторизация!"));
        }
    }
}

if (!$type) {
    $data = $this->apiRequest('https://iot.quasar.yandex.ru/m/user/devices');
    if (is_array($data)) {
        $out['AUTHORIZED_OK'] = 1;
    }

}
