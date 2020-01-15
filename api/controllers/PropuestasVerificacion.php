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


//Valida el acceso a la convocatoria
//Que este antes de la fecha de cierre
//Confirmar el total de posibles numero de propuesta inscritas por la convocatoria
//Verificar que no tenga mas de 2 estimulos ganados
$app->post('/validar_acceso/{id:[0-9]+}', function ($id) use ($app, $config, $logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa a validar acceso a la convocatoria"', ['user' => '', 'token' => $request->get('token')]);

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPost('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual > 0) {

            //Validar array del usuario
            $user_current = json_decode($token_actual->user_current, true);

            //Consulto la fecha de cierre del cronograma de la convocatoria
            $conditions = ['convocatoria' => $id, 'active' => true,'tipo_evento'=>12];
            $fecha_cierre_real = Convocatoriascronogramas::findFirst(([
                        'conditions' => 'convocatoria=:convocatoria: AND active=:active: AND tipo_evento=:tipo_evento:',
                        'bind' => $conditions,
            ]));
            $fecha_actual = strtotime(date("Y-m-d H:i:s"), time());
            $fecha_cierre = strtotime($fecha_cierre_real->fecha_fin, time());
            if ($fecha_actual > $fecha_cierre) {
                echo "ingresar";
            }
            else
            {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"La convocatoria('.$id.') no ha cerrado, la fecha de cierre es ('.$fecha_cierre_real->fecha_fin.')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                $logger->close();
                echo "error_fecha_cierre";
            }
        } else {
            //Registro la accion en el log de convocatorias
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco"', ['user' => "", 'token' => $request->get('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo validar_acceso ' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->get('token')]);
        $logger->close();
        echo "error_metodo";
    }
}
);


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

