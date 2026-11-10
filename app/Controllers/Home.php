<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

class Home extends BaseController
{
    /** GET /  — comprobación rápida de que la API está en línea. */
    public function index(): ResponseInterface
    {
        return $this->response->setJSON([
            'app'     => 'Buzón BUAP SJC — API',
            'version' => 'v1',
            'estado'  => 'en línea',
            'pwa'     => base_url('app/'),
        ]);
    }
}
