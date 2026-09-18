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

$routes->group('', ['filter' => 'auth'], static function (RouteCollection $routes) {
    $routes->get('account', 'Account::edit');
    $routes->post('account', 'Account::update');

    $routes->get('library', 'Library::index');
    $routes->post('library/upload', 'Library::upload');
    $routes->get('library/(:num)', 'Library::show/$1');
    $routes->post('library/(:num)/delete', 'Library::delete/$1');
    $routes->get('media/(:num)/thumb', 'Media::thumb/$1');
    $routes->get('media/(:num)/file', 'Media::file/$1');
    $routes->get('media/(:num)/proxy', 'Media::proxy/$1');
    $routes->get('media/(:num)/strip', 'Media::strip/$1');

    $routes->get('edit/(:num)', 'Edit::index/$1');
    $routes->post('api/edit/(:num)', 'Edit::submit/$1');
    $routes->get('api/jobs/(:num)', 'Jobs::show/$1');
    $routes->get('api/jobs', 'Jobs::index');
});
