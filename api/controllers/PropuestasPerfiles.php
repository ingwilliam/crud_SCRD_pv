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

//Consulto los participante de una convocatoria
$app->post('/consultar_tipos_participantes/{id:[0-9]+}', function ($id) use ($app, $config, $logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa a consultar los tipos participantes de la convocatoria"', ['user' => '', 'token' => $request->get('token')]);

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual > 0) {
            
            //Validar array del usuario
            $user_current = json_decode($token_actual->user_current, true);
            
            //Consulto la fecha de cierre del cronograma de la convocatoria
            $conditions = ['convocatoria' => $id, 'active' => true];
            $tipos_participantes = Convocatoriasparticipantes::find(([
                        'conditions' => 'convocatoria=:convocatoria: AND active=:active: AND tipo_participante IN (1,2,3)',
                        'bind' => $conditions,
            ]));
            
            //Creo el array para retornar
            $array_tipos_participantes=array(); 
            $i=0;
            foreach ($tipos_participantes as $participante) {
                $array_tipos_participantes[$i]["id"] = $participante->tipo_participante;                
                $array_tipos_participantes[$i]["tipo_participante"] = $participante->getTiposparticipantes()->nombre;                
                $array_tipos_participantes[$i]["descripcion_perfil"] = $participante->descripcion_perfil;                
                $array_tipos_participantes[$i]["terminos_condiciones"] = "";                
                //consulto tabla maestra para los terminos y condiciones pn
                if($participante->tipo_participante==1)
                {
                    $terminos_condiciones= Tablasmaestras::findFirst("active=true AND nombre='tc_td_au_pn_".date("Y")."'");   
                    $array_tipos_participantes[$i]["terminos_condiciones"] = str_replace("/view?usp=sharing", "/preview", $terminos_condiciones->valor);                
                }
                //consulto tabla maestra para los terminos y condiciones pj
                if($participante->tipo_participante==2)
                {
                    $terminos_condiciones= Tablasmaestras::findFirst("active=true AND nombre='tc_td_au_pj_".date("Y")."'");   
                    $array_tipos_participantes[$i]["terminos_condiciones"] = str_replace("/view?usp=sharing", "/preview", $terminos_condiciones->valor);                
                }
                //consulto tabla maestra para los terminos y condiciones agr
                if($participante->tipo_participante==3)
                {
                    $terminos_condiciones= Tablasmaestras::findFirst("active=true AND nombre='tc_td_au_agr_".date("Y")."'");   
                    $array_tipos_participantes[$i]["terminos_condiciones"] = str_replace("/view?usp=sharing", "/preview", $terminos_condiciones->valor);                
                }                
                
                $condiciones_participancion= Tablasmaestras::findFirst("active=true AND nombre='condiciones_participacion_".date("Y")."'");   
                $array_tipos_participantes[$i]["condiciones_participacion"] = str_replace("/view?usp=sharing", "/preview", $condiciones_participancion->valor);                
                
                $i++;
            }
            
            //Registro la accion en el log de convocatorias
            $logger->info('"token":"{token}","user":"{user}","message":"Retorna tipos de participantes"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
            $logger->close();
            
            //retorno el array en json
            echo json_encode($array_tipos_participantes);
                      
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco"', ['user' => "", 'token' => $request->get('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo consultar_tipos_participantes ' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->get('token')]);
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
