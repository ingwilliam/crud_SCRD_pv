<?php

//OJOOJOJOJ
//OJO WILLIAM COLOCAR LOG A METODOS
//TERMINAR CON AGRGAR LINK A LA PROPYESTA
////error_reporting(E_ALL);
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

//Metodo el cual carga el formulario del integrante
//Verifica que que tenga creada la propuestas
$app->get('/buscar_documentacion', function () use ($app, $config, $logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al metodo buscar_documentacion como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => '', 'token' => $request->get('token')]);

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
                        if (is_numeric($request->get('p')) AND $request->get('p') != 0) {
                            //Consulto la propuesta solicitada
                            $conditions = ['id' => $request->get('p'), 'active' => true];
                            $propuesta = Propuestas::findFirst(([
                                        'conditions' => 'id=:id: AND active=:active:',
                                        'bind' => $conditions,
                            ]));

                            if (isset($propuesta->id)) {

                                //Creo el array de la propuesta
                                $array = array();
                                $array["propuesta"] = $propuesta->id;
                                $array["estado"] = $propuesta->estado;
                                $array["participante"] = $propuesta->participante;

                                $id = $request->get('conv');
                                
                                //Consulto la convocatoria
                                $convocatoria = Convocatorias::findFirst($id);

                                //Si la convocatoria seleccionada es categoria y no es especial invierto los id
                                if ($convocatoria->convocatoria_padre_categoria > 0 && $convocatoria->getConvocatorias()->tiene_categorias == true && $convocatoria->getConvocatorias()->diferentes_categorias == false) {
                                    $id = $convocatoria->getConvocatorias()->id;                    
                                }
                                
                                $conditions = ['convocatoria' => $id, 'active' => true];

                                //Se crea todo el array de documentos administrativos y tecnicos
                                $consulta_documentos_administrativos = Convocatoriasdocumentos::find(([
                                            'conditions' => 'convocatoria=:convocatoria: AND active=:active:',
                                            'bind' => $conditions,
                                            'order' => 'orden ASC',
                                ]));
                                $documentos_administrativos=array();
                                $documentos_tecnicos=array();
                                foreach ($consulta_documentos_administrativos as $documento) {
                                    if ($documento->getRequisitos()->tipo_requisito == "Administrativos") {
                                        if ($documento->etapa == "Registro") {
                                            $documentos_administrativos[$documento->orden]["id"] = $documento->id;
                                            $documentos_administrativos[$documento->orden]["requisito"] = $documento->getRequisitos()->nombre;
                                            $documentos_administrativos[$documento->orden]["descripcion"] = $documento->descripcion;
                                            $documentos_administrativos[$documento->orden]["archivos_permitidos"] = json_decode($documento->archivos_permitidos);
                                            $documentos_administrativos[$documento->orden]["tamano_permitido"] = $documento->tamano_permitido;
                                            $documentos_administrativos[$documento->orden]["orden"] = $documento->orden;
                                        }
                                    }

                                    if ($documento->getRequisitos()->tipo_requisito == "Tecnicos") {
                                        $documentos_tecnicos[$documento->orden]["id"] = $documento->id;
                                        $documentos_tecnicos[$documento->orden]["requisito"] = $documento->getRequisitos()->nombre;
                                        $documentos_tecnicos[$documento->orden]["descripcion"] = $documento->descripcion;
                                        $documentos_tecnicos[$documento->orden]["archivos_permitidos"] = json_decode($documento->archivos_permitidos);
                                        $documentos_tecnicos[$documento->orden]["tamano_permitido"] = $documento->tamano_permitido;
                                        $documentos_tecnicos[$documento->orden]["orden"] = $documento->orden;
                                    }
                                }

                                $array["administrativos"] = $documentos_administrativos;

                                $array["tecnicos"] = $documentos_tecnicos;

                                //Registro la accion en el log de convocatorias
                                $logger->info('"token":"{token}","user":"{user}","message":"Retorna la información de la documentacion para el perfil como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . '), en el metodo buscar_documentacion"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                                $logger->close();

                                //Retorno el array
                                echo json_encode($array);
                                
                            } else {
                                //Registro la accion en el log de convocatorias           
                                $logger->error('"token":"{token}","user":"{user}","message":"Debe crear la propuesta para el perfil como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . '), en el metodo buscar_documentacion"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                                $logger->close();
                                echo "crear_propuesta";
                                exit;
                            }
                        } else {
                            //Registro la accion en el log de convocatorias           
                            $logger->error('"token":"{token}","user":"{user}","message":"Error al crear el participante PN asociado que se asocia a la propuesta."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                            $logger->close();
                            echo "error_cod_propuesta";
                            exit;
                        }
                    } else {
                        //Busco si tiene el perfil asociado de acuerdo al parametro
                        if ($request->get('m') == "pn") {
                            //Registro la accion en el log de convocatorias           
                            $logger->error('"token":"{token}","user":"{user}","message":"Debe crear el perfil como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . '), en el metodo buscar_documentacion"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                            $logger->close();
                            echo "crear_perfil_pn";
                            exit;
                        }
                        if ($request->get('m') == "pj") {
                            //Registro la accion en el log de convocatorias           
                            $logger->error('"token":"{token}","user":"{user}","message":"Debe crear el perfil como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . '), en el metodo buscar_documentacion"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                            $logger->close();
                            echo "crear_perfil_pj";
                            exit;
                        }
                        if ($request->get('m') == "agr") {
                            //Registro la accion en el log de convocatorias           
                            $logger->error('"token":"{token}","user":"{user}","message":"Debe crear el perfil como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . '), en el metodo buscar_documentacion"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                            $logger->close();
                            echo "crear_perfil_agr";
                            exit;
                        }
                    }
                } else {
                    //Busco si tiene el perfil asociado de acuerdo al parametro
                    if ($request->get('m') == "pn") {
                        //Registro la accion en el log de convocatorias           
                        $logger->error('"token":"{token}","user":"{user}","message":"Debe crear el perfil como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . '), en el metodo buscar_documentacion"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                        $logger->close();
                        echo "crear_perfil_pn";
                        exit;
                    }
                    if ($request->get('m') == "pj") {
                        //Registro la accion en el log de convocatorias           
                        $logger->error('"token":"{token}","user":"{user}","message":"Debe crear el perfil como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . '), en el metodo buscar_documentacion"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                        $logger->close();
                        echo "crear_perfil_pj";
                        exit;
                    }
                    if ($request->get('m') == "agr") {
                        //Registro la accion en el log de convocatorias           
                        $logger->error('"token":"{token}","user":"{user}","message":"Debe crear el perfil como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . '), en el metodo buscar_documentacion"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                        $logger->close();
                        echo "crear_perfil_agr";
                        exit;
                    }
                }
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"Acceso denegado en el metodo buscar_documentacion como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => "", 'token' => $request->get('token')]);
                $logger->close();
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco en el metodo buscar_documentacion como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => "", 'token' => $request->get('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo buscar_documentacion como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ') ' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->get('token')]);
        $logger->close();
        echo "error_metodo";
    }
}
);

