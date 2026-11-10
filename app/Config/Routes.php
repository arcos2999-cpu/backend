<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', 'Home::index');

/*
 * API REST v1 — Buzón BUAP SJC
 *   jwt        -> requiere sesión (Authorization: Bearer <token>)
 *   role:admin -> además requiere rol admin
 */
$routes->group('api/v1', ['namespace' => 'App\Controllers\Api'], static function ($routes) {
    $auth  = ['filter' => 'jwt'];
    $admin = ['filter' => ['jwt', 'role:admin']];

    // --- Públicas ---
    $routes->post('auth/register', 'AuthController::register');
    $routes->post('auth/login', 'AuthController::login');

    // --- Cualquier usuario con sesión ---
    $routes->get('auth/me', 'AuthController::me', $auth);
    $routes->get('catalogos', 'CatalogosController::index', $auth);

    $routes->get('reportes', 'ReportesController::index', $auth);
    $routes->post('reportes', 'ReportesController::create', $auth);
    $routes->get('reportes/(:num)', 'ReportesController::show/$1', $auth);
    $routes->post('reportes/(:num)/apoyo', 'ReportesController::apoyo/$1', $auth);
    $routes->post('reportes/(:num)/confirmar', 'ReportesController::confirmar/$1', $auth);
    $routes->post('reportes/(:num)/reabrir', 'ReportesController::reabrir/$1', $auth);

    $routes->post('dispositivos', 'DispositivosController::registrar', $auth);
    $routes->post('dispositivos/baja', 'DispositivosController::baja', $auth);

    // --- Solo administración ---
    $routes->patch('reportes/(:num)/estado', 'ReportesController::estado/$1', $admin);
    $routes->delete('reportes/(:num)', 'ReportesController::delete/$1', $admin);

    $routes->get('usuarios', 'UsuariosController::index', $admin);
    $routes->patch('usuarios/(:num)/rol', 'UsuariosController::rol/$1', $admin);
    $routes->patch('usuarios/(:num)/activo', 'UsuariosController::activo/$1', $admin);

    $routes->post('catalogos/ubicaciones', 'CatalogosController::crearUbicacion', $admin);
    $routes->post('catalogos/categorias', 'CatalogosController::crearCategoria', $admin);

    $routes->get('estadisticas', 'EstadisticasController::index', $admin);
});
