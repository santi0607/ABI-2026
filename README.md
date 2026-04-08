# ABI 

Un sistema web integral para la gestión de contenidos y proyectos de grado, desarrollado con Laravel y Tablar, que permite administrar frameworks de investigación, contenidos académicos, estudiantes, profesores y proyectos educativos. 

## 🚀 Tecnologías Utilizadas

### Backend
- **Laravel Framework 10.x** - Framework PHP robusto y escalable
- **PHP 8.1+** - Lenguaje de programación del lado del servidor
- **MySQL** - Base de datos relacional 
- **Laravel Sanctum** - Autenticación API
- **Laravel Tinker** - REPL interactivo para Laravel

### Frontend
- **Tablar** - Kit de interfaz de usuario moderno y responsivo para Laravel
- **Bootstrap 5.3.1** - Framework CSS para diseño responsivo
- **Vite** - Build tool moderno para assets
- **jQuery 3.7** - Librería JavaScript para interactividad
- **ApexCharts** - Librería de gráficos y visualizaciones
- **Bootstrap Icons** - Sistema de iconografía

### Librerías Especializadas
- **DomPDF** - Generación de documentos PDF
- **Maatwebsite Excel** - Exportación e importación de Excel
- **PhpSpreadsheet** - Manipulación de hojas de cálculo
- **TCPDF & FPDF** - Generación avanzada de PDFs
- **TinyMCE** - Editor de texto enriquecido
- **Filepond** - Subida de archivos con vista previa

### Herramientas de Desarrollo
- **Laravel Pint** - Formateador de código PHP
- **Laravel Sail** - Entorno de desarrollo con Docker
- **PHPUnit** - Testing framework
- **Faker** - Generación de datos de prueba
- **Tablar CRUD Generator** - Generador automático de CRUDs para interfaz Tablar

## 📁 Arquitectura del Proyecto

### Estructura de Directorios
```
abi-mio/
├── app/
│   ├── Http/
│   │   └── Controllers/          # Controladores de la aplicación
│   └── Models/                   # Modelos Eloquent
├── config/                       # Archivos de configuración
├── database/
│   └── migrations/               # Migraciones de base de datos
├── public/                       # Archivos públicos y assets
├── resources/
│   ├── views/                    # Plantillas Blade
│   └── js/                       # Assets JavaScript
├── routes/
│   └── web.php                   # Rutas web de la aplicación
└── storage/                      # Almacenamiento de archivos
```

### Modelo de Datos

El sistema ABI gestiona las siguientes entidades principales:

#### 🏛️ Estructura Académica
- **Departamentos** - Departamentos universitarios
- **Ciudades** - Localidades geográficas donde se implementa el programa bilingüe
- **Programas** - Programas académicos bilingües
- **Grupos de Investigación** - Grupos de investigación en educación bilingüe

#### 👥 Usuarios del Sistema
- **Usuarios** - Sistema de autenticación con roles (admin/user)
- **Estudiantes** - Estudiantes en programas bilingües vinculados a proyectos
- **Profesores** - Docentes bilingües supervisores de proyectos

#### 📚 Gestión de Contenido Bilingüe
- **Frameworks** - Marcos pedagógicos y metodológicos de enseñanza bilingüe
- **Contenidos** - Material académico bilingüe y recursos interactivos
- **Proyectos** - Proyectos educativos bilingües e interactivos
- **Versiones** - Control de versiones de contenidos bilingües

#### 🔗 Relaciones Principales
- **Content Framework Project** - Vinculación entre contenidos, frameworks y proyectos
- **Student Project** - Asignación de estudiantes a proyectos
- **Professor Project** - Asignación de profesores a proyectos
- **City Program** - Relación entre ciudades y programas académicos

### Patrón de Arquitectura

El proyecto sigue el patrón **MVC (Model-View-Controller)** de Laravel:

- **Modelos (Models)**: Representan la lógica de negocio y la interacción con la base de datos
- **Vistas (Views)**: Plantillas Blade para la presentación de datos
- **Controladores (Controllers)**: Manejan las peticiones HTTP y coordinan entre modelos y vistas

## 🛠️ Instalación y Configuración

### Prerrequisitos

- **PHP 8.1 o superior**
- **Composer** - Gestor de dependencias PHP
- **Node.js y npm** - Para assets del frontend
- **MySQL 5.7 o superior**
- **Apache/Nginx** - Servidor web

### Pasos de Instalación

1. **Clonar el repositorio**
   ```bash
   git clone <url-del-repositorio>
   cd abi
   ```

2. **Instalar dependencias PHP**
   ```bash
   composer install
   ```

   **Nota: Esta instalacion suele tomar bastante tiempo**

3. **Instalar dependencias JavaScript**
   ```bash
   npm install
   ```

4. **Configurar variables de entorno**

   Comando para usar .env con base de datos local:

   ```bash
   cp .env.example .env
   ```

   Comando para usar .env con base de datos en la nube:

   ```bash
   cp .env.examplenube .env
   ```

   **NOTA IMPORTANTE: Si usa la base de datos en linea omita el paso 6.**
   

5. **Generar clave de aplicación**
   ```bash
   php artisan key:generate
   ```

6. **Levantamiento de la base de datos SOLO SI USA .env LOCAL**
   ```bash
   # En Windows powershell desde la raíz del proyecto
   .\scripts\set-db-roles.ps1

   # En linux desde la raíz del proyecto
   bash scripts/set-db-roles.sh
   ./scripts/set-db-roles.sh
   ```

7. **Compilar assets del frontend**
   ```bash
   npm run build
   ```

