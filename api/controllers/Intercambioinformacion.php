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
use Phalcon\Http\Response;

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
        "host" => $config->database->host,"port" => $config->database->port,
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


$app->post('/total_propuestas_inscritas', function () use ($app, $config, $logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();    

    try {
        
        //Consulto el usuario por username del parametro get        
        $usuario_validar = Usuarios::findFirst("UPPER(username) = '" . strtoupper($this->request->getPost('username')) . "'");

        //Valido si existe
        if (isset($usuario_validar->id)) {
            
            //Consulto perfil del usuario
            $perfil = Usuariosperfiles::findFirst("usuario = " . $usuario_validar->id . " AND perfil=29");
            if(isset($perfil->id))
            {
                //Valido si la clave es igual al token del usuario
                if ($this->security->checkHash($this->request->getPost('password'), $usuario_validar->password)) {
                    //Genero reporte
                    
                    if($this->request->getPost('agrupar')=='localidad')
                    {
                        $sql = "
                        SELECT 
                            NOW() AS fecha_consulta,
                            c.anio,
                            e.nombre AS entidad,                            
                            UPPER(l.nombre) AS localidad,                            
                            COUNt(p.id) AS total_propuestas 
                        FROM Propuestas AS p 
                            INNER JOIN Convocatorias AS c ON c.id=p.convocatoria
                            INNER JOIN Entidades AS e ON e.id=c.entidad
                            LEFT JOIN Localidades AS l ON l.id=p.localidad                                                        
                        WHERE p.estado NOT IN (7,20) AND c.anio<>'2016'
                        GROUP BY 1,2,3,4
                        ORDER BY 3,5 DESC";
                    }
                    
                    if($this->request->getPost('agrupar')=='upz')
                    {
                        $sql = "
                        SELECT 
                            NOW() AS fecha_consulta,
                            c.anio,
                            e.nombre AS entidad,
                            UPPER(l.nombre) AS localidad,
                            UPPER(u.nombre) AS upz,                            
                            COUNt(p.id) AS total_propuestas 
                        FROM Propuestas AS p 
                            INNER JOIN Convocatorias AS c ON c.id=p.convocatoria
                            INNER JOIN Entidades AS e ON e.id=c.entidad
                            LEFT JOIN Localidades AS l ON l.id=p.localidad
                            LEFT JOIN Upzs AS u ON u.id=p.upz                            
                        WHERE p.estado NOT IN (7,20) AND c.anio<>'2016'
                        GROUP BY 1,2,3,4,5
                        ORDER BY 3,6 DESC";
                    }
                    
                    if($this->request->getPost('agrupar')=='barrio')
                    {
                        $sql = "
                        SELECT 
                            NOW() AS fecha_consulta,
                            c.anio,
                            e.nombre AS entidad,
                            UPPER(l.nombre) AS localidad,
                            UPPER(u.nombre) AS upz,
                            UPPER(b.nombre) AS barrio,
                            COUNt(p.id) AS total_propuestas 
                        FROM Propuestas AS p 
                            INNER JOIN Convocatorias AS c ON c.id=p.convocatoria
                            INNER JOIN Entidades AS e ON e.id=c.entidad
                            LEFT JOIN Localidades AS l ON l.id=p.localidad
                            LEFT JOIN Upzs AS u ON u.id=p.upz
                            LEFT JOIN Barrios AS b ON b.id=p.barrio
                        WHERE p.estado NOT IN (7,20) AND c.anio<>'2016'
                        GROUP BY 1,2,3,4,5,6
                        ORDER BY 3,7 DESC";
                    }                                        

                    $array = $app->modelsManager->executeQuery($sql);

                    //Cabecera y respuesta
                    $response = new Response();                
                    $headers  = $response->getHeaders();
                    $headers->set('Content-Type', 'application/json');
                    $response->setHeaders($headers);                        
                    $response->setContent(json_encode($array));

                    //Registro la accion en el log de convocatorias
                    $logger->info('"token":"{token}","user":"{user}","message":"Realiza la conculta con éxito en el controlador Intercambioinformacion en el método total_propuestas_barrios"', ['user' => $this->request->getPost('username'), 'token' => $request->getPut('token')]);
                    $logger->close();
                    return $response;
                }
                else
                {
                    //Registro la accion en el log de convocatorias
                    $logger->error('"token":"{token}","user":"{user}","message":"La contraseña no es correcta"', ['user' => $this->request->getPost('username'), 'token' => 'Intercambioinformacion']);
                    $logger->close();
                    echo "error_usuario";
                }
            }
            else
            {
                //Registro la accion en el log de convocatorias
                $logger->error('"token":"{token}","user":"{user}","message":"El usuario no cuenta con el perfil"', ['user' => $this->request->getPost('username'), 'token' => 'Intercambioinformacion']);
                $logger->close();
                echo "error_perfil";
            }
        }
        else
        {
            //Registro la accion en el log de convocatorias
            $logger->error('"token":"{token}","user":"{user}","message":"El usuario no es correcto"', ['user' => $this->request->getPost('username'), 'token' => 'Intercambioinformacion']);
            $logger->close();
            echo "error_usuario";
        }
        
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias
        $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Intercambioinformacion en el método total_propuestas_barrios, ' . $ex->getMessage() . '"', ['user' => $this->request->getPost('username'), 'token' => "Intercambioinformacion"]);
        $logger->close();
        echo "error_metodo";
    }
}
);

try {
    // Gestionar la consulta
    $app->handle();
} catch (\Exception $e) {
    echo 'Excepción: ', $e->getMessage();
}
?>