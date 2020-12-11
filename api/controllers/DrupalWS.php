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
        "host" => $config->database->host, "port" => $config->database->port,
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


$app->post('/autenticacion_autorizacion', function () use ($app, $config, $logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();

    try {

        //Cabecera y respuesta
        $response = new Response();
        $headers = $response->getHeaders();
        $headers->set('Content-Type', 'application/json');
        $response->setHeaders($headers);

        //Retorno el array
        $array_return = array();

        //Consulto el usuario por username del parametro get        
        $usuario_validar = Usuarios::findFirst("UPPER(username) = '" . strtoupper($this->request->getPost('username')) . "'");

        //Valido si existe el usuario
        if (isset($usuario_validar->id)) {

            //Consulto perfil del usuario
            $perfil = Usuariosperfiles::findFirst("usuario = " . $usuario_validar->id . " AND perfil=29");

            //Valido si existe el perfil asociado
            if (isset($perfil->id)) {
                //Valido si la clave es igual al token del usuario
                if ($this->security->checkHash($this->request->getPost('password'), $usuario_validar->password)) {

                    //Validar que solo tenga un token para consultar
                    $sql = "
                        SELECT 
                            * 
                        FROM Tokens                            
                        WHERE UPPER(json_extract_path_text(user_current,'username')) = '".strtoupper($this->request->getPost('username'))."'";

                    $total_tokens = $app->modelsManager->executeQuery($sql);
                    if (count($total_tokens) > 0) {

                        $array_return["error"] = 5;
                        $array_return["respuesta"] = "Ya cuenta con token de consulta";

                        //Set value return
                        $response->setContent(json_encode($array_return));

                        //Registro la accion en el log de convocatorias
                        $logger->error('"token":"{token}","user":"{user}","message":"Ya cuenta con token de consulta en el controlador DrupalWS en el método autenticacion_autorizacion"', ['user' => $this->request->getPost('username'), 'token' => 'DrupalWS']);
                        $logger->close();
                        return $response;
                    } else {
                        //Fecha actual
                        $fecha_actual = date("Y-m-d H:i:s");

                        //Consulto y elimino todos los tokens que ya no se encuentren vigentes
                        $tokens_eliminar = Tokens::find("date_limit<='" . $fecha_actual . "'");
                        $tokens_eliminar->delete();

                        //Creo el token de acceso para el usuario solicitado, con vigencia diaria hasta la media noche
                        $tokens = new Tokens();
                        $tokens->token = $this->security->hash($usuario_validar->id . "-" . $usuario_validar->tipo_documento . "-" . $usuario_validar->numero_documento);
                        $tokens->user_current = json_encode($usuario_validar);
                        $tokens->date_create = $fecha_actual;
                        $tokens->date_limit = date("Y-m-d") . " 23:59:59";
                        $tokens->save();

                        $array_return["error"] = 0;
                        $array_return["respuesta"] = $tokens->token;

                        //Set value return
                        $response->setContent(json_encode($array_return));

                        //Registro la accion en el log de convocatorias
                        $logger->info('"token":"{token}","user":"{user}","message":"Se realiza la consulta con éxito en el controlador DrupalWS en el método autenticacion_autorizacion"', ['user' => $this->request->getPost('username'), 'token' => 'DrupalWS']);
                        $logger->close();
                        return $response;
                    }
                } else {
                    $array_return["error"] = 4;
                    $array_return["respuesta"] = "La contraseña no es correcta";

                    //Set value return
                    $response->setContent(json_encode($array_return));

                    //Registro la accion en el log de convocatorias
                    $logger->error('"token":"{token}","user":"{user}","message":"La contraseña no es correcta en el controlador DrupalWS en el método autenticacion_autorizacion"', ['user' => $this->request->getPost('username'), 'token' => 'DrupalWS']);                    
                    $logger->close();
                    return $response;
                }
            } else {
                $array_return["error"] = 3;
                $array_return["respuesta"] = "El usuario no cuenta con el perfil de WS";

                //Set value return
                $response->setContent(json_encode($array_return));

                //Registro la accion en el log de convocatorias
                $logger->error('"token":"{token}","user":"{user}","message":"El usuario no cuenta con el perfil de WS en el controlador DrupalWS en el método autenticacion_autorizacion"', ['user' => $this->request->getPost('username'), 'token' => 'DrupalWS']);                    
                $logger->close();
                return $response;
            }
        } else {
            $array_return["error"] = 2;
            $array_return["respuesta"] = "El usuario no es correcto";

            //Set value return
            $response->setContent(json_encode($array_return));

            //Registro la accion en el log de convocatorias
            $logger->error('"token":"{token}","user":"{user}","message":"El usuario no es correcto en el controlador DrupalWS en el método autenticacion_autorizacion"', ['user' => $this->request->getPost('username'), 'token' => 'DrupalWS']);                    
            $logger->close();
            return $response;
        }
    } catch (Exception $ex) {
        $array_return["error"] = 1;
        $array_return["respuesta"] = "Error en el controlador DrupalWS en el método autenticacion_autorizacion.";

        //Set value return
        $response->setContent(json_encode($array_return));

        //Registro la accion en el log de convocatorias
        $logger->error('"token":"{token}","user":"{user}","message":"'.$ex->getMessage().' en el controlador DrupalWS en el método autenticacion_autorizacion"', ['user' => $this->request->getPost('username'), 'token' => 'DrupalWS']);                            
        $logger->close();
        return $response;
    }
}
);