$app->get('/buscar_propuestas', function () use ($app, $config, $logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();
    
    try {

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al metodo buscar_propuestas con los siguientes parametros de busqueda (' . $request->get('params') . ')"', ['user' => '', 'token' => $request->get('token')]);        

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
                
                $params= json_decode($request->get('params'),true);                
                $consultar=true;
                
                //Valido la fecha de cierre de la propuesta buscada por el codigo
                if($params["codigo"]!="")
                {
                    
                    //Consulto la propuesta por codigo
                    $conditions = ['codigo' => $params["codigo"], 'active' => true];
                    $propuesta = Propuestas::findFirst(([
                                'conditions' => 'codigo=:codigo: AND active=:active:',
                                'bind' => $conditions,
                    ]));

                    if($propuesta->id!=null)
                    {
                    
                        $convocatoria= Convocatorias::findFirst("id=".$propuesta->convocatoria." AND active=TRUE");

                        //Valido si la convocatoria tiene categorias y tiene diferentes requisitos con el fin de buscar la fecha de cierre
                        $id_convocatoria=$convocatoria->id;                
                        $seudonimo=$convocatoria->seudonimo;
                        $where .= " AND p.convocatoria=$id_convocatoria ";

                        //Consulto la fecha de cierre del cronograma de la convocatoria
                        $conditions = ['convocatoria' => $propuesta->convocatoria, 'active' => true,'tipo_evento'=>12];
                        $fecha_cierre_real = Convocatoriascronogramas::findFirst(([
                                    'conditions' => 'convocatoria=:convocatoria: AND active=:active: AND tipo_evento=:tipo_evento:',
                                    'bind' => $conditions,
                        ]));
                        $fecha_actual = strtotime(date("Y-m-d H:i:s"), time());
                        $fecha_cierre = strtotime($fecha_cierre_real->fecha_fin, time());
                        if ($fecha_actual > $fecha_cierre) {
                            $consultar=true;
                        }
                        else
                        {
                            $consultar=false;
                        }
                    }
                    else
                    {
                        $consultar=false;
                    }
                }
                
                if($consultar==true)
                {
                    
                    //Consulto todas las propuestas menos la del estado registrada
                    $where .= " WHERE p.active=true AND p.estado <> 7 ";
                    
                    
                    if($params["convocatoria"]!="")
                    {
                        $convocatoria= Convocatorias::findFirst("id=".$params["convocatoria"]." AND active=TRUE");
                        
                        //Valido si la convocatoria tiene categorias y tiene diferentes requisitos con el fin de buscar la fecha de cierre
                        $id_convocatoria=$convocatoria->id;                
                        $seudonimo=$convocatoria->seudonimo;
                        $where .= " AND p.convocatoria=$id_convocatoria ";
                    }
                    
                    
                    if($params["estado"]!="")
                    {
                        $where .= " AND p.estado=".$params["estado"];
                    }

                    if($params["codigo"]!="")
                    {
                        $where .= " AND p.codigo='".$params["codigo"]."'";
                    }

                    $participante="CONCAT(par.primer_nombre,' ',par.segundo_nombre,' ',par.primer_apellido,' ',par.segundo_apellido)";                    
                    if($seudonimo)
                    {
                        $participante="p.codigo";    
                    }

                    //Defino el sql del total y el array de datos
                    $sqlTot = "SELECT count(*) as total FROM Propuestas AS p "
                            . "INNER JOIN Estados AS est ON est.id=p.estado "
                            . "INNER JOIN Participantes AS par ON par.id=p.participante "
                            . "INNER JOIN Convocatorias AS c ON c.id=p.convocatoria "
                            . "INNER JOIN Entidades AS e ON e.id=c.entidad "
                            . "INNER JOIN Convocatorias AS cat ON cat.id=c.convocatoria_padre_categoria "
                            . "INNER JOIN Usuariosperfiles AS up ON up.id=par.usuario_perfil "
                            . "INNER JOIN Perfiles AS per ON per.id=up.perfil ";

                    $sqlRec = "SELECT "
                            . "est.nombre AS estado,"
                            . "c.anio ,"
                            . "e.nombre AS entidad ,"
                            . "c.nombre AS convocatoria,"
                            . "cat.nombre AS categoria,"
                            . "p.id AS id_propuesta,"
                            . "p.convocatoria AS id_convocatoria,"
                            . "p.nombre AS propuesta,"
                            . "p.codigo,"
                            . "p.verificacion_administrativos,"
                            . "p.verificacion_tecnicos,"
                            . "'a' AS btn_ver_documentacion,"
                            . "'a' AS btn_ver_subsanacion,"
                            . "'a' AS btn_verificacion_1,"
                            . "'a' AS btn_verificacion_2,"
                            . "per.id AS perfil ,"
                            . "per.nombre AS tipo_participante ,"
                            . "$participante AS participante,"                        
                            . "concat('<button type=\"button\" class=\"btn btn-warning cargar_propuesta\" data-toggle=\"modal\" data-target=\"#ver_propuesta\" title=\"',p.id,'\"><span class=\"glyphicon glyphicon-search\"></span></button>') as ver_propuesta,"
                            . "concat('<a href=\"" . $config->sistema->url_report . "reporte_propuesta_inscrita.php?id=',p.id,'&token=" . $request->get('token') . "\" target=\"_blank\"><button type=\"button\" class=\"btn btn-danger\"><span class=\"fa fa-bar-chart-o\"></span></button></a>') as ver_reporte  "
                            . "FROM Propuestas AS p "
                            . "INNER JOIN Estados AS est ON est.id=p.estado "
                            . "INNER JOIN Participantes AS par ON par.id=p.participante "
                            . "INNER JOIN Convocatorias AS c ON c.id=p.convocatoria "
                            . "INNER JOIN Entidades AS e ON e.id=c.entidad "
                            . "INNER JOIN Convocatorias AS cat ON cat.id=c.convocatoria_padre_categoria "
                            . "INNER JOIN Usuariosperfiles AS up ON up.id=par.usuario_perfil "
                            . "INNER JOIN Perfiles AS per ON per.id=up.perfil ";

                    //concatenate search sql if value exist
                    if (isset($where) && $where != '') {

                        $sqlTot .= $where;
                        $sqlRec .= $where;
                    }

                    //Concateno el orden y el limit para el paginador
                    $sqlRec .= " LIMIT " . $request->get('length') . " offset " . $request->get('start') . " ";
                    
                    //ejecuto el total de registros actual
                    $totalRecords = $app->modelsManager->executeQuery($sqlTot)->getFirst();

                    //creo el array
                    $json_data = array(
                        "draw" => intval($request->get("draw")),
                        "recordsTotal" => intval($totalRecords["total"]),
                        "recordsFiltered" => intval($totalRecords["total"]),
                        "data" => $app->modelsManager->executeQuery($sqlRec)   // total data array
                    );
                    //retorno el array en json
                    echo json_encode($json_data);
                }
                else
                {
                    //creo el array
                    $json_data = array(
                        "draw" => intval($request->get("draw")),
                        "recordsTotal" => 0,
                        "recordsFiltered" => 0,
                        "data" => array()   // total data array
                    );
                    //retorno el array en json
                    echo json_encode($json_data);
                }
                
                
                        
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"Acceso denegado en el metodo buscar_propuesta  con los siguientes parametros de busqueda (' . $request->get('params') . ')" ', ['user' => "", 'token' => $request->get('token')]);                
                $logger->close();
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco en el metodo buscar_propuesta con los siguientes parametros de busqueda (' . $request->get('params') . ')" ', ['user' => "", 'token' => $request->get('token')]);            
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo buscar_propuesta con los siguientes parametros de busqueda (' . $request->get('params') . ')" ' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->get('token')]);        
        $logger->close();
        echo "error_metodo ".$ex->getMessage();
    }
}
);

