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
        if (isset($token_actual->id)) {

            //Validar array del usuario
            $user_current = json_decode($token_actual->user_current, true);

            //Consulto la convocatoria
            $convocatoria= Convocatorias::findFirst("id=".$id." AND active=TRUE");

            //Valido si la convocatoria tiene categorias y tiene diferentes requisitos con el fin de buscar la fecha de cierre
            $id_convocatoria=$convocatoria->id;                

            //Si la convocatoria seleccionada es categoria y no es especial invierto los id
            if ($convocatoria->convocatoria_padre_categoria > 0 && $convocatoria->getConvocatorias()->tiene_categorias == true && $convocatoria->getConvocatorias()->diferentes_categorias == false) {
                $id_convocatoria = $convocatoria->getConvocatorias()->id;                                    
            }
                                    
            //Consulto la fecha de cierre del cronograma de la convocatoria
            $conditions = ['convocatoria' => $id_convocatoria, 'active' => true,'tipo_evento'=>12];
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
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->get('modulo'));

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
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->get('modulo'));

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
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->get('modulo'));

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
                        
                        //Si la convocatoria seleccionada es categoria y no es especial invierto los id
                        if ($convocatoria->convocatoria_padre_categoria > 0 && $convocatoria->getConvocatorias()->tiene_categorias == true && $convocatoria->getConvocatorias()->diferentes_categorias == false) {
                            $id_convocatoria = $convocatoria->getConvocatorias()->id;                    
                            $seudonimo=$convocatoria->getConvocatorias()->seudonimo;
                        }
                        

                        //Consulto la fecha de cierre del cronograma de la convocatoria
                        $conditions = ['convocatoria' => $id_convocatoria, 'active' => true,'tipo_evento'=>12];
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
                else 
                {
                    //Consulto la convocatoria
                    $convocatoria= Convocatorias::findFirst("id=".$params["convocatoria"]." AND active=TRUE");

                    //Valido si la convocatoria tiene categorias y tiene diferentes requisitos con el fin de buscar la fecha de cierre
                    $id_convocatoria=$convocatoria->id;                
                    $seudonimo=$convocatoria->seudonimo;                        

                    //Si la convocatoria seleccionada es categoria y no es especial invierto los id
                    if ($convocatoria->convocatoria_padre_categoria > 0 && $convocatoria->getConvocatorias()->tiene_categorias == true && $convocatoria->getConvocatorias()->diferentes_categorias == false) {
                        $id_convocatoria = $convocatoria->getConvocatorias()->id;                    
                        $seudonimo=$convocatoria->getConvocatorias()->seudonimo;
                    }


                    //Consulto la fecha de cierre del cronograma de la convocatoria
                    $conditions = ['convocatoria' => $id_convocatoria, 'active' => true,'tipo_evento'=>12];
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
                
                if($consultar==true)
                {
                    //Consulto el usuario actual
                    $user_current = json_decode($token_actual->user_current, true);
                    $user_current = Usuarios::findFirst($user_current["id"]);            
                    //Creo array de entidades que puede acceder el usuario
                    $array_usuarios_entidades="";
                    foreach ($user_current->getUsuariosentidades() as $usuario_entidad) {
                        $array_usuarios_entidades = $array_usuarios_entidades . $usuario_entidad->entidad . ",";
                    }
                    $array_usuarios_entidades = substr($array_usuarios_entidades, 0, -1);
                    
                    //Creo array de areas que puede acceder el usuario
                    $array_usuarios_areas="";
                    foreach ($user_current->getUsuariosareas() as $usuario_area) {
                        $array_usuarios_areas = $array_usuarios_areas . $usuario_area->area . ",";
                    }
                    $array_usuarios_areas = substr($array_usuarios_areas, 0, -1);
                                        
                    //Consulto todas las propuestas menos la del estado registrada
                    $where .= " WHERE p.active=true AND p.estado NOT IN (7,20)  AND ( c.area IN ($array_usuarios_areas) OR c.area IS NULL) ";
                    
                    
                    if($params["convocatoria"]!="")
                    {
                        //Elimino las 2 lineas debido a que en el if de las lineas 312
                        //350 ya se valida, este if es solo para colocar en el where
                        //$convocatoria= Convocatorias::findFirst("id=".$params["convocatoria"]." AND active=TRUE");
                        //$id_convocatoria=$convocatoria->id;                
                        //$seudonimo=$convocatoria->seudonimo;
                        
                        $where .= " AND p.convocatoria=".$params["convocatoria"]." ";
                        
                        //Consulto las propuestas en estado 22 Subsanación Recibida
                        //y tambien que se pasaron de la fecha_fin_subsanacion
                        //con el fin de rechazarla
                        
                        $where_subsanacion = ['convocatoria' => $params["convocatoria"], 'estado' => 22];                        
                        $propuestas_no_subsanaron = Propuestas::find(([
                                    'conditions' => 'convocatoria=:convocatoria: AND estado=:estado: AND NOW()>fecha_fin_subsanacion',
                                    'bind' => $where_subsanacion
                        ]));
                                                                        
                        foreach ($propuestas_no_subsanaron as $propuesta_rechazar) { 
                            
                            //Consulto los documentos que enviaron a subsanar
                            $where_subsanacion = ['propuesta' => $propuesta_rechazar->id, 'active' => true, 'estado' => 27];
                            $documentos_subsanar = Propuestasverificaciones::find(([
                                        'conditions' => 'propuesta=:propuesta: AND active=:active: AND estado=:estado:',
                                        'bind' => $where_subsanacion
                            ]));
                            
                            foreach ($documentos_subsanar as $propuesta_verificar_subsanar) {
                                
                                //Consulto si existe el registro
                                $consulto_propuesta_verificacion= Propuestasverificaciones::findFirst("propuesta=".$propuesta_verificar_subsanar->propuesta." AND convocatoriadocumento=".$propuesta_verificar_subsanar->convocatoriadocumento." AND verificacion=2");
            
                                //si no existe lo creo
                                if (!isset($consulto_propuesta_verificacion->id)) {
                                    $propuesta_verificar_rechazar = new Propuestasverificaciones();
                                    $propuesta_verificar_rechazar->propuesta = $propuesta_verificar_subsanar->propuesta;
                                    $propuesta_verificar_rechazar->convocatoriadocumento = $propuesta_verificar_subsanar->convocatoriadocumento;
                                    $propuesta_verificar_rechazar->verificacion = 2;
                                    $propuesta_verificar_rechazar->estado = 30;
                                    $propuesta_verificar_rechazar->observacion = "El participante no subsanó la documentación solicitada.";
                                    $propuesta_verificar_rechazar->active = true;                                
                                    $propuesta_verificar_rechazar->creado_por = $user_current->id;
                                    $propuesta_verificar_rechazar->fecha_creacion = date("Y-m-d H:i:s");
                                    if ($propuesta_verificar_rechazar->save() === false) {
                                        $logger->error('"token":"{token}","user":"{user}","message":"Se presento error en el metodo buscar_propuestas al crear la propuesta verificacion 2 en la propuesta (' . $propuesta_verificar_subsanar->id . ')"', ['user' => $user_current->username, 'token' => $request->get('token')]);
                                        $logger->close();                                    
                                    } else {
                                        //Registro la accion en el log de convocatorias
                                        $logger->info('"token":"{token}","user":"{user}","message":"El metodo buscar_propuestas guardo con exito la verificacion 2 de la propuesta (' . $propuesta_verificar_subsanar->id . ')"', ['user' => $user_current->username, 'token' => $request->get('token')]);
                                        $logger->close();                                   
                                    } 
                                }                                                               
                            }
                            
                            //Agrego el estado de rechazado a la propuesto
                            $propuesta_rechazar->estado=23;
                            if ($propuesta_rechazar->save() === false) {
                                $logger->error('"token":"{token}","user":"{user}","message":"Se presento error en el metodo buscar_propuestas al rechazar la propuesta verificacion 2 en la propuesta (' . $propuesta_verificar_subsanar->id . ')"', ['user' => $user_current->username, 'token' => $request->get('token')]);
                                $logger->close();                                    
                            } else {
                                //Registro la accion en el log de convocatorias
                                $logger->info('"token":"{token}","user":"{user}","message":"El metodo buscar_propuestas guardo con exito la propuesta a rechzar en la verificacion 2 de la propuesta (' . $propuesta_verificar_subsanar->id . ')"', ['user' => $user_current->username, 'token' => $request->get('token')]);
                                $logger->close();                                   
                            }                             
                            
                        }                                                
                        
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
                    $sqlTot = "SELECT count(*) as total "
                            . "FROM Propuestas AS p "
                            . "INNER JOIN Estados AS est ON est.id=p.estado "
                            . "INNER JOIN Participantes AS par ON par.id=p.participante "
                            . "INNER JOIN Convocatorias AS c ON c.id=p.convocatoria "
                            . "INNER JOIN Entidades AS e ON e.id=c.entidad  AND e.id IN ($array_usuarios_entidades)"
                            . "LEFT JOIN Convocatorias AS cat ON cat.id=c.convocatoria_padre_categoria "
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
                            . "CASE c.programa"
                            . "    WHEN 2 THEN concat('<a href=\"" . $config->sistema->url_report . "reporte_propuesta_inscrita_pdac.php?id=',p.id,'&token=" . $request->get('token') . "\" target=\"_blank\"><button type=\"button\" class=\"btn btn-danger\"><span class=\"fa fa-bar-chart-o\"></span></button></a>')"
                            . "    ELSE concat('<a href=\"" . $config->sistema->url_report . "reporte_propuesta_inscrita.php?id=',p.id,'&token=" . $request->get('token') . "\" target=\"_blank\"><button type=\"button\" class=\"btn btn-danger\"><span class=\"fa fa-bar-chart-o\"></span></button></a>')"
                            . "END AS ver_reporte "
                            . "FROM Propuestas AS p "
                            . "INNER JOIN Estados AS est ON est.id=p.estado "
                            . "INNER JOIN Participantes AS par ON par.id=p.participante "
                            . "INNER JOIN Convocatorias AS c ON c.id=p.convocatoria "
                            . "INNER JOIN Entidades AS e ON e.id=c.entidad  AND e.id IN ($array_usuarios_entidades)"
                            . "LEFT JOIN Convocatorias AS cat ON cat.id=c.convocatoria_padre_categoria "
                            . "INNER JOIN Usuariosperfiles AS up ON up.id=par.usuario_perfil "
                            . "INNER JOIN Perfiles AS per ON per.id=up.perfil ";

                    //concatenate search sql if value exist
                    if (isset($where) && $where != '') {

                        $sqlTot .= $where;
                        $sqlRec .= $where;
                    }

                    //Concateno el orden y el limit para el paginador
                    $sqlRec .= " ORDER BY p.codigo LIMIT " . $request->get('length') . " offset " . $request->get('start') . " ";
                    
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
                        "recordsTotal" => null,
                        "recordsFiltered" => null,
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
        if (isset($token_actual->id)) {
            
            //Validar array del usuario
            $user_current = json_decode($token_actual->user_current, true);
            
            //Consulto el cronograma de la convocatoria
            $propuesta= Propuestas::findFirst("id=".$id." AND active=TRUE");
            
            if (isset($propuesta->id)) {
                
                //Consulto la convocatoria de la propuesta
                $convocatoria= Convocatorias::findFirst("id=".$propuesta->convocatoria." AND active=TRUE");                
                //Si la convocatoria seleccionada es categoria, debo invertir los nombres la convocatoria con la categoria
                $nombre_convocatoria = $propuesta->getConvocatorias()->nombre;
                $anio_convocatoria = $propuesta->getConvocatorias()->anio;
                $nombre_categoria = "";
                $seudonimo=$convocatoria->seudonimo;
                $id_convocatoria = $convocatoria->id; 
                
                //Si la convocatoria seleccionada es categoria y no es especial invierto los id
                if ($convocatoria->convocatoria_padre_categoria > 0 && $convocatoria->getConvocatorias()->tiene_categorias == true && $convocatoria->getConvocatorias()->diferentes_categorias == false) {
                    $id_convocatoria = $convocatoria->getConvocatorias()->id;                    
                    $seudonimo=$convocatoria->getConvocatorias()->seudonimo;
                }
                
                //Si la convocatoria tiene categorias invierto los nombres
                if ($propuesta->getConvocatorias()->convocatoria_padre_categoria > 0) {
                    $nombre_convocatoria = $propuesta->getConvocatorias()->getConvocatorias()->nombre;
                    $nombre_categoria = $propuesta->getConvocatorias()->nombre;
                    $anio_convocatoria = $propuesta->getConvocatorias()->getConvocatorias()->anio;
                }

                //Creo el nombre del participante y valido si tiene seudonimos
                $participante = $propuesta->getParticipantes()->primer_nombre . " " . $propuesta->getParticipantes()->segundo_nombre . " " . $propuesta->getParticipantes()->primer_apellido . " " . $propuesta->getParticipantes()->segundo_apellido;                
                if($seudonimo)
                {
                    $participante=$propuesta->codigo;    
                }
                    
                //Creo el array que se va a retornar
                $array=array();
                $array["propuesta"]["nombre_estado"]=$propuesta->getEstados()->nombre;
                $array["propuesta"]["estado"]=$propuesta->estado;
                $array["propuesta"]["codigo_propuesta"]=$propuesta->codigo;
                $array["propuesta"]["tipo_participante"]=$propuesta->getParticipantes()->getUsuariosperfiles()->getPerfiles()->nombre;
                $array["propuesta"]["nombre_participante"]=$participante;                                
                $array["propuesta"]["nombre_propuesta"]=$propuesta->nombre;
                $array["propuesta"]["verificacion_administrativos"]=$propuesta->verificacion_administrativos;
                $array["propuesta"]["verificacion_tecnicos"]=$propuesta->verificacion_tecnicos;
                                
                //Se crea todo el array de documentos administrativos y tecnicos
                //Si es la verificacion 1 traigo todos los documentos administrativos de la convocatoria
                if($request->get('verificacion')==1)
                {
                $conditions = ['convocatoria' => $id_convocatoria, 'active' => true];
                $consulta_documentos_administrativos = Convocatoriasdocumentos::find(([
                            'conditions' => 'convocatoria=:convocatoria: AND active=:active:',
                            'bind' => $conditions,
                            'order' => 'orden ASC',
                ]));
                }
                
                //Si es la verificacion 2 traigo todos los documentos administrativos que fueron marcados como subsanar
                if($request->get('verificacion')==2)
                {
                    $conditions = ['propuesta' => $propuesta->id, 'active' => true, 'estado' => 27];
                    $consulta_propuestas_verificaciones = Propuestasverificaciones::find(([
                                'conditions' => 'propuesta=:propuesta: AND active=:active: AND estado=:estado:',
                                'bind' => $conditions
                    ]));                    
                    $id_convocatorias_documentos = "";
                    foreach ($consulta_propuestas_verificaciones as $cpv) {
                        $id_convocatorias_documentos = $id_convocatorias_documentos . $cpv->convocatoriadocumento . ",";
                    }
                    $id_convocatorias_documentos = substr($id_convocatorias_documentos, 0, -1);
                    
                    
                    $conditions = ['convocatoria' => $id_convocatoria, 'active' => true];
                    if($id_convocatorias_documentos=="")
                    {
                        $consulta_documentos_administrativos=array();
                    }
                    else
                    {
                        $consulta_documentos_administrativos = Convocatoriasdocumentos::find(([
                                'conditions' => 'id IN ('.$id_convocatorias_documentos.') AND convocatoria=:convocatoria: AND active=:active:',
                                'bind' => $conditions,
                                'order' => 'orden ASC',
                    ]));
                    }                                        
                }
                
                
                foreach ($consulta_documentos_administrativos as $documento) {                                                           
                    if ($documento->getRequisitos()->tipo_requisito == "Administrativos") {
                        if ($documento->etapa == "Registro") {
                            $documentos_administrativos[$documento->orden]["id"] = $documento->id;
                            $documentos_administrativos[$documento->orden]["requisito"] = $documento->getRequisitos()->nombre;                            
                            $documentos_administrativos[$documento->orden]["orden"] = $documento->orden;
                            
                            //Consulto las posible verificaciones
                            $verificacion_1= Propuestasverificaciones::findFirst("propuesta=".$propuesta->id." AND active=TRUE AND convocatoriadocumento=".$documento->id." AND verificacion=".$request->get('verificacion'));                                
                            $documentos_administrativos[$documento->orden]["verificacion_1_id"] = $verificacion_1->id;
                            $documentos_administrativos[$documento->orden]["verificacion_1_estado"] = $verificacion_1->estado;
                            $documentos_administrativos[$documento->orden]["verificacion_1_observacion"] = $verificacion_1->observacion;
                            
                            //Consulto todos los documentos cargados por el usuario
                            $conditions = ['propuesta' => $propuesta->id, 'active' => true , 'convocatoriadocumento' => $documento->id, 'cargue_subsanacion' => 'false'];                            
                            if($request->get('verificacion')==2)
                            {
                                $conditions = ['propuesta' => $propuesta->id, 'active' => true , 'convocatoriadocumento' => $documento->id, 'cargue_subsanacion' => 'true'];
                            }
                            
                            $consulta_archivos_propuesta = Propuestasdocumentos::find(([
                                        'conditions' => 'propuesta=:propuesta: AND active=:active: AND convocatoriadocumento=:convocatoriadocumento: AND cargue_subsanacion=:cargue_subsanacion:',
                                        'bind' => $conditions,
                                        'order' => 'fecha_creacion ASC',
                            ]));
                            
                            $url_archivo = explode("/alfresco/", $config->alfresco->api);
                            
                            foreach ($consulta_archivos_propuesta as $archivo) {
                                $documentos_administrativos[$documento->orden]["archivos"][$archivo->id]["id"] = $archivo->id;                                
                                $documentos_administrativos[$documento->orden]["archivos"][$archivo->id]["nombre"] = $archivo->nombre;                                
                                $documentos_administrativos[$documento->orden]["archivos"][$archivo->id]["id_alfresco"] = $archivo->id_alfresco;                                
                                $id_alfreco=explode(";1.0", $archivo->id_alfresco);
                                $documentos_administrativos[$documento->orden]["archivos"][$archivo->id]["url_alfresco"] = $url_archivo[0]."/share/proxy/alfresco/slingshot/node/content/workspace/SpacesStore/".$id_alfreco[0]."/".$archivo->nombre;                                                                
                            }
                            
                            //Consulto todos los link cargados por el usuario
                            $conditions = ['propuesta' => $propuesta->id, 'active' => true , 'convocatoriadocumento' => $documento->id, 'cargue_subsanacion' => 'false'];
                            if($request->get('verificacion')==2)
                            {
                                $conditions = ['propuesta' => $propuesta->id, 'active' => true , 'convocatoriadocumento' => $documento->id, 'cargue_subsanacion' => 'true'];
                            }                            
                            $consulta_links_propuesta = Propuestaslinks::find(([
                                        'conditions' => 'propuesta=:propuesta: AND active=:active: AND convocatoriadocumento=:convocatoriadocumento: AND cargue_subsanacion=:cargue_subsanacion:',
                                        'bind' => $conditions,
                                        'order' => 'fecha_creacion ASC',
                            ]));
                            
                            foreach ($consulta_links_propuesta as $link) {
                                $documentos_administrativos[$documento->orden]["links"][$link->id]["id"] = $link->id;                                
                                $documentos_administrativos[$documento->orden]["links"][$link->id]["link"] = $link->link;                                                                
                            }
                        }
                    }

                    if ($documento->getRequisitos()->tipo_requisito == "Tecnicos") {
                        $documentos_tecnicos[$documento->orden]["id"] = $documento->id;
                        $documentos_tecnicos[$documento->orden]["requisito"] = $documento->getRequisitos()->nombre;
                        $documentos_tecnicos[$documento->orden]["orden"] = $documento->orden;
                        
                        //Consulto las posible verificaciones
                        $verificacion_1= Propuestasverificaciones::findFirst("propuesta=".$propuesta->id." AND active=TRUE AND convocatoriadocumento=".$documento->id." AND verificacion=".$request->get('verificacion'));                                
                        $documentos_tecnicos[$documento->orden]["verificacion_1_id"] = $verificacion_1->id;
                        $documentos_tecnicos[$documento->orden]["verificacion_1_estado"] = $verificacion_1->estado;
                        $documentos_tecnicos[$documento->orden]["verificacion_1_observacion"] = $verificacion_1->observacion;
                            
                        
                        $conditions = ['propuesta' => $propuesta->id, 'active' => true, 'convocatoriadocumento' => $documento->id , 'cargue_subsanacion' => 'false'];
                        //Solo aplica para LEP
                        if($request->get('verificacion')==2)
                        {
                            $conditions = ['propuesta' => $propuesta->id, 'active' => true , 'convocatoriadocumento' => $documento->id, 'cargue_subsanacion' => 'true'];
                        }
                        $consulta_archivos_propuesta = Propuestasdocumentos::find(([
                                    'conditions' => 'propuesta=:propuesta: AND active=:active: AND convocatoriadocumento=:convocatoriadocumento: AND cargue_subsanacion=:cargue_subsanacion:',
                                    'bind' => $conditions,
                                    'order' => 'fecha_creacion ASC',
                        ]));

                        $url_archivo = explode("/alfresco/", $config->alfresco->api);
                        foreach ($consulta_archivos_propuesta as $archivo) {
                            $documentos_tecnicos[$documento->orden]["archivos"][$archivo->id]["id"] = $archivo->id;                                
                            $documentos_tecnicos[$documento->orden]["archivos"][$archivo->id]["nombre"] = $archivo->nombre;                                
                            $documentos_tecnicos[$documento->orden]["archivos"][$archivo->id]["id_alfresco"] = $archivo->id_alfresco;                                
                            $documentos_tecnicos[$documento->orden]["archivos"][$archivo->id]["barbosa"] = $archivo->id_alfresco;                                
                            $id_alfreco=explode(";1.0", $archivo->id_alfresco);
                            $documentos_tecnicos[$documento->orden]["archivos"][$archivo->id]["url_alfresco"] = $url_archivo[0]."/share/proxy/alfresco/slingshot/node/content/workspace/SpacesStore/".$id_alfreco[0]."/".$archivo->nombre;
                        }

                        $conditions = ['propuesta' => $propuesta->id, 'active' => true, 'convocatoriadocumento' => $documento->id, 'cargue_subsanacion' => 'false'];
                        //Solo aplica para LEP
                        if($request->get('verificacion')==2)
                        {
                            $conditions = ['propuesta' => $propuesta->id, 'active' => true , 'convocatoriadocumento' => $documento->id, 'cargue_subsanacion' => 'true'];
                        }
                        $consulta_links_propuesta = Propuestaslinks::find(([
                                    'conditions' => 'propuesta=:propuesta: AND active=:active: AND convocatoriadocumento=:convocatoriadocumento: AND cargue_subsanacion=:cargue_subsanacion:',
                                    'bind' => $conditions,
                                    'order' => 'fecha_creacion ASC',
                        ]));

                        foreach ($consulta_links_propuesta as $link) {
                            $documentos_tecnicos[$documento->orden]["links"][$link->id]["id"] = $link->id;                                
                            $documentos_tecnicos[$documento->orden]["links"][$link->id]["link"] = $link->link;                                                                
                        }
                        
                    }
                }

                $array["estados_verificacion_1"] = Estados::find(array(
                                                        "tipo_estado = 'verificacion_1' AND active = true",
                                                        "order" => "orden"
                                                    )
                                                );
                
                $array["estados_verificacion_2"] = Estados::find(array(
                                                        "tipo_estado = 'verificacion_2' AND active = true",
                                                        "order" => "orden"
                                                    )
                                                );
                $array["administrativos"] = $documentos_administrativos;                
                $array["tecnicos"] = $documentos_tecnicos;                
                $array["modalidad"] = $convocatoria->modalidad;

                //Consulto solo los integrantes de la propuesta
                $sql_integrantes = "
                    SELECT 
                            REPLACE(REPLACE(TRIM(p.numero_documento),'.',''),' ', '') AS numero_documento
                    FROM Participantes AS p                                        
                    WHERE (p.id=".$propuesta->participante." OR p.participante_padre=".$propuesta->participante.") AND p.active=TRUE";                
                $integrantes = $app->modelsManager->executeQuery($sql_integrantes);
                
                $cedulas_integrantes="";
                foreach ($integrantes as $integrante) {                    
                    $cedulas_integrantes=$cedulas_integrantes."'".$integrante->numero_documento."',";
                }
                $cedulas_integrantes = substr($cedulas_integrantes, 0, -1);
                
                //Consultamos las inhabilidades como contratistas                
                $sql_contratistas = "
                    SELECT 
                            concat(p.numero_documento,' ',p.primer_nombre,' ',p.segundo_nombre,' ',p.primer_apellido,' ',p.segundo_apellido) AS participante,
                            concat(e.nombre,' ',ec.numero_documento,' ',ec.primer_nombre,' ',ec.segundo_nombre,' ',ec.primer_apellido,' ',ec.segundo_apellido) AS contratista
                    FROM Participantes AS p
                    INNER JOIN Entidadescontratistas AS ec ON REPLACE(REPLACE(TRIM(ec.numero_documento),'.',''),' ', '')=REPLACE(REPLACE(TRIM(p.numero_documento),'.',''),' ', '')
                    INNER JOIN Entidades AS e ON e.id=ec.entidad
                    WHERE (p.id=".$propuesta->participante." OR p.participante_padre=".$propuesta->participante.") AND p.tipo_documento<>7 AND ec.active=TRUE AND p.active=TRUE";

                $contratistas = $app->modelsManager->executeQuery($sql_contratistas);
                
                $array_contratistas=array();
                foreach ($contratistas as $contratista) {                    
                    $array_contratistas[$contratista->participante][]=$contratista->contratista;
                }
                $array["contratistas"] = $array_contratistas;
                
                //Variables html
                $html_propuestas = "";
                $html_propuestas_ganadoras = "";                
                $html_propuestas_jurados_seleccionados = "";                        
            
                //Genero reporte de jurados seleccionados
                $sql_jurados_seleccionado = "
                            SELECT 
                                    cp.nombre AS convocatoria,
                                    c.nombre AS categoria,	
                                    par.tipo AS rol_participante,	
                                    jp.rol AS rol_jurado,
                                    par.numero_documento,
                                    concat(par.primer_nombre, ' ' ,par.segundo_nombre, ' ' ,par.primer_apellido, ' ' ,par.segundo_apellido ) AS participante,
                                    pro.codigo AS codigo_propuesta,
                                    e.nombre AS estado_de_la_postulacion
                            FROM Juradospostulados as jp
                                INNER JOIN Evaluadores ev ON jp.id=ev.juradopostulado 
                                    INNER JOIN Propuestas AS pro ON jp.propuesta = pro.id
                                    INNER join Participantes par on pro.participante = par.id
                                    INNER JOIN Convocatorias AS c ON jp.convocatoria = c.id
                                    LEFT JOIN Convocatorias as cp ON c.convocatoria_padre_categoria = cp.id
                                    LEFT JOIN Estados e ON jp.estado=e.id
                            WHERE 	
                                    jp.active=true AND
                                ev.active = true AND	                            
                                    REPLACE(REPLACE(TRIM(par.numero_documento),'.',''),' ', '') IN ($cedulas_integrantes)
                            ";

                $jurados_seleccionados = $app->modelsManager->executeQuery($sql_jurados_seleccionado);

                foreach ($jurados_seleccionados as $jurado) {                    
                    if($jurado->convocatoria=="")
                    {
                        $jurado->convocatoria=$jurado->categoria;
                        $jurado->categoria="";
                    }
                    $html_propuestas_jurados_seleccionados = $html_propuestas_jurados_seleccionados . "<tr class='tr_jurados_seleccionados'>";
                    $html_propuestas_jurados_seleccionados = $html_propuestas_jurados_seleccionados . "<td>" . $jurado->convocatoria . "</td>";
                    $html_propuestas_jurados_seleccionados = $html_propuestas_jurados_seleccionados . '<td>' . $jurado->categoria . '</td>';                
                    $html_propuestas_jurados_seleccionados = $html_propuestas_jurados_seleccionados . '<td>Jurado</td>';                
                    $html_propuestas_jurados_seleccionados = $html_propuestas_jurados_seleccionados . '<td>' . $jurado->participante . '</td>';                                
                    $html_propuestas_jurados_seleccionados = $html_propuestas_jurados_seleccionados . '<td>Seleccionado</td>';                
                    $html_propuestas_jurados_seleccionados = $html_propuestas_jurados_seleccionados . "</tr>";
                }
                
                //Genero reporte personas naturales
                $sql_pn = "
                            SELECT 
                                    vwp.convocatoria,
                                    vwp.id_convocatoria,
                                    vwp.codigo,
                                    vwp.nombre_propuesta,
                                    vwp.tipo_participante,
                                    vwp.representante,
                                    vwp.tipo_rol,
                                    vwp.rol,
                                    vwp.primer_nombre,
                                    vwp.segundo_nombre,
                                    vwp.primer_apellido,
                                    vwp.segundo_apellido,
                                    vwp.estado_propuesta                                
                            FROM Viewparticipantes AS vwp                                
                            WHERE vwp.codigo <> '".$propuesta->codigo."' AND vwp.tipo_participante <> 'Jurados' AND REPLACE(REPLACE(TRIM(vwp.numero_documento),'.',''),' ', '') IN ($cedulas_integrantes)
                            ";

                $personas_naturales = $app->modelsManager->executeQuery($sql_pn);


                foreach ($personas_naturales as $pn) {
                    
                    //Consulto la convocatoria
                    $convocatoria_pn = Convocatorias::findFirst($pn->id_convocatoria);

                    //Si la convocatoria seleccionada es categoria, debo invertir los nombres la convocatoria con la categoria
                    $nombre_convocatoria_pn = $convocatoria_pn->nombre;
                    $nombre_categoria_pn = "";
                    $anio_convocatoria_pn = $convocatoria_pn->anio;
                    if ($convocatoria_pn->convocatoria_padre_categoria > 0) {                
                        $nombre_convocatoria_pn = $convocatoria_pn->getConvocatorias()->nombre;
                        $nombre_categoria_pn = $convocatoria_pn->nombre;                                
                        $anio_convocatoria_pn = $convocatoria_pn->getConvocatorias()->anio;
                    }

                    if($anio_convocatoria_pn==$anio_convocatoria)
                    {                
                        if($pn->estado_propuesta=="Ganadora")
                        {
                            $html_propuestas_ganadoras = $html_propuestas_ganadoras . "<tr class='tr_propuestas_ganadoras'>";
                            $html_propuestas_ganadoras = $html_propuestas_ganadoras . "<td>" . $nombre_convocatoria_pn . "</td>";
                            $html_propuestas_ganadoras = $html_propuestas_ganadoras . "<td>" . $nombre_categoria_pn . "</td>";                
                            $html_propuestas_ganadoras = $html_propuestas_ganadoras . "<td>" . $pn->tipo_rol . "</td>";
                            $html_propuestas_ganadoras = $html_propuestas_ganadoras . "<td>" . $pn->primer_nombre . " ". $pn->segundo_nombre . " ". $pn->primer_apellido . " " . $pn->segundo_apellido . "</td>";                
                            $html_propuestas_ganadoras = $html_propuestas_ganadoras . "<td>" . $pn->codigo . "</td>";
                            $html_propuestas_ganadoras = $html_propuestas_ganadoras . "<td>" . $pn->estado_propuesta . "</td>";
                            $html_propuestas_ganadoras = $html_propuestas_ganadoras . "</tr>";
                        }
                        else
                        {
                            $html_propuestas = $html_propuestas . "<tr class='tr_propuestas'>";
                            $html_propuestas = $html_propuestas . "<td>" . $nombre_convocatoria_pn . "</td>";
                            $html_propuestas = $html_propuestas . "<td>" . $nombre_categoria_pn . "</td>";                
                            $html_propuestas = $html_propuestas . "<td>" . $pn->tipo_rol . "</td>";
                            $html_propuestas = $html_propuestas . "<td>" . $pn->primer_nombre . " ". $pn->segundo_nombre . " ". $pn->primer_apellido . " " . $pn->segundo_apellido . "</td>";                
                            $html_propuestas = $html_propuestas . "<td>" . $pn->codigo . "</td>";
                            $html_propuestas = $html_propuestas . "<td>" . $pn->estado_propuesta . "</td>";
                            $html_propuestas = $html_propuestas . "</tr>";
                        }                
                    }
                }
                
                //Genero reporte de jurados seleccionados
                $sql_ganadores_anios_anteriores = "
                            SELECT 
                                    ga.*
                            FROM Ganadoresantes2020 as ga                                
                            WHERE 	
                                    ga.active=true AND                               
                                    REPLACE(REPLACE(TRIM(ga.numero_documento),'.',''),' ', '') IN ($cedulas_integrantes)                            
                            ORDER BY ga.anio DESC
                            ";

                $ganadores_anios_anteriores = $app->modelsManager->executeQuery($sql_ganadores_anios_anteriores);

                foreach ($ganadores_anios_anteriores as $ganador_anio_anterior) {                    
                    $html_ganadoras_anios_anteriores = $html_ganadoras_anios_anteriores . "<tr class='tr_ganador_anio_anterior'>";
                    $html_ganadoras_anios_anteriores = $html_ganadoras_anios_anteriores . "<td>" . $ganador_anio_anterior->anio . "</td>";                    
                    $html_ganadoras_anios_anteriores = $html_ganadoras_anios_anteriores . "<td>" . $ganador_anio_anterior->entidad . "</td>";                    
                    $html_ganadoras_anios_anteriores = $html_ganadoras_anios_anteriores . "<td>" . $ganador_anio_anterior->convocatoria . "</td>";                    
                    $html_ganadoras_anios_anteriores = $html_ganadoras_anios_anteriores . "<td>" . $ganador_anio_anterior->categoria . "</td>";                    
                    $html_ganadoras_anios_anteriores = $html_ganadoras_anios_anteriores . "<td>" . $ganador_anio_anterior->codigo_propuesta . " - " . $ganador_anio_anterior->estado_propuesta . " - " . $ganador_anio_anterior->nombre_propuesta . "</td>";                                                                                                    
                    $html_ganadoras_anios_anteriores = $html_ganadoras_anios_anteriores . "<td>" . $ganador_anio_anterior->primer_nombre . " " . $ganador_anio_anterior->segundo_nombre . " " . $ganador_anio_anterior->primer_apellido . " " . $ganador_anio_anterior->segundo_apellido . "</td>";
                    $html_ganadoras_anios_anteriores = $html_ganadoras_anios_anteriores . "<td>" . $ganador_anio_anterior->tipo_participante . "</td>";
                    $html_ganadoras_anios_anteriores = $html_ganadoras_anios_anteriores . "<td>" . $ganador_anio_anterior->tipo_rol . "</td>";
                    $html_ganadoras_anios_anteriores = $html_ganadoras_anios_anteriores . "</tr>";
                }
                
                $array["html_propuestas"] = $html_propuestas;                
                $array["html_propuestas_ganadoras"] = $html_propuestas_ganadoras;
                $array["html_ganadoras_anios_anteriores"] = $html_ganadoras_anios_anteriores;
                $array["html_propuestas_jurados_seleccionados"] = $html_propuestas_jurados_seleccionados;
                
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
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo cargar_propuesta ' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->get('token')]);
        $logger->close();
        echo "error_metodo";
    }
}
);

$app->post('/guardar_verificacion_1', function () use ($app, $config,$logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();
    
    try {

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPost('token'));
        
        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al metodo guardar_verificacion_1 para guardar la verificacion de la propuesta(' . $request->getPost('propuesta') . ')"', ['user' => '', 'token' => $request->getPost('token')]);

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->getPost('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                
                //Consulto la propuesta actual
                $propuesta = Propuestas::findFirst("id=" . $request->getPost('propuesta') . "");

                if (isset($propuesta->id)) {
                    
                    $new_array = $app->request->getPost();
                    unset($new_array['token']);
                    unset($new_array['modulo']);
                    
                    $propuestasverificaciones = new Propuestasverificaciones();
                    $propuestasverificaciones->creado_por = $user_current["id"];
                    $propuestasverificaciones->fecha_creacion = date("Y-m-d H:i:s");
                    $propuestasverificaciones->active = true;
                    if ($propuestasverificaciones->save($new_array) === false) {
                        //Muestra los mensajes de error cuando no guarda
                        //foreach ($propuestasverificaciones->getMessages() as $message) {
                        //    echo $message;
                        //}
  
                        //Registro la accion en el log de convocatorias           
                        $logger->error('"token":"{token}","user":"{user}","message":"El metodo guardar_verificacion_1 presento error al al guardar la verificacion 1 de la propuesta (' . $request->getPost('propuesta') . ')"', ['user' => "", 'token' => $request->getPost('token')]);
                        $logger->close();
                        echo "error";
                    } else {
                        //Registro la accion en el log de convocatorias
                        $logger->info('"token":"{token}","user":"{user}","message":"El metodo guardar_verificacion_1 guardo con exito la verificacion 1 de la propuesta (' . $request->getPost('propuesta') . ')"', ['user' => $user_current["username"], 'token' => $request->getPost('token')]);
                        $logger->close();
                    
                        echo $propuestasverificaciones->id;
                    }                                                            
                } else {
                    //Registro la accion en el log de convocatorias           
                    $logger->error('"token":"{token}","user":"{user}","message":"La propuesta (' . $request->getPost('propuesta') . '), no esta registrada en el metodo guardar_verificacion_1 "', ['user' => $user_current["username"], 'token' => $request->getPost('token')]);
                    $logger->close();
                    echo "crear_propuesta";
                    exit;
                }
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"Acceso denegado en el metodo guardar_verificacion_1 de la propuesta (' . $request->getPost('propuesta') . ')"', ['user' => "", 'token' => $request->getPost('token')]);
                $logger->close();
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco en el metodo guardar_verificacion_1 de la propuesta (' . $request->getPost('propuesta') . ')"', ['user' => "", 'token' => $request->getPost('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo guardar_verificacion_1 de la propuesta (' . $request->getPost('propuesta') . ') ' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->getPost('token')]);
        $logger->close();
        echo "error_metodo";
    }
}
);

$app->post('/valida_verificacion', function () use ($app, $config,$logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();
    
    try {

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPost('token'));
        
        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al metodo valida_verificacion_1 para guardar la validar la propuesta(' . $request->getPost('propuesta') . ')"', ['user' => '', 'token' => $request->getPost('token')]);

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->getPost('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                
                //Consulto la propuesta actual
                $propuesta = Propuestas::findFirst("id=" . $request->getPost('propuesta') . "");

                if (isset($propuesta->id)) {
                    
                    //Consulto todos los requisitos verificados
                    //Verifico que uno de ellos este en no cumple para informarle al funcinario que debe rechazar
                    //Si no le digo que confirme la verificacion
                    $array_propuestas_verificaciones = Propuestasverificaciones::find("propuesta=" . $request->getPost('propuesta') . " AND verificacion=" . $request->getPost('verificacion') . "");
                    $rechazo=false;
                    foreach ($array_propuestas_verificaciones as $propuesta_verificacion) {                    
                        if( $propuesta_verificacion->getConvocatoriasdocumentos()->getRequisitos()->tipo_requisito==$request->getPost('tipo_requisito') )
                        {
                            if( $propuesta_verificacion->estado == 30 || $propuesta_verificacion->estado == 26){
                                $rechazo=true;                                                                
                                break;
                            }
                        }
                    }
                    
                    if($rechazo)
                    {
                        echo "rechazar";
                    }
                    else
                    {
                        $subsanar=false;
                        foreach ($array_propuestas_verificaciones as $propuesta_verificacion) {                    
                            if( $propuesta_verificacion->getConvocatoriasdocumentos()->getRequisitos()->tipo_requisito==$request->getPost('tipo_requisito') )
                            {
                                if( $propuesta_verificacion->estado == 27){
                                    $subsanar=true;                                                                
                                    break;
                                }
                            }
                        }
                        
                        if($subsanar)
                        {
                            echo "subsanar";
                        }
                        else
                        {
                            //WILLIAM OJO QUEDO EN VALIDAR QUE TODOS ESTEN EN ESTADO CUMPLE 29
                            $cumple=false;
                            foreach ($array_propuestas_verificaciones as $propuesta_verificacion) {                    
                                if( $propuesta_verificacion->getConvocatoriasdocumentos()->getRequisitos()->tipo_requisito==$request->getPost('tipo_requisito') )
                                {
                                    if( $propuesta_verificacion->estado == 29){
                                        $cumple=true;                                                                
                                        break;
                                    }
                                }
                            }

                            if($cumple)
                            {
                                echo "cumple";
                            }
                            else
                            {
                                $habilitar=false;
                                foreach ($array_propuestas_verificaciones as $propuesta_verificacion) {                    
                                    if( $propuesta_verificacion->getConvocatoriasdocumentos()->getRequisitos()->tipo_requisito==$request->getPost('tipo_requisito') )
                                    {
                                        if( $propuesta_verificacion->estado == 27){
                                            $subsanar=true;                                                                
                                            break;
                                        }
                                    }
                                }

                                if($habilitar)
                                {
                                    echo "habilitada";
                                }
                                else
                                {
                                    echo "confirmar";
                                }
                            }
                        }
                    }
                    
                } else {
                    //Registro la accion en el log de convocatorias           
                    $logger->error('"token":"{token}","user":"{user}","message":"La propuesta (' . $request->getPost('propuesta') . '), no esta registrada en el metodo valida_verificacion_1 "', ['user' => $user_current["username"], 'token' => $request->getPost('token')]);
                    $logger->close();
                    echo "crear_propuesta";
                    exit;
                }
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"Acceso denegado en el metodo valida_verificacion_1 de la propuesta (' . $request->getPost('propuesta') . ')"', ['user' => "", 'token' => $request->getPost('token')]);
                $logger->close();
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco en el metodo valida_verificacion_1 de la propuesta (' . $request->getPost('propuesta') . ')"', ['user' => "", 'token' => $request->getPost('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo valida_verificacion_1 de la propuesta (' . $request->getPost('propuesta') . ') ' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->getPost('token')]);
        $logger->close();
        echo "error_metodo";
    }
}
);

$app->post('/guardar_confirmacion', function () use ($app, $config,$logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();
    
    try {

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPost('token'));
        
        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al metodo guardar_confirmacion para guardar la validar la propuesta(' . $request->getPost('propuesta') . ')"', ['user' => '', 'token' => $request->getPost('token')]);

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->getPost('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {

                //Consulto la propuesta actual
                $propuesta = Propuestas::findFirst("id=" . $request->getPost('propuesta') . "");

                if (isset($propuesta->id)) {
                    
                    if($request->getPost('estado_actual_propuesta')=="rechazar")
                    {
                        $propuesta->estado=23;
                    }
                    
                    if($request->getPost('estado_actual_propuesta')=="subsanar")
                    {
                        $propuesta->estado=21;
                        
                        /*
                        //Valido que si la modalidad es diferente de LEP                        
                        if($propuesta->getConvocatorias()->modalidad!=6)
                        {
                            $propuesta->estado=21;
                        }
                        else
                        {
                            if($propuesta->getConvocatorias()->modalidad==6 && $request->getPost('tipo_verificacion')=="tecnica")
                            {
                                $propuesta->estado=21;
                            }
                        } 
                        */                       
                    }
                    
                    if($request->getPost('estado_actual_propuesta')=="habilitada")
                    {
                        //Valido que sea diferente a 21 que es por subsanar
                        //Debido que no puedo colocar una propuesta habilitada 
                        //Sin que haya subsanado
                        if($propuesta->estado!=21)
                        {
                            $propuesta->estado=24;
                        }
                    }
                    
                    if($request->getPost('estado_actual_propuesta')=="cumple")
                    {
                        if($request->getPost('verificacion')=="2")
                        {
                            $propuesta->estado=24;
                        }
                        else
                        {
                            $propuesta->estado=8;
                        }                                                                        
                    }
                    
                    //Solo la verificacion tecnica puede pasar la propuesta a estado 
                    //habilitada, en la primera verificacion                    
                    if($request->getPost('tipo_verificacion')=="tecnica")
                    {
                        $propuesta->verificacion_tecnicos=true;
                        if($request->getPost('estado_actual_propuesta')=="habilitada")
                        {
                            if($propuesta->estado==8||$propuesta->estado==31)
                            {
                                $propuesta->estado=24;
                            }
                        }
                    }
                    
                    //Solo la verificacion administrativa
                    if($request->getPost('tipo_verificacion')=="administrativa")
                    {
                        $propuesta->verificacion_administrativos=true;
                    }                                                                                    
                    
                    $propuesta->update();
                    
                    //Registro la accion en el log de convocatorias                    
                    $logger->info('"token":"{token}","user":"{user}","message":"La propuesta (' . $request->getPost('propuesta') . '), Se actualizó correctamente el metodo guardar_confirmacion "', ['user' => $user_current["username"], 'token' => $request->getPost('token')]);
                    $logger->close();                        
                    echo "exito";
                    exit;
                    
                } else {
                    //Registro la accion en el log de convocatorias           
                    $logger->error('"token":"{token}","user":"{user}","message":"La propuesta (' . $request->getPost('propuesta') . '), no esta registrada en el metodo guardar_confirmacion "', ['user' => $user_current["username"], 'token' => $request->getPost('token')]);
                    $logger->close();
                    echo "crear_propuesta";
                    exit;
                }
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"Acceso denegado en el metodo guardar_confirmacion de la propuesta (' . $request->getPost('propuesta') . ')"', ['user' => "", 'token' => $request->getPost('token')]);
                $logger->close();
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco en el metodo guardar_confirmacion de la propuesta (' . $request->getPost('propuesta') . ')"', ['user' => "", 'token' => $request->getPost('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo guardar_confirmacion de la propuesta (' . $request->getPost('propuesta') . ') ' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->getPost('token')]);
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