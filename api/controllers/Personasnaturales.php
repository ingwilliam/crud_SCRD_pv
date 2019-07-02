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

$app = new Micro($di);

// Recupera todos las modalidades dependiendo el programa
$app->get('/select', function () use ($app) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual > 0) {
            $array = Participantes::find("active = true");
            echo json_encode($array);
        } else {
            echo "error";
        }
    } catch (Exception $ex) {
        echo "error_metodo" . $ex->getMessage();
    }
}
);

// Crear registro
$app->post('/new', function () use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $chemistry_alfresco = new ChemistryPV($config->alfresco->api, $config->alfresco->username, $config->alfresco->password);

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPost('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual > 0) {

            //Realizo una peticion curl por post para verificar si tiene permisos de escritura
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $config->sistema->url_curl . "Session/permiso_escritura");
            curl_setopt($ch, CURLOPT_POST, 2);
            curl_setopt($ch, CURLOPT_POSTFIELDS, "modulo=" . $request->getPost('modulo') . "&token=" . $request->getPost('token'));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $permiso_escritura = curl_exec($ch);
            curl_close($ch);

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                //Consulto el usuario actual
                $user_current = json_decode($token_actual->user_current, true);
                $post = $app->request->getPost();
                $participante = new Participantes();
                $participante->creado_por = $user_current["id"];
                $participante->fecha_creacion = date("Y-m-d H:i:s");
                $participante->active = true;
                if ($participante->save($post) === false) {
                    echo "error";
                } else {

                    //Recorro todos los posibles archivos
                    foreach ($_FILES as $clave => $valor) {
                        $fileTmpPath = $valor['tmp_name'];
                        $fileType = $valor['type'];
                        $fileNameCmps = explode(".", $valor["name"]);
                        $fileExtension = strtolower(end($fileNameCmps));
                        $fileName = "c" . $request->getPost('convocatoria') . "d" . $participante->id . "u" . $participante->creado_por . "f" . date("YmdHis") . "." . $fileExtension;
                        $return = $chemistry_alfresco->newFile("/Sites/convocatorias/" . $request->getPost('convocatoria') . "/documentacion/", $fileName, file_get_contents($fileTmpPath), $fileType);
                        if (strpos($return, "Error") !== FALSE) {
                            echo "error_creo_alfresco";
                        } else {
                            $participante->id_alfresco = $return;
                            if ($participante->save($participante) === false) {
                                echo "error";
                            } else {
                                echo $participante->id;
                            }
                        }
                    }
                }
            } else {
                echo "acceso_denegado";
            }
        } else {
            echo "error_token";
        }
    } catch (Exception $ex) {
        echo "error_metodo" . $ex->getMessage();
    }
}
);

// Editar registro
$app->post('/edit/{id:[0-9]+}', function ($id) use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        $chemistry_alfresco = new ChemistryPV($config->alfresco->api, $config->alfresco->username, $config->alfresco->password);

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPost('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual > 0) {

            //Realizo una peticion curl por post para verificar si tiene permisos de escritura
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $config->sistema->url_curl . "Session/permiso_escritura");
            curl_setopt($ch, CURLOPT_POST, 2);
            curl_setopt($ch, CURLOPT_POSTFIELDS, "modulo=" . $request->getPost('modulo') . "&token=" . $request->getPost('token'));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $permiso_escritura = curl_exec($ch);
            curl_close($ch);

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                //Consulto el usuario actual
                $user_current = json_decode($token_actual->user_current, true);
                $post = $app->request->getPost();
                // Consultar el usuario que se esta editando
                $participante = Participantes::findFirst(json_decode($id));
                $participante->actualizado_por = $user_current["id"];
                $participante->fecha_actualizacion = date("Y-m-d H:i:s");

                //Recorro todos los posibles archivos
                foreach ($_FILES as $clave => $valor) {
                    $fileTmpPath = $valor['tmp_name'];
                    $fileType = $valor['type'];
                    $fileNameCmps = explode(".", $valor["name"]);
                    $fileExtension = strtolower(end($fileNameCmps));
                    $fileName = "c" . $request->getPost('convocatoria') . "d" . $id . "u" . $participante->creado_por . "f" . date("YmdHis") . "." . $fileExtension;
                    $return = $chemistry_alfresco->newFile("/Sites/convocatorias/" . $request->getPost('convocatoria') . "/documentacion/", $fileName, file_get_contents($fileTmpPath), $fileType);
                    if (strpos($return, "Error") !== FALSE) {
                        echo "error_creo_alfresco";
                    } else {
                        $participante->id_alfresco = $return;
                        if ($participante->save($post) === false) {
                            echo "error";
                        } else {
                            echo $id;
                        }
                    }
                }
            } else {
                echo "acceso_denegado";
            }
        } else {
            echo "error_token";
        }
    } catch (Exception $ex) {
        echo "error_metodo";
    }
}
);

//Busca el registro
$app->get('/search', function () use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual > 0) {
            //Si existe consulto la registro
            if ($request->get('id')) {
                $participante = Participantes::findFirst($request->get('id'));
            } else {
                $participante = new Participantes();
            }
            //Creo todos los array del registro
            $array["participante"] = $participante;

            //Creo los array de los select del formulario
            $array["tipo_documento"]= Tiposdocumentos::find("active=true");
            $array["sexo"]= Sexos::find("active=true");
            $array["orientacion_sexual"]= Orientacionessexuales::find("active=true");
            $array["identidad_genero"]= Identidadesgeneros::find("active=true");
            $array["grupo_etnico"]= Gruposetnicos::find("active=true");            
            $array_ciudades=array();
            foreach( Ciudades::find("active=true") as $value )
            {
                $array_ciudades[]=array("id"=>$value->id,"label"=>$value->nombre." - ".$value->getDepartamentos()->nombre." - ".$value->getDepartamentos()->getPaises()->nombre,"value"=>$value->nombre);                
            }            
            $array["ciudad"]=$array_ciudades; 
            $array_barrios=array();
            foreach( Barrios::find("active=true") as $value )
            {
                $array_barrios[]=array("id"=>$value->id,"label"=>$value->nombre." - ".$value->getLocalidades()->nombre." - ".$value->getLocalidades()->getCiudades()->nombre,"value"=>$value->nombre);                
            }
            $array["barrio"]= $array_barrios;
            $tabla_maestra= Tablasmaestras::find("active=true AND nombre='estrato'");            
            $array["estrato"] = explode(",", $tabla_maestra[0]->valor);
            
            //Retorno el array
            echo json_encode($array);
        } else {
            echo "error_token";
        }
    } catch (Exception $ex) {
        //retorno el array en json null
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