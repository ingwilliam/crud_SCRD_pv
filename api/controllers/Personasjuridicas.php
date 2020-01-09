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
$app->post('/new', function () use ($app, $config, $logger) {
    
    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();
        
    try {        
        
        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa a crear perfil persona jurídica"',['user' => '','token'=>$request->get('token')]);
        
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
                $usuario_perfil = Usuariosperfiles::findFirst("usuario=".$user_current["id"]." AND perfil=7");
                
                //Verifico si existe, con el fin de crearlo
                if(!isset($usuario_perfil->id))
                {
                    $usuario_perfil = new Usuariosperfiles();
                    $usuario_perfil->usuario = $user_current["id"];
                    $usuario_perfil->perfil = 7;                    
                    if ($usuario_perfil->save($usuario_perfil) === false) {
                        //Registro la accion en el log de convocatorias           
                        $logger->error('"token":"{token}","user":"{user}","message":"Error al crear el perfil del usuario como persona jurídica"',['user' => "",'token'=>$request->get('token')]);
                        $logger->close();
                        echo "error_usuario_perfil";
                    }                     
                }
                
                //Consulto si existe partipantes que tengan el mismo numero y tipo de documento que sean diferentes a su perfil de persona juridica
                $participante_verificado = Participantes::find("usuario_perfil NOT IN (".$usuario_perfil->id.") AND numero_documento='".$post["numero_documento"]."' AND tipo_documento =".$post["tipo_documento"]);
                
                if(count($participante_verificado)>0)
                {
                    //Registro la accion en el log de convocatorias           
                    $logger->error('"token":"{token}","user":"{user}","message":"El participante ya existe en la base de datos '.$post["tipo_documento"].' '.$post["numero_documento"].'"',['user' => "",'token'=>$request->get('token')]);
                    $logger->close();
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
                        //Creo el objeto del particpante de Persona juridica
                        $participante = new Participantes();
                        $participante->creado_por = $user_current["id"];
                        $participante->fecha_creacion = date("Y-m-d H:i:s");
                        $participante->fecha_creacion = date("Y-m-d H:i:s");
                        $participante->usuario_perfil = $usuario_perfil->id;
                        $participante->tipo = "Inicial";
                        $participante->active = TRUE;                     
                    }
                    
                    if ($participante->save($post) === false) {
                        //Registro la accion en el log de convocatorias           
                        $logger->error('"token":"{token}","user":"{user}","message":"Error al crear el participante como persona jurídica"',['user' => "",'token'=>$request->get('token')]);
                        $logger->close();
                        echo "error";
                    }
                    else 
                    {
                        //Registro la accion en el log de convocatorias
                        $logger->info('"token":"{token}","user":"{user}","message":"Se crea la persona jurídica con éxito"',['user' => $user_current["username"],'token'=>$request->get('token')]);
                        $logger->close();
                        echo $participante->id;
                    }
                    
                }
                
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"Acceso denegado"',['user' => "",'token'=>$request->get('token')]);
                $logger->close();
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco"',['user' => "",'token'=>$request->get('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo'.$ex->getMessage().'"',['user' => "",'token'=>$request->get('token')]);
        $logger->close();        
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
            
            //Validar si existe un participante como Persona juridica, con id usuario innner usuario_perfil
            $user_current = json_decode($token_actual->user_current, true);
            
            //Busco si tiene el perfil de Persona juridica
            $usuario_perfil_pj = Usuariosperfiles::findFirst("usuario=".$user_current["id"]." AND perfil = 7");
            if(!isset($usuario_perfil_pj->id))
            {
                $usuario_perfil_pj=new Usuariosperfiles();                
            }
            
            //Si existe el usuario perfil como pj o jurado
            $participante = new Participantes();
            if (isset($usuario_perfil_pj->id)) {
                $participante = Participantes::findFirst("usuario_perfil=".$usuario_perfil_pj->id." AND tipo='Inicial' AND active=TRUE");
            }
            
            //Asigno siempre el correo electronico del usuario al participante
            if(!isset($participante->correo_electronico))
            {
                $participante->correo_electronico=$user_current["username"];           
            }
            
            //Creo todos los array del registro
            $array["participante"] = $participante;

            //Creo los array de los select del formulario
            $array["tipo_documento"]= Tiposdocumentos::find("active=true");            
            
            $array["barrio_residencia_name"] = $participante->getBarriosresidencia()->nombre;            
            $array["ciudad_residencia_name"] = $participante->getCiudadesresidencia()->nombre;
            
            $tabla_maestra= Tablasmaestras::find("active=true AND nombre='estrato'");            
            $array["estrato"] = explode(",", $tabla_maestra[0]->valor);
            
            $tabla_maestra= Tablasmaestras::find("active=true AND nombre='tipo_sede'");            
            $array["tipo_sede"] = explode(",", $tabla_maestra[0]->valor);
            
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
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa a buscar el participante pj en la convocatoria(' . $request->get('conv') . ')"', ['user' => '', 'token' => $request->get('token')]);

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

                //Busco si tiene el perfil de persona jurídica
                $usuario_perfil_pj = Usuariosperfiles::findFirst("usuario=" . $user_current["id"] . " AND perfil = 7");

                //Si existe el usuario perfil como pj
                $participante = new Participantes();
                if (isset($usuario_perfil_pj->id)) {
                    $participante = Participantes::findFirst("usuario_perfil=" . $usuario_perfil_pj->id . " AND tipo='Inicial' AND active=TRUE");

                    //Si existe el participante inicial con el perfil de pj 
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
                                $logger->error('"token":"{token}","user":"{user}","message":"Error al crear el participante PJ asociado que se asocia a la propuesta."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                                $logger->close();
                                echo "error_participante_propuesta";
                                exit;
                            }
                            
                            //Creo los array de los select del formulario
                            $array["estado"] = $propuesta->estado;
                            $array["tipo_documento"] = Tiposdocumentos::find("active=true");
                            $array["tipo_documento"]= Tiposdocumentos::find("active=true");            
                            $array["barrio_residencia_name"] = $participante_hijo_propuesta->getBarriosresidencia()->nombre;            
                            $array["ciudad_residencia_name"] = $participante_hijo_propuesta->getCiudadesresidencia()->nombre;

                            $tabla_maestra= Tablasmaestras::find("active=true AND nombre='estrato'");            
                            $array["estrato"] = explode(",", $tabla_maestra[0]->valor);

                            $tabla_maestra= Tablasmaestras::find("active=true AND nombre='tipo_sede'");            
                            $array["tipo_sede"] = explode(",", $tabla_maestra[0]->valor);

                            //Registro la accion en el log de convocatorias
                            $logger->info('"token":"{token}","user":"{user}","message":"Retorno el participante pj en la convocatoria(' . $request->get('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                            $logger->close();

                            //Retorno el array
                            echo json_encode($array);
                        
                        } else {
                            //Registro la accion en el log de convocatorias           
                            $logger->error('"token":"{token}","user":"{user}","message":"Error al crear el participante PJ asociado que se asocia a la propuesta."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                            $logger->close();
                            echo "error_participante_propuesta";
                            exit;                            
                        }                        
                    } else {
                        //Registro la accion en el log de convocatorias           
                        $logger->error('"token":"{token}","user":"{user}","message":"Para poder inscribir la propuesta debe crear el perfil de persona jurídica."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                        $logger->close();
                        echo "crear_perfil";
                        exit;
                    }
                } else {
                    //Registro la accion en el log de convocatorias           
                    $logger->error('"token":"{token}","user":"{user}","message":"Para poder inscribir la propuesta debe crear el perfil de persona jurídica."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
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
$app->get('/crear_propuesta_pj', function () use ($app, $config, $logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();
    try {

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa a crear_propuesta_pj en la convocatoria(' . $request->get('conv') . ')"', ['user' => '', 'token' => $request->get('token')]);

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
                //Validar si existe un participante como persona juridica, con id usuario innner usuario_perfil
                $user_current = json_decode($token_actual->user_current, true);

                //Busco si tiene el perfil de persona juridica
                $usuario_perfil_pj = Usuariosperfiles::findFirst("usuario=" . $user_current["id"] . " AND perfil = 7");

                //Si existe el usuario perfil como pj
                $participante = new Participantes();
                if (isset($usuario_perfil_pj->id)) {
                    $participante = Participantes::findFirst("usuario_perfil=" . $usuario_perfil_pj->id . " AND tipo='Inicial' AND active=TRUE");

                    //Si existe el participante inicial con el perfil de pj 
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
                                $logger->info('"token":"{token}","user":"{user}","message":"Retorno la propuesta para el participante pj en la convocatoria(' . $request->get('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                                $logger->close();
                                echo $propuesta->id;
                                exit;                                
                            }
                            else
                            {
                                //Registro la accion en el log de convocatorias           
                                $logger->error('"token":"{token}","user":"{user}","message":"Error al crear el participante PJ asociado que se asocia a la propuesta."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
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
                                $logger->error('"token":"{token}","user":"{user}","message":"Error al crear el participante PJ asociado que se asocia a la propuesta."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                                $logger->close();
                                echo "error_participante_propuesta";
                                exit;
                            } else {
                                //Registro la accion en el log de convocatorias
                                $logger->info('"token":"{token}","user":"{user}","message":"Se creo el participante pj para la propuesta que se registro a la convocatoria(' . $request->get('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);

                                //Consulto el total de propuesta con el fin de generar el codigo de la propuesta
                                $sql_total_propuestas = "SELECT 
                                                            COUNT(p.id) as total_propuestas
                                                    FROM Propuestas AS p                                
                                                    WHERE
                                                    p.convocatoria=" . $request->get('conv');

                                $total_propuesta = $app->modelsManager->executeQuery($sql_total_propuestas)->getFirst();
                                $codigo_propuesta = $request->get('conv') . "-" . (str_pad($total_propuesta->total_propuestas + 1, 3, "0", STR_PAD_LEFT));
                                
                                //Creo la propuesta asociada al participante hijo
                                $propuesta = new Propuestas();
                                $propuesta->creado_por = $user_current["id"];
                                $propuesta->fecha_creacion = date("Y-m-d H:i:s");
                                $propuesta->participante = $participante_hijo_propuesta->id;
                                $propuesta->convocatoria = $request->get('conv');
                                $propuesta->estado = 7;
                                $propuesta->active = TRUE;
                                $propuesta->codigo = $codigo_propuesta;
                                if ($propuesta->save() === false) {
                                    //Registro la accion en el log de convocatorias           
                                    $logger->error('"token":"{token}","user":"{user}","message":"Error al crear la propuesta para el participante como PJ."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                                    $logger->close();
                                    echo "error_participante_propuesta";
                                    exit;
                                } else {
                                    
                                    $chemistry_alfresco = new ChemistryPV($config->alfresco->api, $config->alfresco->username, $config->alfresco->password);

                                    //Se crea la carpeta principal de la propuesta en la convocatoria                                    
                                    if ($chemistry_alfresco->newFolder("/Sites/convocatorias/" . $request->get('conv') . "/propuestas/", $propuesta->id) != "ok") {
                                        //Registro la accion en el log de convocatorias           
                                        $logger->error('"token":"{token}","user":"{user}","message":"Error al crear la carpeta de la propuesta para el participante como PJ."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
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
                        $logger->error('"token":"{token}","user":"{user}","message":"Para poder inscribir la propuesta debe crear el perfil de persona jurídica."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                        $logger->close();
                        echo "crear_perfil";
                        exit;
                    }
                } else {
                    //Registro la accion en el log de convocatorias           
                    $logger->error('"token":"{token}","user":"{user}","message":"Para poder inscribir la propuesta debe crear el perfil de persona jurídica."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                    $logger->close();
                    echo "crear_perfil";
                    exit;
                }
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"Acceso denegado crear_propuesta_pj"', ['user' => "", 'token' => $request->get('token')]);
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
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo crear_propuesta_pj ' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->get('token')]);
        $logger->close();
        echo "error_metodo";
    }
}
);

//Edito el participante hijo ya relacionado con la propuesta
$app->post('/editar_participante', function () use ($app, $config,$logger) {

    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPost('token'));

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa a buscar el participante pj hijo pj en la convocatoria(' . $request->get('conv') . ')"', ['user' => '', 'token' => $request->get('token')]);
        
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

                //Consulto si existe el usuario perfil como persona juridica
                $usuario_perfil = Usuariosperfiles::findFirst("usuario=" . $user_current["id"] . " AND perfil=7");

                //Verifico si existe, con el fin de crearlo
                if (!isset($usuario_perfil->id)) {
                    $usuario_perfil = new Usuariosperfiles();
                    $usuario_perfil->usuario = $user_current["id"];
                    $usuario_perfil->perfil = 7;
                    if ($usuario_perfil->save($usuario_perfil) === false) {
                        echo "error_usuario_perfil";
                    }
                }

                //Consulto los usuarios perfil del persona juridica
                //SE QUITA LA VALIDACION 21 DE NOVIEMBRE DEL 2019
                //DEBIDO QUE SIEMPRE DEBE LLEGAR EL PARTICIPANTE CON TIPO PARTICIPANTE
                /*
                $array_usuario_perfil = Usuariosperfiles::find("usuario=" . $user_current["id"] . " AND perfil IN (7)");
                $id_usuarios_perfiles = "";
                foreach ($array_usuario_perfil as $aup) {
                    $id_usuarios_perfiles = $id_usuarios_perfiles . $aup->id . ",";
                }
                $id_usuarios_perfiles = substr($id_usuarios_perfiles, 0, -1);

                //Consulto si existe partipantes que tengan el mismo numero y tipo de documento que sean diferentes a su perfil de persona juridica
                $participante_verificado = Participantes::find("usuario_perfil NOT IN (" . $id_usuarios_perfiles . ") AND numero_documento='" . $post["numero_documento"] . "' AND tipo_documento =" . $post["tipo_documento"]);
                */
                
                $participante = Participantes::findFirst($post["id"]);
                
                //if (count($participante_verificado) > 0) {
                if ($participante->tipo!="Participante") {
                    $logger->error('"token":"{token}","user":"{user}","message":"Acceso denegado editar_participante"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                    $logger->close();
                    echo "no_existente_participante";
                } else {
                    $post["actualizado_por"] = $user_current["id"];
                    $post["fecha_actualizacion"] = date("Y-m-d H:i:s");

                    if ($participante->save($post) === false) {
                        $logger->error('"token":"{token}","user":"{user}","message":"Se creo un error al editar el participante pj hijo."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                        $logger->close();
                        echo "error";
                    } else {
                        //Registro la accion en el log de convocatorias
                        $logger->info('"token":"{token}","user":"{user}","message":"Se edito el participante pj hijo en la convocatoria(' . $request->get('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
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

try {
    // Gestionar la consulta
    $app->handle();
} catch (\Exception $e) {
    echo 'Excepción: ', $e->getMessage();
}
?>