//Metodo el cual carga el formulario del integrante
//Verifica que que tenga creada la propuestas
$app->get('/buscar_documentacion_subsanacion', function () use ($app, $config, $logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al metodo buscar_documentacion_subsanacion como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => '', 'token' => $request->get('token')]);

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

                //Valido si existe el codigo de la propuesta
                //De lo contratio creo el participante del cual depende del inicial
                //Creo la propuesta asociando el participante creado
                if (is_numeric($request->get('p'))) {
                    //Consulto la propuesta solicitada
                    $conditions = ['id' => $request->get('p'), 'active' => true , 'estado' => 22 ];
                    $propuesta = Propuestas::findFirst(([
                                'conditions' => 'id=:id: AND active=:active: AND estado=:estado:',
                                'bind' => $conditions,
                    ]));

                    if (isset($propuesta->id)) {

                        //Creo el array de la propuesta
                        $array = array();
                        $array["propuesta"] = $propuesta->id;
                        $array["periodo_actual"] = "desde <b>" .date('Y-m-d',strtotime($propuesta->fecha_inicio_subsanacion, time()))."</b> hasta el <b>".$propuesta->fecha_fin_subsanacion."</b>";
                        
                        $array["nombre_propuesta"] = $propuesta->nombre;
                        $array["estado"] = $propuesta->estado;
                        $array["participante"] = $propuesta->participante;

                        $id = $propuesta->convocatoria;

                        //Consulto la convocatoria
                        $convocatoria = Convocatorias::findFirst($propuesta->convocatoria);

                        //Si la convocatoria seleccionada es categoria y no es especial invierto los id
                        if ($convocatoria->convocatoria_padre_categoria > 0 && $convocatoria->getConvocatorias()->tiene_categorias == true && $convocatoria->getConvocatorias()->diferentes_categorias == false) {
                            $id = $convocatoria->getConvocatorias()->id;                    
                        }

                         //Consulto los documentos por subsanar y la verificacion 1
                        $verificaciones_1 = Propuestasverificaciones::find("propuesta=" . $propuesta->id . " AND estado=27");
                        $documentos_administrativos=array();
                        foreach ($verificaciones_1 as $documento) {                            
                            $documentos_administrativos[$documento->getConvocatoriasdocumentos()->id]["id"] = $documento->getConvocatoriasdocumentos()->id;
                            $documentos_administrativos[$documento->getConvocatoriasdocumentos()->id]["requisito"] = $documento->getConvocatoriasdocumentos()->getRequisitos()->nombre;
                            $documentos_administrativos[$documento->getConvocatoriasdocumentos()->id]["descripcion"] = "<b>".$documento->observacion."</b>";
                            $documentos_administrativos[$documento->getConvocatoriasdocumentos()->id]["archivos_permitidos"] = json_decode($documento->getConvocatoriasdocumentos()->archivos_permitidos);
                            $documentos_administrativos[$documento->getConvocatoriasdocumentos()->id]["tamano_permitido"] = $documento->getConvocatoriasdocumentos()->tamano_permitido;
                            $documentos_administrativos[$documento->getConvocatoriasdocumentos()->id]["estado"] = $documento->estado;
                            $documentos_administrativos[$documento->getConvocatoriasdocumentos()->id]["orden"] = $documento->getConvocatoriasdocumentos()->orden;                                                         
                        }
                        
                        
                        $array["administrativos"] = $documentos_administrativos;                        

                        //Registro la accion en el log de convocatorias
                        $logger->info('"token":"{token}","user":"{user}","message":"Retorna la información de la documentacion para el perfil como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . '), en el metodo buscar_documentacion_subsanacion"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                        $logger->close();

                        //Retorno el array
                        echo json_encode($array);

                    } else {
                        //Registro la accion en el log de convocatorias           
                        $logger->error('"token":"{token}","user":"{user}","message":"Debe crear la propuesta para el perfil como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . '), en el metodo buscar_documentacion_subsanacion"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                        $logger->close();
                        echo "crear_propuesta";
                        exit;
                    }
                } else {
                    //Registro la accion en el log de convocatorias           
                    $logger->error('"token":"{token}","user":"{user}","message":"Error al crear el participante PN asociado que se asocia a la propuesta."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                    $logger->close();
                    echo "error_cod_propuesta";
                    exit;
                }
                                
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"Acceso denegado en el metodo buscar_documentacion_subsanacion como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => "", 'token' => $request->get('token')]);
                $logger->close();
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco en el metodo buscar_documentacion_subsanacion como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => "", 'token' => $request->get('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo buscar_documentacion_subsanacion como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ') ' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->get('token')]);
        $logger->close();
        echo "error_metodo";
    }
}
);