8. **Iniciar servidor de desarrollo**
   ```bash
   php artisan serve
   ```

La aplicación estará disponible en `http://127.0.0.1:8000`

### Configuración de Base de Datos

El sistema ABI requiere una base de datos MySQL. Las migraciones incluyen:

- Tablas de autenticación (usuarios, tokens, etc.)
- Estructura académica bilingüe (departamentos, ciudades, programas)
- Gestión de proyectos y contenidos bilingües
- Relaciones entre entidades del sistema educativo

## 👤 Sistema de Autenticación

El sistema implementa autenticación basada en roles:

### Roles Disponibles
- **Admin**: Acceso completo al sistema
- **User**: Acceso limitado a funcionalidades específicas

### Funcionalidades por Rol

#### Administrador
- Gestión completa de departamentos y ciudades
- Administración de frameworks y contenidos
- Control de usuarios y perfiles
- Acceso a todos los reportes y estadísticas

#### Usuario
- Consulta de información académica
- Acceso limitado a funcionalidades específicas

## 🎯 Funcionalidades Principales

### 📊 Gestión de Frameworks
- **CRUD completo** de frameworks de investigación
- **Búsqueda y filtrado** por nombre, descripción o año
- **Validación avanzada** de datos
- **Control de integridad** referencial

### 🏗️ Gestión de Proyectos
- Creación y administración de proyectos académicos
- Asignación de estudiantes y profesores
- Vinculación con frameworks y contenidos
- Control de estados y versiones

### 👥 Gestión de Usuarios
- Sistema de registro y autenticación
- Gestión de perfiles de estudiantes y profesores
- Control de acceso basado en roles

### 📈 Reportes y Exportación
- Generación de reportes en PDF
- Exportación de datos a Excel
- Visualización de estadísticas con gráficos

## 🎨 Interfaz Tablar para CRUDs

El sistema utiliza **Tablar** como framework de UI, que proporciona una interfaz moderna y responsiva para todas las operaciones CRUD:

### Características de Tablar
- **Diseño Responsivo**: Se adapta automáticamente a dispositivos móviles y desktop
- **Componentes Preconstruidos**: Formularios, tablas, modales y botones estilizados
- **Navegación Intuitiva**: Breadcrumbs y menús laterales organizados
- **Alertas y Notificaciones**: Sistema integrado de mensajes de éxito/error
- **Paginación Automática**: Manejo eficiente de grandes conjuntos de datos

### Generación de CRUDs con Tablar
El proyecto incluye **Tablar CRUD Generator** para crear automáticamente interfaces completas:

```bash
# Generar CRUD completo para un modelo
php artisan make:tablar-crud ModelName 

# Generar solo el controlador con interfaz Tablar
php artisan make:tablar-controller ModelController

# Generar vistas Tablar para un modelo existente
php artisan make:tablar-views ModelName
```

### Estructura de Vistas Tablar
Cada CRUD generado incluye:
- **index.blade.php**: Listado con búsqueda, filtros y paginación
- **create.blade.php**: Formulario de creación con validación
- **edit.blade.php**: Formulario de edición con datos precargados
- **show.blade.php**: Vista detallada del registro
- **form.blade.php**: Componente reutilizable de formulario

### Personalización de la Interfaz
```php
// En el controlador, personalizar la vista
public function index()
{
    $items = Model::paginate(10);
    
    return view('tablar::models.index', [
        'items' => $items,
        'title' => 'Gestión de Items',
        'create_route' => 'models.create',
        'show_route' => 'models.show'
    ]);
}
```

## 🔧 Comandos Útiles

### Desarrollo
```bash
# Servidor de desarrollo
php artisan serve

# Compilar assets en desarrollo
npm run dev

# Limpiar caché
php artisan cache:clear
php artisan config:clear
php artisan view:clear
```

### Producción
```bash
# Optimizar aplicación
php artisan optimize
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Compilar assets para producción
npm run build
```

### Base de Datos
```bash
# Ejecutar migraciones
php artisan migrate

# Rollback migraciones
php artisan migrate:rollback

# Refrescar base de datos
php artisan migrate:refresh
```

## 🚀 Despliegue

### Preparación para Producción

1. **Configurar variables de entorno**
   ```env
   APP_ENV=production
   APP_DEBUG=false
   ```

2. **Optimizar aplicación**
   ```bash
   composer install --optimize-autoloader --no-dev
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   ```

3. **Configurar permisos**
   ```bash
   chmod -R 775 storage bootstrap/cache
   chown -R www-data:www-data storage bootstrap/cache
   ```

### Servidor Web

#### Apache
```apache
<VirtualHost *:80>
    DocumentRoot /path/to/abi-mio/public
    <Directory /path/to/abi-mio/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

#### Nginx
```nginx
server {
    listen 80;
    root /path/to/abi-mio/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

## 🔒 Seguridad

El sistema implementa las siguientes medidas de seguridad:

- **Autenticación Laravel Sanctum**
- **Protección CSRF**
- **Validación de datos de entrada**
- **Control de acceso basado en roles**
- **Sanitización de datos**

## 🤝 Contribución

Para contribuir al proyecto:

1. Fork el repositorio
2. Crea una rama para tu funcionalidad (`git checkout -b feature/nueva-funcionalidad`)
3. Commit tus cambios (`git commit -am 'Añadir nueva funcionalidad'`)
4. Push a la rama (`git push origin feature/nueva-funcionalidad`)
5. Crea un Pull Request

## 📄 Licencia

Este proyecto está bajo la Licencia MIT. Ver el archivo `LICENSE` para más detalles.



