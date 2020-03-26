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
                                e.nombre,count(p.id) AS total 
                            FROM Propuestas AS p 
                            LEFT JOIN Estados AS e ON e.id=p.estado
                            WHERE p.convocatoria=".$request->get('convocatoria')."
                            GROUP BY 1";

                $propuestas_estado = $app->modelsManager->executeQuery($sql_propuestas_estado);
                
                $array_retorno=array();                
                foreach ($propuestas_estado as $propuestas) {
                    $array_propuestas_estado[]=array("device"=>$propuestas->nombre,"geekbench"=>$propuestas->total);
                }
                
                //Genero reporte propuestas por estado
                $sql_propuestas_participante = "
                            SELECT 
                            per.id,
                            pro.creado_por,
                            count(per.id) 
                            FROM Perfiles AS per
                            INNER JOIN Usuariosperfiles AS up ON up.perfil=per.id
                            INNER JOIN Participantes AS par ON par.usuario_perfil=up.id
                            INNER JOIN Propuestas AS pro ON pro.participante=par.id
                            WHERE per.id IN (6,7,8) AND pro.convocatoria=".$request->get('convocatoria')." AND par.tipo='Participante'
                            GROUP BY 1,2";
                
                $propuestas_participantes = $app->modelsManager->executeQuery($sql_propuestas_participante);
                
                $array_participantes=array();
                $pn_6=0;
                $pj_7=0;
                $agr_8=0;
                foreach ($propuestas_participantes as $propuestas) {
                    if($propuestas->id==6)
                    {
                        $pn_6++;
                    }
                    if($propuestas->id==7)
                    {
                        $pj_7++;
                    }
                    if($propuestas->id==8)
                    {
                        $agr_8++;
                    }
                }
                
                $array_propuestas_participantes[]=array("device"=>"Persona\nNatural","geekbench"=>$pn_6);
                $array_propuestas_participantes[]=array("device"=>"Persona\nJurídica","geekbench"=>$pj_7);
                $array_propuestas_participantes[]=array("device"=>"Agrupación","geekbench"=>$agr_8);
                
                //Seteo los varoles a retornar
                $array_retorno["reporte_propuestas_estados"]=$array_propuestas_estado;
                $array_retorno["reporte_propuestas_participantes"]=$array_propuestas_participantes;
                $array_retorno["fecha_actual"]= date("Y-m-d H:i:s");                
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

$app->get('/generar_reportes_entidades', function () use ($app, $config, $logger) {
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
                
                //Seteo los varoles a retornar
                $array_retorno["reporte_propuestas_estados"]='<a target="_blank" href="'.$config->sistema->url_report.'listado_entidades_convocatorias_estado.php?token='.$request->get('token').'&anio='.$request->get('anio').'&entidad='.$request->get('entidad').'" class="btn">Generar Reporte <i class="fa fa-file-pdf-o"></i></a>';                
                $array_retorno["reporte_convocatorias_cerrar"]='<a target="_blank" href="'.$config->sistema->url_report.'listado_entidades_convocatorias_cerrar.php?token='.$request->get('token').'&anio='.$request->get('anio').'&entidad='.$request->get('entidad').'" class="btn">Generar Reporte <i class="fa fa-file-pdf-o"></i></a>';                
                $array_retorno["reporte_convocatorias_cantidad_jurados"]='<a target="_blank" href="'.$config->sistema->url_report.'listado_entidades_convocatorias_total_jurados.php?token='.$request->get('token').'&anio='.$request->get('anio').'&entidad='.$request->get('entidad').'" class="btn">Generar Reporte <i class="fa fa-file-pdf-o"></i></a>';                
                $array_retorno["fecha_actual"]= date("Y-m-d H:i:s");                
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

$app->get('/generar_reportes_contratistas', function () use ($app, $config, $logger) {
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
                                
                //Defino columnas para el orden desde la tabla html
                $columns = array(
                    0 => 'ec.primer_nombre',
                    1 => 'ec.segundo_nombre',
                    2 => 'ec.primer_apellido',
                    3 => 'ec.segundo_apellido'                    
                );

                
                //Condiciones para la consulta
                $where .= " INNER JOIN Entidades AS e ON e.id=ec.entidad";
                $where .= " WHERE ec.active=true";
                
                if($request->get('entidad')!=""){
                    $where .= " AND ec.entidad=".$request->get('entidad');                    
                }

                if (!empty($request->get("search")['value'])) {
                    $where .= " AND ( UPPER(" . $columns[0] . ") LIKE '%" . strtoupper($request->get("search")['value']) . "%' )";
                    $where .= " OR  UPPER(" . $columns[1] . ") LIKE '%" . strtoupper($request->get("search")['value']) . "%' ";
                    $where .= " OR  UPPER(" . $columns[2] . ") LIKE '%" . strtoupper($request->get("search")['value']) . "%' ";
                    $where .= " OR ( UPPER(" . $columns[3] . ") LIKE '%" . strtoupper($request->get("search")['value']) . "%' )";
                }

                //Defino el sql del total y el array de datos
                $sqlTot = "SELECT count(*) as total FROM Entidadescontratistas AS ec";
                $sqlRec = "SELECT e.nombre AS entidad," . $columns[0] . " ," . $columns[1] . " ," . $columns[2] . " ," . $columns[3] . " , concat('<button type=\"button\" class=\"btn btn-warning\" onclick=\"form_edit(',ec.id,')\"><span class=\"glyphicon glyphicon-edit\"></span></button><button type=\"button\" class=\"btn btn-danger\" onclick=\"form_del(',ec.id,')\"><span class=\"glyphicon glyphicon-remove\"></span></button>') as acciones FROM Entidadescontratistas AS ec";

                //concatenate search sql if value exist
                if (isset($where) && $where != '') {

                    $sqlTot .= $where;
                    $sqlRec .= $where;
                }

                //Concateno el orden y el limit para el paginador
                $sqlRec .= " ORDER BY " . $columns[$request->get('order')[0]['column']] . "   " . $request->get('order')[0]['dir'] . "  LIMIT " . $request->get('length') . " offset " . $request->get('start') . " ";

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