$app->get('/validar_requisitos', function () use ($app, $config, $logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al metodo validar_requisitos como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => '', 'token' => $request->get('token')]);

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

                    //Consulto la convocatoria
                    $id=$request->get('conv');
                    $convocatoria = Convocatorias::findFirst($id);

                    //Si la convocatoria seleccionada es categoria y no es especial invierto los id
                    if ($convocatoria->convocatoria_padre_categoria > 0 && $convocatoria->getConvocatorias()->tiene_categorias == true && $convocatoria->getConvocatorias()->diferentes_categorias == false) {
                        $id = $convocatoria->getConvocatorias()->id;                    
                    }
                    
                    //Consulto los requisitos no guardados
                    $sql_requisitos = "SELECT 
                                                cd.id,
                                                r.nombre	
                                        FROM Convocatoriasdocumentos AS cd
                                        INNER JOIN Requisitos AS r ON r.id=cd.requisito
                                        LEFT JOIN Propuestasdocumentos AS pd ON pd.convocatoriadocumento = cd.id AND pd.propuesta=" . $propuesta->id . " AND pd.active = TRUE 
                                        LEFT JOIN Propuestaslinks AS pl ON pl.convocatoriadocumento = cd.id AND pl.propuesta=" . $propuesta->id . " AND pl.active = TRUE
                                        WHERE cd.obligatorio=TRUE AND cd.convocatoria=" . $id . " AND pd.convocatoriadocumento IS NULL AND pl.convocatoriadocumento IS NULL";

                    $requisitos = $app->modelsManager->executeQuery($sql_requisitos);

                    $id_perfil = $propuesta->getParticipantes()->getUsuariosperfiles()->getPerfiles()->id;
                    
                    if( $id_perfil==7 || $id_perfil==8)
                    {
                        $participantes = Participantes::find("active = TRUE AND participante_padre=" . $propuesta->participante . "");
                        
                        if( count($participantes) <= 0 )
                        {
                            $data = json_decode(json_encode($requisitos), true);
                    
                            if( $id_perfil==7)
                            {
                                $new_json = array(array('id' => "Junta", 'nombre' => "Junta"));
                            }
                            
                            if($id_perfil==8)
                            {
                                $new_json = array(array('id' => "Integrante", 'nombre' => "Integrante"));
                            }
                            
                            $requisitos = array_merge($data, $new_json);
                        }
                        
                    }
                    
                    //Registro la accion en el log de convocatorias
                    $logger->info('"token":"{token}","user":"{user}","message":"Retorna la información de la documentacion para el perfil como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . '), en el metodo validar_requisitos"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                    $logger->close();

                    //Retorno el array
                    echo json_encode($requisitos);
                } else {
                    //Registro la accion en el log de convocatorias           
                    $logger->error('"token":"{token}","user":"{user}","message":"Debe crear la propuesta para el perfil como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . '), en el metodo validar_requisitos"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                    $logger->close();
                    echo "crear_propuesta";
                    exit;
                }
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"Acceso denegado en el metodo validar_requisitos como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => "", 'token' => $request->get('token')]);
                $logger->close();
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco en el metodo validar_requisitos como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => "", 'token' => $request->get('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo validar_requisitos como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ') ' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->get('token')]);
        $logger->close();
        echo "error_metodo";
    }
}
);

