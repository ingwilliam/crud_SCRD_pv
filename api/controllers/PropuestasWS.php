<?php

//error_reporting(E_ALL);
//ini_set('display_errors', '1');
use Phalcon\Loader;
use Phalcon\Mvc\Micro;
use Phalcon\Di\FactoryDefault;
use Phalcon\Db\Adapter\Pdo\Postgresql as DbAdapter;
use Phalcon\Config\Adapter\Ini as ConfigIni;
use Phalcon\Http\Request;
use Phalcon\Logger\Adapter\File as FileAdapter;
use Phalcon\Logger\Formatter\Line;

// Definimos algunas rutas constantes para localizar recursos
define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH);

//Defino las variables principales de conexion
$config = new ConfigIni('../config/config.ini');

// Registramos un autoloader
$loader = new Loader();

$loader->registerDirs(
        [
            APP_PATH . '/models/',
            APP_PATH . '/library/class/',
        ]
);

$loader->register();

// Crear un DI
$di = new FactoryDefault();

//Set up the database service
$di->set('db', function () use ($config) {
    return new DbAdapter(
            array(
        "host" => $config->database->host,
        "username" => $config->database->username,
        "password" => $config->database->password,
        "dbname" => $config->database->name
            )
    );
});

//Funcionalidad para crear los log de la aplicación
//la carpeta debe tener la propietario y usuario
//sudo chown -R www-data:www-data log/
//https://docs.phalcon.io/3.4/es-es/logging
$formatter = new Line('{"date":"%date%","type":"%type%",%message%},');
$formatter->setDateFormat('Y-m-d H:i:s');
$logger = new FileAdapter($config->sistema->path_log . "convocatorias." . date("Y-m-d") . ".log");
$logger->setFormatter($formatter);

$app = new Micro($di);

