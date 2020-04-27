<?php

//error_reporting(E_ALL);
//ini_set('display_errors', '1');
use Phalcon\Loader;
use Phalcon\Mvc\Micro;
use Phalcon\Di\FactoryDefault;
use Phalcon\Db\Adapter\Pdo\Postgresql as DbAdapter;
use Phalcon\Config\Adapter\Ini as ConfigIni;
use Phalcon\Http\Request;

// Definimos algunas rutas constantes para localizar recursos
define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH);

$config = new ConfigIni('../config/config.ini');

// Registramos un autoloader
$loader = new Loader();

$loader->registerDirs(
        [
            APP_PATH . '/models/',
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

$app = new Micro($di);

// Recupera todos los registros
$app->get('/new', function () use ($app,$config) {

    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {
            
            //Realizo una peticion curl por post para verificar si tiene permisos de escritura
            $ch = curl_init();
            curl_setopt($ch,CURLOPT_URL, $config->sistema->url_curl."Session/permiso_escritura");
            curl_setopt($ch,CURLOPT_POST, 2);
            curl_setopt($ch,CURLOPT_POSTFIELDS, "modulo=".$request->get('modulo_acceso')."&token=".$request->get('token'));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $permiso_escritura = curl_exec($ch);
            curl_close($ch);
            
            //Verifico que la respuesta es ok, para poder realizar la escritura
            if($permiso_escritura=="ok")
            {            
                //Obtengo los parametros del post
                $post = $app->request->get();
                $moduloperfilpermisos = new Moduloperfilpermisos();

                //Validamos si existe, para eliminar el actual
                $moduloperfilpermisos_validar = Moduloperfilpermisos::findFirst("perfil = '" . $post["perfil"] . "' AND modulo = '" . $post["modulo"] . "'");


                //Valido si el permiso es 0 con el fin de eliminarlo directamente
                if ($post["permiso"] == "0") {
                    $moduloperfilpermisos_validar->delete();
                    echo "ok";
                } else {
                    //Validamos si existe, para eliminar el actual
                    if (isset($moduloperfilpermisos_validar->permiso)) {
                        $moduloperfilpermisos_validar->delete();
                    }
                    //Guardamos el registro
                    if ($moduloperfilpermisos->save($post) === false) {
                        echo "error";
                    } else {
                        echo "ok";
                    }
                }
            }
            else
            {
                echo "acceso_denegado";
            }
        } else {
            echo "error";
        }
    } catch (Exception $ex) {
        echo "error_metodo" . $ex->getMessage();
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