$app->get('/validar_requisitos_subsanacion', function () use ($app, $config, $logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->get('token'));

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al metodo validar_requisitos_subsanacion como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => '', 'token' => $request->get('token')]);

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

                    //Consulto la convocatoria
                    $id=$request->get('conv');
                    $convocatoria = Convocatorias::findFirst($id);

                    //Si la convocatoria seleccionada es categoria y no es especial invierto los id
                    if ($convocatoria->convocatoria_padre_categoria > 0 && $convocatoria->getConvocatorias()->tiene_categorias == true && $convocatoria->getConvocatorias()->diferentes_categorias == false) {
                        $id = $convocatoria->getConvocatorias()->id;                    
                    }
                    
                    //Consulto los requisitos no guardados
                    $sql_requisitos = "SELECT 
                                                cd.id,
                                                r.nombre	
                                        FROM Convocatoriasdocumentos AS cd
                                        INNER JOIN Requisitos AS r ON r.id=cd.requisito
                                        LEFT JOIN Propuestasdocumentos AS pd ON pd.convocatoriadocumento = cd.id AND pd.propuesta=" . $propuesta->id . " AND pd.active = TRUE AND pd.cargue_subsanacion = TRUE 
                                        LEFT JOIN Propuestaslinks AS pl ON pl.convocatoriadocumento = cd.id AND pl.propuesta=" . $propuesta->id . " AND pl.active = TRUE AND pl.cargue_subsanacion = TRUE 
                                        WHERE r.tipo_requisito='Administrativos' AND cd.obligatorio=TRUE AND cd.convocatoria=" . $id . " AND pd.convocatoriadocumento IS NULL AND pl.convocatoriadocumento IS NULL";

                    $requisitos = $app->modelsManager->executeQuery($sql_requisitos);

                    $id_perfil = $propuesta->getParticipantes()->getUsuariosperfiles()->getPerfiles()->id;
                    
                    if( $id_perfil==7 || $id_perfil==8)
                    {
                        $participantes = Participantes::find("active = TRUE AND participante_padre=" . $propuesta->participante . "");
                        
                        if( count($participantes) <= 0 )
                        {
                            $data = json_decode(json_encode($requisitos), true);
                    
                            if( $id_perfil==7)
                            {
                                $new_json = array(array('id' => "Junta", 'nombre' => "Junta"));
                            }
                            
                            if($id_perfil==8)
                            {
                                $new_json = array(array('id' => "Integrante", 'nombre' => "Integrante"));
                            }
                            
                            $requisitos = array_merge($data, $new_json);
                        }
                        
                    }
                    
                    //Registro la accion en el log de convocatorias
                    $logger->info('"token":"{token}","user":"{user}","message":"Retorna la información de la documentacion para el perfil como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . '), en el metodo validar_requisitos_subsanacion"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                    $logger->close();

                    //Retorno el array
                    echo json_encode($requisitos);
                } else {
                    //Registro la accion en el log de convocatorias           
                    $logger->error('"token":"{token}","user":"{user}","message":"Debe crear la propuesta para el perfil como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . '), en el metodo validar_requisitos_subsanacion"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                    $logger->close();
                    echo "crear_propuesta";
                    exit;
                }
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"Acceso denegado en el metodo validar_requisitos_subsanacion como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => "", 'token' => $request->get('token')]);
                $logger->close();
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco en el metodo validar_requisitos_subsanacion como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => "", 'token' => $request->get('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo validar_requisitos_subsanacion como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ') ' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->get('token')]);
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
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al metodo buscar_archivos como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => '', 'token' => $request->get('token')]);

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
                    $logger->info('"token":"{token}","user":"{user}","message":"Retorna la información documento convocatoriadocumento para el perfil como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . '), en el metodo buscar_archivos"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                    $logger->close();

                    //Retorno el array
                    echo json_encode($consulta_documentos_administrativos);
                } else {
                    //Registro la accion en el log de convocatorias           
                    $logger->error('"token":"{token}","user":"{user}","message":"Debe crear la propuesta para el perfil como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . '), en el metodo buscar_archivos"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                    $logger->close();
                    echo "crear_propuesta";
                    exit;
                }
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"Acceso denegado en el metodo buscar_archivos como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => "", 'token' => $request->get('token')]);
                $logger->close();
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco en el metodo buscar_archivos como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => "", 'token' => $request->get('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo buscar_archivos como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ') ' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->get('token')]);
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

