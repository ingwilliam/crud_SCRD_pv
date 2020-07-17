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

// Recupera todos las modalidades dependiendo el programa
$app->get('/select', function () use ($app) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {
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
$app->post('/new', function () use ($app, $config, $logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    //Consulto si al menos hay un token
    $token_actual = $tokens->verificar_token($request->getPost('token'));
    
    //Consulto el usuario actual
    $user_current = json_decode($token_actual->user_current, true);    
    try {
        
        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {
            
            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->getPost('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {                

                //Trae los datos del formulario por post
                $post = $app->request->getPost();

                //Consulto los usuarios perfil del jurado y persona natural
                $array_usuario_perfil = Usuariosperfiles::find("usuario=" . $user_current["id"] . " AND perfil IN (6,17,8)");
                $id_usuarios_perfiles = "";
                foreach ($array_usuario_perfil as $aup) {
                    $id_usuarios_perfiles = $id_usuarios_perfiles . $aup->id . ",";
                }
                $id_usuarios_perfiles = substr($id_usuarios_perfiles, 0, -1);
                
                
                $not_in_usuario_perfil="";
                if($id_usuarios_perfiles!="")
                {
                    $not_in_usuario_perfil="usuario_perfil NOT IN (" . $id_usuarios_perfiles . ") AND ";
                }
                                
                //Consulto si existe partipantes que tengan el mismo numero y tipo de documento que sean diferentes a su perfil de persona natutal o jurado
                $participante_verificado = Participantes::find($not_in_usuario_perfil."numero_documento='" . $post["numero_documento"] . "' AND tipo_documento =" . $post["tipo_documento"]." AND tipo='Inicial'");
                if (count($participante_verificado) > 0) {
                    
                    $correos="";
                    foreach ($participante_verificado as $participante_correo) {
                        $correos = $correos . $participante_correo->correo_electronico . " ,";
                    }
                    $correos = substr($correos, 0, -1);
                    
                    
                    //Registro la accion en el log de convocatorias           
                    $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Personasnaturales en el método new, el participante '.$post["numero_documento"].' ya existe en la base de datos"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                    $logger->close();                 
                    $array_respuesta["error"]=6;
                    $array_respuesta["mensaje"]="Error el participante que intenta ingresar ya se encuentra registrado en la base de datos con el correo electrónico (".$correos."), comuníquese con la mesa de ayuda convocatorias@scrd.gov.co";
                    echo json_encode($array_respuesta);                      
                } else {

                    //Consulto si existe el usuario perfil
                    $usuario_perfil = Usuariosperfiles::findFirst("usuario=" . $user_current["id"] . " AND perfil=6");

                    //Verifico si existe, con el fin de crearlo
                    if (!isset($usuario_perfil->id)) {
                        $usuario_perfil = new Usuariosperfiles();
                        $usuario_perfil->usuario = $user_current["id"];
                        $usuario_perfil->perfil = 6;
                        if ($usuario_perfil->save($usuario_perfil) === false) {
                            //Registro la accion en el log de convocatorias           
                            $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Personasnaturales en el método new, al crear el perfil como persona natural"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                            $logger->close();                 
                            $array_respuesta["error"]=5;
                            echo json_encode($array_respuesta);                                                        
                        }
                    }

                    //Valido si existe para editar o crear
                    if (is_numeric($post["id"])) {
                        $participante = Participantes::findFirst($post["id"]);
                        $post["actualizado_por"] = $user_current["id"];
                        $post["fecha_actualizacion"] = date("Y-m-d H:i:s");
                    } else {
                        //Creo el objeto del particpante de persona natural
                        $participante = new Participantes();
                        $participante->creado_por = $user_current["id"];
                        $participante->fecha_creacion = date("Y-m-d H:i:s");
                        $participante->usuario_perfil = $usuario_perfil->id;
                        $participante->tipo = "Inicial";
                        $participante->active = TRUE;
                    }

                    if ($participante->save($post) === false) {
                        //Registro la accion en el log de convocatorias           
                        $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Personasnaturales en el método new, al crear o editar"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                        $logger->close();                 
                        $array_respuesta["error"]=4;
                        echo json_encode($array_respuesta);                        
                    } else {
                        //Registro la accion en el log de convocatorias           
                        $logger->info('"token":"{token}","user":"{user}","message":"Se creo y/o edito la persona natural con éxito"', ['user' => $user_current["username"], 'token' => $request->get('token')]);                        
                        $logger->close();                 
                        $array_respuesta["error"]=0;
                        $array_respuesta["respuesta"]=$participante->id;
                        echo json_encode($array_respuesta);                          
                    }
                }
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Personasnaturales en el método new, acceso denegado"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                $logger->close();                 
                $array_respuesta["error"]=3;
                echo json_encode($array_respuesta);                            
            }
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Personasnaturales en el método new, token caduco"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
            $logger->close();                 
            $array_respuesta["error"]=2;
            echo json_encode($array_respuesta);
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Personasnaturales en el método new, ' . $ex->getMessage() . '"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
        $logger->close();
        $array_respuesta["error"]=1;
        echo json_encode($array_respuesta);        
    }
}
);

//Busca el registro
$app->get('/search', function () use ($app, $config,$logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    //Consulto si al menos hay un token
    $token_actual = $tokens->verificar_token($request->get('token'));
            
    //Validar si existe un participante como persona natural, con id usuario innner usuario_perfil
    $user_current = json_decode($token_actual->user_current, true);
            
    //Inicio el array
    $array_respuesta=array();
    
    try {        

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {            

            //Busco si tiene el perfil de persona natural
            $eliminar_id=false;
            $usuario_perfil_pn = Usuariosperfiles::findFirst("usuario=" . $user_current["id"] . " AND perfil = 6");
            if (!isset($usuario_perfil_pn->id)) {
                //Busco si tiene el perfil de jurado
                $usuario_perfil_pn = Usuariosperfiles::findFirst("usuario=" . $user_current["id"] . " AND perfil = 17");
                $eliminar_id=true;
                if (!isset($usuario_perfil_pn->id)) {
                    $usuario_perfil_pn = new Usuariosperfiles();
                }
            }

            //Si existe el usuario perfil como pn o jurado
            $participante = new Participantes();
            if (isset($usuario_perfil_pn->id)) {
                $participante = Participantes::findFirst("usuario_perfil=" . $usuario_perfil_pn->id . " AND tipo='Inicial' AND active=TRUE");
            }        

            //Asigno siempre el correo electronico del usuario al participante
            if (!isset($participante->correo_electronico)) {
                $participante->correo_electronico = $user_current["username"];
            }

            //Creo todos los array del registro
            //Creo los array de los select del formulario
            $array["tipo_documento"] = Tiposdocumentos::find("active=true AND id<>7");
            $array["sexo"] = Sexos::find("active=true");
            $array["orientacion_sexual"] = Orientacionessexuales::find("active=true");
            $array["identidad_genero"] = Identidadesgeneros::find("active=true");
            $array["grupo_etnico"] = Gruposetnicos::find("active=true");
            $array["discapacidades"] = Tiposdiscapacidades::find("active=true");            
                                    
            $array["pais_residencia_id"] = "";
            $array["departamento_residencia_id"] = "";
            $array["ciudad_residencia_id"] = "";
            
            $array["pais_nacimiento_id"] = "";
            $array["departamento_nacimiento_id"] = "";
            $array["ciudad_nacimiento_id"] = "";
            
            $array["departamentos"]=array();
            $array["ciudades"]=array();                        
            $array["barrios"] = array();
            
            $array["departamentos_nacimiento"]=array();
            $array["ciudades_nacimiento"]=array();
            
            if(isset($participante->id))
            {
                
                $array["pais_residencia_id"] = $participante->getCiudadesresidencia()->getDepartamentos()->getPaises()->id;
                $array["departamento_residencia_id"] = $participante->getCiudadesresidencia()->getDepartamentos()->id;                
                $array["ciudad_residencia_id"] = $participante->ciudad_residencia;
                
                
                if(isset($participante->ciudad_nacimiento))
                {
                    $array["pais_nacimiento_id"] = $participante->getCiudadesnacimiento()->getDepartamentos()->getPaises()->id;
                    $array["departamento_nacimiento_id"] = $participante->getCiudadesnacimiento()->getDepartamentos()->id;                
                    $array["ciudad_nacimiento_id"] = $participante->ciudad_nacimiento;
                    
                    $array["departamentos_nacimiento"]= Departamentos::find("active=true AND pais='".$participante->getCiudadesnacimiento()->getDepartamentos()->getPaises()->id."'");
                    $array["ciudades_nacimiento"]= Ciudades::find("active=true AND departamento='".$participante->getCiudadesnacimiento()->getDepartamentos()->id."'");
                }                                                     
                
                $array["departamentos"]= Departamentos::find("active=true AND pais='".$participante->getCiudadesresidencia()->getDepartamentos()->getPaises()->id."'");
                $array["ciudades"]= Ciudades::find("active=true AND departamento='".$participante->getCiudadesresidencia()->getDepartamentos()->id."'");
                        
                if(isset($participante->localidad_residencia))
                {
                    $array["barrios"] = Barrios::find("active=true AND localidad=" . $participante->localidad_residencia);                                            
                }
            
            }
            
            //Elimino el id si se importa de jurados
            if($eliminar_id)
            {
                $participante->id=null;
            }

            $array["participante"] = $participante;

            $tabla_maestra = Tablasmaestras::find("active=true AND nombre='estrato'");
            $array["estrato"] = explode(",", $tabla_maestra[0]->valor);            
            
            //Registro la accion en el log de convocatorias           
            $logger->info('"token":"{token}","user":"{user}","message":"Ingreso al formulario de persona natural"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
            $logger->close();
            
            //Retorno el array
            $array_respuesta["error"]=0;
            $array_respuesta["respuesta"]=$array;
            echo json_encode($array_respuesta);
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Personasnaturales en el método search, token caduco"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
            $logger->close();                 
            $array_respuesta["error"]=2;
            echo json_encode($array_respuesta);
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Personasnaturales en el método search, ' . $ex->getMessage() . '"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
        $logger->close();
        $array_respuesta["error"]=1;
        echo json_encode($array_respuesta);
    }
}
);

//Metodo que consulta el participante, con el cual va a registar la propuesta
//Se realiza la busqueda del participante
//Si no existe en inicial lo enviamos a crear el perfil
//Si existe el participante asociado a la propuesta se retorna
$app->get('/buscar_participante', function () use ($app, $config, $logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();
    try {

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa a buscar el participante pn en la convocatoria(' . $request->get('conv') . ')"', ['user' => '', 'token' => $request->get('token')]);

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
                
                //Busco si tiene el perfil de persona natural
                $usuario_perfil_pn = Usuariosperfiles::findFirst("usuario=" . $user_current["id"] . " AND perfil = 6");

                //Si existe el usuario perfil como pn
                $participante = new Participantes();
                if (isset($usuario_perfil_pn->id)) {
                    $participante = Participantes::findFirst("usuario_perfil=" . $usuario_perfil_pn->id . " AND tipo='Inicial' AND active=TRUE");

                    //Si existe el participante inicial con el perfil de pn
                    if (isset($participante->id)) {

                        //Valido si existe el codigo de la propuesta
                        //De lo contratio creo el participante del cual depende del inicial
                        //Creo la propuesta asociando el participante creado
                        if (is_numeric($request->get('p')) AND $request->get('p')!=0) {
                            //Consulto la propuesta solicitada
                            $conditions = ['id' => $request->get('p'), 'active' => true];
                            $propuesta = Propuestas::findFirst(([
                                        'conditions' => 'id=:id: AND active=:active:',
                                        'bind' => $conditions,
                            ]));

                            if (isset($propuesta->id)) {
                                $array["participante"] = $propuesta->getParticipantes();
                                $participante_hijo_propuesta= $propuesta->getParticipantes();
                            }
                            else
                            {
                                //Registro la accion en el log de convocatorias
                                $logger->error('"token":"{token}","user":"{user}","message":"Error al crear el participante PN asociado que se asocia a la propuesta."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                                $logger->close();
                                echo "error_participante_propuesta";
                                exit;
                            }

                            //Creo los array de los select del formulario
                            $array["estado"] = $propuesta->estado;
                            $array["tipo_documento"] = Tiposdocumentos::find("active=true");
                            $array["sexo"] = Sexos::find("active=true");
                            $array["orientacion_sexual"] = Orientacionessexuales::find("active=true");
                            $array["identidad_genero"] = Identidadesgeneros::find("active=true");
                            $array["grupo_etnico"] = Gruposetnicos::find("active=true");
                            $array["discapacidades"] = Tiposdiscapacidades::find("active=true");     
                            
                            $array["pais_residencia_id"] = $propuesta->getParticipantes()->getCiudadesresidencia()->getDepartamentos()->getPaises()->id;
                            $array["ciudad_residencia_id"] = $propuesta->getParticipantes()->ciudad_residencia;
                            $array["departamento_residencia_id"] = $propuesta->getParticipantes()->getCiudadesresidencia()->getDepartamentos()->id;                            

                            $array["departamentos_nacimiento"]=array();
                            $array["ciudades_nacimiento"]=array();
                            
                            if(isset($propuesta->getParticipantes()->ciudad_nacimiento))
                            {
                                $array["pais_nacimiento_id"] = $propuesta->getParticipantes()->getCiudadesnacimiento()->getDepartamentos()->getPaises()->id;
                                $array["departamento_nacimiento_id"] = $propuesta->getParticipantes()->getCiudadesnacimiento()->getDepartamentos()->id;                
                                $array["ciudad_nacimiento_id"] = $propuesta->getParticipantes()->ciudad_nacimiento;
                                
                                $array["departamentos_nacimiento"]= Departamentos::find("active=true AND pais='".$propuesta->getParticipantes()->getCiudadesnacimiento()->getDepartamentos()->getPaises()->id."'");
                                $array["ciudades_nacimiento"]= Ciudades::find("active=true AND departamento='".$propuesta->getParticipantes()->getCiudadesnacimiento()->getDepartamentos()->id."'");
                            }
                
                            $array["departamentos"]= Departamentos::find("active=true AND pais='".$propuesta->getParticipantes()->getCiudadesresidencia()->getDepartamentos()->getPaises()->id."'");
                            $array["ciudades"]= Ciudades::find("active=true AND departamento='".$propuesta->getParticipantes()->getCiudadesresidencia()->getDepartamentos()->id."'");
                             
                            $array["barrios"]=array();
                            if(isset($propuesta->getParticipantes()->localidad_residencia))
                            {
                                $array["barrios"] = Barrios::find("active=true AND localidad=" . $propuesta->getParticipantes()->localidad_residencia);
                            }
                            
                            $tabla_maestra = Tablasmaestras::find("active=true AND nombre='estrato'");
                            $array["estrato"] = explode(",", $tabla_maestra[0]->valor);

                            //Registro la accion en el log de convocatorias
                            $logger->info('"token":"{token}","user":"{user}","message":"Retorno el participante pn en la convocatoria(' . $request->get('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                            $logger->close();

                            //Retorno el array
                            echo json_encode($array);

                        } else {
                            //Registro la accion en el log de convocatorias
                            $logger->error('"token":"{token}","user":"{user}","message":"Error al crear el participante PN asociado que se asocia a la propuesta."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                            $logger->close();
                            echo "error_participante_propuesta";
                            exit;
                        }
                    } else {
                        //Registro la accion en el log de convocatorias
                        $logger->error('"token":"{token}","user":"{user}","message":"Para poder inscribir la propuesta debe crear el perfil de persona natural."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                        $logger->close();
                        echo "crear_perfil";
                        exit;
                    }
                } else {
                    //Registro la accion en el log de convocatorias
                    $logger->error('"token":"{token}","user":"{user}","message":"Para poder inscribir la propuesta debe crear el perfil de persona natural."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                    $logger->close();
                    echo "crear_perfil";
                    exit;
                }
            } else {
                //Registro la accion en el log de convocatorias
                $logger->error('"token":"{token}","user":"{user}","message":"Acceso denegado buscar_participante"', ['user' => "", 'token' => $request->get('token')]);
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

//Metodo que consulta el participante, con el cual va a registar la propuesta
//Se realiza la busqueda del participante
//Si no existe en inicial lo enviamos a crear el perfil
//Si existe el participante asociado a la propuesta se retorna
$app->get('/crear_propuesta_pn', function () use ($app, $config, $logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();
    try {

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa a crear_propuesta_pn en la convocatoria(' . $request->get('conv') . ')"', ['user' => '', 'token' => $request->get('token')]);

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
                
                //Busco si tiene el perfil de persona natural
                $usuario_perfil_pn = Usuariosperfiles::findFirst("usuario=" . $user_current["id"] . " AND perfil = 6");

                //Si existe el usuario perfil como pn
                $participante = new Participantes();
                if (isset($usuario_perfil_pn->id)) {
                    $participante = Participantes::findFirst("usuario_perfil=" . $usuario_perfil_pn->id . " AND tipo='Inicial' AND active=TRUE");

                    //Si existe el participante inicial con el perfil de pn
                    if (isset($participante->id)) {

                        //Valido si existe el codigo de la propuesta
                        //De lo contratio creo el participante del cual depende del inicial
                        //Creo la propuesta asociando el participante creado
                        if (is_numeric($request->get('p')) AND $request->get('p')!=0) {
                            //Consulto la propuesta solicitada
                            $conditions = ['id' => $request->get('p'), 'active' => true];
                            $propuesta = Propuestas::findFirst(([
                                        'conditions' => 'id=:id: AND active=:active:',
                                        'bind' => $conditions,
                            ]));

                            if (isset($propuesta->id)) {
                                //Registro la accion en el log de convocatorias
                                $logger->info('"token":"{token}","user":"{user}","message":"Retorno la propuesta para el participante pn en la convocatoria(' . $request->get('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                                $logger->close();
                                echo $propuesta->id;
                                exit;
                            }
                            else
                            {
                                //Registro la accion en el log de convocatorias
                                $logger->error('"token":"{token}","user":"{user}","message":"Error al crear el participante PN asociado que se asocia a la propuesta."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                                $logger->close();
                                echo "error_participante_propuesta";
                                exit;
                            }
                        } else {

                            $id_participante_padre = $participante->id;
                            //Creo el participante hijo
                            $participante_hijo_propuesta = $participante;
                            $participante_hijo_propuesta->id = null;
                            $participante_hijo_propuesta->creado_por = $user_current["id"];
                            $participante_hijo_propuesta->fecha_creacion = date("Y-m-d H:i:s");
                            $participante_hijo_propuesta->participante_padre = $id_participante_padre;
                            $participante_hijo_propuesta->tipo = "Participante";
                            $participante_hijo_propuesta->active = TRUE;
                            $participante_hijo_propuesta->terminos_condiciones = TRUE;
                            if ($participante_hijo_propuesta->save() === false) {
                                //Registro la accion en el log de convocatorias
                                $logger->error('"token":"{token}","user":"{user}","message":"Error al crear el participante PN asociado que se asocia a la propuesta."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                                $logger->close();
                                echo "error_participante_propuesta";
                                exit;
                            } else {
                                //Registro la accion en el log de convocatorias
                                $logger->info('"token":"{token}","user":"{user}","message":"Se creo el participante pn para la propuesta que se registro a la convocatoria(' . $request->get('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);

                                //Creo la propuesta asociada al participante hijo
                                $propuesta = new Propuestas();
                                $propuesta->creado_por = $user_current["id"];
                                $propuesta->fecha_creacion = date("Y-m-d H:i:s");
                                $propuesta->participante = $participante_hijo_propuesta->id;
                                $propuesta->convocatoria = $request->get('conv');
                                $propuesta->estado = 7;
                                $propuesta->active = TRUE;
                                if ($propuesta->save() === false) {
                                    //Registro la accion en el log de convocatorias
                                    $logger->error('"token":"{token}","user":"{user}","message":"Error al crear la propuesta para el participante como PN."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                                    $logger->close();
                                    echo "error_participante_propuesta";
                                    exit;
                                } else {

                                    $chemistry_alfresco = new ChemistryPV($config->alfresco->api, $config->alfresco->username, $config->alfresco->password);

                                    //Se crea la carpeta principal de la propuesta en la convocatoria
                                    if ($chemistry_alfresco->newFolder("/Sites/convocatorias/" . $request->get('conv') . "/propuestas/", $propuesta->id) != "ok") {
                                        //Registro la accion en el log de convocatorias
                                        $logger->error('"token":"{token}","user":"{user}","message":"Error al crear la carpeta de la propuesta para el participante como PN."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                                    }

                                    //Registro la accion en el log de convocatorias
                                    $logger->info('"token":"{token}","user":"{user}","message":"Se creo la propuesta para la convocatoria(' . $request->get('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                                    $logger->close();
                                    echo $propuesta->id;
                                    exit;
                                }
                            }

                        }

                    } else {
                        //Registro la accion en el log de convocatorias
                        $logger->error('"token":"{token}","user":"{user}","message":"Para poder inscribir la propuesta debe crear el perfil de persona natural."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                        $logger->close();
                        echo "crear_perfil";
                        exit;
                    }
                } else {
                    //Registro la accion en el log de convocatorias
                    $logger->error('"token":"{token}","user":"{user}","message":"Para poder inscribir la propuesta debe crear el perfil de persona natural."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                    $logger->close();
                    echo "crear_perfil";
                    exit;
                }
            } else {
                //Registro la accion en el log de convocatorias
                $logger->error('"token":"{token}","user":"{user}","message":"Acceso denegado crear_propuesta_pn"', ['user' => "", 'token' => $request->get('token')]);
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
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo crear_propuesta_pn ' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->get('token')]);
        $logger->close();
        echo "error_metodo";
    }
}
);

//Edito el participante hijo ya relacionado con la propuesta
$app->post('/editar_participante', function () use ($app, $config, $logger) {

    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPost('token'));

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa a editar el participante hijo pn en la convocatoria(' . $request->get('conv') . ')"', ['user' => '', 'token' => $request->get('token')]);

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->getPost('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                
                //Trae los datos del formulario por post
                $post = $app->request->getPost();

                //Consulto si existe el usuario perfil
                $usuario_perfil = Usuariosperfiles::findFirst("usuario=" . $user_current["id"] . " AND perfil=6");

                //Verifico si existe, con el fin de crearlo
                if (!isset($usuario_perfil->id)) {
                    $usuario_perfil = new Usuariosperfiles();
                    $usuario_perfil->usuario = $user_current["id"];
                    $usuario_perfil->perfil = 6;
                    if ($usuario_perfil->save($usuario_perfil) === false) {
                        //Registro la accion en el log de convocatorias
                        $logger->error('"token":"{token}","user":"{user}","message":"Error al crear el perfil del usuario como persona natural"', ['user' => "", 'token' => $request->get('token')]);
                        $logger->close();
                        echo "error_usuario_perfil";
                    }
                }

                //Consulto el participante actual
                $participante = Participantes::findFirst($post["id"]);

                //if (count($participante_verificado) > 0) {

                if ($participante->tipo != "Participante") {
                    $logger->error('"token":"{token}","user":"{user}","message":"Acceso denegado editar_participante debido a que no esta creado el participante"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                    $logger->close();
                    echo "no_existente_participante";
                } else {

                    $post["actualizado_por"] = $user_current["id"];
                    $post["fecha_actualizacion"] = date("Y-m-d H:i:s");

                    if ($participante->save($post) === false) {
                        $logger->error('"token":"{token}","user":"{user}","message":"Se creo un error al editar el participante pn hijo."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                        $logger->close();
                        echo "error";
                    } else {
                        //Registro la accion en el log de convocatorias
                        $logger->info('"token":"{token}","user":"{user}","message":"Se edito el participante pn hijo en la convocatoria(' . $request->get('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                        $logger->close();
                        echo $participante->id;
                    }
                }
            } else {
                //Registro la accion en el log de convocatorias
                $logger->error('"token":"{token}","user":"{user}","message":"Acceso denegado editar_participante"', ['user' => "", 'token' => $request->get('token')]);
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
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo editar_participante ' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->get('token')]);
        $logger->close();
        echo "error_metodo";
    }
}
);


//Metodo el cual carga el formulario del integrante
//Verifica que que tenga creada la propuestas
$app->get('/formulario_integrante', function () use ($app, $config, $logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al metodo formulario_integrante como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => '', 'token' => $request->get('token')]);

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->get('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                
                //Busco si tiene el perfil asociado de acuerdo al parametro
                if ($request->get('m') == "pn") {
                    $tipo_participante = "Persona Natural";
                    $usuario_perfil = Usuariosperfiles::findFirst("usuario=" . $user_current["id"] . " AND perfil = 6");
                }
                if ($request->get('m') == "pj") {
                    $tipo_participante = "Persona Jurídica";
                    $usuario_perfil = Usuariosperfiles::findFirst("usuario=" . $user_current["id"] . " AND perfil = 7");
                }
                if ($request->get('m') == "agr") {
                    $tipo_participante = "Agrupaciones";
                    $usuario_perfil = Usuariosperfiles::findFirst("usuario=" . $user_current["id"] . " AND perfil = 8");
                }

                if (isset($usuario_perfil->id)) {

                    //Consulto el participante inicial
                    $participante = Participantes::findFirst("usuario_perfil=" . $usuario_perfil->id . " AND tipo='Inicial' AND active=TRUE");

                    //Si existe el participante inicial con el perfil de acuerdo al parametro
                    if (isset($participante->id)) {

                        //Valido si existe el codigo de la propuesta
                        //De lo contratio creo el participante del cual depende del inicial
                        //Creo la propuesta asociando el participante creado
                        if (is_numeric($request->get('p')) AND $request->get('p')!=0) {
                            //Consulto la propuesta solicitada
                            $conditions = ['id' => $request->get('p'), 'active' => true];
                            $propuesta = Propuestas::findFirst(([
                                        'conditions' => 'id=:id: AND active=:active:',
                                        'bind' => $conditions,
                            ]));

                            if (isset($propuesta->id)) {

                                //Creo el array de la propuesta
                                $array = array();
                                //Valido si se habilita propuesta por derecho de petición
                                $array["estado"] = $propuesta->estado;
                                if($propuesta->habilitar)
                                {
                                    $fecha_actual = strtotime(date("Y-m-d H:i:s"), time());
                                    $habilitar_fecha_inicio = strtotime($propuesta->habilitar_fecha_inicio, time());
                                    $habilitar_fecha_fin = strtotime($propuesta->habilitar_fecha_fin, time());
                                    if (($fecha_actual >= $habilitar_fecha_inicio) && ($fecha_actual <= $habilitar_fecha_fin))
                                    {
                                        $array["estado"] = 7;
                                    }
                                }

                                $array["formulario"]["propuesta"] = $propuesta->id;
                                $array["formulario"]["participante"] = $propuesta->participante;
                                //Creo los array de los select del formulario
                                $array["tipo_documento"] = Tiposdocumentos::find("active=true");
                                $array["sexo"] = Sexos::find("active=true");
                                $array["orientacion_sexual"] = Orientacionessexuales::find("active=true");
                                $array["identidad_genero"] = Identidadesgeneros::find("active=true");
                                $array["grupo_etnico"] = Gruposetnicos::find("active=true");
                                $tabla_maestra = Tablasmaestras::find("active=true AND nombre='estrato'");
                                $array["estrato"] = explode(",", $tabla_maestra[0]->valor);
                                $array["discapacidades"] = Tiposdiscapacidades::find("active=true");            

                                //Registro la accion en el log de convocatorias
                                $logger->info('"token":"{token}","user":"{user}","message":"Retorna la información para el perfil como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . '), en el metodo formulario_integrante"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                                $logger->close();

                                //Retorno el array
                                echo json_encode($array);
                            }
                            else
                            {
                                //Registro la accion en el log de convocatorias
                                $logger->error('"token":"{token}","user":"{user}","message":"Debe crear la propuesta para el perfil como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . '), en el metodo formulario_integrante"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                                $logger->close();
                                echo "crear_propuesta";
                                exit;
                            }

                        } else {
                            //Registro la accion en el log de convocatorias
                            $logger->error('"token":"{token}","user":"{user}","message":"Error cod de la propuesta no es valido."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                            $logger->close();
                            echo "error_cod_propuesta";
                            exit;
                        }
                    } else {
                        //Busco si tiene el perfil asociado de acuerdo al parametro
                        if ($request->get('m') == "pn") {
                            //Registro la accion en el log de convocatorias
                            $logger->error('"token":"{token}","user":"{user}","message":"Debe crear el perfil como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . '), en el metodo formulario_integrante"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                            $logger->close();
                            echo "crear_perfil_pn";
                            exit;
                        }
                        if ($request->get('m') == "pj") {
                            //Registro la accion en el log de convocatorias
                            $logger->error('"token":"{token}","user":"{user}","message":"Debe crear el perfil como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . '), en el metodo formulario_integrante"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                            $logger->close();
                            echo "crear_perfil_pj";
                            exit;
                        }
                        if ($request->get('m') == "agr") {
                            //Registro la accion en el log de convocatorias
                            $logger->error('"token":"{token}","user":"{user}","message":"Debe crear el perfil como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . '), en el metodo formulario_integrante"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                            $logger->close();
                            echo "crear_perfil_agr";
                            exit;
                        }
                    }
                } else {
                    //Busco si tiene el perfil asociado de acuerdo al parametro
                    if ($request->get('m') == "pn") {
                        //Registro la accion en el log de convocatorias
                        $logger->error('"token":"{token}","user":"{user}","message":"Debe crear el perfil como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . '), en el metodo formulario_integrante"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                        $logger->close();
                        echo "crear_perfil_pn";
                        exit;
                    }
                    if ($request->get('m') == "pj") {
                        //Registro la accion en el log de convocatorias
                        $logger->error('"token":"{token}","user":"{user}","message":"Debe crear el perfil como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . '), en el metodo formulario_integrante"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                        $logger->close();
                        echo "crear_perfil_pj";
                        exit;
                    }
                    if ($request->get('m') == "agr") {
                        //Registro la accion en el log de convocatorias
                        $logger->error('"token":"{token}","user":"{user}","message":"Debe crear el perfil como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . '), en el metodo formulario_integrante"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                        $logger->close();
                        echo "crear_perfil_agr";
                        exit;
                    }
                }
            } else {
                //Registro la accion en el log de convocatorias
                $logger->error('"token":"{token}","user":"{user}","message":"Acceso denegado en el metodo formulario_integrante como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => "", 'token' => $request->get('token')]);
                $logger->close();
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco en el metodo formulario_integrante como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => "", 'token' => $request->get('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo formulario_integrante como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ') ' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->get('token')]);
        $logger->close();
        echo "error_metodo";
    }
}
);

// Crear el inetegrante
$app->post('/crear_integrante', function () use ($app, $config, $logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPost('token'));

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al metodo crear_integrante como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => '', 'token' => $request->get('token')]);

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Consulto el usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->getPost('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {

                //Trae los datos del formulario por post
                $post = $app->request->getPost();

                //Validar que exite un representante
                $validar_representante=true;
                if( $post["representante"] == "true")
                {
                    //Valido si enviaron el id del participante
                    $validacion="";
                    if (is_numeric($post["id"])) {
                     $validacion= "id<>".$post["id"]." AND ";
                    }

                    $representante = Participantes::findFirst($validacion." participante_padre=".$post["participante"]." AND representante = true AND active IN (TRUE,FALSE)");
                    if($representante->id>0)
                    {
                        $validar_representante=false;
                    }
                }

                if($validar_representante)
                {
                    //Valido si existe para editar o crear
                    if (is_numeric($post["id"])) {
                        $participante = Participantes::findFirst($post["id"]);
                        $post["actualizado_por"] = $user_current["id"];
                        $post["fecha_actualizacion"] = date("Y-m-d H:i:s");
                    } else {
                        //Consulto el participante
                        $participante_padre = Participantes::findFirst($post["participante"]);

                        //Creo el objeto del particpante de persona natural
                        $participante = new Participantes();
                        $participante->creado_por = $user_current["id"];
                        $participante->fecha_creacion = date("Y-m-d H:i:s");
                        $participante->participante_padre = $post["participante"];
                        $participante->usuario_perfil = $participante_padre->usuario_perfil;
                        //$participante->tipo = "Integrante";
                        $participante->active = TRUE;
                    }

                    $post["representante"] = $post["representante"] === 'true'? true: false;

                    if ($participante->save($post) === false) {
                        //Registro la accion en el log de convocatorias
                        $logger->error('"token":"{token}","user":"{user}","message":"Error en el metodo crear_integrante como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                        $logger->close();
                        echo "error";
                    } else {
                        //Registro la accion en el log de convocatorias
                        $logger->info('"token":"{token}","user":"{user}","message":"Retorno en el metodo crear_integrante como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                        $logger->close();

                        echo $participante->id;
                    }
                }
                else
                {
                    //Registro la accion en el log de convocatorias
                    $logger->error('"token":"{token}","user":"{user}","message":"Ya existe el representante en el metodo crear_integrante como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                    $logger->close();
                    echo "error_representante";
                }
            } else {
                //Registro la accion en el log de convocatorias
                $logger->error('"token":"{token}","user":"{user}","message":"Acceso denegado en el metodo crear_integrante como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                $logger->close();
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco en el metodo crear_integrante como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => "", 'token' => $request->get('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo crear_integrante como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ') ' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->get('token')]);
        $logger->close();
        echo "error_metodo";
    }
}
);

// Carga los integrantes de las agrupaciones
$app->get('/cargar_tabla_integrantes', function () use ($app, $config, $logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al metodo cargar_tabla_integrantes como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => '', 'token' => $request->get('token')]);

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->get('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                
                //Consulto la propuesta solicitada
                $conditions = ['id' => $request->get('p'), 'active' => true];
                $propuesta = Propuestas::findFirst(([
                            'conditions' => 'id=:id: AND active=:active:',
                            'bind' => $conditions,
                ]));

                //Defino columnas para el orden desde la tabla html
                $columns = array(
                    0 => 'p.tipo_documento',
                    1 => 'p.numero_documento',
                    2 => 'p.primer_nombre',
                    3 => 'p.segundo_nombre',
                    4 => 'p.primer_apellido',
                    5 => 'p.segundo_apellido',
                    6 => 'p.rol',
                    7 => 'p.id',
                    8 => 'p.representante',
                );

                $where .= " INNER JOIN Tiposdocumentos AS td ON td.id=p.tipo_documento";
                $where .= " WHERE p.id <> " . $propuesta->participante . " AND p.participante_padre = " . $propuesta->participante . " AND tipo='" . $request->get('tipo') . "'";
                //Condiciones para la consulta

                if (!empty($request->get("search")['value'])) {
                    $where .= " AND ( UPPER(" . $columns[1] . ") LIKE '%" . strtoupper($request->get("search")['value']) . "%' ";
                    $where .= " OR UPPER(" . $columns[2] . ") LIKE '%" . strtoupper($request->get("search")['value']) . "%' ";
                    $where .= " OR UPPER(" . $columns[3] . ") LIKE '%" . strtoupper($request->get("search")['value']) . "%' ";
                    $where .= " OR UPPER(" . $columns[4] . ") LIKE '%" . strtoupper($request->get("search")['value']) . "%' ";
                    $where .= " OR UPPER(" . $columns[5] . ") LIKE '%" . strtoupper($request->get("search")['value']) . "%' ";
                    $where .= " OR UPPER(" . $columns[6] . ") LIKE '%" . strtoupper($request->get("search")['value']) . "%' )";
                }

                //Defino el sql del total y el array de datos
                $sqlTot = "SELECT count(*) as total FROM Participantes AS p";
                $sqlRec = "SELECT td.descripcion AS tipo_documento," . $columns[1] . "," . $columns[2] . "," . $columns[3] . " ," . $columns[4] . "," . $columns[5] . "," . $columns[6] . "," . $columns[7] . "," . $columns[8] . ",concat('<button title=\"',p.id,'\" type=\"button\" class=\"btn btn-warning cargar_formulario\" data-toggle=\"modal\" data-target=\"#nuevo_evento\"><span class=\"glyphicon glyphicon-edit\"></span></button>') as acciones , concat('<input title=\"',p.id,'\" type=\"checkbox\" class=\"check_activar_',p.active,' activar_categoria\" />') as activar_registro FROM Participantes AS p";

                //concarnar search sql if value exist
                if (isset($where) && $where != '') {

                    $sqlTot .= $where;
                    $sqlRec .= $where;
                }

                //Concarno el orden y el limit para el paginador
                $sqlRec .= " ORDER BY p.representante DESC  LIMIT " . $request->get('length') . " offset " . $request->get('start') . " ";

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
                $logger->error('"token":"{token}","user":"{user}","message":"Acceso denegado en el metodo cargar_tabla_integrantes como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => "", 'token' => $request->get('token')]);
                $logger->close();
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco en el metodo cargar_tabla_integrantes como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => "", 'token' => $request->get('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo cargar_tabla_integrantes como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ') ' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->get('token')]);
        $logger->close();
        echo "error_metodo";
    }
}
);

//Busca el registro
$app->get('/editar_integrante', function () use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {
            //Si existe consulto la convocatoria
            if ($request->get('id')) {
                $participante = Participantes::findFirst($request->get('id'));
            } else {
                $participante = new Participantes();
            }
            //Creo el array de la propuesta
            $array = array();

            //Creo todos los array del registro
            $array["participante"] = $participante;

            //Creo los array de los select del formulario
            $array["pais_residencia_id"] = "";
            $array["departamento_residencia_id"] = "";
            $array["ciudad_residencia_id"] = "";
            
            $array["pais_nacimiento_id"] = "";
            $array["departamento_nacimiento_id"] = "";
            $array["ciudad_nacimiento_id"] = "";
            
            $array["departamentos"]=array();
            $array["ciudades"]=array();                        
            $array["barrios"] = array();
            
            $array["departamentos_nacimiento"]=array();
            $array["ciudades_nacimiento"]=array();
            
            if(isset($participante->id))
            {
                $array["pais_residencia_id"] = $participante->getCiudadesresidencia()->getDepartamentos()->getPaises()->id;
                $array["departamento_residencia_id"] = $participante->getCiudadesresidencia()->getDepartamentos()->id;                
                $array["ciudad_residencia_id"] = $participante->ciudad_residencia;
                                
                if(isset($participante->ciudad_nacimiento))
                {
                    $array["pais_nacimiento_id"] = $participante->getCiudadesnacimiento()->getDepartamentos()->getPaises()->id;
                    $array["departamento_nacimiento_id"] = $participante->getCiudadesnacimiento()->getDepartamentos()->id;                
                    $array["ciudad_nacimiento_id"] = $participante->ciudad_nacimiento;
                    
                    $array["departamentos_nacimiento"]= Departamentos::find("active=true AND pais='".$participante->getCiudadesnacimiento()->getDepartamentos()->getPaises()->id."'");
                    $array["ciudades_nacimiento"]= Ciudades::find("active=true AND departamento='".$participante->getCiudadesnacimiento()->getDepartamentos()->id."'");
                }                                                     
                
                $array["departamentos"]= Departamentos::find("active=true AND pais='".$participante->getCiudadesresidencia()->getDepartamentos()->getPaises()->id."'");
                $array["ciudades"]= Ciudades::find("active=true AND departamento='".$participante->getCiudadesresidencia()->getDepartamentos()->id."'");
                        
                if(isset($participante->localidad_residencia))
                {
                    $array["barrios"] = Barrios::find("active=true AND localidad=" . $participante->localidad_residencia);                                            
                }
            
            }            
            
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


// Eliminar registro
$app->delete('/eliminar_integrante/{id:[0-9]+}', function ($id) use ($app, $config) {
    try {
        //Instancio los objetos que se van a manejar
        $request = new Request();
        $tokens = new Tokens();
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->getPut('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                // Consultar el usuario que se esta editando
                $user = Participantes::findFirst(json_decode($id));
                if($request->getPut('active')=='false')
                {
                    $user->active = FALSE;
                }
                if($request->getPut('active')=='true')
                {
                    $user->active = true;
                }
                
                if ($user->save($user) === false) {
                    echo "error";
                } else {
                    echo "ok";
                }
            } else {
                echo "acceso_denegado";
            }

            exit;
        } else {
            echo "error";
        }
    } catch (Exception $ex) {
        echo "error_metodo";
    }
});


try {
    // Gestionar la consulta
    $app->handle();
} catch (\Exception $e) {
    echo 'Excepción: ', $e->getMessage();
}
?>

