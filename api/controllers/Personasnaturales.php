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
                                
                //Trae los datos del formulario por post
                $post = $app->request->getPost();
                
                //Consulto si existe el usuario perfil
                $usuario_perfil = Usuariosperfiles::findFirst("usuario=".$user_current["id"]." AND perfil=6");
                
                //Verifico si existe, con el fin de crearlo
                if(!isset($usuario_perfil->id))
                {
                    $usuario_perfil = new Usuariosperfiles();
                    $usuario_perfil->usuario = $user_current["id"];
                    $usuario_perfil->perfil = 6;                    
                    if ($usuario_perfil->save($usuario_perfil) === false) {
                        echo "error_usuario_perfil";
                    }                     
                }
                
                //Consulto los usuarios perfil del jurado y persona natural
                $array_usuario_perfil = Usuariosperfiles::find("usuario=".$user_current["id"]." AND perfil IN (6,17)");
                $id_usuarios_perfiles="";
                foreach ($array_usuario_perfil as $aup) {
                    $id_usuarios_perfiles=$id_usuarios_perfiles.$aup->id.",";
                }
                $id_usuarios_perfiles = substr($id_usuarios_perfiles, 0, -1);
                
                //Consulto si existe partipantes que tengan el mismo numero y tipo de documento que sean diferentes a su perfil de persona natutal o jurado
                $participante_verificado = Participantes::find("usuario_perfil NOT IN (".$id_usuarios_perfiles.") AND numero_documento='".$post["numero_documento"]."' AND tipo_documento =".$post["tipo_documento"]);
                
                if(count($participante_verificado)>0)
                {
                    echo "participante_existente";
                }
                else
                {
                    
                    //Valido si existe para editar o crear
                    if(is_numeric($post["id"]))
                    {
                        $participante = Participantes::findFirst($post["id"]);
                        $post["actualizado_por"] = $user_current["id"];
                        $post["fecha_actualizacion"] = date("Y-m-d H:i:s");
                    }
                    else
                    {
                        //Creo el objeto del particpante de persona natural
                        $participante = new Participantes();
                        $participante->creado_por = $user_current["id"];
                        $participante->fecha_creacion = date("Y-m-d H:i:s");                        
                        $participante->usuario_perfil = $usuario_perfil->id;
                        $participante->tipo = "Inicial";
                        $participante->active = TRUE;
                    }
                    
                    if ($participante->save($post) === false) {
                        echo "error";
                    }
                    else 
                    {
                        echo $participante->id;
                    }
                    
                }
                
            } else {
                echo "acceso_denegado";
            }
        } else {
            echo "error_token";
        }
    } catch (Exception $ex) {
        echo "error_metodo".$ex->getMessage();
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
            
            //Validar si existe un participante como persona natural, con id usuario innner usuario_perfil
            $user_current = json_decode($token_actual->user_current, true);
            
            //Busco si tiene el perfil de persona natural
            $usuario_perfil_pn = Usuariosperfiles::findFirst("usuario=".$user_current["id"]." AND perfil = 6");
            if(!isset($usuario_perfil_pn->id))
            {
                //Busco si tiene el perfil de jurado
                $usuario_perfil_pn = Usuariosperfiles::findFirst("usuario=".$user_current["id"]." AND perfil = 17");
                if(!isset($usuario_perfil_pn->id))
                {
                    $usuario_perfil_pn=new Usuariosperfiles();
                }                                
            }
            
            //Si existe el usuario perfil como pn o jurado
            $participante = new Participantes();
            if (isset($usuario_perfil_pn->id)) {
                $participante = Participantes::findFirst("usuario_perfil=".$usuario_perfil_pn->id." AND tipo='Inicial' AND active=TRUE");
            }
            
            //Asigno siempre el correo electronico del usuario al participante
            if(!isset($participante->correo_electronico)){
                $participante->correo_electronico=$user_current["username"];           
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
        echo $ex->getMessage();
    }
}
);