// Crear registro
$app->post('/guardar_archivo', function () use ($app, $config, $logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al metodo guardar_archivo como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')"', ['user' => '', 'token' => $request->getPut('token')]);

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual > 0) {

            //Realizo una peticion curl por post para verificar si tiene permisos de escritura
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $config->sistema->url_curl . "Session/permiso_escritura");
            curl_setopt($ch, CURLOPT_POST, 2);
            curl_setopt($ch, CURLOPT_POSTFIELDS, "modulo=" . $request->getPut('modulo') . "&token=" . $request->getPut('token'));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $permiso_escritura = curl_exec($ch);
            curl_close($ch);

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                //Validar si existe un participante como persona jurídica, con id usuario innner usuario_perfil
                $user_current = json_decode($token_actual->user_current, true);

                $explode = explode(',', substr($request->getPut('srcData'), 5), 2);
                $data = $explode[1];
                $fileName = "c" . $request->getPost('convocatoria_padre_categoria') . "d" . $request->getPut('conv') . "u" . $user_current["id"] . "f" . date("YmdHis") . "." . $request->getPut("srcExt");

                $chemistry_alfresco = new ChemistryPV($config->alfresco->api, $config->alfresco->username, $config->alfresco->password);
                $return = $chemistry_alfresco->newFile("/Sites/convocatorias/" . $request->getPut('conv') . "/propuestas/" . $request->getPut('propuesta'), $fileName, base64_decode($data), $request->getPut("srcType"));
                if (strpos($return, "Error") !== FALSE) {
                    //Registro la accion en el log de convocatorias           
                    $logger->error('"token":"{token}","user":"{user}","message":"Error al crear el archivo (' . $request->getPut('srcName') . ') en el metodo guardar_archivo como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')"', ['user' => "", 'token' => $request->getPut('token')]);
                    $logger->close();
                    echo "error_archivo";
                } else {
                    $propuestasdocumentos = new Propuestasdocumentos();
                    $propuestasdocumentos->creado_por = $user_current["id"];
                    $propuestasdocumentos->fecha_creacion = date("Y-m-d H:i:s");
                    $propuestasdocumentos->active = true;
                    $propuestasdocumentos->propuesta = $request->getPut('propuesta');
                    $propuestasdocumentos->convocatoriadocumento = $request->getPut('documento');
                    $propuestasdocumentos->id_alfresco = $return;
                    $propuestasdocumentos->nombre = $request->getPut('srcName');
                    if($request->getPut('cargue_subsanacion'))
                    {
                        $propuestasdocumentos->cargue_subsanacion = true;
                    }                                        
                    if ($propuestasdocumentos->save() === false) {
                        //Registro la accion en el log de convocatorias           
                        $logger->error('"token":"{token}","user":"{user}","message":"Error al crear el archivo en la base de datos (' . $request->getPut('srcName') . ') en el metodo guardar_archivo como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')"', ['user' => "", 'token' => $request->getPut('token')]);
                        $logger->close();
                        echo "error_archivo";
                    } else {
                        $logger->info('"token":"{token}","user":"{user}","message":"Se creo el archivo en la base de datos (' . $request->getPut('srcName') . ') en el metodo guardar_archivo como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')"', ['user' => "", 'token' => $request->getPut('token')]);
                        $logger->close();
                        echo $propuestasdocumentos->id;
                    }
                }
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"Acceso denegado en el metodo guardar_archivo como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')"', ['user' => "", 'token' => $request->getPut('token')]);
                $logger->close();
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco en el metodo guardar_archivo como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')"', ['user' => "", 'token' => $request->getPut('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo guardar_archivo como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ') ' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->getPut('token')]);
        $logger->close();
        echo "error_metodo";
    }
});

