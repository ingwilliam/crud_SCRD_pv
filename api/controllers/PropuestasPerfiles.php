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

//Consulto los participante de una convocatoria
$app->post('/consultar_tipos_participantes/{id:[0-9]+}', function ($id) use ($app, $config, $logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa a consultar los tipos participantes de la convocatoria"', ['user' => '', 'token' => $request->get('token')]);

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPost('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {
            
            //Validar array del usuario
            $user_current = json_decode($token_actual->user_current, true);
            
            //Consulto la convocatoria
            $convocatoria = Convocatorias::findFirst($id);

            //Si la convocatoria seleccionada es categoria y no es especial invierto los id
            if ($convocatoria->convocatoria_padre_categoria > 0 && $convocatoria->getConvocatorias()->tiene_categorias == true && $convocatoria->getConvocatorias()->diferentes_categorias == false) {
                $id = $convocatoria->getConvocatorias()->id;                    
            }
                        
            //generar las siglas del programa
            if($convocatoria->programa==1)
            {
                $siglas_programa="pde";
            }
            if($convocatoria->programa==2)
            {
                $siglas_programa="pdac";
            }
            if($convocatoria->programa==3)
            {
                $siglas_programa="pdsc";
            }
            
            //Consulto los tipos de partticipantes permitidos de la convocatoria
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
                $array_tipos_participantes[$i]["acepto_terminos_condiciones"] = false;                
                
                //consulto tabla maestra para los terminos y condiciones pn
                if($participante->tipo_participante==1)
                {
                    $terminos_condiciones= Tablasmaestras::findFirst("active=true AND nombre='tc_td_au_pn_".date("Y")."'");   
                    $array_tipos_participantes[$i]["terminos_condiciones"] = str_replace("/view?usp=sharing", "/preview", $terminos_condiciones->valor);                
                                                            
                    //Busco si tiene el perfil de persona natural
                    $usuario_perfil_pn = Usuariosperfiles::findFirst("usuario=" . $user_current["id"] . " AND perfil = 6");

                    //Si existe el usuario perfil como pn
                    $participante = new Participantes();
                    if (isset($usuario_perfil_pn->id)) {
                        $participante = Participantes::findFirst("usuario_perfil=" . $usuario_perfil_pn->id . " AND tipo='Inicial' AND active=TRUE");

                        //Si existe el participante inicial con el perfil de pn 
                        if (isset($participante->id)) {
                            //Consulto participante pn hijo este relacionado con una propuesta
                            $sql_participante_hijo_propuesta = "SELECT 
                                                            pn.* 
                                                    FROM Propuestas AS p
                                                        INNER JOIN Participantes AS pn ON pn.id=p.participante
                                                    WHERE
                                                    p.id = ".$request->getPost('p')." AND p.convocatoria=" . $id . " AND pn.usuario_perfil=" . $usuario_perfil_pn->id . " AND pn.tipo='Participante' AND pn.participante_padre=" . $participante->id . "";

                            $participante_hijo_propuesta = $app->modelsManager->executeQuery($sql_participante_hijo_propuesta)->getFirst();                                                
                            //Valido si existe el participante hijo relacionado con una propuesta de la convocatoria actual
                            if (isset($participante_hijo_propuesta->id)) {
                                //Retorno el array hijo que tiene relacionado la propuesta
                                $array_tipos_participantes[$i]["acepto_terminos_condiciones"] = $participante_hijo_propuesta->terminos_condiciones;                
                            }                            
                        }
                    }
                    
                }
                //consulto tabla maestra para los terminos y condiciones pj
                if($participante->tipo_participante==2)
                {
                    $terminos_condiciones= Tablasmaestras::findFirst("active=true AND nombre='tc_td_au_pj_".date("Y")."'");   
                    $array_tipos_participantes[$i]["terminos_condiciones"] = str_replace("/view?usp=sharing", "/preview", $terminos_condiciones->valor);                
                    
                    //Busco si tiene el perfil de persona juridica
                    $usuario_perfil_pj = Usuariosperfiles::findFirst("usuario=" . $user_current["id"] . " AND perfil = 7");

                    //Si existe el usuario perfil como pn
                    $participante = new Participantes();
                    if (isset($usuario_perfil_pj->id)) {
                        $participante = Participantes::findFirst("usuario_perfil=" . $usuario_perfil_pj->id . " AND tipo='Inicial' AND active=TRUE");

                        //Si existe el participante inicial con el perfil de pn 
                        if (isset($participante->id)) {
                            //Consulto participante pn hijo este relacionado con una propuesta
                            $sql_participante_hijo_propuesta = "SELECT 
                                                            pn.* 
                                                    FROM Propuestas AS p
                                                        INNER JOIN Participantes AS pn ON pn.id=p.participante
                                                    WHERE
                                                    p.id = ".$request->getPost('p')." AND p.convocatoria=" . $id . " AND pn.usuario_perfil=" . $usuario_perfil_pj->id . " AND pn.tipo='Participante' AND pn.participante_padre=" . $participante->id . "";

                            $participante_hijo_propuesta = $app->modelsManager->executeQuery($sql_participante_hijo_propuesta)->getFirst();                                                
                            //Valido si existe el participante hijo relacionado con una propuesta de la convocatoria actual
                            if (isset($participante_hijo_propuesta->id)) {
                                //Retorno el array hijo que tiene relacionado la propuesta
                                $array_tipos_participantes[$i]["acepto_terminos_condiciones"] = $participante_hijo_propuesta->terminos_condiciones;                
                            }                            
                        }
                    }
                    
                }
                //consulto tabla maestra para los terminos y condiciones agr
                if($participante->tipo_participante==3)
                {
                    $terminos_condiciones= Tablasmaestras::findFirst("active=true AND nombre='tc_td_au_agr_".date("Y")."'");   
                    $array_tipos_participantes[$i]["terminos_condiciones"] = str_replace("/view?usp=sharing", "/preview", $terminos_condiciones->valor);                
                    
                    //Busco si tiene el perfil de agrupacion
                    $usuario_perfil_agr = Usuariosperfiles::findFirst("usuario=" . $user_current["id"] . " AND perfil = 8");

                    //Si existe el usuario perfil como pn
                    $participante = new Participantes();
                    if (isset($usuario_perfil_agr->id)) {
                        $participante = Participantes::findFirst("usuario_perfil=" . $usuario_perfil_agr->id . " AND tipo='Inicial' AND active=TRUE");

                        //Si existe el participante inicial con el perfil de pn 
                        if (isset($participante->id)) {
                            //Consulto participante pn hijo este relacionado con una propuesta
                            $sql_participante_hijo_propuesta = "SELECT 
                                                            pn.* 
                                                    FROM Propuestas AS p
                                                        INNER JOIN Participantes AS pn ON pn.id=p.participante
                                                    WHERE
                                                    p.id = ".$request->getPost('p')." AND p.convocatoria=" . $id . " AND pn.usuario_perfil=" . $usuario_perfil_agr->id . " AND pn.tipo='Participante' AND pn.participante_padre=" . $participante->id . "";

                            $participante_hijo_propuesta = $app->modelsManager->executeQuery($sql_participante_hijo_propuesta)->getFirst();                                                
                            //Valido si existe el participante hijo relacionado con una propuesta de la convocatoria actual
                            if (isset($participante_hijo_propuesta->id)) {
                                //Retorno el array hijo que tiene relacionado la propuesta
                                $array_tipos_participantes[$i]["acepto_terminos_condiciones"] = $participante_hijo_propuesta->terminos_condiciones;                
                            }                            
                        }
                    }
                    
                    
                }                
                
                $condiciones_participancion= Tablasmaestras::findFirst("active=true AND nombre='condiciones_participacion_".$siglas_programa."_".date("Y")."'");   
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