$app->post('/reporte_propuesta_inscrita', function () use ($app, $config, $logger) {

$propuesta = Propuestas::findFirst(10);    
    
$bogota=($propuesta->bogota) ? "Si":"No";

//Si la convocatoria seleccionada es categoria, debo invertir los nombres la convocatoria con la categoria
$nombre_convocatoria = $propuesta->getConvocatorias()->nombre;
$nombre_categoria = "";
if ($propuesta->getConvocatorias()->convocatoria_padre_categoria > 0) {
    $nombre_convocatoria = $propuesta->getConvocatorias()->getConvocatorias()->nombre;
    $nombre_categoria = $propuesta->getConvocatorias()->nombre;    
}

$participante = $propuesta->getParticipantes()->primer_nombre . " " . $propuesta->getParticipantes()->segundo_nombre . " " . $propuesta->getParticipantes()->primer_apellido . " " . $propuesta->getParticipantes()->segundo_apellido;

$html = 
'<!-- EXAMPLE OF CSS STYLE -->
<style>
        table {
		font-size: 10pt;	
	}
        
	td {
		border: 1px solid #E3E3E3;	
                background-color: #ffffee;
	}
</style>
<br/>
<h3>Información de la propuesta</h3>        
<table>
    <tr>
        <td colspan="2">Código</td>
        <td colspan="2">64-10</td>            
    </tr>    
    <tr>
        <td>Nombre de la convocatoria</td>
        <td>'.$nombre_convocatoria.'</td>    
        <td>Categoría de la convocatoria</td>
        <td>'.$nombre_categoria.'</td>
    </tr>    
    <tr>
        <td>Nombre del participante</td>
        <td>'.$participante.'</td>
        <td>Tipo de participante</td>
        <td>'.$propuesta->getParticipantes()->usuario_perfil.'</td>
    </tr>    
    <tr>
        <td>Estado</td>
        <td>'.$propuesta->getEstados()->nombre.'</td>
        <td>Nombre de la propuesta</td>
        <td>'.$propuesta->nombre.'</td>
    </tr>    
    <tr>
        <td>Resumen de la propuesta</td>
        <td>'.$propuesta->resumen.'</td>
        <td>Objetivo de la propuesta</td>
        <td>'.$propuesta->objetivo.'</td>
    </tr>    
    <tr>
        <td>¿Su propuesta se desarrolla en Bogotá D.C.?</td>
        <td>'.$bogota.'</td>
        <td>Localidad</td>
        <td>'.$propuesta->localidad.'</td>
    </tr>    
    <tr>
        <td>Upz</td>
        <td>'.$propuesta->upz.'</td>
        <td>Barrio</td>
        <td>'.$propuesta->barrio.'</td>
    </tr>    
</table>
<h3>Información del participante</h3>
<table>
    <tr>
        <td>Tipo de documento de identificación</td>
        <td>'.$propuesta->getParticipantes()->tipo_documento.'</td>    
        <td>Número de documento de identificación</td>
        <td>'.$propuesta->getParticipantes()->numero_documento.'</td>
    </tr>    
    <tr>
        <td>Primer nombre</td>
        <td>'.$propuesta->getParticipantes()->primer_nombre.'</td>
        <td>Segundo nombre</td>
        <td>'.$propuesta->getParticipantes()->segundo_nombre.'</td>
    </tr>    
    <tr>
        <td>Primer apellido</td>
        <td>'.$propuesta->getParticipantes()->primer_apellido.'</td>
        <td>Segundo apellido</td>
        <td>'.$propuesta->getParticipantes()->segundo_apellido.'</td>
    </tr>    
    <tr>
        <td>Sexo</td>
        <td>'.$propuesta->getParticipantes()->segundo_sexo.'</td>
        <td>Orientación Sexual</td>
        <td>'.$propuesta->getParticipantes()->orientacion_sexual.'</td>
    </tr>    
    <tr>
        <td>Identidad de género</td>
        <td>'.$propuesta->getParticipantes()->identidad_genero.'</td>
        <td>Grupo étnico</td>
        <td>'.$propuesta->getParticipantes()->grupo_etnico.'</td>
    </tr>    
    <tr>
        <td>Fecha de nacimiento</td>
        <td>'.$propuesta->getParticipantes()->fecha_nacimiento.'</td>
        <td>Ciudad de nacimiento</td>
        <td>'.$propuesta->getParticipantes()->getCiudadesnacimiento()->nombre.'</td>
    </tr>    
    <tr>
        <td>Ciudad de residencia</td>
        <td>'.$propuesta->getParticipantes()->getCiudadesresidencia()->nombre.'</td>
        <td>Barrio residencia</td>
        <td>'.$propuesta->getParticipantes()->getBarriosresidencia()->nombre.'</td>
    </tr>    
    <tr>
        <td>Dirección de residencia</td>
        <td>'.$propuesta->getParticipantes()->direccion_residencia.'</td>
        <td>Dirección correspondencia</td>
        <td>'.$propuesta->getParticipantes()->direccion_correspondencia.'</td>
    </tr>    
    <tr>
        <td>Estrato</td>
        <td>'.$propuesta->getParticipantes()->estrato.'</td>
        <td>Teléfono fijo</td>
        <td>'.$propuesta->getParticipantes()->numero_telefono.'</td>
    </tr>    
    <tr>
        <td>Número de celular personal</td>
        <td>'.$propuesta->getParticipantes()->numero_celular.'</td>
        <td>Correo electrónico</td>
        <td>'.$propuesta->getParticipantes()->correo_electronico.'</td>
    </tr>    
    <tr>
        <td>Redes sociales</td>
        <td>'.$propuesta->getParticipantes()->redes_sociales.'</td>
        <td>Página web, vínculo o blog</td>
        <td>'.$propuesta->getParticipantes()->links.'</td>
    </tr>           
</table>
<h3>Documentación administrativa</h3>
<table>    
    <tr>
        <td>N°</td>
        <td>Requisito</td>    
        <td>Archivo</td>        
    </tr>                   
</table>
<table>    
    <tr>
        <td>N°</td>
        <td>Requisito</td>    
        <td>Link</td>        
    </tr>                   
</table>
<h3>Documentación técnica</h3>
<table>    
    <tr>
        <td>N°</td>
        <td>Requisito</td>    
        <td>Archivo</td>        
    </tr>                   
</table>
<table>    
    <tr>
        <td>N°</td>
        <td>Requisito</td>    
        <td>Link</td>        
    </tr>                   
</table>
';
 echo $html;   
});


try {
    // Gestionar la consulta
    $app->handle();
} catch (\Exception $e) {
    echo 'Excepción: ', $e->getMessage();
}
?>