// Crear registro
$app->post('/guardar_link', function () use ($app, $config, $logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();
    try {

        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al metodo guardar_link como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')"', ['user' => '', 'token' => $request->getPut('token')]);

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual > 0) {

            //Realizo una peticion curl por post para verificar si tiene permisos de escritura
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $config->sistema->url_curl . "Session/permiso_escritura");
            curl_setopt($ch, CURLOPT_POST, 2);
            curl_setopt($ch, CURLOPT_POSTFIELDS, "modulo=" . $request->getPut('modulo') . "&token=" . $request->getPut('token'));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $permiso_escritura = curl_exec($ch);
            curl_close($ch);

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                //Validar si existe un participante como persona jurídica, con id usuario innner usuario_perfil
                $user_current = json_decode($token_actual->user_current, true);

                $propuestaslinks = new Propuestaslinks();
                $propuestaslinks->creado_por = $user_current["id"];
                $propuestaslinks->fecha_creacion = date("Y-m-d H:i:s");
                $propuestaslinks->active = true;
                $propuestaslinks->propuesta = $request->getPut('propuesta');
                $propuestaslinks->convocatoriadocumento = $request->getPut('documento');
                $propuestaslinks->link = $request->getPut('link');
                if($request->getPut('cargue_subsanacion'))
                {
                    $propuestaslinks->cargue_subsanacion = true;
                } 
                if ($propuestaslinks->save() === false) {
                    //Registro la accion en el log de convocatorias           
                    $logger->error('"token":"{token}","user":"{user}","message":"Error al crear el link en la base de datos (' . $request->getPut('documento') . ') en el metodo guardar_link como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')"', ['user' => "", 'token' => $request->getPut('token')]);
                    $logger->close();
                    echo "error_archivo";
                } else {
                    $logger->info('"token":"{token}","user":"{user}","message":"Se creo el link en la base de datos (' . $request->getPut('documento') . ') en el metodo guardar_link como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')"', ['user' => "", 'token' => $request->getPut('token')]);
                    $logger->close();
                    echo $propuestaslinks->id;
                }
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"Acceso denegado en el metodo guardar_link como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')"', ['user' => "", 'token' => $request->getPut('token')]);
                $logger->close();
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco en el metodo guardar_link como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')"', ['user' => "", 'token' => $request->getPut('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo guardar_link como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ') ' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->getPut('token')]);
        $logger->close();
        echo "error_metodo";
    }
});

