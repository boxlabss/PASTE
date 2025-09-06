<?php
/*
 * Language File: Russian
 *
 * Paste https://github.com/boxlabss/PASTE
 * demo: https://paste.boxlabs.uk/
 *
 * https://phpaste.sourceforge.io/
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 3
 * of the License, or (at your option) any later version.
 * 
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See LICENCE for more details.
 */

$lang = array();

$lang['banned'] = "Вы заблокированы на " . $site_name;
$lang['expired']        = "Искомая паста истекла.";
$lang['pleaseregister'] = "<br><br> <a class=\"btn btn-default\" href=\"login.php\">Войдите</a> или <a class=\"btn btn-default\" href=\"login.php?action=signup\">Зарегистрируйтесь</a>, чтобы создать новую пасту. Это бесплатно.";
$lang['registertoedit'] = "<a class=\"btn btn-default\" href=\"login.php\">Войдите</a> или <a class=\"btn btn-default\" href=\"login.php?action=signup\">Зарегистрируйтесь</a>, чтобы редактировать или сделать форк этой пасты. Это бесплатно.";
$lang['editpaste'] = "Редактировать";
$lang['forkpaste'] = "Форк";
$lang['guestmsgbody'] = "<a href=\"login.php\">Войдите</a> или <a href=\"login.php?action=signup\">Зарегистрируйтесь</a>, чтобы редактировать, удалять и отслеживать свои пасты и многое другое.";
$lang['emptypastebin'] = "Нет паст для отображения.";
$lang['siteprivate'] = "Этот пастебин приватный. <a class=\"btn btn-default\" href=\"login.php\">Войти</a>";
$lang['image_wrong'] = "Неверная капча.";
$lang['missing-input-response'] = "Отсутствует ответ reCAPTCHA. Проверьте настройки PASTE.";
$lang['missing-input-secret'] = "Отсутствует секретный ключ reCAPTCHA. Добавьте его в настройки PASTE.";
$lang['invalid-input-response'] = "Ответ reCAPTCHA недействителен. Попробуйте снова пройти проверку.";
$lang['invalid-input-secret'] = "Секретный ключ reCAPTCHA недействителен или имеет неверный формат. Проверьте настройки PASTE.";
$lang['empty_paste'] = "Нельзя отправить пустую пасту.";
$lang['large_paste'] = "Паста слишком большая. Максимальный размер — " . $pastelimit . " МБ";
$lang['paste_db_error'] = "Не удалось сохранить в базе данных.";
$lang['error'] = "Что-то пошло не так.";
$lang['archive'] = "Архив";
$lang['archives'] = "Архив паст";
$lang['archivestitle'] = "На этой странице показаны 100 последних публичных паст.";
$lang['contact'] = "Связаться с нами";
$lang['full_name'] = "Полное имя";
$lang['email'] = "Электронная почта";
$lang['email_invalid'] = "Похоже, адрес электронной почты недействителен.";
$lang['message'] = "Требуется текст сообщения.";
$lang['login/register'] = "Войти или зарегистрироваться";
$lang['rememberme'] = "Оставаться в системе.";
$lang['mail_acc_con'] = "Подтверждение аккаунта $site_name";
$lang['mail_suc'] = "Код подтверждения успешно отправлен на ваш адрес электронной почты.";
$lang['email_ver'] = "Email уже подтверждён.";
$lang['email_not'] = "Email не найден.";
$lang['pass_change'] = "Ссылка отправлена на ваш email.";
$lang['notverified'] = "Аккаунт не подтверждён.";
$lang['incorrect'] = "Неверные имя пользователя/пароль";
$lang['missingfields'] = "Заполните все поля.";
$lang['userexists'] = "Имя пользователя уже занято.";
$lang['emailexists'] = "Email уже зарегистрирован.";
$lang['registered'] = "Ваш аккаунт успешно зарегистрирован.";
$lang['usrinvalid'] = "Имя пользователя может содержать только буквы и цифры.";
$lang['mypastes'] = "Мои пасты";
$lang['pastedeleted'] = "Паста удалена.";
$lang['databaseerror'] = "Не удалось сохранить в базе данных.";
$lang['userchanged'] = "Имя пользователя успешно изменено.";
$lang['usernotvalid'] = "Недопустимое имя пользователя.";
$lang['privatepaste'] = "Это приватная паста.";
$lang['wrongpassword'] = "Неверный пароль.";
$lang['pwdprotected'] = "Паста защищена паролем";
$lang['notfound'] = "404 Не найдено";
$lang['wrongpwd'] = "Неверный пароль. Попробуйте ещё раз.";
$lang['myprofile'] = "Мой профиль";
$lang['profileerror'] = "Не удалось обновить информацию профиля";
$lang['profileupdated'] = "Информация профиля обновлена";
$lang['oldpasswrong'] = "Старый пароль указан неверно.";
$lang['pastetitle'] = "Заголовок";
$lang['pastetime'] = "Создано";
$lang['pastesyntax'] = "Синтаксис";
$lang['pasteviews'] = "Просмотры";
$lang['wentwrong'] = "Что-то пошло не так.";
$lang['versent'] = "Письмо с подтверждением отправлено на ваш email.";
$lang['modpaste'] = "Изменить или форк";
$lang['newpaste'] = "Новая паста";
$lang['highlighting'] = "Подсветка синтаксиса";
$lang['expiration'] = "Срок действия пасты";
$lang['visibility'] = "Видимость пасты";
$lang['pwopt'] = "Пароль (необязательно)";
$lang['encrypt'] = "Все пасты в базе данных шифруются с использованием AES-256-CBC";
$lang['entercode'] = "Введите CAPTCHA";
$lang['almostthere'] = "Почти готово. Остался последний шаг.";
$lang['username'] = "Имя пользователя";
$lang['autogen'] = "Автосгенерированное имя";
$lang['setuser'] = "Установите имя пользователя";
$lang['keepuser'] = "Оставить автосгенерированное имя? Изменить можно один раз.";
$lang['enterpwd'] = "Введите пароль";
$lang['totalpastes'] = "Всего паст:";
$lang['membtype'] = "Тип аккаунта:";
$lang['chgpwd'] = "Сменить пароль";
$lang['curpwd'] = "Текущий пароль";
$lang['newpwd'] = "Новый пароль";
$lang['confpwd'] = "Подтвердите пароль";
$lang['viewpastes'] = "Показать все мои пасты";
$lang['recentpastes'] = "Недавние пасты";
$lang['user_public_pastes'] = " — пасты пользователя";
$lang['yourpastes'] = "Ваши пасты";
$lang['mypastestitle'] = "Все ваши пасты — в одном месте.";
$lang['delete'] = "Удалить";
$lang['highlighted'] = "Текст ниже выделен — нажмите Ctrl+C, чтобы скопировать (&#8984;+C на Mac).";
$lang['download'] = "Скачать";
$lang['showlineno'] = "Показать/скрыть номера строк";
$lang['copyto'] = "Скопировать текст в буфер обмена";
$lang['rawpaste'] = "Необработанный текст";
$lang['membersince'] = "С нами с: ";
$lang['delete_error_invalid'] = "Ошибка: паста не удалена, потому что она не существует или не принадлежит вам.";
$lang['deleteaccount'] = 'Удалить мой аккаунт';
$lang['deletewarn'] = 'Это навсегда удалит ваш аккаунт и все ваши пасты. Действие необратимо.';
$lang['typedelete'] = 'Введите DELETE для подтверждения.';
$lang['confirmdeletehint'] = 'Необходимо ввести DELETE (заглавными).';
$lang['cancel'] = 'Отмена';
$lang['confirmdelete'] = 'Подтвердить удаление';
$lang['wentwrong'] = 'Что-то пошло не так.';
$lang['invalidtoken'] = 'Недействительный CSRF-токен.';
$lang['not_logged_in'] = "Ошибка: нужно войти в систему.";
$lang['public'] = "Публичная";
$lang['unlisted'] = "По ссылке";
$lang['private'] = "Приватная";
$lang['hello'] = "Здравствуйте";
$lang['profile-message'] = "Это ваша страница профиля, где вы можете управлять пастами. Здесь отображаются ваши публичные, приватные и доступные по ссылке пасты. С этой страницы вы также можете удалять пасты. Другим пользователям видны только те пасты, которые вы сделали публичными.";
$lang['profile-stats'] = "Немного вашей статистики:";
$lang['profile-total-pastes'] = "Всего паст:";
$lang['profile-total-pub'] = "Всего публичных паст:";
$lang['profile-total-unl'] = "Всего паст по ссылке:";
$lang['profile-total-pri'] = "Всего приватных паст:";
$lang['profile-total-views'] = "Всего просмотров ваших паст:";
$lang['embed-hosted-by'] = "размещено на";
$lang['view-raw'] = "Показать исходник";
$lang['my_account'] = "Мой аккаунт";
$lang['guest'] = "Гость";
$lang['login'] = "Войти";
$lang['signup'] = "Регистрация";
$lang['forgot_password'] = "Забыли пароль";
$lang['resend_verification'] = "Отправить письмо подтверждения ещё раз";
$lang['or_login_with'] = "Или войдите через";
$lang['login_with_google'] = "Google";
$lang['login_with_facebook'] = "Facebook";
$lang['already_have_account'] = "Уже есть аккаунт?";
$lang['reset_password'] = "Сброс пароля";
$lang['new_password'] = "Новый пароль";
$lang['send_reset_link'] = "Отправить ссылку для сброса";
$lang['email_verified'] = "Адрес электронной почты успешно подтверждён. Теперь вы можете войти.";
$lang['invalid_code'] = "Неверный или истёкший код.";
$lang['pass_reset'] = "Пароль успешно сброшен. Теперь вы можете войти.";
$lang['mail_error'] = "Не удалось отправить письмо.";
$lang['settings'] = "Настройки";
$lang['logout'] = "Выйти";
$lang['49'] = "49";
$lang['50'] = "50";
$lang['account_suspended'] = "Аккаунт заблокирован";
$lang['ajax_error'] = "Ошибка Ajax";
$lang['createpaste'] = "Создать пасту";
$lang['email_not_verified'] = "Email не подтверждён";
$lang['expired'] = "Истёк срок";
$lang['forgot'] = "Забыли";
$lang['fullname'] = "Полное имя";
$lang['guestmsgtitle'] = "Привет, гость! PASTE предназначён для исходного кода и текста для отладки.";
$lang['guestwelcome'] = "Добро пожаловать, гость";
$lang['invalid_credentials'] = "Неверные учётные данные";
$lang['invalid_email'] = "Неверный email";
$lang['invalid_reset_code'] = "Неверный код сброса";
$lang['invalid_state'] = "Недопустимое состояние";
$lang['invalid_username'] = "Недопустимое имя пользователя";
$lang['login_required'] = "Требуется вход";
$lang['login_success'] = "Вход выполнен";
$lang['low_score'] = "Низкий рейтинг";
$lang['my-pastes'] = "Мои пасты";
$lang['no_results'] = "Нет результатов";
$lang['password'] = "Пароль";
$lang['password_reset_success'] = "Пароль успешно сброшен";
$lang['password_too_short'] = "Слишком короткий пароль";
$lang['pastemember'] = "Участник";
$lang['pastes'] = "Пасты";
$lang['recaptcha_error'] = "Ошибка reCAPTCHA";
$lang['recaptcha_failed'] = "reCAPTCHA не смогла подтвердить, что вы не бот. Обновите страницу и попробуйте снова.";
$lang['recaptcha_missing'] = "Отсутствует reCAPTCHA";
$lang['recaptcha_timeout'] = "Тайм-аут reCAPTCHA";
$lang['resend'] = "Отправить снова";
$lang['search'] = "Поиск";
$lang['search_results_for'] = "Результаты поиска по";
$lang['signup_success'] = "Регистрация успешна";
$lang['sort'] = "Сортировать";
$lang['sort_code_asc'] = "Код ↑";
$lang['sort_code_desc'] = "Код ↓";
$lang['sort_date_asc'] = "Дата ↑";
$lang['sort_date_desc'] = "Дата ↓";
$lang['sort_title_asc'] = "Заголовок ↑";
$lang['sort_title_desc'] = "Заголовок ↓";
$lang['sort_views_asc'] = "Просмотры ↑";
$lang['sort_views_desc'] = "Просмотры ↓";
$lang['submit_error'] = "Ошибка отправки";
$lang['user_exists'] = "Пользователь уже существует";
$lang['views'] = "Просмотры";
$lang['charsleft'] = "символов осталось";
$lang['cancel'] = "Отмена";
$lang['postreply'] = "Отправить ответ";
$lang['loginreply'] = "Войти, чтобы ответить";
$lang['reply'] = "Ответить";
$lang['logintocomment'] = "Войдите, чтобы присоединиться к обсуждению.";
$lang['nocomments'] = "Комментариев пока нет. Будьте первым.";
$lang['commentexplain'] = "Markdown отключён; ссылки распознаются автоматически.";
$lang['comments'] = "Комментарии";
$lang['postcomment'] = "Отправить комментарий";
$lang['logintocomment'] = "Войдите, чтобы оставить комментарий.";
$lang['commentsdisabled'] = "Комментарии отключены.";
$lang['postedon'] = "Опубликовано:";
$lang['size'] = "Размер:";
$lang['detected'] = "Определён";
$lang['viewdifferences'] = "Показать различия";
$lang['loadraw'] = "Загрузить исходный текст";
$lang['detectedexplainlabel'] = "Как мы определили язык";

?>