//Se realiza la busqueda del participante
//Si no existe el participante hijo traemos el inicial o el array para crear uno nuevo
//Si existe el participante asociado a la propuesta se retorna
$app->get('/buscar_participante', function () use ($app, $config , $logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();
        
    try {        
        
        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa a buscar el participante pn en la convocatoria('.$request->get('conv').')"', ['user' => '', 'token' => $request->get('token')]);
        
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual > 0) {
            
            //Validar si existe un participante como persona natural, con id usuario innner usuario_perfil
            $user_current = json_decode($token_actual->user_current, true);
            
            //Busco si tiene el perfil de persona natural
            $usuario_perfil_pn = Usuariosperfiles::findFirst("usuario=".$user_current["id"]." AND perfil = 6");
            if(!isset($usuario_perfil_pn->id))
            {
                //Busco si tiene el perfil de jurado
                $usuario_perfil_pn = Usuariosperfiles::findFirst("usuario=".$user_current["id"]." AND perfil = 17");
                if(!isset($usuario_perfil_pn->id))
                {
                    $usuario_perfil_pn=new Usuariosperfiles();
                }                                
            }
            
            //Si existe el usuario perfil como pn o jurado
            $participante = new Participantes();
            if (isset($usuario_perfil_pn->id)) {
                $participante = Participantes::findFirst("usuario_perfil=".$usuario_perfil_pn->id." AND tipo='Inicial' AND active=TRUE");
            }
            
            //Asigno siempre el correo electronico del usuario al participante
            if(!isset($participante->correo_electronico)){
                $participante->correo_electronico=$user_current["username"];           
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
            
            //Registro la accion en el log de convocatorias
            $logger->info('"token":"{token}","user":"{user}","message":"Retorno el participante pn en la convocatoria('.$request->get('conv').')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
            $logger->close();
            
            //Retorno el array
            echo json_encode($array);
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco"', ['user' => "", 'token' => $request->get('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo buscar_participante ' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->get('token')]);
        $logger->close();
        echo "error_metodo";
    }
}
);

//Creo el participante relacionando al padre
//Creo la propuesta asociandola al hijo participante
$app->post('/nuevo_participante', function () use ($app, $config,$logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();
        
    try {
        
        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa a crear el participante pn en la convocatoria('.$request->get('conv').')"', ['user' => '', 'token' => $request->get('token')]);
                
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

            //Consulto el usuario actual
            $user_current = json_decode($token_actual->user_current, true);
                
            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                                                
                //Trae los datos del formulario por post
                $post = $app->request->getPost();
                
                //Consulto si existe el usuario perfil
                $usuario_perfil = Usuariosperfiles::findFirst("usuario=".$user_current["id"]." AND perfil=6");
                
                //Verifico si existe, con el fin de crearlo
                if(!isset($usuario_perfil->id))
                {
                    $usuario_perfil = new Usuariosperfiles();
                    $usuario_perfil->usuario = $user_current["id"];
                    $usuario_perfil->perfil = 6;                    
                    if ($usuario_perfil->save($usuario_perfil) === false) {
                        echo "error_usuario_perfil";
                    }                     
                }
                
                //Consulto los usuarios perfil del jurado y persona natural
                $array_usuario_perfil = Usuariosperfiles::find("usuario=".$user_current["id"]." AND perfil IN (6,17)");
                $id_usuarios_perfiles="";
                foreach ($array_usuario_perfil as $aup) {
                    $id_usuarios_perfiles=$id_usuarios_perfiles.$aup->id.",";
                }
                $id_usuarios_perfiles = substr($id_usuarios_perfiles, 0, -1);
                
                //Consulto si existe partipantes que tengan el mismo numero y tipo de documento que sean diferentes a su perfil de persona natutal o jurado
                $participante_verificado = Participantes::find("usuario_perfil NOT IN (".$id_usuarios_perfiles.") AND numero_documento='".$post["numero_documento"]."' AND tipo_documento =".$post["tipo_documento"]);
                
                if(count($participante_verificado)>0)
                {
                    echo "participante_existente";
                }
                else
                {
                    //Busco si tiene el perfil de persona natural
                    $usuario_perfil_pn = Usuariosperfiles::findFirst("usuario=".$user_current["id"]." AND perfil = 6");
            
                    //Consulto si existe el participante pn inicial
                    $participante_inicial = Participantes::findFirst("usuario_perfil=".$usuario_perfil_pn->id." AND tipo='Inicial' AND active=TRUE");
                    
                    //Valido si existe el inicial                    
                    if(isset($participante_inicial->id)){
                        $sql_participante_propuesta="   SELECT 
                                                                pn.* 
                                                        FROM Propuestas AS p
                                                            INNER JOIN Participantes AS pn ON pn.id=p.participante AND pn.tipo='Participante'
                                                        WHERE
                                                        p.convocatoria=".$request->get('conv')." AND pn.usuario_perfil=".$usuario_perfil_pn->id.";";
                        
                        $participante_hijo = $app->modelsManager->executeQuery($sql_participante_propuesta);
                        
                    }
                    else
                    {
                        //Creo el objeto del participante inicial de persona natural
                        $participante_inicial = new Participantes();
                        $participante_inicial->creado_por = $user_current["id"];
                        $participante_inicial->fecha_creacion = date("Y-m-d H:i:s");                        
                        $participante_inicial->usuario_perfil = $usuario_perfil->id;
                        $participante_inicial->tipo = "Inicial";
                        $participante_inicial->active = TRUE;                        
                        if ($participante_inicial->save($post) === false) {
                            //Registro la accion en el log de convocatorias
                            $logger->error('"token":"{token}","user":"{user}","message":"No creo el participante pn inicial en la convocatoria('.$request->get('conv').')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                            $logger->close();
                            echo "error_inicial";
                        }
                        else 
                        {
                            //Registro la accion en el log de convocatorias
                            $logger->info('"token":"{token}","user":"{user}","message":"Creo el participante pn inicial en la convocatoria('.$request->get('conv').')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                            
                            //Creo el objeto del participante hijo de persona natural
                            $participante_hijo = new Participantes();
                            $participante_hijo->creado_por = $user_current["id"];
                            $participante_hijo->fecha_creacion = date("Y-m-d H:i:s");                            
                            $participante_hijo->usuario_perfil = $usuario_perfil->id;
                            $participante_hijo->tipo = "Participante";
                            $participante_hijo->participante_padre = $participante_inicial->id;
                            $participante_hijo->active = TRUE;                         
                            if ($participante_hijo->save($post) === false) {
                                //Registro la accion en el log de convocatorias
                                $logger->error('"token":"{token}","user":"{user}","message":"No creo el participante pn hijo en la convocatoria('.$request->get('conv').')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                                $logger->close();
                                echo "error_hijo";
                            }
                            else
                            {
                                //Creo la propuesta con estado registrada y asociada al hijo del inicial
                                $propuesta = new Propuestas();
                                $propuesta->estado=7;
                                $propuesta->participante=$participante_hijo->id;
                                $propuesta->convocatoria=$request->get('conv');
                                $propuesta->active = TRUE;
                                $propuesta->creado_por = $user_current["id"];
                                $propuesta->fecha_creacion = date("Y-m-d H:i:s");   
                                if ($participante_hijo->save($post) === false) {
                                    //Registro la accion en el log de convocatorias
                                    $logger->error('"token":"{token}","user":"{user}","message":"No creo la propuesta en la convocatoria('.$request->get('conv').')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                                    $logger->close();
                                    echo "error_propuesta";
                                }
                                else
                                {
                                    echo $participante_hijo->id;
                                }                                
                            }
                        }
                    }
                    
                    
                    
                    
                    
                    
                    
                    
                    
                    
                    
                    
                    
                    
                    
                    
                    
                    
                    
                    
                    
                    
                    
                    
                    
                    //Valido si existe para editar o crear
                    if(is_numeric($post["id"]))
                    {
                        $participante = Participantes::findFirst($post["id"]);
                        $post["actualizado_por"] = $user_current["id"];
                        $post["fecha_actualizacion"] = date("Y-m-d H:i:s");
                    }
                    else
                    {
                        //Creo el objeto del particpante de persona natural
                        $participante = new Participantes();
                        $participante->creado_por = $user_current["id"];
                        $participante->fecha_creacion = date("Y-m-d H:i:s");                        
                        $participante->usuario_perfil = $usuario_perfil->id;
                        $participante->tipo = "Inicial";
                        $participante->active = TRUE;
                    }
                    
                    if ($participante->save($post) === false) {
                        echo "error";
                    }
                    else 
                    {
                        echo $participante->id;
                    }
                    
                }
                
            } else {
                //Registro la accion en el log de convocatorias
                $logger->error('"token":"{token}","user":"{user}","message":"Acceso denegado', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                $logger->close();            
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco"', ['user' => "", 'token' => $request->get('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo buscar_participante ' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->get('token')]);
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