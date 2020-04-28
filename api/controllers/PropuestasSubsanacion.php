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
                
                //Dias habilitados para subsanar                
                if($convocatoria->programa==1 || $convocatoria->programa==2)
                {
                    $variable_dias_subsanacion="dias_subsanacion_pde";
                }
                if($convocatoria->programa==3)
                {
                    $variable_dias_subsanacion="dias_subsanacion_pdsc";
                }
                
                $tabla_maestra= Tablasmaestras::find("active=true AND nombre='".$variable_dias_subsanacion."'");
                $dias = $tabla_maestra[0]->valor;
                
                //Genero el periodo de fechas para subsanar
                $datestart= strtotime(date("Y-m-d"));
                $datesuma = 15 * 86400;
                $diasemana = date('N',$datestart);
                $totaldias = $diasemana+$dias;
                $findesemana = intval( $totaldias/5) *2 ; 
                $diasabado = $totaldias % 5 ; 
                if ($diasabado==6) $findesemana++;
                if ($diasabado==0) $findesemana=$findesemana-2;                
                $total = (($dias+$findesemana) * 86400)+$datestart ; 
                
                $fechafinal = "desde <b>" .date('Y-m-d')."</b> hasta el <b>".date('Y-m-d', $total)." 17:00:00</b>";
                
                $array=array("fecha"=>$fechafinal,"error"=>"ingresar");
                echo json_encode($array);
            }
            else
            {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"La convocatoria('.$id.') no ha cerrado, la fecha de cierre es ('.$fecha_cierre_real->fecha_fin.')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                $logger->close();                
                $array=array("fecha"=>"","error"=>"error_fecha_cierre");
                echo json_encode($array);
            }
        } else {
            //Registro la accion en el log de convocatorias
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco"', ['user' => "", 'token' => $request->get('token')]);
            $logger->close();            
            $array=array("fecha"=>"","error"=>"error_token");
            echo json_encode($array);
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo validar_acceso ' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->get('token')]);
        $logger->close();
        $array=array("fecha"=>"","error"=>"error_metodo");
        echo json_encode($array);
    }
}
);

