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
        if (isset($token_actual->id)) {
            
            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->get('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                
                //Consulto el usuario actual                
                $user_entidad = Usuarios::findFirst($user_current["id"]);            
                //Consulto si tiene relacionada la entidad
                $ver_reportes=false;
                foreach ($user_entidad->getUsuariosentidades() as $usuario_entidad) {
                    if($usuario_entidad->entidad==$request->get('entidad'))
                    {
                        $ver_reportes=true;
                    }                    
                }
                
                if($ver_reportes)
                {
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
                    $params=array("token"=>$request->get('token'),"entidad"=>$request->get('entidad'),"anio"=>$request->get('anio'),"convocatoria"=>$request->get('convocatoria'));
                    $array_retorno["reporte_convocatorias_listado_contratistas"]="<a target='_blank' href='".$config->sistema->url_report."listado_entidades_convocatorias_listado_contratistas.php?token=".$request->get('token')."&anio=".$request->get('anio')."&entidad=".$request->get('entidad')."&convocatoria=".$request->get('convocatoria')."' class='btn'>Generar Reporte <i class='fa fa-file-pdf-o'></i></a><a href='javascript:void(0);' rel='". json_encode($params)."' class='btn reporte_convocatorias_listado_contratistas'>Generar Reporte <i class='fa fa-file-excel-o'></i></a>";                
                    $array_retorno["reporte_convocatorias_listado_participantes"]="<a target='_blank' href='".$config->sistema->url_report."listado_entidades_convocatorias_listado_participantes.php?token=".$request->get('token')."&anio=".$request->get('anio')."&entidad=".$request->get('entidad')."&convocatoria=".$request->get('convocatoria')."' class='btn'>Generar Reporte <i class='fa fa-file-pdf-o'></i></a><a href='javascript:void(0);' rel='". json_encode($params)."' class='btn reporte_convocatorias_listado_participantes'>Generar Reporte <i class='fa fa-file-excel-o'></i></a>";                
                    $array_retorno["reporte_convocatorias_listado_no_inscritas"]="<a target='_blank' href='".$config->sistema->url_report."listado_entidades_convocatorias_listado_no_inscritas.php?token=".$request->get('token')."&anio=".$request->get('anio')."&entidad=".$request->get('entidad')."&convocatoria=".$request->get('convocatoria')."' class='btn'>Generar Reporte <i class='fa fa-file-pdf-o'></i></a><a href='javascript:void(0);' rel='". json_encode($params)."' class='btn reporte_convocatorias_listado_no_inscritas'>Generar Reporte <i class='fa fa-file-excel-o'></i></a>";                
                    //Reporte de jurados postulados
                    $array_retorno["reporte_jurados_postulados"]="<a target='_blank' href='".$config->sistema->url_report."reporte_jurados_postulados.php?convocatoria=".$request->get('convocatoria')."' class='btn'>Generar Reporte <i class='fa fa-file-pdf-o'></i></a>";                
                    //11-08-2020 Reporte de evaluación jurados postulados
                    $array_retorno["reporte_evaluacion_jurados"]="<a target='_blank' href='".$config->sistema->url_report."reporte_evaluacion_jurados.php?convocatoria=".$request->get('convocatoria')."' class='btn'>Generar Reporte <i class='fa fa-file-pdf-o'></i></a>";                
                    
                    
                    $rondas_evaluacion= Convocatoriasrondas::find("active=true AND convocatoria=".$request->get('convocatoria'));
                    
                    $option='<option value="">:: Seleccionar ::</option>';
                    foreach ($rondas_evaluacion as $ronda) {
                        $option=$option.'<option value="'.$ronda->id.'">'.$ronda->nombre_ronda.'</option>';
                    }
                    
                    $select_ronda='<select id="ronda" class="form-control" >';                    
                    $select_ronda=$select_ronda.$option;
                    $select_ronda=$select_ronda.'</select> ';
                    
                    $array_retorno["reporte_planillas_evaluacion"]='<div class="row"><div class="col-lg-6"><div class="form-group"><label>Ronda</label>'.$select_ronda.'</div></div><div class="col-lg-6"><div class="form-group"><label>Deliberación</label><select id="deliberacion" name="deliberacion" class="form-control" ><option value="true">Sí</option><option value="false" selected="selected">No</option></select></div></div><div class="col-lg-6"><div class="form-group"><label>Códigos de propuestas</label><input type="text" id="codigos" name="codigos" class="form-control"></div></div><div class="col-lg-6"><div class="form-group"><label>&nbsp;</label><button id="btn_planillas" class="btn btn-primary form-control">Generar reporte</button></div></div></div>';                
                    
                    $array_retorno["fecha_actual"]= date("Y-m-d H:i:s");                
                    echo json_encode($array_retorno);
                }
                else
                {
                    //Registro la accion en el log de convocatorias           
                    $logger->error('"token":"{token}","user":"{user}","message":"Acceso denegado por entidad en el metodo generar_reportes  con los siguientes parametros de busqueda (' . $request->get('params') . ')" ', ['user' => $user_current["username"], 'token' => $request->get('token')]);                
                    $logger->close();
                    echo "error_entidad";
                }
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
        if (isset($token_actual->id)) {
            
            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->get('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                
                //Consulto el usuario actual                
                $user_entidad = Usuarios::findFirst($user_current["id"]);            
                //Consulto si tiene relacionada la entidad
                $ver_reportes=false;
                foreach ($user_entidad->getUsuariosentidades() as $usuario_entidad) {
                    if($usuario_entidad->entidad==$request->get('entidad'))
                    {
                        $ver_reportes=true;
                    }                    
                }
                
                if($ver_reportes)
                {
                
                    $params=array("token"=>$request->get('token'),"entidad"=>$request->get('entidad'),"anio"=>$request->get('anio'));
                    //Seteo los varoles a retornar
                    $array_retorno["reporte_propuestas_estados"]="<a target='_blank' href='".$config->sistema->url_report."listado_entidades_convocatorias_estado.php?token=".$request->get('token')."&anio=".$request->get('anio')."&entidad=".$request->get('entidad')."' class='btn'>Generar Reporte <i class='fa fa-file-pdf-o'></i></a><a href='javascript:void(0);' rel='". json_encode($params)."' class='btn reporte_propuestas_estados_excel'>Generar Reporte <i class='fa fa-file-excel-o'></i></a>";                
                    $array_retorno["reporte_convocatorias_cerrar"]="<a target='_blank' href='".$config->sistema->url_report."listado_entidades_convocatorias_cerrar.php?token=".$request->get('token')."&anio=".$request->get('anio')."&entidad=".$request->get('entidad')."' class='btn'>Generar Reporte <i class='fa fa-file-pdf-o'></i></a><a href='javascript:void(0);' rel='". json_encode($params)."' class='btn reporte_convocatorias_cerrar_excel'>Generar Reporte <i class='fa fa-file-excel-o'></i></a>";                
                    $array_retorno["reporte_convocatorias_cantidad_jurados"]="<a target='_blank' href='".$config->sistema->url_report."listado_entidades_convocatorias_total_jurados.php?token=".$request->get('token')."&anio=".$request->get('anio')."&entidad=".$request->get('entidad')."' class='btn'>Generar Reporte <i class='fa fa-file-pdf-o'></i></a><a href='javascript:void(0);' rel='". json_encode($params)."' class='btn reporte_convocatorias_cantidad_jurados'>Generar Reporte <i class='fa fa-file-excel-o'></i></a>";                
                    $array_retorno["reporte_convocatorias_listado_jurados"]="<a target='_blank' href='".$config->sistema->url_report."listado_entidades_convocatorias_listado_jurados.php?token=".$request->get('token')."&anio=".$request->get('anio')."&entidad=".$request->get('entidad')."' class='btn'>Generar Reporte <i class='fa fa-file-pdf-o'></i></a><a href='javascript:void(0);' rel='". json_encode($params)."' class='btn reporte_convocatorias_listado_jurados'>Generar Reporte <i class='fa fa-file-excel-o'></i></a>";                
                    //25-09-2020 Se incorpora botón para reporte de linea base
                    $array_retorno["reporte_linea_base_jurados"]="<a href='javascript:void(0);' rel='". json_encode($params)."' class='btn reporte_linea_base_jurados_btn'>Generar Reporte <i class='fa fa-file-excel-o'></i></a>";                
                    $array_retorno["reporte_ganadores"]="<a target='_blank' href='".$config->sistema->url_report."reporte_ganadores.php?token=".$request->get('token')."&anio=".$request->get('anio')."&entidad=".$request->get('entidad')."' class='btn'>Generar Reporte <i class='fa fa-file-pdf-o'></i></a><a href='javascript:void(0);' rel='". json_encode($params)."' class='btn reporte_propuestas_ganadoras'>Generar Reporte <i class='fa fa-file-excel-o'></i></a>";                
                    $array_retorno["fecha_actual"]= date("Y-m-d H:i:s");                
                    echo json_encode($array_retorno);
                }
                else
                {
                    //Registro la accion en el log de convocatorias           
                    $logger->error('"token":"{token}","user":"{user}","message":"Acceso denegado por entidad en el metodo generar_reportes_entidades  con los siguientes parametros de busqueda (' . $request->get('params') . ')" ', ['user' => $user_current["username"], 'token' => $request->get('token')]);                
                    $logger->close();
                    echo "error_entidad";
                }                                                                        
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"Acceso denegado en el metodo generar_reportes_entidades  con los siguientes parametros de busqueda (' . $request->get('params') . ')" ', ['user' => $user_current["username"], 'token' => $request->get('token')]);                
                $logger->close();
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco en el metodo generar_reportes_entidades con los siguientes parametros de busqueda (' . $request->get('params') . ')" ', ['user' => $user_current["username"], 'token' => $request->get('token')]);            
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo generar_reportes_entidades con los siguientes parametros de busqueda (' . $request->get('params') . ')" ' . $ex->getMessage() . '"', ['user' => $user_current["username"], 'token' => $request->get('token')]);        
        $logger->close();
        echo "error_metodo ".$ex->getMessage();
    }
}
);

$app->get('/generar_reportes_generales', function () use ($app, $config, $logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();
    
    //Consulto si al menos hay un token
    $token_actual = $tokens->verificar_token($request->get('token'));
    
    //Validar array del usuario
    $user_current = json_decode($token_actual->user_current, true);
        
    try {

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {
            
            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->get('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                
                $params=array("token"=>$request->get('token'),"entidad"=>$request->get('entidad'),"anio"=>$request->get('anio'));
                //Seteo los varoles a retornar
                $array_retorno["reporte_pn"]='<div class="row"><div class="col-lg-12"><div class="form-group"><label>Número de documento</label><input type="text" id="pn_numero_documento" class="form-control"></div></div></div><div class="row"><div class="col-lg-12" style="text-align: right"><button id="btn_pn" type="submit" class="btn btn-default">Generar reporte</button></div></div>';                
                $array_retorno["reporte_ganadores"]="<a target='_blank' href='".$config->sistema->url_report."reporte_ganadores.php?token=".$request->get('token')."&anio=".$request->get('anio')."&entidad=".$request->get('entidad')."' class='btn'>Generar Reporte <i class='fa fa-file-pdf-o'></i></a><a href='javascript:void(0);' rel='". json_encode($params)."' class='btn reporte_propuestas_ganadoras'>Generar Reporte <i class='fa fa-file-excel-o'></i></a>";                
                $array_retorno["fecha_actual"]= date("Y-m-d H:i:s");                
                echo json_encode($array_retorno);                                                                                       
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"Acceso denegado en el metodo generar_reportes_entidades  con los siguientes parametros de busqueda (' . $request->get('params') . ')" ', ['user' => $user_current["username"], 'token' => $request->get('token')]);                
                $logger->close();
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco en el metodo generar_reportes_entidades con los siguientes parametros de busqueda (' . $request->get('params') . ')" ', ['user' => $user_current["username"], 'token' => $request->get('token')]);            
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo generar_reportes_entidades con los siguientes parametros de busqueda (' . $request->get('params') . ')" ' . $ex->getMessage() . '"', ['user' => $user_current["username"], 'token' => $request->get('token')]);        
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
        if (isset($token_actual->id)) {

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->get('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                                
                //Defino columnas para el orden desde la tabla html
                $columns = array(
                    0 => 'ec.primer_nombre',
                    1 => 'ec.segundo_nombre',
                    2 => 'ec.primer_apellido',
                    3 => 'ec.segundo_apellido',                    
                    4 => 'ec.numero_documento',                    
                    5 => 'ec.active'                    
                );

                
                //Condiciones para la consulta
                $where .= " INNER JOIN Entidades AS e ON e.id=ec.entidad";
                $where .= " WHERE ec.active IN (true,false)";
                
                if($request->get('entidad')!=""){
                    $where .= " AND ec.entidad=".$request->get('entidad');                    
                }

                if (!empty($request->get("search")['value'])) {
                    $where .= " AND ( UPPER(" . $columns[0] . ") LIKE '%" . strtoupper($request->get("search")['value']) . "%' )";
                    $where .= " OR  UPPER(" . $columns[1] . ") LIKE '%" . strtoupper($request->get("search")['value']) . "%' ";
                    $where .= " OR  UPPER(" . $columns[2] . ") LIKE '%" . strtoupper($request->get("search")['value']) . "%' ";
                    $where .= " OR  UPPER(" . $columns[3] . ") LIKE '%" . strtoupper($request->get("search")['value']) . "%' ";
                    $where .= " OR ( UPPER(" . $columns[4] . ") LIKE '%" . strtoupper($request->get("search")['value']) . "%' )";
                }

                //Defino el sql del total y el array de datos
                $sqlTot = "SELECT count(*) as total FROM Entidadescontratistas AS ec";
                $sqlRec = "SELECT e.nombre AS entidad," . $columns[0] . " ," . $columns[1] . " ," . $columns[2] . " ," . $columns[3] . " ," . $columns[4] . "," . $columns[5] . " , concat('<button title=\"',ec.id,'\" type=\"button\" class=\"btn btn-warning cargar_contratista\" data-toggle=\"modal\" data-target=\"#editar_contratista\"><span class=\"glyphicon glyphicon-edit\"></span></button>') as acciones FROM Entidadescontratistas AS ec";

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