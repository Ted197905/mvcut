<?php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */
$routes->get('/', 'Home::index');

$routes->get('login', 'Auth::login');
$routes->post('login', 'Auth::attemptLogin');
$routes->get('register', 'Auth::register');
$routes->post('register', 'Auth::attemptRegister');
$routes->post('logout', 'Auth::logout');
$routes->get('session/expired', 'Auth::expired');
$routes->get('share/(:num)/(:num)/([a-f0-9]{64})', 'Media::share/$1/$2/$3');

// YouTube downloads + subtitles + summary: public, no login
$routes->get('youtube', 'Youtube::index');
$routes->post('youtube', 'Youtube::add');
$routes->get('youtube/(:num)', 'Youtube::show/$1');
$routes->get('youtube/(:num)/status', 'Youtube::status/$1');
$routes->get('youtube/(:num)/file', 'Youtube::file/$1');
$routes->get('youtube/(:num)/thumb', 'Youtube::thumb/$1');
$routes->get('youtube/(:num)/sub/([\w.-]+)\.(vtt|srt|txt)', 'Youtube::sub/$1/$2/$3');
$routes->post('youtube/(:num)/analyze', 'Youtube::analyze/$1');
$routes->post('youtube/(:num)/retry', 'Youtube::retry/$1');
$routes->post('youtube/(:num)/delete', 'Youtube::delete/$1');

// Chrome extension (token auth, no session/CSRF)
$routes->get('xapi/status', 'XApi::status');
$routes->post('xapi/posts', 'XApi::posts');

$routes->group('', ['filter' => 'auth'], static function (RouteCollection $routes) {
    $routes->get('account', 'Settings::index');
    $routes->get('settings', 'Settings::index');
    $routes->post('settings/account', 'Settings::account');
    $routes->post('settings/cookies/([a-z]+)', 'Settings::uploadCookie/$1');
    $routes->post('settings/cookies/([a-z]+)/delete', 'Settings::deleteCookie/$1');

    $routes->get('xwatch', 'XWatchPage::index');
    $routes->get('xwatch/panel', 'Library::xwatchFragment');
    $routes->get('xwatch/checks', 'XWatchPage::checks');
    $routes->post('xwatch/telegram', 'XWatchPage::telegram');
    $routes->post('xwatch/settings', 'XWatchPage::save');
    $routes->post('xwatch/token', 'XWatchPage::token');
    $routes->post('xwatch/check', 'XWatchPage::checkNow');
    $routes->post('xwatch/posts/(:num)/delete', 'XWatchPage::deletePost/$1');

    $routes->get('library', 'Library::index');
    $routes->post('library/upload', 'Library::upload');
    $routes->post('library/delete', 'Library::bulkDelete');
    $routes->post('library/category', 'Library::bulkCategory');
    $routes->post('library/convert', 'Convert::bulk');
    $routes->post('api/upload/init', 'Upload::init');
    $routes->post('api/upload/chunk', 'Upload::chunk');
    $routes->post('api/upload/finish', 'Upload::finish');
    $routes->post('api/upload/abort', 'Upload::abort');
    $routes->get('library/(:num)', 'Library::show/$1');
    $routes->post('library/(:num)/delete', 'Library::delete/$1');
    $routes->post('library/(:num)/split', 'Library::split/$1');
    $routes->get('media/(:num)/thumb', 'Media::thumb/$1');
    $routes->get('media/(:num)/file', 'Media::file/$1');
    $routes->get('media/(:num)/proxy', 'Media::proxy/$1');
    $routes->get('media/(:num)/strip', 'Media::strip/$1');

    $routes->get('fonts/([a-z0-9]+)', 'Media::font/$1');
    $routes->get('edit/(:num)', 'Edit::index/$1');
    $routes->post('api/edit/(:num)', 'Edit::submit/$1');
    $routes->post('api/prefs/watermark', 'Prefs::watermark');
    $routes->get('api/jobs/(:num)', 'Jobs::show/$1');
    $routes->get('api/jobs', 'Jobs::index');
    $routes->get('api/media/(:num)', 'Media::info/$1');
    $routes->post('api/media/(:num)/rename', 'Media::rename/$1');
    $routes->post('api/media/(:num)/category', 'Media::category/$1');
    $routes->post('api/media/(:num)/sharelink', 'Media::shareLink/$1');
    $routes->post('api/convert/(:num)', 'Convert::submit/$1');
    $routes->post('api/import/inspect', 'Import::inspect');
    $routes->post('api/import/duplicates', 'Import::duplicates');
    $routes->post('api/import', 'Import::submit');

    $routes->group('admin', ['filter' => 'admin'], static function (RouteCollection $routes) {
        $routes->get('', 'Admin::index');
        $routes->post('users/(:num)', 'Admin::update/$1');
        $routes->post('categories', 'Admin::addCategory');
        $routes->post('categories/(:num)', 'Admin::category/$1');
    });
});