// Eliminar registro de los perfiles de las convocatorias
$app->delete('/delete/{id:[0-9]+}', function ($id) use ($app, $config, $logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al metodo delete como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')"', ['user' => '', 'token' => $request->getPut('token')]);

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual > 0) {

            //Realizo una peticion curl por post para verificar si tiene permisos de escritura
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $config->sistema->url_curl . "Session/permiso_eliminar");
            curl_setopt($ch, CURLOPT_POST, 2);
            curl_setopt($ch, CURLOPT_POSTFIELDS, "modulo=" . $request->getPut('modulo') . "&token=" . $request->getPut('token'));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $permiso_escritura = curl_exec($ch);
            curl_close($ch);

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                // Consultar el registro
                $propuestasdocumentos = Propuestasdocumentos::findFirst(json_decode($id));
                $propuestasdocumentos->active = false;

                if ($propuestasdocumentos->save($propuestasdocumentos) === false) {
                    //Registro la accion en el log de convocatorias           
                    $logger->error('"token":"{token}","user":"{user}","message":"Error al desactivar el archivo en la base de datos (' . $request->getPut('srcName') . ') en el metodo delete como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')"', ['user' => "", 'token' => $request->getPut('token')]);
                    $logger->close();
                    echo "error";
                } else {
                    $logger->info('"token":"{token}","user":"{user}","message":"Se desactivo el archivo en la base de datos (' . $request->getPut('srcName') . ') en el metodo delete como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')"', ['user' => "", 'token' => $request->getPut('token')]);
                    $logger->close();
                    echo $propuestasdocumentos->id;
                }
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"Acceso denegado en el metodo delete como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')"', ['user' => "", 'token' => $request->getPut('token')]);
                $logger->close();
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco en el metodo delete como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')"', ['user' => "", 'token' => $request->getPut('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo delete como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ') ' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->getPut('token')]);
        $logger->close();
        echo "error_metodo";
    }
});

