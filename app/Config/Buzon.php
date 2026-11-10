<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Configuración propia de "Buzón BUAP SJC".
 *
 * Todos los valores se pueden sobrescribir desde el archivo .env, por ejemplo:
 *   buzon.jwtSecret = una-cadena-larga-y-aleatoria
 *   buzon.maxReportsPerHour = 5
 */
class Buzon extends BaseConfig
{
    // ---- IDs del catálogo "estados" (deben coincidir con el seeder) ----
    public const ESTADO_PENDIENTE   = 1;
    public const ESTADO_REVISION    = 2;
    public const ESTADO_EN_PROCESO  = 3;
    public const ESTADO_NO_VIABLE   = 4;
    public const ESTADO_SOLUCIONADO = 5;
    public const ESTADO_REABIERTO   = 6;

    public const ROLES       = ['alumno', 'maestro', 'personal', 'admin'];
    public const PRIORIDADES = ['baja', 'media', 'alta', 'urgente'];

    /** Carreras que se pueden elegir al registrarse. */
    public const CARRERAS = ['ISTII', 'IAA', 'IPGI'];

    /**
     * Clave secreta para firmar los JWT (mínimo 32 caracteres).
     * OBLIGATORIO cambiarla en el .env. Genera una con:  php spark key:generate
     * o con:  php -r "echo bin2hex(random_bytes(32));"
     */
    public string $jwtSecret = '';

    /** Días de vigencia de la sesión. */
    public int $jwtTtlDays = 7;

    /**
     * Formato válido de la matrícula al registrarse (expresión regular).
     * Por defecto: solo números, de 6 a 12 dígitos (la matrícula BUAP tiene 9).
     * En .env:  buzon.matriculaPatron = "/^[0-9]{9}$/"
     */
    public string $matriculaPatron = '/^[0-9]{6,12}$/';

    /** Máximo de reportes que un usuario puede crear por hora (anti-spam). */
    public int $maxReportsPerHour = 5;

    /** Máximo de imágenes por reporte y tamaño máximo de cada una (KB). */
    public int $maxImages  = 3;
    public int $maxImageKB = 3072;

    /** Carpeta pública (dentro de /public) donde se guardan las fotos. */
    public string $uploadDir = 'uploads/reportes';

    /** Peticiones permitidas por minuto y por IP en login/registro. */
    public int $authAttemptsPerMinute = 10;

    // ---- Notificaciones push (Firebase Cloud Messaging HTTP v1) ----
    /** Ruta absoluta al JSON de la cuenta de servicio de Firebase. Vacío = push desactivado. */
    public string $fcmCredentialsPath = '';

    // ---- Datos del primer administrador (solo para el seeder) ----
    public string $adminMatricula = '000000001';
    public string $adminName      = 'Administrador';
    public string $adminPassword  = '';
}
