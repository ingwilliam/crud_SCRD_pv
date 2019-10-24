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
$logger = new FileAdapter($config->sistema->path_log."convocatorias.".date("Y-m-d").".log");
$logger->setFormatter($formatter);

$app = new Micro($di);

//Metodo que consulta el participante, con el cual va a registar la propuesta
//Se realiza la busqueda del participante
//Si no existe en inicial lo enviamos a crear el perfil
//Si existe el participante asociado a la propuesta se retorna
$app->get('/buscar_propuesta', function () use ($app, $config, $logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al metodo buscar_propuesta como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => '', 'token' => $request->get('token')]);

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
                //Validar si existe un participante como persona jurídica, con id usuario innner usuario_perfil
                $user_current = json_decode($token_actual->user_current, true);

                //Busco si tiene el perfil asociado de acuerdo al parametro
                if($request->get('m')=="pn")
                {
                    $tipo_participante="Persona Natural";
                    $usuario_perfil = Usuariosperfiles::findFirst("usuario=" . $user_current["id"] . " AND perfil = 6");
                }                
                if($request->get('m')=="pj")
                {
                    $tipo_participante="Persona Jurídica";
                    $usuario_perfil = Usuariosperfiles::findFirst("usuario=" . $user_current["id"] . " AND perfil = 7");
                }
                if($request->get('m')=="agr")
                {
                    $tipo_participante="Agrupaciones";
                    $usuario_perfil = Usuariosperfiles::findFirst("usuario=" . $user_current["id"] . " AND perfil = 8");
                }                                                                                                                
               
                if (isset($usuario_perfil->id)) {
                    
                    //Consulto el participante inicial
                    $participante = Participantes::findFirst("usuario_perfil=" . $usuario_perfil->id . " AND tipo='Inicial' AND active=TRUE");

                    //Si existe el participante inicial con el perfil de acuerdo al parametro
                    if (isset($participante->id)) {
                        //Consulto la convocatoria
                        $convocatoria = Convocatorias::findFirst($request->get('conv'));
                        
                        //Si la convocatoria seleccionada es categoria, debo invertir los nombres la convocatoria con la categoria
                        $nombre_convocatoria=$convocatoria->nombre;
                        $nombre_categoria="";                        
                        if($convocatoria->convocatoria_padre_categoria>0)
                        {
                            $nombre_convocatoria=$convocatoria->getConvocatorias()->nombre;
                            $nombre_categoria=$convocatoria->nombre;
                            
                        }
                       
                        
                        //Consulto la propuesta que esta relacionada con el participante
                        $sql_propuesta = "SELECT 
                                                par.*, 
                                                p.*
                                        FROM Propuestas AS p
                                            INNER JOIN Participantes AS par ON par.id=p.participante                                            
                                        WHERE
                                        p.convocatoria=" . $request->get('conv') . " AND par.usuario_perfil=" . $usuario_perfil->id . " AND par.tipo='Participante' AND par.participante_padre=" . $participante->id . "";

                        $propuesta = $app->modelsManager->executeQuery($sql_propuesta)->getFirst();

                        //Creo el array de la propuesta
                        $array = array();
                        $array["propuesta"]["nombre_participante"] = $propuesta->par->primer_nombre." ".$propuesta->par->segundo_nombre." ".$propuesta->par->primer_apellido." ".$propuesta->par->segundo_apellido;
                        $array["propuesta"]["tipo_participante"] = $tipo_participante;
                        $array["propuesta"]["nombre_convocatoria"] = $nombre_convocatoria;
                        $array["propuesta"]["nombre_categoria"] = $nombre_categoria;
                        $array["propuesta"]["estado"] = $propuesta->p->getEstados()->nombre;
                        $array["propuesta"]["nombre"] = $propuesta->p->nombre;
                        $array["propuesta"]["resumen"] = $propuesta->p->resumen;
                        $array["propuesta"]["bogota"] = $propuesta->p->bogota;
                        $array["propuesta"]["localidad"] = $propuesta->p->localidad;
                        $array["propuesta"]["upz"] = $propuesta->p->upz;
                        $array["propuesta"]["barrio"] = $propuesta->p->barrio;
                        $array["propuesta"]["id"] = $propuesta->p->id;
                        $array["localidades"]= Localidades::find("active=true");
                        $array["upzs"]=array();
                        $array["barrios"]=array();
                        if(isset($propuesta->p->localidad))
                        {
                            $array["upzs"]= Upzs::find("active=true AND localidad=".$convocatoria->localidad);
                            $array["barrios"]= Barrios::find("active=true AND localidad=".$convocatoria->localidad);
                        }
                        
                        //Registro la accion en el log de convocatorias
                        $logger->info('"token":"{token}","user":"{user}","message":"Retorno en el metodo buscar_propuesta como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                        $logger->close();

                        //Retorno el array
                        echo json_encode($array);
                    } else {
                        //Registro la accion en el log de convocatorias           
                        $logger->error('"token":"{token}","user":"{user}","message":"Debe crear el perfil como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . '), en el metodo buscar_propuesta"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                        $logger->close();
                        echo "crear_perfil";
                        exit;
                    }
                } else {
                    //Registro la accion en el log de convocatorias           
                    $logger->error('"token":"{token}","user":"{user}","message":"Debe crear el perfil como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . '), en el metodo buscar_propuesta"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                    $logger->close();
                    echo "crear_perfil";
                    exit;
                }
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"Acceso denegado en el metodo buscar_propuesta como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => "", 'token' => $request->get('token')]);
                $logger->close();
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco en el metodo buscar_propuesta como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => "", 'token' => $request->get('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo buscar_propuesta como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ') ' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->get('token')]);
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