//Cargar cronograma de cada convocatoria
$app->post('/cargar_propuesta/{id:[0-9]+}', function ($id) use ($app, $config, $logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al metodo cargar_propuesta de la propuesta ('.$id.')"', ['user' => '', 'token' => $request->get('token')]);

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual > 0) {
            
            //Validar array del usuario
            $user_current = json_decode($token_actual->user_current, true);
            
            //Consulto el cronograma de la convocatoria
            $propuesta= Propuestas::findFirst("id=".$id." AND active=TRUE");
            
            if (isset($propuesta->id)) {
                
                //Si la convocatoria seleccionada es categoria, debo invertir los nombres la convocatoria con la categoria
                $nombre_convocatoria = $propuesta->getConvocatorias()->nombre;
                $nombre_categoria = "";
                if ($propuesta->getConvocatorias()->convocatoria_padre_categoria > 0) {
                    $nombre_convocatoria = $propuesta->getConvocatorias()->getConvocatorias()->nombre;
                    $nombre_categoria = $propuesta->getConvocatorias()->nombre;
                }

                //Cambio del nombre del participante
                $participante = $propuesta->getParticipantes()->primer_nombre . " " . $propuesta->getParticipantes()->segundo_nombre . " " . $propuesta->getParticipantes()->primer_apellido . " " . $propuesta->getParticipantes()->segundo_apellido;
                if($propuesta->getConvocatorias()->seudonimo)
                {
                    $participante=$propuesta->codigo;    
                }
                    
                //Creo el array que se va a retornar
                $array=array();
                $array["propuesta"]["nombre_estado"]=$propuesta->getEstados()->nombre;
                $array["propuesta"]["codigo_propuesta"]=$propuesta->codigo;
                $array["propuesta"]["tipo_participante"]=$propuesta->getParticipantes()->getUsuariosperfiles()->getPerfiles()->nombre;
                $array["propuesta"]["nombre_participante"]=$participante;                                
                $array["propuesta"]["nombre_propuesta"]=$propuesta->nombre;
                                
                //Se crea todo el array de documentos administrativos y tecnicos
                $conditions = ['convocatoria' => $propuesta->convocatoria, 'active' => true];
                $consulta_documentos_administrativos = Convocatoriasdocumentos::find(([
                            'conditions' => 'convocatoria=:convocatoria: AND active=:active:',
                            'bind' => $conditions,
                            'order' => 'orden ASC',
                ]));
                
                foreach ($consulta_documentos_administrativos as $documento) {
                    if ($documento->getRequisitos()->tipo_requisito == "Administrativos") {
                        if ($documento->etapa == "Registro") {
                            $documentos_administrativos[$documento->id]["id"] = $documento->id;
                            $documentos_administrativos[$documento->id]["requisito"] = $documento->getRequisitos()->nombre;                            
                            $documentos_administrativos[$documento->id]["orden"] = $documento->orden;
                            
                            $conditions = ['propuesta' => $propuesta->id, 'active' => true];
                            $consulta_archivos_propuesta = Propuestasdocumentos::find(([
                                        'conditions' => 'propuesta=:propuesta: AND active=:active:',
                                        'bind' => $conditions,
                                        'order' => 'fecha_creacion ASC',
                            ]));
                            
                            foreach ($consulta_archivos_propuesta as $archivo) {
                                $documentos_administrativos[$documento->id]["archivos"][$archivo->id]["id"] = $archivo->id;                                
                                $documentos_administrativos[$documento->id]["archivos"][$archivo->id]["nombre"] = $archivo->nombre;                                
                                $documentos_administrativos[$documento->id]["archivos"][$archivo->id]["id_alfresco"] = $archivo->id_alfresco;                                
                            }
                            
                            $conditions = ['propuesta' => $propuesta->id, 'active' => true];
                            $consulta_links_propuesta = Propuestaslinks::find(([
                                        'conditions' => 'propuesta=:propuesta: AND active=:active:',
                                        'bind' => $conditions,
                                        'order' => 'fecha_creacion ASC',
                            ]));
                            
                            foreach ($consulta_links_propuesta as $link) {
                                $documentos_administrativos[$documento->id]["links"][$link->id]["id"] = $link->id;                                
                                $documentos_administrativos[$documento->id]["links"][$link->id]["link"] = $link->link;                                                                
                            }
                        }
                    }

                    if ($documento->getRequisitos()->tipo_requisito == "Tecnicos") {
                        $documentos_tecnicos[$documento->id]["id"] = $documento->id;
                        $documentos_tecnicos[$documento->id]["requisito"] = $documento->getRequisitos()->nombre;
                        $documentos_tecnicos[$documento->id]["orden"] = $documento->orden;
                        
                        $conditions = ['propuesta' => $propuesta->id, 'active' => true];
                        $consulta_archivos_propuesta = Propuestasdocumentos::find(([
                                    'conditions' => 'propuesta=:propuesta: AND active=:active:',
                                    'bind' => $conditions,
                                    'order' => 'fecha_creacion ASC',
                        ]));

                        foreach ($consulta_archivos_propuesta as $archivo) {
                            $documentos_tecnicos[$documento->id]["archivos"][$archivo->id]["id"] = $archivo->id;                                
                            $documentos_tecnicos[$documento->id]["archivos"][$archivo->id]["nombre"] = $archivo->nombre;                                
                            $documentos_tecnicos[$documento->id]["archivos"][$archivo->id]["id_alfresco"] = $archivo->id_alfresco;                                
                        }

                        $conditions = ['propuesta' => $propuesta->id, 'active' => true];
                        $consulta_links_propuesta = Propuestaslinks::find(([
                                    'conditions' => 'propuesta=:propuesta: AND active=:active:',
                                    'bind' => $conditions,
                                    'order' => 'fecha_creacion ASC',
                        ]));

                        foreach ($consulta_links_propuesta as $link) {
                            $documentos_tecnicos[$documento->id]["links"][$link->id]["id"] = $link->id;                                
                            $documentos_tecnicos[$documento->id]["links"][$link->id]["link"] = $link->link;                                                                
                        }
                        
                    }
                }

                $array["administrativos"] = $documentos_administrativos;                
                $array["tecnicos"] = $documentos_tecnicos;                
                
                //Registro la accion en el log de convocatorias
                $logger->info('"token":"{token}","user":"{user}","message":"Retorna la propuesta ('.$id.') en el metodo cargar_propuesta"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                $logger->close();
                echo json_encode($array);
            }
            else
            {   
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"La propuesta (' . $id . ') no existe en el metodo cargar_propuesta', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
                $logger->close();
                echo "error_propuesta";
            }
                        
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco"', ['user' => "", 'token' => $request->get('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo cargar_cronograma ' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->get('token')]);
        $logger->close();
        echo "error_metodo";
    }
}
);

