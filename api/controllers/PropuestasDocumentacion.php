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

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //Registro la accion en el log de convocatorias
            $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al controlador PropuestasDocumentacion en el método buscar_documentacion, ingresa al formulario de documentación como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
            
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
                                $array["programa"] = $propuesta->getConvocatorias()->programa;
                                $array["propuesta"] = $propuesta->id;
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

                                            //cuento si existe que el requisto aplica para el perfil de la categoria
                                            $resultado = substr_count($documento->getRequisitos()->perfiles, $propuesta->getParticipantes()->getUsuariosperfiles()->perfil);
                                            
                                            if($resultado>0)
                                            {                                            
                                                $documentos_administrativos[$documento->orden]["id"] = $documento->id;
                                                $documentos_administrativos[$documento->orden]["requisito"] = $documento->getRequisitos()->nombre;
                                                $documentos_administrativos[$documento->orden]["descripcion"] = $documento->descripcion;
                                                $documentos_administrativos[$documento->orden]["archivos_permitidos"] = json_decode($documento->archivos_permitidos);
                                                $documentos_administrativos[$documento->orden]["tamano_permitido"] = $documento->tamano_permitido;
                                                $documentos_administrativos[$documento->orden]["orden"] = $documento->orden;
                                            }
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
                                $logger->info('"token":"{token}","user":"{user}","message":"Retorna al controlador PropuestasDocumentacion en el método buscar_documentacion, retorna la información de la documentacion de la propuesta como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . '), en el metodo buscar_documentacion"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                                $logger->close();

                                //Retorno el array
                                echo json_encode($array);
                                
                            } else {
                                //Registro la accion en el log de convocatorias           
                                $logger->error('"token":"{token}","user":"{user}","message":"Error al controlador PropuestasDocumentacion en el método buscar_documentacion, la propuesta no existe como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . '), en el metodo buscar_documentacion"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                                $logger->close();
                                echo "crear_propuesta";
                                exit;
                            }
                        } else {
                            //Registro la accion en el log de convocatorias           
                            $logger->error('"token":"{token}","user":"{user}","message":"Error al controlador PropuestasDocumentacion en el método buscar_documentacion, la propuesta no existe como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . '), en el metodo buscar_documentacion"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                            $logger->close();
                            echo "error_cod_propuesta";
                            exit;
                        }
                    } else {
                        //Busco si tiene el perfil asociado de acuerdo al parametro
                        if ($request->get('m') == "pn") {
                            //Registro la accion en el log de convocatorias           
                            $logger->error('"token":"{token}","user":"{user}","message":"Error al controlador PropuestasDocumentacion en el método buscar_documentacion, debe crear el perfil como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                            $logger->close();
                            echo "crear_perfil_pn";
                            exit;
                        }
                        if ($request->get('m') == "pj") {
                            //Registro la accion en el log de convocatorias           
                            $logger->error('"token":"{token}","user":"{user}","message":"Error al controlador PropuestasDocumentacion en el método buscar_documentacion, debe crear el perfil como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                            $logger->close();
                            echo "crear_perfil_pj";
                            exit;
                        }
                        if ($request->get('m') == "agr") {
                            //Registro la accion en el log de convocatorias           
                            $logger->error('"token":"{token}","user":"{user}","message":"Error al controlador PropuestasDocumentacion en el método buscar_documentacion, debe crear el perfil como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                            $logger->close();
                            echo "crear_perfil_agr";
                            exit;
                        }
                    }
                } else {
                    //Busco si tiene el perfil asociado de acuerdo al parametro
                    if ($request->get('m') == "pn") {
                        //Registro la accion en el log de convocatorias           
                        $logger->error('"token":"{token}","user":"{user}","message":"Error al controlador PropuestasDocumentacion en el método buscar_documentacion, debe crear el perfil como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                        $logger->close();
                        echo "crear_perfil_pn";
                        exit;
                    }
                    if ($request->get('m') == "pj") {
                        //Registro la accion en el log de convocatorias           
                        $logger->error('"token":"{token}","user":"{user}","message":"Error al controlador PropuestasDocumentacion en el método buscar_documentacion, debe crear el perfil como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                        $logger->close();
                        echo "crear_perfil_pj";
                        exit;
                    }
                    if ($request->get('m') == "agr") {
                        //Registro la accion en el log de convocatorias           
                        $logger->error('"token":"{token}","user":"{user}","message":"Error al controlador PropuestasDocumentacion en el método buscar_documentacion, debe crear el perfil como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                        $logger->close();
                        echo "crear_perfil_agr";
                        exit;
                    }
                }
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"Error al controlador PropuestasDocumentacion en el método buscar_documentacion, acceso denegado como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                $logger->close();
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Error al controlador PropuestasDocumentacion en el método buscar_documentacion, token caduco como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => "", 'token' => $request->get('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error al controlador PropuestasDocumentacion en el método buscar_documentacion, error metodo como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ') ' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->get('token')]);
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
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->get('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                
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
                            $tipo_requisito=$documento->getConvocatoriasdocumentos()->getRequisitos()->tipo_requisito;
                            if($tipo_requisito=="Tecnicos")
                            {
                                $tipo_requisito="Técnicos";
                            }
                            $documentos_administrativos[$documento->getConvocatoriasdocumentos()->id]["tipo_requisito"] = $tipo_requisito;
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

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //Registro la accion en el log de convocatorias
            $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al controlador PropuestasDocumentacion en el método validar_requisitos, valida requisitos de la propuesta (' . $request->get('propuesta') . ') de la convocatoria(' . $request->get('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
            
            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->get('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                
                $propuesta = Propuestas::findFirst("id=" . $request->get('propuesta') . "");

                if (isset($propuesta->id)) {

                    //Consulto la convocatoria
                    $id=$request->get('conv');
                    $convocatoria = Convocatorias::findFirst($id);
                    $programa=$convocatoria->programa;
                    //Si la convocatoria seleccionada es categoria y no es especial invierto los id
                    if ($convocatoria->convocatoria_padre_categoria > 0 && $convocatoria->getConvocatorias()->tiene_categorias == true && $convocatoria->getConvocatorias()->diferentes_categorias == false) {
                        $id = $convocatoria->getConvocatorias()->id;                    
                        $programa=$convocatoria->getConvocatorias()->programa;
                    }
                    
                    //Consulto los requisitos administrativos no guardados
                    $sql_requisitos_administrativos = "SELECT 
                                                cd.id,
                                                r.nombre	
                                        FROM Convocatoriasdocumentos AS cd
                                        INNER JOIN Requisitos AS r ON r.id=cd.requisito
                                        LEFT JOIN Propuestasdocumentos AS pd ON pd.convocatoriadocumento = cd.id AND pd.propuesta=" . $propuesta->id . " AND pd.active = TRUE 
                                        LEFT JOIN Propuestaslinks AS pl ON pl.convocatoriadocumento = cd.id AND pl.propuesta=" . $propuesta->id . " AND pl.active = TRUE
                                        WHERE r.tipo_requisito='Administrativos' AND r.perfiles='".$propuesta->getParticipantes()->getUsuariosperfiles()->perfil."' AND cd.obligatorio=TRUE AND cd.active=TRUE AND cd.convocatoria=" . $id . " AND pd.convocatoriadocumento IS NULL AND pl.convocatoriadocumento IS NULL";

                    $requisitos_administrativos = $app->modelsManager->executeQuery($sql_requisitos_administrativos);
                    
                    $array_retorno=array();
                    foreach ($requisitos_administrativos as $requisito) {
                        
                        $array_retorno[] = array('id' => $requisito->id, 'nombre' => $requisito->nombre);
                        $logger->error('"token":"{token}","user":"{user}","message":"Validar en el controlador PropuestasDocumentacion en el método validar_requisitos, no ha ingresado requisito administrativo ('.$requisito->nombre.') en la propuesta (' . $request->get('propuesta') . ')."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                    }
                    
                    //Consulto los requisitos tecnicos no guardados
                    $sql_requisitos_tecnicos = "SELECT 
                                                cd.id,
                                                r.nombre	
                                        FROM Convocatoriasdocumentos AS cd
                                        INNER JOIN Requisitos AS r ON r.id=cd.requisito
                                        LEFT JOIN Propuestasdocumentos AS pd ON pd.convocatoriadocumento = cd.id AND pd.propuesta=" . $propuesta->id . " AND pd.active = TRUE 
                                        LEFT JOIN Propuestaslinks AS pl ON pl.convocatoriadocumento = cd.id AND pl.propuesta=" . $propuesta->id . " AND pl.active = TRUE
                                        WHERE r.tipo_requisito='Tecnicos'  AND cd.obligatorio=TRUE AND cd.active=TRUE AND cd.convocatoria=" . $id . " AND pd.convocatoriadocumento IS NULL AND pl.convocatoriadocumento IS NULL";

                    $requisitos_tecnicos = $app->modelsManager->executeQuery($sql_requisitos_tecnicos);
                                        
                    foreach ($requisitos_tecnicos as $requisito) {
                        
                        $array_retorno[] = array('id' => $requisito->id, 'nombre' => $requisito->nombre);
                        $logger->error('"token":"{token}","user":"{user}","message":"Validar en el controlador PropuestasDocumentacion en el método validar_requisitos, no ha ingresado requisito técnico ('.$requisito->nombre.') en la propuesta (' . $request->get('propuesta') . ')."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                                                
                    }
                    
                    $id_perfil = $propuesta->getParticipantes()->getUsuariosperfiles()->getPerfiles()->id;
                    
                    if( $id_perfil==7 || $id_perfil==8)
                    {
                        
                        //Se valida que al menos tenga registrado un integrante
                        $participantes = Participantes::find("tipo IN ('Junta','Integrante') AND active = TRUE AND participante_padre=" . $propuesta->participante . "");
                        
                        if( count($participantes) <= 0 )
                        {                                                
                            if( $id_perfil==7)
                            {
                                $array_retorno[] = array('id' => "Junta", 'nombre' => "Junta");
                                $logger->error('"token":"{token}","user":"{user}","message":"Validar en el controlador PropuestasDocumentacion en el método validar_requisitos, no ha ingresado los integrantes de la junta en la propuesta (' . $request->get('propuesta') . ')."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                            }
                            
                            if($id_perfil==8)
                            {
                                $array_retorno[] = array('id' => "Integrante", 'nombre' => "Integrante");                                                                                                                                
                                $logger->error('"token":"{token}","user":"{user}","message":"Validar en el controlador PropuestasDocumentacion en el método validar_requisitos, no ha ingresado los integrantes de la agrupación en la propuesta (' . $request->get('propuesta') . ')."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                            }                                                        
                        }
                        
                        //Se valida que al menos tenga un representante de la junta o agrupación
                        $participantes = Participantes::find("representante = TRUE AND active = TRUE AND participante_padre=" . $propuesta->participante . "");
                        
                        if( count($participantes) <= 0 )
                        {
                            if( $id_perfil==7)
                            {
                                $array_retorno[] = array('id' => "RJunta", 'nombre' => "RJunta");                                                                                                                                
                                $logger->error('"token":"{token}","user":"{user}","message":"Validar en el controlador PropuestasDocumentacion en el método validar_requisitos, no ha ingresado el representante de la junta en la propuesta (' . $request->get('propuesta') . ')."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                            }
                            
                            if($id_perfil==8)
                            {
                                $array_retorno[] = array('id' => "RIntegrante", 'nombre' => "RIntegrante");                                                                                                                                                                
                                $logger->error('"token":"{token}","user":"{user}","message":"Validar en el controlador PropuestasDocumentacion en el método validar_requisitos, no ha ingresado el representante de la agrupación en la propuesta (' . $request->get('propuesta') . ')."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                            }                                                        
                        }
                        
                        //Validaciones solo para el PDAC
                        if($programa==2){
                            //Se valida que al menos tenga un integrante equipo
                            $participantes = Participantes::find("tipo='EquipoTrabajo' AND active = TRUE AND participante_padre=" . $propuesta->participante . "");

                            if( count($participantes) <= 0 )
                            {
                                $array_retorno[] = array('id' => "EquipoTrabajo", 'nombre' => "EquipoTrabajo");
                                $logger->error('"token":"{token}","user":"{user}","message":"Validar en el controlador PropuestasDocumentacion en el método validar_requisitos, no ha ingresado los integrantes del equipo de trabajo de la agrupación en la propuesta (' . $request->get('propuesta') . ')."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                            }
                            
                            //No ha ingresado la informacion del objetivo general
                            if($propuesta->objetivo_general=="")
                            {
                                $array_retorno[] = array('id' => "FObjetivogeneral", 'nombre' => "FObjetivogeneral");
                                $logger->error('"token":"{token}","user":"{user}","message":"Validar en el controlador PropuestasDocumentacion en el método validar_requisitos, no ha ingresado información en el formulario del objetivo general de la agrupación en la propuesta (' . $request->get('propuesta') . ')."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                            }

                            //No ha ingresado la informacion del formulario para el registro de los territorios y población 
                            if($propuesta->poblacion_objetivo=="" || $propuesta->comunidad_objetivo=="" || $propuesta->total_beneficiario=="" || $propuesta->establecio_cifra=="" || $propuesta->localidades=="")
                            {
                                $array_retorno[] = array('id' => "FTerritorio", 'nombre' => "FTerritorio");
                                $logger->error('"token":"{token}","user":"{user}","message":"Validar en el controlador PropuestasDocumentacion en el método validar_requisitos, no ha ingresado información en el formulario del territorio y pobación de la agrupación en la propuesta (' . $request->get('propuesta') . ')."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                            }
                            
                            //Objetivos especificos de la propuesta
                            $total_objetivos = COUNT($propuesta->getPropuestasobjetivos("active=TRUE"));
                            if($total_objetivos<=0)
                            {
                                $array_retorno[] = array('id' => "FObjetivos", 'nombre' => "FObjetivos");
                                $logger->error('"token":"{token}","user":"{user}","message":"Validar en el controlador PropuestasDocumentacion en el método validar_requisitos, no ha ingresado objetivos especificos en la propuesta (' . $request->get('propuesta') . ')."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                            }
                            
                            //Actividades de la propuesta
                            $error_objetivos=false;
                            foreach($propuesta->getPropuestasobjetivos("active=TRUE") as $objetivo){
                                $total_actividades= COUNT(Propuestasactividades::find("active=true AND propuestaobjetivo = ".$objetivo->id));
                                if($total_actividades<=0)
                                {
                                    $error_objetivos=true;
                                    break;
                                }                                                                
                            }             
                            
                            if($error_objetivos){
                                $array_retorno[] = array('id' => "FActividades", 'nombre' => "FActividades");
                                $logger->error('"token":"{token}","user":"{user}","message":"Validar en el controlador PropuestasDocumentacion en el método validar_requisitos, hay objetivos que no cuentan con actividades en la propuesta (' . $request->get('propuesta') . ')."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                            }
                            
                            //Cronograma de la propuesta
                            $error_cronograma=false;
                            foreach($propuesta->getPropuestasobjetivos("active=TRUE") as $objetivo){                                
                                foreach(Propuestasactividades::find("active=true AND propuestaobjetivo = ".$objetivo->id) as $actividad)
                                {
                                    $total_cronograma= COUNT(Propuestascronogramas::find("active=true AND propuestaactividad = ".$actividad->id));
                                    if($total_cronograma<=0)
                                    {
                                        $error_cronograma=true;
                                        break;
                                    }
                                }
                            }
                            
                            if($error_cronograma){
                                $array_retorno[] = array('id' => "FCronograma", 'nombre' => "FCronograma");
                                $logger->error('"token":"{token}","user":"{user}","message":"Validar en el controlador PropuestasDocumentacion en el método validar_requisitos, hay actividades que no cuentan con cronograma en la propuesta (' . $request->get('propuesta') . ')."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                            }
                            
                            //presupuesto de la propuesta
                            $error_presupuesto=false;
                            foreach($propuesta->getPropuestasobjetivos("active=TRUE") as $objetivo){                                
                                foreach(Propuestasactividades::find("active=true AND propuestaobjetivo = ".$objetivo->id) as $actividad)
                                {
                                    $total_presupuesto= COUNT(Propuestaspresupuestos::find("active=true AND propuestaactividad = ".$actividad->id));
                                    if($total_presupuesto<=0)
                                    {
                                        $error_presupuesto=true;
                                        break;
                                    }
                                }
                            }
                            
                            if($error_presupuesto){
                                $array_retorno[] = array('id' => "FPresupuesto", 'nombre' => "FPresupuesto");
                                $logger->error('"token":"{token}","user":"{user}","message":"Validar en el controlador PropuestasDocumentacion en el método validar_requisitos, hay actividades que no cuentan con presupuesto en la propuesta (' . $request->get('propuesta') . ')."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                            }
                            
                            //total presupuesto de la propuesta
                            $error_total_presupuesto=false;
                            $valor_total_proyecto=0;
                            $valor_total_concertacion=0;
                            foreach($propuesta->getPropuestasobjetivos("active=TRUE") as $objetivo){                                
                                foreach(Propuestasactividades::find("active=true AND propuestaobjetivo = ".$objetivo->id) as $actividad)
                                {
                                    
                                    $sql_totales = 'SELECT SUM(valortotal) AS total_proyecto, SUM(aportesolicitado) AS total_concertacion FROM Propuestaspresupuestos WHERE active=TRUE';

                                    $totales = $app->modelsManager->executeQuery($sql_totales)->getFirst();
                                    
                                    $valor_total_proyecto=$valor_total_proyecto+$totales->total_proyecto;
                                    
                                    $valor_total_concertacion=$valor_total_concertacion+$totales->total_concertacion;
                                    
                                }
                            }
                            
                            //Generamos el 70% del total del proyecto
                            $porcentaje_proyecto=($valor_total_proyecto*70)/100;
                            
                            if($valor_total_concertacion>$porcentaje_proyecto){
                                $array_retorno[] = array('id' => "FPorcentajePresupuesto", 'nombre' => "FPorcentajePresupuesto");
                                $logger->error('"token":"{token}","user":"{user}","message":"Validar en el controlador PropuestasDocumentacion en el método validar_requisitos, el total de la cofinanciación es superior al 70% del valor total del proyecto en la propuesta (' . $request->get('propuesta') . ')."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                            }
                            
                        }
                        
                        
                    }
                    
                    //No ha ingresado la informacion de la propuesta
                    if($propuesta->nombre=="")
                    {
                        $array_retorno[] = array('id' => "FPropuesta", 'nombre' => "FPropuesta");
                        $logger->error('"token":"{token}","user":"{user}","message":"Validar en el controlador PropuestasDocumentacion en el método validar_requisitos, no ha ingresado información en el formulario de la propuesta en la propuesta (' . $request->get('propuesta') . ')."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                    }
                                        
                    $logger->close();                    
                    echo json_encode($array_retorno);
                    
                } else {
                    //Registro la accion en el log de convocatorias           
                    $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestasDocumentacion en el método validar_requisitos, no existe la propuesta (' . $request->get('propuesta') . ')."', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                    $logger->close();
                    echo "crear_propuesta";
                    exit;
                }
            } else {
                //Registro la accion en el log de convocatorias      
                $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestasDocumentacion en el método validar_requisitos, acceso denegado en la propuesta (' . $request->get('propuesta') . ')."', ['user' => $user_current["username"], 'token' => $request->get('token')]);                
                $logger->close();
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias                       
            $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestasDocumentacion en el método validar_requisitos, token caduco en la propuesta (' . $request->get('propuesta') . ')."', ['user' => '', 'token' => $request->get('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestasDocumentacion en el método validar_requisitos, error en el metodo de la propuesta (' . $request->get('propuesta') . ')  ' . $ex->getMessage() . '"', ['user' => '', 'token' => $request->get('token')]);        
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
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->get('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                
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
                                        FROM Propuestasverificaciones AS pv
                                        INNER JOIN Convocatoriasdocumentos AS cd ON cd.id=pv.convocatoriadocumento
                                        INNER JOIN Requisitos AS r ON r.id=cd.requisito
                                        LEFT JOIN Propuestasdocumentos AS pd ON pd.convocatoriadocumento = cd.id AND pd.propuesta=" . $propuesta->id . " AND pd.active = TRUE AND pd.cargue_subsanacion = TRUE 
                                        LEFT JOIN Propuestaslinks AS pl ON pl.convocatoriadocumento = cd.id AND pl.propuesta=" . $propuesta->id . " AND pl.active = TRUE AND pl.cargue_subsanacion = TRUE 
                                        WHERE  pv.verificacion=1 AND pv.estado=27 AND pv.propuesta=" . $propuesta->id . " AND r.tipo_requisito IN ('Administrativos','Tecnicos') AND cd.convocatoria=" . $id . " AND pd.convocatoriadocumento IS NULL AND pl.convocatoriadocumento IS NULL";
                    
                    $requisitos = $app->modelsManager->executeQuery($sql_requisitos);

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

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);
            
            //Registro la accion en el log de convocatorias
            $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al controlador PropuestasDocumentacion en el método buscar_archivos, cargar tabla de los archivos del documento como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->get('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                
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
                    $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al controlador PropuestasDocumentacion en el método buscar_archivos, retorna la información pata el documento como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . '), en el metodo buscar_archivos"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                    $logger->close();

                    //Retorno el array
                    echo json_encode($consulta_documentos_administrativos);
                } else {
                    //Registro la accion en el log de convocatorias           
                    $logger->error('"token":"{token}","user":"{user}","message":"Error al controlador PropuestasDocumentacion en el método buscar_archivos, no existe la propuesta como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . '), en el metodo buscar_archivos"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                    $logger->close();
                    echo "crear_propuesta";
                    exit;
                }
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"Error al controlador PropuestasDocumentacion en el método buscar_archivos, acceso denegado como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                $logger->close();
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Error al controlador PropuestasDocumentacion en el método buscar_archivos, token caduco como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error al controlador PropuestasDocumentacion en el método buscar_archivos, error en el metodo como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ') ' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->get('token')]);
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

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);
            
            //Registro la accion en el log de convocatorias
            $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al controlador PropuestasDocumentacion en el método buscar_link, cargar tabla de los link de los documentos como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->get('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                
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
                    $logger->info('"token":"{token}","user":"{user}","message":"Retorna al controlador PropuestasDocumentacion en el método buscar_link, retorna link para el documento como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . '), en el metodo buscar_link"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                    $logger->close();

                    //Retorno el array
                    echo json_encode($consulta_documentos_link);
                } else {
                    //Registro la accion en el log de convocatorias           
                    $logger->error('"token":"{token}","user":"{user}","message":"Error al controlador PropuestasDocumentacion en el método buscar_link, la propuesta no existe como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . '), en el metodo buscar_link"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                    $logger->close();
                    echo "crear_propuesta";
                    exit;
                }
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"Error al controlador PropuestasDocumentacion en el método buscar_link, acceso denegado como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->get('token')]);
                $logger->close();
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Error al controlador PropuestasDocumentacion en el método buscar_link, token caduco en el metodo buscar_link como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ')"', ['user' => "", 'token' => $request->get('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error al controlador PropuestasDocumentacion en el método buscar_link, error metodo como (' . $request->get('m') . ') en la convocatoria(' . $request->get('conv') . ') ' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->get('token')]);
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

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //Registro la accion en el log de convocatorias
            $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al controlador PropuestasDocumentacion en el método guardar_archivo, ingresa a crear documento como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
        
            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->getPut('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                
                $explode = explode(',', substr($request->getPut('srcData'), 5), 2);
                $data = $explode[1];
                $fileName = "c" . $request->getPost('convocatoria_padre_categoria') . "d" . $request->getPut('conv') . "u" . $user_current["id"] . "f" . date("YmdHis") . "." . $request->getPut("srcExt");

                $chemistry_alfresco = new ChemistryPV($config->alfresco->api, $config->alfresco->username, $config->alfresco->password);
                $return = $chemistry_alfresco->newFile("/Sites/convocatorias/" . $request->getPut('conv') . "/propuestas/" . $request->getPut('propuesta'), $fileName, base64_decode($data), $request->getPut("srcType"));
                if (strpos($return, "Error") !== FALSE) {
                    //Registro la accion en el log de convocatorias           
                    $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestasDocumentacion en el método guardar_archivo, error al crear el archivo (' . $request->getPut('srcName') . ') en el metodo guardar_archivo como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
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
                        $logger->error('"token":"{token}","user":"{user}","message":"Error al controlador PropuestasDocumentacion en el método guardar_archivo, error al relacionar el idalfresco con idpropuestadocumento en el archivo (' . $request->getPut('srcName') . ') como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
                        $logger->close();
                        echo "error_archivo";
                    } else {
                        $logger->info('"token":"{token}","user":"{user}","message":"Retorna en el controlador PropuestasDocumentacion en el método guardar_archivo, se relaciona el idalfresco con idpropuestadocumento en el archivo (' . $request->getPut('srcName') . ') como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
                        $logger->close();
                        echo $propuestasdocumentos->id;
                    }
                }
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"Error al controlador PropuestasDocumentacion en el método guardar_archivo, acceso denegado como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
                $logger->close();
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Error al controlador PropuestasDocumentacion en el método guardar_archivo, token caduco como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')"', ['user' => "", 'token' => $request->getPut('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error al controlador PropuestasDocumentacion en el método guardar_archivo, error en el metodo como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ') ' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->getPut('token')]);
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

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);
            
            //Registro la accion en el log de convocatorias
            $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al controlador PropuestasDocumentacion en el método guardar_link, ingresa a guardar_link como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->getPut('modulo'));

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
                    $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestasDocumentacion en el método guardar_link, error al crear el link como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
                    $logger->close();
                    echo "error_archivo";
                } else {
                    $logger->info('"token":"{token}","user":"{user}","message":"Retorno el controlador PropuestasDocumentacion en el método guardar_link, se creo el link como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
                    $logger->close();
                    echo $propuestaslinks->id;
                }
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestasDocumentacion en el método guardar_link, acceso denegado como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
                $logger->close();
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestasDocumentacion en el método guardar_link, token caduco como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ')"', ['user' => "", 'token' => $request->getPut('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error en el controlador PropuestasDocumentacion en el método guardar_link, error metodo como (' . $request->getPut('m') . ') en la convocatoria(' . $request->getPut('conv') . ') ' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->getPut('token')]);
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

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);
            
            //Registro la accion en el log de convocatorias
            $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al controlador PropuestasDocumentacion en el método delete, ingreso a inactivar el archivo"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);


            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->getPut('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                // Consultar el registro
                $propuestasdocumentos = Propuestasdocumentos::findFirst(json_decode($id));
                $propuestasdocumentos->active = false;

                if ($propuestasdocumentos->save($propuestasdocumentos) === false) {
                    //Registro la accion en el log de convocatorias           
                    $logger->error('"token":"{token}","user":"{user}","message":"Error al controlador PropuestasDocumentacion en el método delete, error al desactivar el archivo "', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
                    $logger->close();
                    echo "error";
                } else {
                    $logger->info('"token":"{token}","user":"{user}","message":"Retorno al controlador PropuestasDocumentacion en el método delete, se desactivo el archivo"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
                    $logger->close();
                    echo $propuestasdocumentos->id;
                }
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"Error al controlador PropuestasDocumentacion en el método delete, acceso denegado"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
                $logger->close();
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Error al controlador PropuestasDocumentacion en el método delete, token caduco"', ['user' => "", 'token' => $request->getPut('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error al controlador PropuestasDocumentacion en el método delete, error metodo ' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->getPut('token')]);
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

        //Si el token existe y esta activo entra a realizar la tabla
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //Registro la accion en el log de convocatorias
            $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al controlador PropuestasDocumentacion en el método delete_link, ingresa inactivar"', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
        
            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->getPut('modulo'));

            //Verifico que la respuesta es ok, para poder realizar la escritura
            if ($permiso_escritura == "ok") {
                // Consultar el registro
                $propuestaslinks = Propuestaslinks::findFirst(json_decode($id));
                $propuestaslinks->active = false;

                if ($propuestaslinks->save($propuestaslinks) === false) {
                    //Registro la accion en el log de convocatorias           
                    $logger->error('"token":"{token}","user":"{user}","message":"Error al controlador PropuestasDocumentacion en el método delete_link, error al inactivar "', ['user' => "", 'token' => $request->getPut('token')]);
                    $logger->close();
                    echo "error";
                } else {
                    $logger->info('"token":"{token}","user":"{user}","message":"Ingresa al controlador PropuestasDocumentacion en el método delete_link, se inactivo el link "', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
                    $logger->close();
                    echo $propuestaslinks->id;
                }
            } else {
                //Registro la accion en el log de convocatorias           
                $logger->error('"token":"{token}","user":"{user}","message":"Error al controlador PropuestasDocumentacion en el método delete_link, acceso denegado "', ['user' => $user_current["username"], 'token' => $request->getPut('token')]);
                $logger->close();
                echo "acceso_denegado";
            }
        } else {
            //Registro la accion en el log de convocatorias           
            $logger->error('"token":"{token}","user":"{user}","message":"Error al controlador PropuestasDocumentacion en el método delete_link, token caduco "', ['user' => "", 'token' => $request->getPut('token')]);
            $logger->close();
            echo "error_token";
        }
    } catch (Exception $ex) {
        //Registro la accion en el log de convocatorias           
        $logger->error('"token":"{token}","user":"{user}","message":"Error al controlador PropuestasDocumentacion en el método delete_link, error metodo ' . $ex->getMessage() . '"', ['user' => "", 'token' => $request->getPut('token')]);
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
        if (isset($token_actual->id)) {

            //Usuario actual
            $user_current = json_decode($token_actual->user_current, true);

            //verificar si tiene permisos de escritura
            $permiso_escritura = $tokens->permiso_lectura($user_current["id"], $request->getPut('modulo'));

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