# Buzón BUAP SJC — API (CodeIgniter 4)

Esta carpeta es la API REST. Las instrucciones completas de instalación, configuración y puesta en producción están en el [README principal](../README.md#2-backend-codeigniter-4).

Resumen rápido:

```bash
composer install
cp env .env                 # y edita la base de datos y buzon.jwtSecret
php spark migrate
php spark db:seed BuzonSeeder
php spark serve --host 0.0.0.0 --port 8080
```

Código propio (el resto es el esqueleto estándar de CodeIgniter):

| Ruta | Contenido |
|---|---|
| `app/Config/Buzon.php` | Configuración de la app (se sobrescribe desde `.env`) |
| `app/Config/Routes.php` | Rutas de la API (`/api/v1/...`) |
| `app/Controllers/Api/` | Auth, Reportes, Usuarios, Catálogos, Dispositivos, Estadísticas |
| `app/Filters/` | `JwtAuth` (sesión) y `RoleFilter` (roles) |
| `app/Libraries/` | `JwtService`, `Fcm` (push), `AuthContext` |
| `app/Models/` | `UsuarioModel`, `ReporteModel`, `AnotacionModel` |
| `app/Database/` | Migración del esquema y `BuzonSeeder` |
| `app/Language/es/` | Mensajes de validación en español |