//Metodo el cual carga el formulario del integrante
//Verifica que que tenga creada la propuestas
$app->get('/buscar_archivos', function () use ($app, $config, $logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al metodo buscar_archivos de la propuesta (' . $request->get('propuesta') . ')"', ['user' => '', 'token' => $request->get('token')]);

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

                $propuesta = Propuestas::findFirst("id=" . $request->get('propuesta') . "");

                if (isset($propuesta->id)) {

                    $conditions = ['propuesta' => $propuesta->id, 'convocatoriadocumento' => $request->get('documento'), 'active' => true];
                    //Se crea todo el array de archivos de la propuesta
                    $consulta_documentos_administrativos = Propuestasdocumentos::find(([
                                'conditions' => 'propuesta=:propuesta: AND active=:active: AND convocatoriadocumento=:convocatoriadocumento:',
                                'bind' => $conditions,
                                'order' => 'id ASC',
                    ]));

                    //Registro la accion en el log de convocatorias
                    $logger->info('"token":"{token}","user":"{user}","message":"Retorna la información documentacion de la propuesta (' . $request->get('propuesta') . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                    $logger->close();

                    //Retorno el array
                    echo json_encode($consulta_documentos_administrativos);
                } else {
                    //Registro la accion en el log de convocatorias           
                    $logger->error('"token":"{token}","user":"{user}","message":"La propuesta (' . $request->get('propuesta') . ') no existe"', ['user' => $user_current["username"], 'token' => $request->get('token')]);                    
                    $logger->close();
                    echo "crear_propuesta";
                    exit;
                }
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"Acceso denegado en el metodo buscar_archivos de la propuesta (' . $request->get('propuesta') . ')"', ['user' => "", 'token' => $request->get('token')]);                
                $logger->close();
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco en el metodo buscar_archivos de la propuesta (' . $request->get('propuesta') . ')"', ['user' => "", 'token' => $request->get('token')]);            
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo buscar_archivos de la propuesta (' . $request->get('propuesta') . ') ' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->get('token')]);        
        $logger->close();
        echo "error_metodo";
    }
}
);

