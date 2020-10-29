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
        
    try {        
        
        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa a crear perfil persona jurídica"',['user' => '','token'=>$request->get('token')]);
        
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPost('token'));
        
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
        if (isset($token_actual->id)) {
            
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
            
            $tabla_maestra= Tablasmaestras::find("active=true AND nombre='estrato'");            
            $array["estrato"] = explode(",", $tabla_maestra[0]->valor);
            
            $tabla_maestra= Tablasmaestras::find("active=true AND nombre='tipo_sede'");            
            $array["tipo_sede"] = explode(",", $tabla_maestra[0]->valor);
            
            $array["pais_residencia_id"] = "";
            $array["departamento_residencia_id"] = "";
            $array["ciudad_residencia_id"] = "";
            
            $array["departamentos"]=array();
            $array["ciudades"]=array();                        
            $array["barrios"] = array();
            
            if(isset($participante->id))
            {
                $array["pais_residencia_id"] = $participante->getCiudadesresidencia()->getDepartamentos()->getPaises()->id;
                $array["departamento_residencia_id"] = $participante->getCiudadesresidencia()->getDepartamentos()->id;                
                $array["ciudad_residencia_id"] = $participante->ciudad_residencia;
                
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

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //Registro la accion en el log de convocatorias
            $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al controlador Personasjuridicas en el método buscar_participante, Busca el participante PJ para el formulario del partipante en la convocatoria(' . $request->get('conv') . ')."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
            
            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->get('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {                

                //Busco si tiene el perfil de persona jurídica
                $usuario_perfil_pj = Usuariosperfiles::findFirst("usuario=" . $user_current["id"] . " AND perfil = 7");

                //Si existe el usuario perfil como pj
                $participante = new Participantes();
                if (isset($usuario_perfil_pj->id)) {
                    $participante = Participantes::findFirst("usuario_perfil=" . $usuario_perfil_pj->id . " AND tipo='Inicial' AND active=TRUE");

                    //Si existe el participante inicial con el perfil de pj 
                    if (isset($participante->id)) {

                        //Valido si existe el codigo de la propuesta                        
                        if (is_numeric($request->get('p')) AND $request->get('p')!=0) {
                            //Consulto la propuesta solicitada
                            $conditions = ['id' => $request->get('p'), 'active' => true];
                            $propuesta = Propuestas::findFirst(([
                                        'conditions' => 'id=:id: AND active=:active:',
                                        'bind' => $conditions,
                            ]));

                            //Creo el array
                            $array = array();
                            //Valido si se habilita propuesta por derecho de petición
                            $array["programa"] = $propuesta->getConvocatorias()->programa;
                            
                            if (isset($propuesta->id)) {
                                $array["participante"] = $propuesta->getParticipantes();
                                $participante_hijo_propuesta= $propuesta->getParticipantes();
                            }
                            else
                            {
                                //Registro la accion en el log de convocatorias           
                                $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Personasjuridicas en el método buscar_participante, no existe el participante PJ asociado la propuesta."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                                $logger->close();
                                echo "error_participante_propuesta";
                                exit;
                            }
                            
                            //Creo los array de los select del formulario
                            $array["estado"] = $propuesta->estado;
                            $array["tipo_documento"] = Tiposdocumentos::find("active=true");
                            $tabla_maestra= Tablasmaestras::find("active=true AND nombre='estrato'");            
                            $array["estrato"] = explode(",", $tabla_maestra[0]->valor);

                            $tabla_maestra= Tablasmaestras::find("active=true AND nombre='tipo_sede'");            
                            $array["tipo_sede"] = explode(",", $tabla_maestra[0]->valor);

                            $array["pais_residencia_id"] = "";
                            $array["departamento_residencia_id"] = "";
                            $array["ciudad_residencia_id"] = "";

                            $array["departamentos"]=array();
                            $array["ciudades"]=array();                        
                            $array["barrios"] = array();

                            if(isset($propuesta->id))
                            {
                                $array["pais_residencia_id"] = $participante_hijo_propuesta->getCiudadesresidencia()->getDepartamentos()->getPaises()->id;
                                $array["departamento_residencia_id"] = $participante_hijo_propuesta->getCiudadesresidencia()->getDepartamentos()->id;                
                                $array["ciudad_residencia_id"] = $participante_hijo_propuesta->ciudad_residencia;

                                $array["departamentos"]= Departamentos::find("active=true AND pais='".$participante_hijo_propuesta->getCiudadesresidencia()->getDepartamentos()->getPaises()->id."'");
                                $array["ciudades"]= Ciudades::find("active=true AND departamento='".$participante_hijo_propuesta->getCiudadesresidencia()->getDepartamentos()->id."'");

                                if(isset($participante_hijo_propuesta->localidad_residencia))
                                {
                                    $array["barrios"] = Barrios::find("active=true AND localidad=" . $participante_hijo_propuesta->localidad_residencia);                                            
                                }

                            }
                            
                            //Registro la accion en el log de convocatorias
                            $logger->info('"token":"{token}","user":"{user}","message":"Retornamos en el controlador Personasjuridicas en el método buscar_participante, se retorna el registro PJ, para el formulario del participante, en la convocatoria(' . $request->get('conv') . ')."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                            $logger->close();

                            //Retorno el array
                            echo json_encode($array);
                        
                        } else {
                            //Registro la accion en el log de convocatorias           
                            $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Personasjuridicas en el método buscar_participante, no existe la propuesta, en la convocatoria(' . $request->get('conv') . ')."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                            $logger->close();
                            echo "error_participante_propuesta";
                            exit;                            
                        }                        
                    } else {
                        //Registro la accion en el log de convocatorias           
                        $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Personasjuridicas en el método buscar_participante, no existe el perfil de persona jurídica, en la convocatoria(' . $request->get('conv') . ')."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                        $logger->close();
                        echo "crear_perfil";
                        exit;
                    }
                } else {
                    //Registro la accion en el log de convocatorias                               
                    $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Personasjuridicas en el método buscar_participante, no existe el perfil de persona jurídica, en la convocatoria(' . $request->get('conv') . ')."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                    $logger->close();
                    echo "crear_perfil";
                    exit;
                }
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"Acceso denegado en el controlador Personasjuridicas en el método buscar_participante, buscar_participante."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                $logger->close();
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco en el controlador Personasjuridicas en el método buscar_participante."', ['user' => "", 'token' => $request->get('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Personasjuridicas en el método buscar_participante, ' . $ex->getMessage() . '."', ['user' => "", 'token' => $request->get('token')]);
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

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));
        
        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //Registro la accion en el log de convocatorias
            $logger->info('"token":"{token}","user":"{user}","message":"Ingreso al controlador Personasjuridicas en el método crear_propuesta_pj, ingresa a crear la propuesta en la convocatoria(' . $request->get('conv') . ')."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
        
            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->get('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {                

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
                                $logger->info('"token":"{token}","user":"{user}","message":"Retorno en el controlador Personasjuridicas en el método crear_propuesta_pj, retorno la propuesta ('.$propuesta->id.') para el participante pj en la convocatoria(' . $request->get('conv') . ')."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                                $logger->close();
                                echo $propuesta->id;
                                exit;                                
                            }
                            else
                            {
                                //Registro la accion en el log de convocatorias           
                                $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Personasjuridicas en el método crear_propuesta_pj, error al crear el participante PJ asociado a la propuesta."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                                $logger->close();
                                echo "error_participante_propuesta";
                                exit;
                            }
                        } else {
                            
                            //Consulto la convocatoria solicitada                            
                            $convocatoria_solicitada = Convocatorias::findFirst($request->get('conv'));

                            //Realizo el in para las propuestas con las categorias
                            $in_convocatorias=$request->get('conv');
                            
                            //Programa de la convocatoria
                            $programa = $convocatoria_solicitada->programa;
                            
                            //Convocatoria par solo aplica para el PDAC
                            $convocatoria_par = $convocatoria_solicitada->convocatoria_par;
                            
                            if($convocatoria_solicitada->convocatoria_padre_categoria!=null)
                            {
                                //Consulto la convocatoria padre
                                $convocatoria_padre = $convocatoria_solicitada->getConvocatorias();
                        
                                //Programa de la convocatoria
                                $programa = $convocatoria_solicitada->getConvocatorias()->programa;                                                                                    
                                
                                $convocatoria_par = $convocatoria_solicitada->getConvocatorias()->convocatoria_par;
                                
                                $where_convocatoria_par="";
                                if($convocatoria_par!=null)
                                {
                                    $where_convocatoria_par=",".$convocatoria_par;
                                }
                                
                                
                                //Consulto todas las convocatorias hijas de la convocatoria actual
                                // y de la convocatoria par
                                $convocatorias_hijas = Convocatorias::find("convocatoria_padre_categoria IN (" . $convocatoria_padre->id . $where_convocatoria_par.") OR id IN (".$request->get('conv').$where_convocatoria_par.")");                                
                                $in_convocatorias="";
                                $in_convocatorias_par="";
                                foreach ($convocatorias_hijas as $convocatoria_hija) {
                                    if( $convocatoria_hija->id==$convocatoria_padre->id || $convocatoria_hija->convocatoria_padre_categoria==$convocatoria_padre->id)
                                    {
                                        $in_convocatorias=$in_convocatorias.$convocatoria_hija->id.",";
                                    }
                                    if( $convocatoria_hija->id==$convocatoria_par || $convocatoria_hija->convocatoria_padre_categoria==$convocatoria_par)
                                    {
                                        $in_convocatorias_par=$in_convocatorias_par.$convocatoria_hija->id.",";
                                    }
                                }
                                
                                $in_convocatorias = substr($in_convocatorias, 0, -1); 
                                $in_convocatorias_par = substr($in_convocatorias_par, 0, -1); 
                                
                            }
                            
                            //Valido si el programa es PDAC
                            $crear_propuesta=true;
                            if($programa==2)
                            { 
                                $alianza_sectorial='false';
                                if($request->get('alianza_sectorial')!="")
                                {                                    
                                    $alianza_sectorial=$request->get('alianza_sectorial');
                                }
                                //Valido que no este tenga otra propuesta inscrita en la
                                //Convocatoria par
                                $phql = "SELECT COUNT(p.id) AS total_propuestas  FROM Propuestas AS p
                                        INNER JOIN Participantes AS par ON par.id=p.participante AND par.participante_padre=".$participante->id."
                                        WHERE p.convocatoria IN (".$in_convocatorias_par.") AND p.estado IN (7,8,21,22,23,24,31,33,34,44)";
                                $propuestas_convocatoria_par = $app->modelsManager->executeQuery($phql)->getFirst();
                                if($propuestas_convocatoria_par->total_propuestas>0)
                                {
                                    $crear_propuesta=false;
                                    $error_validar_pdac = "error_otra_propuesta_pdac";
                                    $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Personasjuridicas en el método crear_propuesta_pj, ya cuenta con una propuesta inscrita en la otra convocatoria ('.$in_convocatorias_par.') del PDAC."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                                }
                                else
                                {                                    
                                    //Consulto las propuestas de los participantes
                                    //con el estado Registrada, Inscrita,Por Subsanar, Subsanación Recibida, Rechazada, Habilitada
                                    $phql = "SELECT COUNT(p.id) AS total_alianza  FROM Propuestas AS p
                                            INNER JOIN Participantes AS par ON par.id=p.participante AND par.participante_padre=".$participante->id."
                                            WHERE p.convocatoria IN (".$in_convocatorias.") AND p.alianza_sectorial=".$alianza_sectorial." AND p.estado IN (7,8,21,22,23,24,31,33,34,44)";
                                    $propuestas_pdac_alianza = $app->modelsManager->executeQuery($phql)->getFirst();                                    
                                    if($propuestas_pdac_alianza->total_alianza>0)
                                    {
                                        $crear_propuesta=false;
                                        $error_validar_pdac = "error_maximo_propuesta_pdac";
                                        $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Personasjuridicas en el método crear_propuesta_pj, ya cuenta con el maximo de propuestas para el PDAC, debe ser una con alianza y la otra sin alianza."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                                    }
                                }                                                                                                                                
                            }
                            
                            //Valido que pueda crear la propuesta
                            if($crear_propuesta)
                            {
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
                                    $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Personasjuridicas en el método crear_propuesta_pj, error al crear el participante PJ asociado a la propuesta de la convocatoria(' . $request->get('conv') . ')."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                                    $logger->close();
                                    echo "error_participante_propuesta";
                                    exit;
                                } else {
                                    //Registro la accion en el log de convocatorias
                                    $logger->info('"token":"{token}","user":"{user}","message":"Informa en el controlador Personasjuridicas en el método crear_propuesta_pj, se creo el participante PJ que se asocia a la propuesta de la convocatoria(' . $request->get('conv') . ')."', ['user' => $user_current["username"], 'token' => $request->get('token')]);

                                    //Creo la propuesta asociada al participante hijo
                                    $propuesta = new Propuestas();
                                    $propuesta->creado_por = $user_current["id"];
                                    $propuesta->fecha_creacion = date("Y-m-d H:i:s");
                                    $propuesta->participante = $participante_hijo_propuesta->id;
                                    $propuesta->convocatoria = $request->get('conv');
                                    $propuesta->estado = 7;
                                    $propuesta->active = TRUE;   
                                    //Valido si el programa es PDAC
                                    if($programa==2)
                                    { 
                                        $propuesta->alianza_sectorial=$alianza_sectorial;
                                    }
                                    if ($propuesta->save() === false) {
                                        //Registro la accion en el log de convocatorias           
                                        $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Personasjuridicas en el método crear_propuesta_pj, error al crear la propuesta para el participante ('.$participante_hijo_propuesta->id.') como PJ de la convocatoria(' . $request->get('conv') . ')."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
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
                                        $logger->info('"token":"{token}","user":"{user}","message":"Retorno al controlador Personasjuridicas en el método crear_propuesta_pj, Se creo la propuesta ('.$propuesta->id.') para la convocatoria(' . $request->get('conv') . ')."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                                        $logger->close();
                                        echo $propuesta->id;
                                        exit;
                                    }
                                }
                            }
                            else
                            {
                                //Registro la accion en el log de convocatorias                                           
                                $logger->close();
                                echo $error_validar_pdac;
                                exit;
                            }
                        }
                        
                    } else {
                        //Registro la accion en el log de convocatorias           
                        $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Personasjuridicas en el método crear_propuesta_pj, para poder inscribir la propuesta debe crear el perfil de persona jurídica para la convocatoria(' . $request->get('conv') . ')."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                        $logger->close();
                        echo "crear_perfil";
                        exit;
                    }
                } else {
                    //Registro la accion en el log de convocatorias           
                    $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Personasjuridicas en el método crear_propuesta_pj, para poder inscribir la propuesta debe crear el perfil de persona jurídica para la convocatoria(' . $request->get('conv') . ')."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                    $logger->close();
                    echo "crear_perfil";
                    exit;
                }
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Personasjuridicas en el método crear_propuesta_pj, acceso denegado"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                $logger->close();
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Personasjuridicas en el método crear_propuesta_pj, token caduco"', ['user' => "", 'token' => $request->get('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Personasjuridicas en el método crear_propuesta_pj, error metodo ' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->get('token')]);
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

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //Registro la accion en el log de convocatorias
            $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al controlador Personasjuridicas en el método editar_participante, ingresa a editar el participante ('.$request->getPost('id').') PJ en la convocatoria(' . $request->getPost('conv') . ')."', ['user' => $user_current["username"], 'token' => $request->get('token')]);                
            
            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->getPost('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                
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
                        $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador Personasjuridicas en el método editar_participante, al editar el participante pj hijo ('.$request->getPost('id').') PJ en la convocatoria(' . $request->getPost('conv') . ')."', ['user' => $user_current["username"], 'token' => $request->get('token')]);                                        
                        $logger->close();
                        echo "error";
                    } else {
                        //Registro la accion en el log de convocatorias
                        $logger->info('"token":"{token}","user":"{user}","message":"Edito en el controlador Personasjuridicas en el método editar_participante, edita el participante ('.$request->getPost('id').') PJ en la convocatoria(' . $request->getPost('conv') . ')."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                        $logger->close();
                        echo $participante->id;
                    }                    
                }
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"Acceso denegado al controlador Personasjuridicas en el método editar_participante, en el participante ('.$request->getPost('id').') PJ en la convocatoria(' . $request->getPost('conv') . ')."', ['user' => $user_current["username"], 'token' => $request->get('token')]);                
                $logger->close();
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco en el controlador Personasjuridicas en el método editar_participante, en el participante ('.$request->getPost('id').') PJ en la convocatoria(' . $request->getPost('conv') . ')."', ['user' => "", 'token' => $request->get('token')]);                                        
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo en el controlador Personasjuridicas en el método editar_participante, en el participante ('.$request->getPost('id').') PJ en la convocatoria(' . $request->getPost('conv') . ') ' . $ex->getMessage() . '."', ['user' => "", 'token' => $request->get('token')]);        
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