$app->post('/convocatorias_publicadas', function () use ($app, $config, $logger) {
    
    //Cabecera y respuesta
    $response = new Response();
    $headers = $response->getHeaders();
    $headers->set('Content-Type', 'application/json');
    $response->setHeaders($headers);

    //Retorno el array
    $array_return = array();
    
    //Instancio los objetos que se van a manejar
    $request = new Request();

    try {

        //Consulto el unico token de consulta
        $token_validar = Tokens::findFirst("token = '" . $this->request->getPost('token') . "'");

        //Valido si existe el token
        if (isset($token_validar->id)) {

            
            $sql = "SELECT * FROM Viewconvocatoriaspublicas";
            
            $array = $app->modelsManager->executeQuery($sql);


            $array_return["error"] = 0;
            $array_return["respuesta"] = $array;

            //Set value return
            $response->setContent(json_encode($array_return));

            //Registro la accion en el log de convocatorias
            $logger->info('"token":"{token}","user":"{user}","message":"Realiza la conculta con éxito en el controlador Intercambioinformacion en el método total_propuestas_barrios"', ['user' => $this->request->getPost('username'), 'token' => $request->getPut('token')]);
            $logger->close();
            return $response;
        } else {
            $array_return["error"] = 2;
            $array_return["respuesta"] = "El token no es correcto";

            //Set value return
            $response->setContent(json_encode($array_return));

            //Registro la accion en el log de convocatorias
            $logger->error('"token":"{token}","user":"{user}","message":"El token no es correcto en el controlador DrupalWS en el método convocatorias_publicadas"', ['user' => $this->request->getPost('username'), 'token' => 'DrupalWS']);                                
            $logger->close();
            return $response;
        }
    } catch (Exception $ex) {
        $array_return["error"] = 1;
        $array_return["respuesta"] = "Error en el controlador DrupalWS en el método convocatorias_publicadas.";

        //Set value return
        $response->setContent(json_encode($array_return));

        //Registro la accion en el log de convocatorias
        $logger->error('"token":"{token}","user":"{user}","message":"'.$ex->getMessage().' en el controlador DrupalWS en el método convocatorias_publicadas"', ['user' => $this->request->getPost('username'), 'token' => 'DrupalWS']);                            
        $logger->close();
        return $response;
    }
}
);

$app->post('/liberar_token', function () use ($app, $config, $logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();

    try {

        //Cabecera y respuesta
        $response = new Response();
        $headers = $response->getHeaders();
        $headers->set('Content-Type', 'application/json');
        $response->setHeaders($headers);

        //Retorno el array
        $array_return = array();

        //Consulto el usuario por username del parametro get        
        $usuario_validar = Usuarios::findFirst("UPPER(username) = '" . strtoupper($this->request->getPost('username')) . "'");

        //Valido si existe el usuario
        if (isset($usuario_validar->id)) {

            //Consulto perfil del usuario
            $perfil = Usuariosperfiles::findFirst("usuario = " . $usuario_validar->id . " AND perfil=29");

            //Valido si existe el perfil asociado
            if (isset($perfil->id)) {
                //Valido si la clave es igual al token del usuario
                if ($this->security->checkHash($this->request->getPost('password'), $usuario_validar->password)) {

                    //Elimino todos los tokens relacionados con el usuario de consulta ws
                    $sql = "
                        DELETE FROM Tokens
                        WHERE UPPER(json_extract_path_text(user_current,'username')) = '".strtoupper($this->request->getPost('username'))."'";

                    $app->modelsManager->executeQuery($sql);
                    
                    $array_return["error"] = 0;
                    $array_return["respuesta"] = "Token liberado";

                    //Set value return
                    $response->setContent(json_encode($array_return));

                    //Registro la accion en el log de convocatorias
                    $logger->info('"token":"{token}","user":"{user}","message":"Se libera el token éxito en el controlador DrupalWS en el método liberar_token"', ['user' => $this->request->getPost('username'), 'token' => 'DrupalWS']);
                    $logger->close();
                    return $response;
                    
                } else {
                    $array_return["error"] = 4;
                    $array_return["respuesta"] = "La contraseña no es correcta";

                    //Set value return
                    $response->setContent(json_encode($array_return));

                    //Registro la accion en el log de convocatorias
                    $logger->error('"token":"{token}","user":"{user}","message":"La contraseña no es correcta en el controlador DrupalWS en el método liberar_token"', ['user' => $this->request->getPost('username'), 'token' => 'DrupalWS']);                    
                    $logger->close();
                    return $response;
                }
            } else {
                $array_return["error"] = 3;
                $array_return["respuesta"] = "El usuario no cuenta con el perfil de WS";

                //Set value return
                $response->setContent(json_encode($array_return));

                //Registro la accion en el log de convocatorias
                $logger->error('"token":"{token}","user":"{user}","message":"El usuario no cuenta con el perfil de WS en el controlador DrupalWS en el método liberar_token"', ['user' => $this->request->getPost('username'), 'token' => 'DrupalWS']);                    
                $logger->close();
                return $response;
            }
        } else {
            $array_return["error"] = 2;
            $array_return["respuesta"] = "El usuario no es correcto";

            //Set value return
            $response->setContent(json_encode($array_return));

            //Registro la accion en el log de convocatorias
            $logger->error('"token":"{token}","user":"{user}","message":"El usuario no es correcto en el controlador DrupalWS en el método liberar_token"', ['user' => $this->request->getPost('username'), 'token' => 'DrupalWS']);                    
            $logger->close();
            return $response;
        }
    } catch (Exception $ex) {
        $array_return["error"] = 1;
        $array_return["respuesta"] = "Error en el controlador DrupalWS en el método liberar_token.";

        //Set value return
        $response->setContent(json_encode($array_return));

        //Registro la accion en el log de convocatorias
        $logger->error('"token":"{token}","user":"{user}","message":"'.$ex->getMessage().' en el controlador DrupalWS en el método liberar_token"', ['user' => $this->request->getPost('username'), 'token' => 'DrupalWS']);                            
        $logger->close();
        return $response;
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