$app->get('/buscar_link', function () use ($app, $config, $logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al metodo buscar_link como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => '', 'token' => $request->get('token')]);

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

                $propuesta = Propuestas::findFirst("id=" . $request->get('propuesta') . "");

                if (isset($propuesta->id)) {

                    $conditions = ['propuesta' => $propuesta->id, 'convocatoriadocumento' => $request->get('documento'), 'active' => true];
                    //Se crea todo el array de archivos de la propuesta
                    $consulta_documentos_link = Propuestaslinks::find(([
                                'conditions' => 'propuesta=:propuesta: AND active=:active: AND convocatoriadocumento=:convocatoriadocumento:',
                                'bind' => $conditions,
                                'order' => 'id ASC',
                    ]));

                    //Registro la accion en el log de convocatorias
                    $logger->info('"token":"{token}","user":"{user}","message":"Retorna la información documento convocatoriadocumento para el perfil como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . '), en el metodo buscar_link"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                    $logger->close();

                    //Retorno el array
                    echo json_encode($consulta_documentos_link);
                } else {
                    //Registro la accion en el log de convocatorias           
                    $logger->error('"token":"{token}","user":"{user}","message":"Debe crear la propuesta para el perfil como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . '), en el metodo buscar_link"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                    $logger->close();
                    echo "crear_propuesta";
                    exit;
                }
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"Acceso denegado en el metodo buscar_link como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => "", 'token' => $request->get('token')]);
                $logger->close();
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco en el metodo buscar_link como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => "", 'token' => $request->get('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo buscar_link como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ') ' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->get('token')]);
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