// Eliminar registro de los perfiles de las convocatorias
$app->delete('/delete_link/{id:[0-9]+}', function ($id) use ($app, $config, $logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();

    try {
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al metodo delete_link como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')"', ['user' => '', 'token' => $request->getPut('token')]);

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual > 0) {

            //Realizo una peticion curl por post para verificar si tiene permisos de escritura
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $config->sistema->url_curl . "Session/permiso_eliminar");
            curl_setopt($ch, CURLOPT_POST, 2);
            curl_setopt($ch, CURLOPT_POSTFIELDS, "modulo=" . $request->getPut('modulo') . "&token=" . $request->getPut('token'));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $permiso_escritura = curl_exec($ch);
            curl_close($ch);

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                // Consultar el registro
                $propuestaslinks = Propuestaslinks::findFirst(json_decode($id));
                $propuestaslinks->active = false;

                if ($propuestaslinks->save($propuestaslinks) === false) {
                    //Registro la accion en el log de convocatorias           
                    $logger->error('"token":"{token}","user":"{user}","message":"Error al desactivar el link en la base de datos (' . $request->getPut('srcName') . ') en el metodo delete_link como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')"', ['user' => "", 'token' => $request->getPut('token')]);
                    $logger->close();
                    echo "error";
                } else {
                    $logger->info('"token":"{token}","user":"{user}","message":"Se desactivo el link en la base de datos (' . $request->getPut('srcName') . ') en el metodo delete_link como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')"', ['user' => "", 'token' => $request->getPut('token')]);
                    $logger->close();
                    echo $propuestaslinks->id;
                }
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"Acceso denegado en el metodo delete_link como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')"', ['user' => "", 'token' => $request->getPut('token')]);
                $logger->close();
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco en el metodo delete_link como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')"', ['user' => "", 'token' => $request->getPut('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo delete_link como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ') ' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->getPut('token')]);
        $logger->close();
        echo "error_metodo";
    }
});

$app->post('/download_file', function () use ($app, $config, $logger) {
    //Instancio los objetos que se van a manejar
    $request = new Request();
    $tokens = new Tokens();
    try {
        //Consulto si al menos hay un token
        $token_actual = $tokens->verificar_token($request->getPut('token'));

        //Registro la accion en el log de convocatorias
        $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al metodo download_file como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')"', ['user' => '', 'token' => $request->getPut('token')]);

        //Si el token existe y esta activo entra a realizar la tabla
        if ($token_actual > 0) {

            //Realizo una peticion curl por post para verificar si tiene permisos de escritura
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $config->sistema->url_curl . "Session/permiso_eliminar");
            curl_setopt($ch, CURLOPT_POST, 2);
            curl_setopt($ch, CURLOPT_POSTFIELDS, "modulo=" . $request->getPut('modulo') . "&token=" . $request->getPut('token'));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $permiso_escritura = curl_exec($ch);
            curl_close($ch);

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                $chemistry_alfresco = new ChemistryPV($config->alfresco->api, $config->alfresco->username, $config->alfresco->password);
                echo $chemistry_alfresco->download($request->getPost('cod'));
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"Acceso denegado en el metodo download_file como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')"', ['user' => "", 'token' => $request->getPut('token')]);
                $logger->close();
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Token caduco en el metodo download_file como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')"', ['user' => "", 'token' => $request->getPut('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error metodo download_file como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ') ' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->getPut('token')]);
        $logger->close();
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