$app->post('/enviar_notificaciones/{id:[0-9]+}', function ($id) use ($app, $config, $logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa a enviar_notificaciones a la convocatoria"', ['user' => '', 'token' => $request->get('token')]);

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
                
                //Proceso para el envio de notificaciones
                //Consulto todas las propuestas en estado por subsanar
                $conditions = ['convocatoria' => $id, 'active' => true,'estado'=>21];            
                $array_propuestas = Propuestas::find(([
                            'conditions' => 'convocatoria=:convocatoria: AND active=:active: AND estado=:estado:',
                            'bind' => $conditions,
                ]));
            
                $mensaje="ingresar";
                foreach ($array_propuestas as $propuesta) {
                    
                    //Creo el cuerpo del messaje html del email
                    $html_subsanacion = Tablasmaestras::find("active=true AND nombre='html_subsanacion'")[0]->valor;
                    $html_subsanacion = str_replace("**nombre_propuesta**", $propuesta->nombre, $html_subsanacion);
                    $html_subsanacion = str_replace("**codigo_propuesta**", $propuesta->codigo, $html_subsanacion);
                    
                    //Dias habilitados para subsanar
                    if($convocatoria->programa==1 || $convocatoria->programa==2)
                    {
                        $variable_dias_subsanacion="dias_subsanacion_pde";
                    }
                    if($convocatoria->programa==3)
                    {
                        $variable_dias_subsanacion="dias_subsanacion_pdsc";
                    }

                    $tabla_maestra= Tablasmaestras::find("active=true AND nombre='".$variable_dias_subsanacion."'");
                    $dias = $tabla_maestra[0]->valor;                                        
                    
                    //Genero el periodo de fechas para subsanar
                    $datestart= strtotime(date("Y-m-d"));
                    $datesuma = 15 * 86400;
                    $diasemana = date('N',$datestart);
                    $totaldias = $diasemana+$dias;
                    $findesemana = intval( $totaldias/5) *2 ; 
                    $diasabado = $totaldias % 5 ; 
                    if ($diasabado==6) $findesemana++;
                    if ($diasabado==0) $findesemana=$findesemana-2;                
                    $total = (($dias+$findesemana) * 86400)+$datestart ; 

                    $fechafinal = "desde <b>" .date('Y-m-d')."</b> hasta el <b>".date('Y-m-d', $total)." 17:00:00</b>";
                    $html_subsanacion = str_replace("**fecha_inicio_propuesta**", date('Y-m-d'), $html_subsanacion);
                    $html_subsanacion = str_replace("**fecha_fin_propuesta**", date('Y-m-d', $total)." 17:00:00", $html_subsanacion);
                    
                    

                    $mail = new PHPMailer();
                    $mail->IsSMTP();
                    $mail->Host = "smtp-relay.gmail.com";
                    $mail->Port = 25;
                    $mail->CharSet = "UTF-8";
                    $mail->IsHTML(true); // El correo se env  a como HTML  
                    $mail->From = "convocatorias@scrd.gov.co";
                    $mail->FromName = "Sistema de Convocatorias";
                    $usuario_participante=$propuesta->getParticipantes()->getUsuariosperfiles()->getUsuarios()->username;
                    $mail->AddAddress($usuario_participante);
                    $mail->addCC($user_current["username"]);                    
                    
                    $mail->Subject = "Sistema de Convocatorias - Subsanación de propuesta";
                    $mail->Body = $html_subsanacion;

                    $exito = $mail->Send(); // Env  a el correo.

                    if ($exito) { 
                        //Actualizo el estado a Subsanación Recibida
                        $propuesta->estado=22;
                        $propuesta->fecha_inicio_subsanacion=date('Y-m-d H:i:s');
                        $propuesta->fecha_fin_subsanacion=date('Y-m-d', $total)." 17:00:00";
                        $propuesta->update();
                        
                    } else {
                        //Registro la accion en el log de convocatorias           
                        $logger->error('"token":"{token}","user":"{user}","message":"Error en el metodo enviar_notificaciones, phpmailer no envio la notificacion a '.$usuario_participante.'"', ['user' => $user_current["username"], 'token' => $request->get('token')]);                        
                        $mensaje="error_envio";
                    }
                    
                }
                
                $logger->close();
                                
                echo $mensaje;
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
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo enviar_notificaciones ' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->get('token')]);
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
        if (isset($token_actual->id)) {

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
        if (isset($token_actual->id)) {

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
                
                //Selecciono los parametros
                $params= json_decode($request->get('params'),true);                
                
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
                    
                    //Consulto todas las propuestas menos la del estado Por Subsanar y Subsanación Recibida
                    $where .= " WHERE p.active=true AND p.estado IN ( 21 , 22 ) AND ( c.area IN ($array_usuarios_areas) OR c.area IS NULL) ";
                    
                    
                    if($params["convocatoria"]!="")
                    {
                        $convocatoria= Convocatorias::findFirst("id=".$params["convocatoria"]." AND active=TRUE");
                        
                        //Valido si la convocatoria tiene categorias y tiene diferentes requisitos con el fin de buscar la fecha de cierre
                        $id_convocatoria=$convocatoria->id;                
                        $seudonimo=$convocatoria->seudonimo;
                        
                        $where .= " AND p.convocatoria=$id_convocatoria ";
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
                            . "INNER JOIN Entidades AS e ON e.id=c.entidad  AND e.id IN ($array_usuarios_entidades) "
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
                            . "per.id AS perfil ,"
                            . "per.nombre AS tipo_participante ,"
                            . "$participante AS participante ,"                                                    
                            . "concat('Desde <b>',p.fecha_inicio_subsanacion,'</b> hasta <b>',p.fecha_fin_subsanacion,'</b>') AS periodo_subsanacion "                                                    
                            . "FROM Propuestas AS p "
                            . "INNER JOIN Estados AS est ON est.id=p.estado "
                            . "INNER JOIN Participantes AS par ON par.id=p.participante "
                            . "INNER JOIN Convocatorias AS c ON c.id=p.convocatoria "                            
                            . "INNER JOIN Entidades AS e ON e.id=c.entidad  AND e.id IN ($array_usuarios_entidades) "
                            . "LEFT JOIN Convocatorias AS cat ON cat.id=c.convocatoria_padre_categoria "
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


try {
    // Gestionar la consulta
    $app->handle();
} catch (\Exception $e) {
    echo 'Excepción: ', $e->getMessage();
}
?>