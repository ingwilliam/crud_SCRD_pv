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


$app->get('/select_convocatorias', function () use ($app, $config, $logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al metodo select_convocatorias para cargar las convocatorias con el año (' . $request->get('anio') . ') y la entidad (' . $request->get('entidad') . ')"', ['user' => '', 'token' => $request->get('token')]);

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual > 0) {

            //Realizo una peticion curl por post para verificar si tiene permisos de escritura
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $config->sistema->url_curl . "Session/permiso_escritura");
            curl_setopt($ch, CURLOPT_POST, 2);
            curl_setopt($ch, CURLOPT_POSTFIELDS, "modulo=" . $request->get('modulo') . "&token=" . $request->get('token'));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $permiso_escritura = curl_exec($ch);
            curl_close($ch);

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                             
                $array_convocatorias=Convocatorias::find("anio=".$request->get('anio')." AND entidad=".$request->get('entidad')." AND convocatoria_padre_categoria IS NULL AND estado > 4 AND modalidad <> 2 AND active=TRUE");
                
                $array_interno=array();
                foreach ($array_convocatorias as $convocatoria) {                    
                    $array_interno[$convocatoria->id]["id"]=$convocatoria->id;
                    $array_interno[$convocatoria->id]["nombre"]=$convocatoria->nombre;
                    $array_interno[$convocatoria->id]["tiene_categorias"]=$convocatoria->tiene_categorias;
                    $array_interno[$convocatoria->id]["diferentes_categorias"]=$convocatoria->diferentes_categorias;
                    
                }
                
                //Registro la accion en el log de convocatorias
                $logger->info('"token":"{token}","user":"{user}","message":"Se retorno la información en el metodo select_convocatorias para cargar las convocatorias con el año (' . $request->get('anio') . ') y la entidad (' . $request->get('entidad') . ')"', ['user' => '', 'token' => $request->get('token')]);
                $logger->close();
                
                echo json_encode($array_interno);
                
        
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"Acceso denegado en el metodo select_convocatorias para cargar las convocatorias con el año (' . $request->get('anio') . ') y la entidad (' . $request->get('entidad') . ')"', ['user' => "", 'token' => $request->get('token')]);
                $logger->close();
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco en el metodo select_convocatorias para cargar las convocatorias con el año (' . $request->get('anio') . ') y la entidad (' . $request->get('entidad') . ')"', ['user' => "", 'token' => $request->get('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo select_convocatorias para cargar las convocatorias con el año (' . $request->get('anio') . ') y la entidad (' . $request->get('entidad') . ')' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->get('token')]);
        $logger->close();
        echo "error_metodo";
    }
}
);

$app->get('/select_categorias', function () use ($app, $config, $logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al metodo select_categorias para cargar las categorias de la convocatoria (' . $request->get('conv') . ')"', ['user' => '', 'token' => $request->get('token')]);

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual > 0) {

            //Realizo una peticion curl por post para verificar si tiene permisos de escritura
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $config->sistema->url_curl . "Session/permiso_escritura");
            curl_setopt($ch, CURLOPT_POST, 2);
            curl_setopt($ch, CURLOPT_POSTFIELDS, "modulo=" . $request->get('modulo') . "&token=" . $request->get('token'));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $permiso_escritura = curl_exec($ch);
            curl_close($ch);

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                             
                $array_convocatorias=Convocatorias::find("convocatoria_padre_categoria=".$request->get('conv')." AND active=TRUE");
                
                $array_interno=array();
                foreach ($array_convocatorias as $convocatoria) {                    
                    $array_interno[$convocatoria->id]["id"]=$convocatoria->id;
                    $array_interno[$convocatoria->id]["nombre"]=$convocatoria->nombre;                    
                    
                }
                
                //Registro la accion en el log de convocatorias
                $logger->info('"token":"{token}","user":"{user}","message":"Se retorno la información en el metodo select_categorias para cargar las categorias de la convocatoria (' . $request->get('conv') . ')"', ['user' => '', 'token' => $request->get('token')]);
                $logger->close();
                
                echo json_encode($array_interno);
                
        
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"Acceso denegado en el metodo select_categorias para cargar las categorias de la convocatioria (' . $request->get('conv') . ')"', ['user' => "", 'token' => $request->get('token')]);
                $logger->close();
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco en el metodo select_categorias para cargar las categorias de la convocatoria (' . $request->get('conv') . ')"', ['user' => "", 'token' => $request->get('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo select_convocatorias para cargar las convocatorias con el año (' . $request->get('anio') . ') y la entidad (' . $request->get('entidad') . ')' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->get('token')]);
        $logger->close();
        echo "error_metodo";
    }
}
);

$app->get('/generar_reportes', function () use ($app, $config, $logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();
    
    //Consulto si al menos hay un token
    $token_actual = $tokens->verificar_token($request->get('token'));
    
    //Validar array del usuario
    $user_current = json_decode($token_actual->user_current, true);
        
    try {

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual > 0) {

            //Realizo una peticion curl por post para verificar si tiene permisos de escritura
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $config->sistema->url_curl . "Session/permiso_escritura");
            curl_setopt($ch, CURLOPT_POST, 2);
            curl_setopt($ch, CURLOPT_POSTFIELDS, "modulo=" . $request->get('modulo') . "&token=" . $request->get('token'));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $permiso_escritura = curl_exec($ch);
            curl_close($ch);

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                
                //Genero reporte propuestas por estado
                $sql_propuestas_estado = "
                            SELECT 
                                    p.estado,count(p.id) AS total 
                            FROM Propuestas AS p 
                            LEFT JOIN Estados AS e ON e.id=p.estado
                            WHERE p.convocatoria=".$request->get('convocatoria')."
                            GROUP BY 1";

                $propuestas_estado = $app->modelsManager->executeQuery($sql_propuestas_estado);
                
                $array_retorno=array();
                $array_propuestas_estado=array("7"=>0,"8"=>0,"20"=>0,"21"=>0,"22"=>0,"23"=>0,"24"=>0,"31"=>0);
                foreach ($propuestas_estado as $propuestas) {
                    $array_propuestas_estado[$propuestas->estado]=$propuestas->total;
                }
                $array_retorno["reporte_propuestas_estados"]=$array_propuestas_estado;
                
                
                echo json_encode($array_retorno);
                                                                        
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"Acceso denegado en el metodo generar_reportes  con los siguientes parametros de busqueda (' . $request->get('params') . ')" ', ['user' => $user_current["username"], 'token' => $request->get('token')]);                
                $logger->close();
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco en el metodo generar_reportes con los siguientes parametros de busqueda (' . $request->get('params') . ')" ', ['user' => $user_current["username"], 'token' => $request->get('token')]);            
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo generar_reportes con los siguientes parametros de busqueda (' . $request->get('params') . ')" ' . $ex->getMessage() . '"', ['user' => $user_current["username"], 'token' => $request->get('token')]);        
        $logger->close();
        echo "error_metodo ".$ex->getMessage();
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